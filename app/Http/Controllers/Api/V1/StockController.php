<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\StockBalanceResource;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Services\AuditLogger;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

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

    /**
     * Record a stock adjustment — the ONLY way stock changes through the API.
     *
     * Stock is never an overwriteable number: this delegates to InventoryService::adjust,
     * the same pipeline the web "Adjust stock" form uses, so a StockMovement row is always
     * written and the balance can never drift from its history. There is deliberately no
     * DELETE here — movements are the audit trail of the quantity.
     */
    public function adjust(Request $request, InventoryService $inventory)
    {
        $this->auth->authorize('stock.adjust');

        $tenant = $this->tenant($request);

        $validated = $request->validate([
            'product_id' => [
                'required',
                Rule::exists('products', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'warehouse_id' => [
                'required',
                Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'direction' => ['required', 'in:in,out'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000000'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:65535'],
        ]);

        // Resolved without global scopes then re-checked against the token's workspace:
        // an id belonging to another tenant must 404, never read or write.
        $product = Product::withoutGlobalScopes()->findOrFail($validated['product_id']);
        $warehouse = Warehouse::withoutGlobalScopes()->findOrFail($validated['warehouse_id']);

        abort_unless(
            $product->tenant_id === $tenant->id && $warehouse->tenant_id === $tenant->id,
            404
        );

        if (! $product->track_inventory) {
            return response()->json([
                'message' => 'This product does not track inventory.',
                'code' => 'inventory_not_tracked',
            ], 422);
        }

        $reducing = $validated['direction'] === 'out';
        $signedQuantity = $reducing
            ? -1 * (int) $validated['quantity']
            : (int) $validated['quantity'];

        try {
            $movement = $inventory->adjust(
                $tenant,
                $product,
                $warehouse->id,
                $signedQuantity,
                $validated['reason'],
                $request->user()?->id,
                $validated['notes'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            // Raised BEFORE writing anything, so no movement and no partial balance.
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'stock_adjustment_rejected',
            ], 422);
        }

        $balance = (int) StockBalance::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('quantity');

        AuditLogger::log('stock.adjusted', $movement, [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $signedQuantity,
            'balance' => $balance,
            'reason' => $validated['reason'],
        ]);

        return response()->json([
            'message' => ($reducing ? 'Stock reduced successfully.' : 'Stock added successfully.')
                .' New balance: '.$balance.' '.$product->unit.'.',
            'movement_id' => $movement->id,
            'balance' => $balance,
        ]);
    }
}