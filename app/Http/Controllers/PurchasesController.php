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
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->integer('supplier_id'));
        }

        if ($request->filled('from')) {
            $query->whereDate('ordered_at', '>=', $request->string('from')->toString());
        }

        if ($request->filled('to')) {
            $query->whereDate('ordered_at', '<=', $request->string('to')->toString());
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
            // Drives the empty-supplier state: the page may only offer the "Add supplier"
            // call to action when this user would actually be allowed through it.
            'canCreateSupplier' => $this->auth->can('suppliers.create'),
            'products' => Product::where('is_active', true)->orderBy('name')->get(),
            'warehouses' => $tenant->warehouses()->active()->orderBy('name')->get(),
            'pageTitle' => __('New Purchase Order'),
            'submitUrl' => route('purchases.store'),
        ]);
    }


    public function show(Purchase $purchase)
    {
        $this->auth->authorize('purchases.view');
        $this->ensureOwned($purchase);
        return view('purchases.show', [
            'purchase' => $purchase->load(['supplier', 'warehouse', 'createdBy', 'items.product', 'invoice.payments']),
        ]);
    }

    public function store(Request $request)
    {
        $this->auth->authorize('purchases.create');
        $tenant = $request->user()->currentTenant;
        $validated = $request->validate($this->rules($request, $tenant));
        $totals = $this->totals($validated);

        $purchase = DB::transaction(function () use ($tenant, $validated, $totals) {
            $purchase = Purchase::create([
                'tenant_id' => $tenant->id, 'supplier_id' => $validated['supplier_id'],
                'warehouse_id' => $validated['warehouse_id'], 'invoice_number' => $this->numbering->next('purchase', $tenant->id),
                'status' => 'ordered', 'subtotal' => $totals['subtotal'], 'discount' => $totals['discount'],
                'tax' => $totals['tax'], 'shipping' => $totals['shipping'], 'total' => $totals['total'],
                'ordered_at' => now(), 'expected_at' => $validated['expected_at'] ?? null,
                'created_by' => auth()->id(), 'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['items'] as $row) {
                $product = Product::findOrFail((int) $row['product_id']);
                $unitCost = \App\Services\Money::centsFromDisplay($row['unit_cost']);
                $quantity = (int) $row['quantity'];
                $purchase->items()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id,
                    'product_name' => $product->name, 'sku' => $product->sku, 'unit' => $product->unit,
                    'quantity' => $quantity, 'unit_cost' => $unitCost, 'subtotal' => $unitCost * $quantity]);
            }

            BusinessInvoice::create(['tenant_id' => $tenant->id, 'invoice_number' => $purchase->invoice_number,
                'supplier_id' => $purchase->supplier_id, 'purchase_id' => $purchase->id, 'status' => 'open',
                'subtotal' => $purchase->subtotal, 'discount' => $purchase->discount, 'tax' => $purchase->tax,
                'shipping' => $purchase->shipping, 'total' => $purchase->total, 'outstanding' => $purchase->total,
                'issued_at' => now(), 'due_at' => $validated['expected_at'] ?? null,
                'notes' => 'Supplier invoice generated from purchase '.$purchase->invoice_number]);
            return $purchase;
        });

        AuditLogger::log('purchase.created', $purchase, ['invoice_number' => $purchase->invoice_number, 'total' => $purchase->total]);
        return redirect()->route('purchases.show', $purchase)->with('status', ['type' => 'success', 'message' => __('Purchase order created. Receive it to increase stock.')]);
    }

    public function edit(Purchase $purchase)
    {
        $this->auth->authorize('purchases.update');
        $this->ensureOwned($purchase);
        abort_unless(in_array($purchase->status, ['draft', 'ordered'], true), 403, 'Received purchases are immutable.');
        $tenant = request()->user()->currentTenant;

        // A supplier deactivated after the order was placed is still what this purchase
        // belongs to. Without appending it, the dropdown would lose the record's current
        // value and the form would silently submit a different supplier on save.
        $suppliers = $tenant->suppliers()->active()->orderBy('name')->get();

        if ($purchase->supplier && ! $suppliers->contains('id', $purchase->supplier_id)) {
            $suppliers = $suppliers->concat(collect([$purchase->supplier]));
        }

        return view('purchases.form', [
            'purchase' => $purchase->load('items'),
            'suppliers' => $suppliers,
            'canCreateSupplier' => $this->auth->can('suppliers.create'),
            'products' => Product::where('is_active', true)->orderBy('name')->get(),
            'warehouses' => $tenant->warehouses()->active()->orderBy('name')->get(),
            'pageTitle' => __('Edit Purchase Order'),
            'submitUrl' => route('purchases.update', $purchase),
        ]);
    }

    public function update(Request $request, Purchase $purchase)
    {
        $this->auth->authorize('purchases.update');
        $this->ensureOwned($purchase);
        abort_unless(in_array($purchase->status, ['draft', 'ordered'], true), 403, 'Received purchases are immutable.');
        $validated = $request->validate($this->rules($request, $request->user()->currentTenant));
        $totals = $this->totals($validated);
        DB::transaction(function () use ($purchase, $validated, $totals) {
            $purchase->update(array_merge($validated, $totals));
            $purchase->items()->delete();
            foreach ($validated['items'] as $row) {
                $product = Product::findOrFail((int) $row['product_id']);
                $unitCost = \App\Services\Money::centsFromDisplay($row['unit_cost']);
                $quantity = (int) $row['quantity'];
                $purchase->items()->create(['tenant_id' => $purchase->tenant_id, 'product_id' => $product->id,
                    'product_name' => $product->name, 'sku' => $product->sku, 'unit' => $product->unit,
                    'quantity' => $quantity, 'unit_cost' => $unitCost, 'subtotal' => $unitCost * $quantity]);
            }
            $purchase->invoice?->update(['supplier_id' => $purchase->supplier_id, 'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'], 'tax' => $totals['tax'], 'shipping' => $totals['shipping'],
                'total' => $totals['total'], 'outstanding' => max(0, $totals['total'] - (int) ($purchase->invoice?->amount_paid ?? 0))]);
        });
        AuditLogger::log('purchase.updated', $purchase, ['invoice_number' => $purchase->invoice_number]);
        return redirect()->route('purchases.show', $purchase)->with('status', ['type' => 'success', 'message' => __('Purchase updated.')]);
    }

    public function destroy(Purchase $purchase)
    {
        $this->auth->authorize('purchases.delete');
        $this->ensureOwned($purchase);
        abort_unless($purchase->status === 'draft', 403, 'Only draft purchases can be deleted.');
        $purchase->invoice()?->delete();
        $purchase->delete();
        AuditLogger::log('purchase.deleted', $purchase, ['invoice_number' => $purchase->invoice_number]);
        return redirect()->route('purchases.index')->with('status', ['type' => 'success', 'message' => __('Draft purchase deleted.')]);
    }

    public function receive(Purchase $purchase)
    {
        $this->auth->authorize('purchases.receive');
        $this->ensureOwned($purchase);

        if ($purchase->status !== 'ordered') {
            return back()->with('status', ['type' => 'error', 'message' => __('Only ordered purchases can be received.')]);
        }

        DB::transaction(function () use ($purchase) {
            $purchase->update(['status' => 'received', 'received_at' => now()]);

            foreach ($purchase->items as $item) {
                $product = Product::withoutGlobalScopes()->findOrFail($item->product_id);

                if ($product->track_inventory) {
                    // A purchase RECEIPT adds stock. fulfill() is the outgoing (sale) path and
                    // would deduct inventory — receiving must use the generic movement writer.
                    $this->inventory->apply(
                        $purchase->tenant,
                        $product,
                        $purchase->warehouse_id,
                        'purchase',
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

        return redirect()->route('purchases.show', $purchase)->with('status', ['type' => 'success', 'message' => __('Purchase received. Stock updated.')]);
    }


    public function cancel(Purchase $purchase)
    {
        $this->auth->authorize('purchases.cancel');
        $this->ensureOwned($purchase);

        if (! in_array($purchase->status, ['ordered', 'received'])) {
            return back()->with('status', ['type' => 'error', 'message' => __('Cannot cancel this purchase.')]);
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

        return redirect()->route('purchases.show', $purchase)->with('status', ['type' => 'success', 'message' => __('Purchase cancelled.')]);
    }

    private function rules(Request $request, \App\Models\Tenant $tenant): array
    {
        $owned = fn (string $table) => \Illuminate\Validation\Rule::exists($table, 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id));
        return [
                'supplier_id' => ['required', 'integer', $owned('suppliers')],
                'warehouse_id' => ['required', 'integer', $owned('warehouses')],
            'expected_at' => ['nullable', 'date'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'shipping' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:65535'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', $owned('products'), 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ];
    }

    private function totals(array $data): array
    {
        $subtotal = 0;
        foreach ($data['items'] as $item) {
            $subtotal += (int) $item['quantity'] * \App\Services\Money::centsFromDisplay($item['unit_cost']);
        }
        $discount = min($subtotal, \App\Services\Money::centsFromDisplay($data['discount'] ?? 0));
        $tax = \App\Services\Money::centsFromDisplay($data['tax'] ?? 0);
        $shipping = \App\Services\Money::centsFromDisplay($data['shipping'] ?? 0);
        return compact('subtotal', 'discount', 'tax', 'shipping') + ['total' => max(0, $subtotal - $discount) + $tax + $shipping];
    }

    private function ensureOwned(Purchase $purchase): void
    {
        // Never trust the route id: compare against the *active* tenant context.
        if (! $purchase->tenant_id || $purchase->tenant_id !== app('tenant.context')->tenant()?->id) {
            abort(404);
        }
    }
}
