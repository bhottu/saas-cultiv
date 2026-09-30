<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\PurchaseResource;
use App\Models\Purchase;
use Illuminate\Http\Request;

class PurchaseController extends ApiController
{
    public function index(Request $request)
    {
        $this->auth->authorize('purchases.view');

        $query = Purchase::query()->with([
            'supplier:id,name',
            'warehouse:id,name',
            'createdBy:id,name',
        ])->withCount('items');

        $this->applySearch($query, $request, ['invoice_number']);

        if ($request->filled('status')) {
            $query->whereIn('status', (array) $request->input('status'));
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->integer('supplier_id'));
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->integer('warehouse_id'));
        }

        if ($request->filled('from')) {
            $query->whereDate('ordered_at', '>=', $request->string('from')->toString());
        }

        if ($request->filled('to')) {
            $query->whereDate('ordered_at', '<=', $request->string('to')->toString());
        }

        $purchases = $query->orderByDesc('ordered_at')->paginate($this->perPage($request))->withQueryString();

        return $this->withMeta(PurchaseResource::collection($purchases), $request);
    }

    public function show(Request $request, int $purchase)
    {
        $this->auth->authorize('purchases.view');

        $model = Purchase::query()
            ->with(['supplier:id,name', 'warehouse:id,name', 'createdBy:id,name', 'items'])
            ->findOrFail($purchase);

        return PurchaseResource::make($model)->additional([
            'currency' => 'IDR',
            'money_unit' => 'cents',
        ]);
    }
}