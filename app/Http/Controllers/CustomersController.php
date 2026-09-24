<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\BusinessUsageService;
use App\Models\Customer;
use App\Services\BusinessAuthorization;
use App\Services\Money;
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

        $tenant = $request->user()->currentTenant;

        $query = Customer::where('tenant_id', $tenant->id);

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

        $customers = $query->latest('created_at')->paginate(50);

        return view('customers.index', [
            'customers' => $customers,
            'search' => $request->string('search')->toString(),
            'active_only' => $request->boolean('active_only'),
        ]);
    }

    public function create()
    {
        $this->auth->authorize('customers.create');

        return view('customers.form', [
            'customer'  => new Customer(['is_active' => true]),
            'pageTitle' => 'Add Customer',
            'submitUrl' => route('customers.store'),
        ]);
    }

    public function store(Request $request)
    {
        $this->auth->authorize('customers.create');

        $tenant = $request->user()->currentTenant;

        $validated = $request->validate([
            'form_context' => 'nullable|in:sales_create',
            'name' => 'required|string|max:255',
            'phone' => 'required_if:form_context,sales_create|nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:65535',
            'credit_limit' => 'nullable|integer|min:0',
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
            'tenant_id'    => $this->tenantId($request),
            // Same money convention as products: user-facing amount in, cents stored.
            'credit_limit' => Money::centsFromDisplay($validated['credit_limit'] ?? 0),
        ]));

        $this->usage->recordMetric($tenant, 'customers_count');

        AuditLogger::log('customer.created', $customer, ['name' => $validated['name']]);

        if ($request->expectsJson()) {
            return response()->json([
                'customer' => [
                    'id'    => (int) $customer->id,
                    'name'  => $customer->name,
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
            'sales'    => $customer->sales()
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
            'customer'  => $customer,
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
            'credit_limit' => 'nullable|integer|min:0',
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
