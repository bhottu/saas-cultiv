<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\Request;

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
}