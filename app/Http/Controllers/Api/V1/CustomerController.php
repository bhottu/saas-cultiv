<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\AuditLogger;
use App\Services\UsageService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CustomerController extends ApiController
{
    public function index(Request $request)
    {
        $this->auth->authorize('customers.view');

        $query = Customer::query()->withCount('sales');

        $this->applySearch($query, $request, ['name', 'phone', 'email']);

        if (($active = $this->isActiveFilter($request)) !== null) {
            $query->where('is_active', $active);
        }

        $customers = $query->orderBy('name')->paginate($this->perPage($request))->withQueryString();

        return $this->withMeta(CustomerResource::collection($customers), $request);
    }

    public function show(Request $request, int $customer)
    {
        $this->auth->authorize('customers.view');

        $model = Customer::query()->withCount('sales')->findOrFail($customer);

        // Lifetime totals are derived, never stored, so they cannot go stale.
        return CustomerResource::make($model)->additional([
            'currency' => 'IDR',
            'money_unit' => 'cents',
            'totals' => [
                'orders' => $model->totalOrders(),
                'spent' => $model->totalSpent(),
            ],
        ]);
    }

    public function sales(Request $request, int $customer)
    {
        $this->auth->authorize('sales.view');

        $model = Customer::query()->findOrFail($customer);

        $sales = $model->sales()
            ->with(['items:id,sale_id,product_name,quantity,selling_price,subtotal'])
            ->latest('sold_at')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return \App\Http\Resources\SaleResource::collection($sales)->additional([
            'currency' => 'IDR',
            'money_unit' => 'cents',
            'customer' => ['id' => $model->id, 'name' => $model->name],
        ]);
    }

    /**
     * Create a customer with the web form's validation, duplicate-contact rule and
     * metered seat limit (§15) — the API is not a second, laxer code path.
     */
    public function store(Request $request)
    {
        $this->auth->authorize('customers.create');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:65535',
            // money_unit = cents: this API advertises cents in every response meta, so
            // it accepts cents here (the web form takes display amounts instead).
            'credit_limit' => 'nullable|integer|min:0|max:10000000000',
            'is_active' => 'boolean',
        ]);

        $tenant = $this->tenant($request);

        // Same duplicate rule as the web controller: name + phone must be unique per
        // workspace, reported as a validation error rather than a DB violation.
        if (! empty($validated['phone']) && Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('phone', $validated['phone'])
            ->where('name', $validated['name'])
            ->exists()) {
            throw ValidationException::withMessages([
                'phone' => 'A customer with this name and phone already exists.',
            ]);
        }

        // Metered plan limit (same metering the web/POS path uses).
        app(UsageService::class)->enforce($tenant, 'max_customers');

        $customer = Customer::create($validated);

        AuditLogger::log('customer.created', $customer, ['name' => $customer->name]);

        return CustomerResource::make($customer)
            ->additional(['message' => 'Customer created.'])
            ->response()
            ->setStatusCode(201);
    }

    /** Update a customer (PUT/PATCH). Tenant-scoped lookup: a foreign id is a 404. */
    public function update(Request $request, int $customer)
    {
        $this->auth->authorize('customers.update');

        $model = Customer::query()->findOrFail($customer);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:65535',
            'credit_limit' => 'nullable|integer|min:0|max:10000000000',
            'is_active' => 'boolean',
        ]);

        $model->update($validated);

        AuditLogger::log('customer.updated', $model, ['name' => $model->name]);

        return CustomerResource::make($model)->additional(['message' => 'Customer updated.']);
    }
}