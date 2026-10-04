<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\AuditLogger;
use App\Services\BusinessAuthorization;
use App\Services\BusinessUsageService;
use App\Services\Money;
use App\Services\VCardExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CustomersController extends Controller
{
    public function __construct(
        private readonly BusinessAuthorization $auth,
        private readonly BusinessUsageService $usage,
    ) {}

    public function index(Request $request)
    {
        $this->auth->authorize('customers.view');

        $customers = $this->filtered($request)->latest('created_at')->paginate(50);

        return view('customers.index', [
            'customers' => $customers,
            'search' => $request->string('search')->toString(),
            'active_only' => $request->boolean('active_only'),
            // The export links replay exactly the filters on screen, so the downloaded
            // file and the visible list can never disagree about which customers are in
            // scope. Built here rather than in Blade so "the current filter" has one
            // definition.
            'exportFilters' => array_filter([
                'search' => $request->string('search')->toString(),
                'active_only' => $request->boolean('active_only') ? 1 : null,
                'show_inactive' => $request->boolean('show_inactive') ? 1 : null,
            ], fn ($value) => $value !== null && $value !== ''),
        ]);
    }

    /**
     * Contact export: one file holding every customer in scope.
     *
     * The query is the SAME filtered() the list uses, minus the pagination. That is the
     * whole point — exporting the 50 rows currently on screen would silently drop the
     * other 50 of a workspace with 100 customers, so the page count would never reach
     * the file.
     *
     * `format` picks between the two targets described in the UI: `vcf` (default) for a
     * phone's Contacts app, `csv` for Excel / Sheets / a CRM import. An unknown value
     * falls back to vcf rather than erroring, so a hand-edited URL still downloads
     * something the importer can read.
     */
    public function export(Request $request, VCardExporter $exporter)
    {
        // The same permission as reading the list: this is the same customer data in a
        // different file format. No new permission is invented for it.
        $this->auth->authorize('customers.view');

        $format = strtolower((string) $request->query('format', 'vcf'));
        $customers = $this->filtered($request)->orderBy('name')->get();

        // Nothing to export is a normal outcome, not an error — and an empty file would
        // download as 0 bytes and fail silently at the importer.
        if ($customers->isEmpty()) {
            return redirect()->route('customers.index', $request->except(['page', 'format']))
                ->with('status', ['type' => 'error', 'message' => __('No customers to export.')]);
        }

        AuditLogger::log('customer.exported', null, [
            'format' => $format,
            'count' => $customers->count(),
        ]);

        return $format === 'csv'
            ? $this->streamCsv($customers)
            : $this->streamVcf($exporter, $customers);
    }

    /** streamDownload mirrors the existing CSV report export. */
    private function streamVcf(VCardExporter $exporter, mixed $customers)
    {
        return response()->streamDownload(
            fn () => print($exporter->render($customers)),
            'customers.vcf',
            [
                'Content-Type' => 'text/vcard; charset=utf-8',
                // Naming the type text/vcard is what tells iOS and Android to hand the
                // file to the Contacts app instead of previewing it as plain text.
                'Content-Disposition' => 'attachment; filename="customers.vcf"',
            ]
        );
    }

    /**
     * The same rows as the vCard, as a spreadsheet-friendly CSV.
     *
     * Rows are written with fputcsv, never by string concatenation: that is what
     * correctly quotes a name containing a comma, a double quote or a newline, which a
     * hand-built line would turn into a corrupt file. A BOM is emitted first so Excel
     * opens UTF-8 (Indonesian names are full of non-ASCII characters) without mangling
     * them.
     */
    private function streamCsv(mixed $customers)
    {
        return response()->streamDownload(function () use ($customers) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM so Excel detects the encoding.
            echo "\xEF\xBB\xBF";

            fputcsv($handle, [
                __('Name'), __('Phone'), __('Email'), __('Address'), __('Notes'),
            ]);

            foreach ($customers as $customer) {
                // Empty string, never null: a blank cell is a blank cell. fputcsv
                // quotes these correctly on its own.
                fputcsv($handle, [
                    (string) ($customer->name ?? ''),
                    (string) ($customer->phone ?? ''),
                    (string) ($customer->email ?? ''),
                    (string) ($customer->address ?? ''),
                    (string) ($customer->notes ?? ''),
                ]);
            }

            fclose($handle);
        }, 'customers.csv', [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="customers.csv"',
        ]);
    }

    /**
     * The customers the current request is asking for, before pagination.
     *
     * Shared with index() so the filter on screen and the filter applied to the export
     * cannot drift apart — the classic bug where an export quietly ignores the search box.
     */
    private function filtered(Request $request): Builder
    {
        $query = Customer::where('tenant_id', $request->user()->currentTenant->id);

        if ($request->filled('search')) {
            $term = $request->string('search')->toString();
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
            });
        }

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        if ($request->boolean('show_inactive')) {
            $query->where('is_active', false);
        }

        return $query;
    }

    public function create()
    {
        $this->auth->authorize('customers.create');

        return view('customers.form', [
            'customer' => new Customer(['is_active' => true]),
            'pageTitle' => 'Add Customer',
            'submitUrl' => route('customers.store'),
        ]);
    }

    public function store(Request $request)
    {
        $this->auth->authorize('customers.create');

        $tenant = $request->user()->currentTenant;

        $validated = $request->validate([
            // `pos` is the cashier screen reusing this same endpoint, so a customer
            // created at the counter is validated by exactly the same rules — including
            // the phone requirement — as one created from /sales/create.
            'form_context' => 'nullable|in:sales_create,pos',
            'name' => 'required|string|max:255',
            'phone' => 'required_if:form_context,sales_create,pos|nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:65535',
            'credit_limit' => 'nullable|numeric|min:0|max:100000000',
            'is_active' => 'boolean',
        ]);

        if (! empty($validated['phone']) && Customer::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId($request))
            ->where('phone', $validated['phone'])
            ->where('name', $validated['name'])
            ->exists()) {
            throw ValidationException::withMessages([
                'phone' => 'A customer with this name and phone already exists.',
            ]);
        }

        unset($validated['form_context']);

        // Metered plan limit (same metering the products/sales modules use).
        $this->usage->enforce($tenant, 'customers_count');

        $customer = Customer::create(array_merge($validated, [
            'tenant_id' => $this->tenantId($request),
            // Same money convention as products: user-facing amount in, cents stored.
            'credit_limit' => Money::centsFromDisplay($validated['credit_limit'] ?? 0),
        ]));

        $this->usage->recordMetric($tenant, 'customers_count');

        AuditLogger::log('customer.created', $customer, ['name' => $validated['name']]);

        if ($request->expectsJson()) {
            return response()->json([
                'customer' => [
                    'id' => (int) $customer->id,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                ],
            ], 201);
        }

        return redirect()->route('customers.index')->with('status', ['type' => 'success', 'message' => 'Customer created.']);
    }

    /** Customer detail: lifetime metrics + purchase history (§8). */
    public function show(Customer $customer)
    {
        $this->auth->authorize('customers.view');
        $this->ensureOwned($customer);

        return view('customers.show', [
            'customer' => $customer,
            'sales' => $customer->sales()
                ->with('items')
                ->latest('sold_at')
                ->paginate(25),
        ]);
    }

    public function edit(Customer $customer)
    {
        $this->auth->authorize('customers.update');
        $this->ensureOwned($customer);

        return view('customers.form', [
            'customer' => $customer,
            'pageTitle' => 'Edit Customer',
            'submitUrl' => route('customers.update', $customer),
        ]);
    }

    public function update(Request $request, Customer $customer)
    {
        $this->auth->authorize('customers.update');
        $this->ensureOwned($customer);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:65535',
            'credit_limit' => 'nullable|numeric|min:0|max:100000000',
            'is_active' => 'boolean',
        ]);

        $customer->update(array_merge($validated, [
            'credit_limit' => Money::centsFromDisplay($validated['credit_limit'] ?? 0),
        ]));

        AuditLogger::log('customer.updated', $customer, ['name' => $customer->name]);

        return redirect()->route('customers.index')->with('status', ['type' => 'success', 'message' => 'Customer updated.']);
    }

    public function destroy(Request $request, Customer $customer)
    {
        $this->auth->authorize('customers.delete');
        $this->ensureOwned($customer);

        if ($customer->sales()->where('status', 'completed')->exists()) {
            return back()->with('status', ['type' => 'error', 'message' => 'Cannot delete a customer with completed sales.']);
        }

        $customer->delete();

        AuditLogger::log('customer.deleted', $customer, ['name' => $customer->name]);

        return redirect()->route('customers.index')->with('status', ['type' => 'success', 'message' => 'Customer deleted.']);
    }

    private function tenantId(Request $request): int
    {
        return $request->user()->currentTenant->id;
    }

    private function ensureOwned(Customer $customer): void
    {
        // Never trust the route id: compare against the *active* tenant context.
        if (! $customer->tenant_id || $customer->tenant_id !== app('tenant.context')->tenant()?->id) {
            abort(404);
        }
    }
}
