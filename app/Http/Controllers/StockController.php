<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Services\AuditLogger;
use App\Services\BusinessAuthorization;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class StockController extends Controller
{
    public function __construct(
        private readonly BusinessAuthorization $auth,
        private readonly InventoryService $inventory,
    ) {}

    public function index(Request $request)
    {
        $this->auth->authorize('inventory.view');

        $tenant = $request->user()->currentTenant;
        $products = Product::with(['category', 'brand'])
            ->where('track_inventory', true)
            ->where('is_active', true)
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        $warehouses = $tenant->warehouses()->active()->orderBy('name')->get();
        $warehouse = $warehouses->first();
        $productIds = $products->pluck('id');

        $balances = $warehouse && $productIds->isNotEmpty()
            ? StockBalance::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('warehouse_id', $warehouse->id)
                ->whereIn('product_id', $productIds)
                ->pluck('quantity', 'product_id')
            : collect();

        $lowStockCount = StockBalance::withoutGlobalScopes()
            ->where('stock_balances.tenant_id', $tenant->id)
            ->join('products', 'products.id', '=', 'stock_balances.product_id')
            ->where('products.track_inventory', true)
            ->where('products.is_active', true)
            ->whereColumn('stock_balances.quantity', '<=', 'products.minimum_stock')
            ->count();

        return view('stock.index', [
            'products' => $products,
            'warehouses' => $warehouses,
            'warehouse' => $warehouse,
            'balances' => $balances,
            'lowStockCount' => $lowStockCount,
        ]);
    }

    public function adjust(Request $request)
    {
        $this->auth->authorize('stock.adjust');

        $tenant = $request->user()->currentTenant;
        $validated = $request->validate([
            'product_id' => [
                'required',
                Rule::exists('products', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'warehouse_id' => [
                'required',
                Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'direction' => 'required|in:in,out',
            'quantity' => 'required|integer|min:1|max:100000000',
            'reason' => 'required|string|max:255',
            'notes' => 'nullable|string|max:65535',
        ]);

        $product = Product::withoutGlobalScopes()->findOrFail($validated['product_id']);
        $warehouse = Warehouse::withoutGlobalScopes()->findOrFail($validated['warehouse_id']);

        abort_unless($product->tenant_id === $tenant->id && $warehouse->tenant_id === $tenant->id, 404);

        if (! $product->track_inventory) {
            return back()->with('status', ['type' => 'error', 'message' => 'This product does not track inventory.']);
        }

        $signedQuantity = $validated['direction'] === 'out'
            ? -1 * (int) $validated['quantity']
            : (int) $validated['quantity'];

        try {
            $movement = $this->inventory->adjust(
                $tenant,
                $product,
                $warehouse->id,
                $signedQuantity,
                $validated['reason'],
                $request->user()->id,
                $validated['notes'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            return back()->withInput()->with('status', ['type' => 'error', 'message' => $exception->getMessage()]);
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

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Stock adjusted.',
                'movement_id' => $movement->id,
                'balance' => $balance,
            ]);
        }

        return back()->with('status', [
            'type' => 'success',
            'message' => "Stock adjusted. New balance: {$balance}.",
        ]);
    }
}