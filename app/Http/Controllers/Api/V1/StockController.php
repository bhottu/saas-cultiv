<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\StockBalanceResource;
use App\Models\Product;
use App\Models\StockBalance;
use Illuminate\Http\Request;

class StockController extends ApiController
{
    /** Current on-hand balance, one row per product+warehouse. */
    public function index(Request $request)
    {
        $this->auth->authorize('stock.view');

        $query = StockBalance::query()->with(['product:id,name,sku,unit,minimum_stock', 'warehouse:id,name,code']);

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->integer('warehouse_id'));
        }

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->integer('product_id'));
        }

        if ($request->boolean('in_stock')) {
            $query->where('quantity', '>', 0);
        }

        // Low stock compares the balance against the product's own threshold, not
        // against a hard-coded number.
        if ($request->boolean('low_stock')) {
            $query->whereHas('product', fn ($p) => $p->whereColumn(
                'stock_balances.quantity', '<=', 'products.minimum_stock'
            ));
        }

        $this->applySearch($query, $request, ['products.name', 'products.sku', 'products.barcode']);

        $balances = $query->orderBy('product_id')->paginate($this->perPage($request))->withQueryString();

        return $this->withMeta(StockBalanceResource::collection($balances), $request);
    }

    /** On-hand per product, aggregated across every warehouse. */
    public function summary(Request $request)
    {
        $this->auth->authorize('stock.view');

        $rows = StockBalance::query()
            ->join('products', 'products.id', '=', 'stock_balances.product_id')
            ->selectRaw('stock_balances.product_id, products.name, products.unit, products.minimum_stock, SUM(stock_balances.quantity) AS quantity')
            ->where('stock_balances.tenant_id', app('tenant.context')->tenant()->id)
            ->where('products.tenant_id', app('tenant.context')->tenant()->id)
            ->groupBy('stock_balances.product_id', 'products.name', 'products.unit', 'products.minimum_stock')
            ->orderBy('quantity', 'desc')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return response()->json([
            'data' => collect($rows->items())->map(fn ($row) => [
                'product_id' => (int) $row->product_id,
                'name' => $row->name,
                'unit' => $row->unit,
                'minimum_stock' => (int) $row->minimum_stock,
                'quantity_on_hand' => (int) $row->quantity,
                'is_low_stock' => (int) $row->quantity <= (int) $row->minimum_stock,
            ])->values(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function show(Request $request, int $product)
    {
        $this->auth->authorize('stock.view');

        $model = Product::query()->findOrFail($product);

        $balances = $model->stockBalances()
            ->with('warehouse:id,name,code')
            ->orderBy('warehouse_id')
            ->get();

        return response()->json([
            'data' => [
                'product' => ['id' => $model->id, 'name' => $model->name, 'sku' => $model->sku, 'unit' => $model->unit],
                'minimum_stock' => (int) $model->minimum_stock,
                'maximum_stock' => $model->max_stock,
                'on_hand' => (int) $balances->sum('quantity'),
                'by_warehouse' => StockBalanceResource::collection($balances),
            ],
        ]);
    }
}