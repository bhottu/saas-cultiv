<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\SaleResource;
use App\Models\Sale;
use Illuminate\Http\Request;

class SaleController extends ApiController
{
    public function index(Request $request)
    {
        $this->auth->authorize('sales.view');

        $query = Sale::query()->with([
            'customer:id,name,phone',
            'warehouse:id,name',
            'createdBy:id,name',
        ])->withCount('items');

        $this->applySearch($query, $request, ['invoice_number']);

        if ($request->filled('status')) {
            $query->whereIn('status', (array) $request->input('status'));
        }

        if ($request->filled('payment_status')) {
            $query->whereIn('payment_status', (array) $request->input('payment_status'));
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->integer('customer_id'));
        }

        if ($request->filled('sales_channel')) {
            $query->where('sales_channel', $request->string('sales_channel')->toString());
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->string('payment_method')->toString());
        }

        $this->applyDateRange($query, $request);

        $sales = $query->latest('sold_at')->paginate($this->perPage($request))->withQueryString();

        return $this->withMeta(SaleResource::collection($sales), $request);
    }

    public function show(Request $request, int $sale)
    {
        $this->auth->authorize('sales.view');

        $model = Sale::query()
            ->with(['customer:id,name,phone,email', 'warehouse:id,name', 'createdBy:id,name', 'items', 'payments'])
            ->findOrFail($sale);

        return SaleResource::make($model)->additional([
            'currency' => 'IDR',
            'money_unit' => 'cents',
            'payments' => $model->payments->map(fn ($p) => [
                'id' => $p->id,
                'method' => $p->method,
                'amount' => (int) $p->amount,
                'reference' => $p->reference,
                'paid_at' => $p->paid_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    private function applyDateRange($query, Request $request): void
    {
        if ($request->filled('from')) {
            $query->whereDate('sold_at', '>=', $request->string('from')->toString());
        }

        if ($request->filled('to')) {
            $query->whereDate('sold_at', '<=', $request->string('to')->toString());
        }
    }
}