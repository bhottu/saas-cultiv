<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\Product;
use App\Services\BusinessAuthorization;
use App\Services\InventoryService;
use App\Services\DocumentNumberingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockMovementController extends Controller
{
    public function __construct(
        private readonly BusinessAuthorization $auth,
        private readonly InventoryService $inventory,
        private readonly DocumentNumberingService $numbering,
    ) {}

    public function index(Request $request)
    {
        $this->auth->authorize('inventory.view');

        $tenant = $request->user()->currentTenant;

        $query = StockMovement::with(['product', 'warehouse', 'createdBy'])
            ->where('tenant_id', $tenant->id);

        if ($request->filled('product_id')) {
            $query->where('product_id', (int) $request->getString('product_id'));
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', (int) $request->getString('warehouse_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->getString('type'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->getString('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->getString('to'));
        }

        $movements = $query->latest('created_at')->paginate(50);

        return view('stock-movements.index', [
            'movements' => $movements,
            'products' => Product::where('track_inventory', true)->orderBy('name')->get(),
            'warehouses' => $tenant->warehouses()->orderBy('name')->get(),
            'types' => [
                'purchase' => 'Purchase',
                'sale' => 'Sale',
                'sale_return' => 'Sale Return',
                'purchase_return' => 'Purchase Return',
                'adjustment_in' => 'Adjustment In',
                'adjustment_out' => 'Adjustment Out',
                'damage' => 'Damage',
                'loss' => 'Loss',
                'transfer_in' => 'Transfer In',
                'transfer_out' => 'Transfer Out',
            ],
            'filters' => $request->only(['product_id', 'warehouse_id', 'type', 'from', 'to']),
        ]);
    }

    public function create()
    {
        $this->auth->authorize('inventory.adjust');

        $tenant = request()->user()->currentTenant;

        return view('stock-movements.form', [
            'movement' => new StockMovement(),
            'products' => Product::where('track_inventory', true)->orderBy('name')->get(),
            'warehouses' => $tenant->warehouses()->active()->orderBy('name')->get(),
            'types' => [
                'adjustment_in' => 'Adjustment In (Add stock)',
                'adjustment_out' => 'Adjustment Out (Reduce stock)',
                'damage' => 'Damage/Loss',
                'loss' => 'Loss',
            ],
            'pageTitle' => 'Stock Adjustment',
            'submitUrl' => route('stock-movements.store'),
        ]);
    }

    public function store(Request $request)
    {
        $this->auth->authorize('inventory.adjust');

        $validated = $request->validate([
            'type' => 'required|in:adjustment_in,adjustment_out,damage,loss',
            'product_id' => 'required|exists:products,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:65535',
        ]);

        $tenant = $request->user()->currentTenant;
        $product = Product::withoutGlobalScopes()->findOrFail($validated['product_id']);

        // Product must belong to this tenant.
        if ($product->tenant_id !== $tenant->id) {
            abort(404);
        }

        if (! $product->track_inventory) {
            return back()->with('status', ['type' => 'error', 'message' => 'This product does not track inventory.']);
        }

        $signed = $validated['type'] === 'adjustment_in' ? $validated['quantity'] : -1 * $validated['quantity'];

        $movement = DB::transaction(function () use ($tenant, $product, $validated, $signed) {
            $movement = StockMovement::create([
                'tenant_id' => $tenant->id,
                'product_id' => $product->id,
                'warehouse_id' => $validated['warehouse_id'],
                'type' => $validated['type'],
                'quantity' => $signed,
                'reference_type' => null,
                'reference_id' => null,
                'notes' => $validated['notes'],
                'created_by' => auth()->id(),
            ]);

            $this->inventory->applyMovement($movement, auth()->id());

            return $movement;
        });

        AuditLogger::log('stock_adjusted', $movement, [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'type' => $validated['type'],
            'quantity' => $signed,
            'new_balance' => $product->min_stock,
        ]);

        return redirect()->route('stock-movements.index')->with('status', ['type' => 'success', 'message' => 'Stock adjusted.']);
    }

    public function export(Request $request)
    {
        $this->auth->authorize('inventory.export');

        $tenant = $request->user()->currentTenant;

        $movements = StockMovement::with(['product', 'warehouse', 'createdBy'])
            ->where('tenant_id', $tenant->id)
            ->get();

        return response()->streamDownload(
            function () use ($movements) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['Date', 'Reference', 'Product', 'Warehouse', 'Type', 'Quantity', 'Notes', 'By']);
                foreach ($movements as $m) {
                    fputcsv($handle, [
                        $m->created_at?->format('Y-m-d H:i:s'),
                        $m->reference_type.'-'.$m->reference_id,
                        $m->product?->name,
                        $m->warehouse?->name,
                        $m->type,
                        $m->quantity,
                        $m->notes,
                        $m->createdBy?->name,
                    ]);
                }
                fclose($handle);
            },
            'stock-movements-export.csv',
            ['Content-Type' => 'text/csv']
        );
    }
}
