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

        // Optional pre-selection, so a user arriving here from a product page does not
        // have to hunt the item out of a list that may not even contain it on page one.
        // The id is never trusted on its own: it is resolved through the tenant-scoped
        // Product query and under the SAME conditions the list itself applies, so the
        // parameter cannot be used to reach a product from another workspace, or one
        // that is inactive or not inventory-tracked. Anything else simply falls through
        // as "no pre-selection" rather than erroring on an ordinary link.
        $selectedProduct = null;

        if ($request->filled('product_id')) {
            $selectedProduct = Product::query()
                ->where('tenant_id', $tenant->id)
                ->where('track_inventory', true)
                ->where('is_active', true)
                ->find($request->integer('product_id'));
        }

        // The options offered by the adjustment form, with the chosen product guaranteed
        // to be among them. The list is paginated and sorted by name, so a product that
        // was created a moment ago may not appear on page one; without appending it,
        // `selected` would name an option that does not exist and the preselection
        // would fail silently for exactly the newest products.
        $selectOptions = $products->contains('id', $selectedProduct?->id)
            ? $products
            : $products->concat($selectedProduct ? collect([$selectedProduct]) : collect());

        return view('stock.index', [
            'products' => $products,
            'warehouses' => $warehouses,
            'warehouse' => $warehouse,
            'balances' => $balances,
            'lowStockCount' => $lowStockCount,
            'selectedProduct' => $selectedProduct,
            'selectOptions' => $selectOptions,
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

        // "in" adds stock, "out" reduces it. The outcome is announced differently in each
        // direction, so the wording is chosen here once and used by every branch below —
        // the HTML redirect and the JSON response cannot drift apart.
        $reducing = $validated['direction'] === 'out';

        $successMessage = $reducing
            ? __('Stock reduced successfully.')
            : __('Stock added successfully.');

        $failureMessage = $reducing
            ? __('Failed to reduce stock.')
            : __('Failed to add stock.');

        // Returned for the HTML flow; JSON callers get the same outcome as a status code.
        $fail = fn (string $reason) => $request->expectsJson()
            ? response()->json(['message' => $failureMessage.' '.$reason], 422)
            : back()->withInput()->with('status', ['type' => 'error', 'message' => $failureMessage.' '.$reason]);

        if (! $product->track_inventory) {
            return $fail(__('This product does not track inventory.'));
        }

        $signedQuantity = $reducing
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
            // InventoryService raised BEFORE writing anything, so no movement exists and
            // no success message can be shown. The reason is surfaced rather than swallowed.
            return $fail($exception->getMessage());
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

        $message = $successMessage.' '.__('New balance: :balance.', [
            'balance' => $balance.' '.$product->unit,
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'movement_id' => $movement->id,
                'balance' => $balance,
            ]);
        }

        return back()->with('status', ['type' => 'success', 'message' => $message]);
    }
}