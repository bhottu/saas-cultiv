<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\PurchaseResource;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\BusinessInvoice;
use App\Services\AuditLogger;
use App\Services\BusinessAuthorization;
use App\Services\Money;
use App\Services\PurchaseOrderService;
use App\Services\DocumentNumberingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseController extends ApiController
{
    public function __construct(
        BusinessAuthorization $auth,
        private readonly PurchaseOrderService $orders,
        private readonly DocumentNumberingService $numbering,
    ) {
        parent::__construct($auth);
    }

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

    /**
     * Create a purchase order.
     *
     * Mirrors PurchasesController::store exactly — same shared rules and the same
     * server-side totals — with one property worth calling out: NO STOCK IS ADDED HERE.
     * An order is a promise; inventory only grows when the order is received, so a
     * client cannot inflate stock by creating orders.
     */
    public function store(Request $request)
    {
        $this->auth->authorize('purchases.create');

        $tenant = $this->tenant($request);
        $validated = $request->validate($this->orders->rules($tenant));
        $totals = $this->orders->totals($validated);

        $purchase = DB::transaction(function () use ($tenant, $request, $validated, $totals) {
            $purchase = Purchase::create([
                'tenant_id' => $tenant->id,
                'supplier_id' => $validated['supplier_id'],
                'warehouse_id' => $validated['warehouse_id'],
                'invoice_number' => $this->numbering->next('purchase', $tenant->id),
                'status' => 'ordered',
                'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'],
                'tax' => $totals['tax'],
                'shipping' => $totals['shipping'],
                'total' => $totals['total'],
                'ordered_at' => now(),
                'expected_at' => $validated['expected_at'] ?? null,
                'created_by' => $request->user()?->id,
                'notes' => $validated['notes'] ?? null,
            ]);

            $this->syncItems($purchase, $validated);

            BusinessInvoice::create([
                'tenant_id' => $tenant->id,
                'invoice_number' => $purchase->invoice_number,
                'supplier_id' => $purchase->supplier_id,
                'purchase_id' => $purchase->id,
                'status' => 'open',
                'subtotal' => $purchase->subtotal,
                'discount' => $purchase->discount,
                'tax' => $purchase->tax,
                'shipping' => $purchase->shipping,
                'total' => $purchase->total,
                'outstanding' => $purchase->total,
                'issued_at' => now(),
                'due_at' => $validated['expected_at'] ?? null,
                'notes' => 'Supplier invoice generated from purchase '.$purchase->invoice_number,
            ]);

            return $purchase;
        });

        AuditLogger::log('purchase.created', $purchase, [
            'invoice_number' => $purchase->invoice_number,
            'total' => $purchase->total,
        ]);

        return PurchaseResource::make($purchase)->additional([
            'message' => 'Purchase order created. Receive it to increase stock.',
            'currency' => 'IDR',
            'money_unit' => 'cents',
        ])->response()->setStatusCode(201);
    }

    /**
     * Edit a purchase that has not been received yet.
     *
     * Same guard as the web UI: received purchases are immutable because their stock
     * effect already happened and rewriting them would desynchronise inventory.
     */
    public function update(Request $request, int $purchase)
    {
        $this->auth->authorize('purchases.update');

        $tenant = $this->tenant($request);
        $model = Purchase::query()->findOrFail($purchase);

        abort_unless(in_array($model->status, ['draft', 'ordered'], true), 403, 'Received purchases are immutable.');

        $validated = $request->validate($this->orders->rules($tenant));
        $totals = $this->orders->totals($validated);

        DB::transaction(function () use ($model, $validated, $totals) {
            $model->update(array_merge($validated, $totals));

            $model->items()->delete();
            $this->syncItems($model, $validated);

            $invoice = $model->invoice;
            $invoice?->update([
                'supplier_id' => $model->supplier_id,
                'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'],
                'tax' => $totals['tax'],
                'shipping' => $totals['shipping'],
                'total' => $totals['total'],
                'outstanding' => max(0, $totals['total'] - (int) ($invoice?->amount_paid ?? 0)),
            ]);
        });

        AuditLogger::log('purchase.updated', $model, ['invoice_number' => $model->invoice_number]);

        return PurchaseResource::make($model->fresh())->additional([
            'message' => 'Purchase updated.',
            'currency' => 'IDR',
            'money_unit' => 'cents',
        ]);
    }

    /**
     * Delete a purchase — only while it is still a draft.
     *
     * Mirrors PurchasesController::destroy. Once received, the stock it brought in is
     * real, so the order becomes a historical record rather than something deletable.
     * The supplier invoice is removed alongside it, as in the web flow.
     */
    public function destroy(Request $request, int $purchase)
    {
        $this->auth->authorize('purchases.delete');

        $model = Purchase::query()->findOrFail($purchase);

        if ($model->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft purchases can be deleted.',
                'code' => 'purchase_not_draft',
            ], 403);
        }

        $model->invoice()?->delete();
        $model->delete();

        AuditLogger::log('purchase.deleted', $model, ['invoice_number' => $model->invoice_number]);

        return response()->json(['message' => 'Draft purchase deleted.']);
    }

    /** Rewrite the purchase's lines from validated input (snapshotted money per line). */
    private function syncItems(Purchase $purchase, array $validated): void
    {
        foreach ($validated['items'] as $row) {
            $product = Product::query()->findOrFail((int) $row['product_id']);
            $unitCost = Money::centsFromDisplay($row['unit_cost']);
            $quantity = (int) $row['quantity'];

            $purchase->items()->create([
                'tenant_id' => $purchase->tenant_id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'unit' => $product->unit,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'subtotal' => $unitCost * $quantity,
            ]);
        }
    }
}
