<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\BusinessInvoice;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\BusinessAuthorization;
use App\Services\InventoryService;
use App\Services\DocumentNumberingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchasesController extends Controller
{
    public function __construct(
        private readonly BusinessAuthorization $auth,
        private readonly InventoryService $inventory,
        private readonly DocumentNumberingService $numbering,
    ) {}

    public function index(Request $request)
    {
        $this->auth->authorize('purchases.view');

        $tenant = $request->user()->currentTenant;

        $query = Purchase::with(['supplier', 'createdBy']);

        if ($request->filled('status')) {
            $query->where('status', $request->getString('status'));
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', (int) $request->getString('supplier_id'));
        }

        if ($request->filled('from')) {
            $query->whereDate('ordered_at', '>=', $request->getString('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('ordered_at', '<=', $request->getString('to'));
        }

        $purchases = $query->latest('ordered_at')->paginate(50);

        return view('purchases.index', [
            'purchases' => $purchases,
            'suppliers' => $tenant->suppliers()->active()->orderBy('name')->get(),
            'statuses' => ['draft', 'ordered', 'received', 'cancelled'],
            'filters' => $request->only(['status', 'supplier_id', 'from', 'to']),
        ]);
    }

    public function create()
    {
        $this->auth->authorize('purchases.create');

        $tenant = request()->user()->currentTenant;

        return view('purchases.form', [
            'purchase' => new Purchase(),
            'suppliers' => $tenant->suppliers()->active()->orderBy('name')->get(),
            'products' => Product::where('is_active', true)->orderBy('name')->get(),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(),
            'pageTitle' => 'New Purchase Order',
            'submitUrl' => route('purchases.store'),
        ]);
    }


    public function receive(Purchase $purchase)
    {
        $this->auth->authorize('purchases.receive');
        $this->ensureOwned($purchase);

        if ($purchase->status !== 'ordered') {
            return back()->with('status', ['type' => 'error', 'message' => 'Only ordered purchases can be received.']);
        }

        DB::transaction(function () use ($purchase) {
            $purchase->update(['status' => 'received', 'received_at' => now()]);

            foreach ($purchase->items as $item) {
                $product = Product::withoutGlobalScopes()->findOrFail($item->product_id);

                if ($product->track_inventory) {
                    $this->inventory->fulfill(
                        $purchase->tenant,
                        $product,
                        $purchase->warehouse_id,
                        $item->quantity,
                        auth()->id(),
                        'purchase',
                        $purchase->id
                    );
                }
            }

            $invoice = $purchase->invoice;
            if ($invoice) {
                $invoice->update(['status' => 'partial', 'amount_paid' => 0, 'outstanding' => $purchase->total]);
            }

            AuditLogger::log('purchase.received', $purchase, ['invoice_number' => $purchase->invoice_number]);
        });

        return redirect()->route('purchases.show', $purchase)->with('status', ['type' => 'success', 'message' => 'Purchase received. Stock updated.']);
    }


    public function cancel(Purchase $purchase)
    {
        $this->auth->authorize('purchases.cancel');
        $this->ensureOwned($purchase);

        if (! in_array($purchase->status, ['ordered', 'received'])) {
            return back()->with('status', ['type' => 'error', 'message' => 'Cannot cancel this purchase.']);
        }

        DB::transaction(function () use ($purchase) {
            $purchase->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            if ($purchase->wasChanged('status')) {
                foreach ($purchase->items as $item) {
                    $product = Product::withoutGlobalScopes()->findOrFail($item->product_id);
                    if ($product->track_inventory) {
                        $this->inventory->return(
                            $purchase->tenant,
                            $product,
                            $purchase->warehouse_id,
                            $item->quantity,
                            auth()->id(),
                            'purchase_return',
                            $purchase->id
                        );
                    }
                }
            }

            AuditLogger::log('purchase.cancelled', $purchase, ['invoice_number' => $purchase->invoice_number]);
        });

        return redirect()->route('purchases.show', $purchase)->with('status', ['type' => 'success', 'message' => 'Purchase cancelled.']);
    }

    private function ensureOwned(Purchase $purchase): void
    {
        // Never trust the route id: compare against the *active* tenant context.
        if (! $purchase->tenant_id || $purchase->tenant_id !== app('tenant.context')->tenant()?->id) {
            abort(404);
        }
    }
}
