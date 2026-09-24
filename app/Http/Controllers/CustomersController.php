<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Models\Product;
use App\Models\Category;
use App\Models\Brand;
use App\Models\Customer;
use App\Services\BusinessAuthorization;
use Illuminate\Http\Request;

class CustomersController extends Controller
{
    public function __construct(private readonly BusinessAuthorization $auth) {}

    public function index(Request $request)
    {
        $this->auth->authorize('customers.view');

        $tenant = $request->user()->currentTenant;

        $query = Customer::where('tenant_id', $tenant->id);

        if ($request->filled('search')) {
            $term = $request->getString('search');
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
            'search' => $request->getString('search', ''),
            'active_only' => $request->boolean('active_only'),
        ]);
    }

    public function create()
    {
        $this->auth->authorize('customers.create');

        return view('customers.form', ['customer' => new Customer(), 'pageTitle' => 'Add Customer']);
    }

    public function store(Request $request)
    {
        $this->auth->authorize('customers.create');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:65535',
            'credit_limit' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        Customer::create(array_merge($validated, ['tenant_id' => $this->tenantId($request)]));

        AuditLogger::log('customer.created', Customer::latest('id')->first(), ['name' => $validated['name']]);

        return redirect()->route('customers.index')->with('status', ['type' => 'success', 'message' => 'Customer created.']);
    }

    public function edit(Customer $customer)
    {
        $this->auth->authorize('customers.update');
        $this->ensureOwned($customer);

        return view('customers.form', ['customer' => $customer, 'pageTitle' => 'Edit Customer']);
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

        $customer->update($validated);

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
