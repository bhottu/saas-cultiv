<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Services\BusinessAuthorization;
use App\Services\CalculateSaleTotals;
use App\Services\Money;
use App\Services\RecordSaleService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * POS terminal: the first runtime module (module key "pos").
 *
 * This controller is MODULE-SCOPED but not MODULE-LOGIC. It owns the cashier screen
 * and its JSON endpoints, while every money/stock/payment decision is delegated to
 * RecordSaleService -- the same service SalesController::store() uses. Nothing here
 * re-implements sale semantics, so a POS receipt and a back-office sale are by
 * construction the same document.
 *
 * Gating is two-tier and decoupled (see ModuleManager):
 *  - AVAILABILITY: the `module:pos` middleware already required an ACTIVE install for
 *    the active workspace before this controller ran.
 *  - ACCESS: every action calls authorize('sales.create'), the existing business
 *    permission. Activating the module never grants a user the right to sell.
 *
 * Money: the browser previews totals via pos.calculate() for usability, but the
 * authoritative numbers are always recomputed server-side by RecordSaleService. The
 * cart is a user interface, never a source of truth.
 */
class PosController extends Controller
{
    public function __construct(
        private readonly TenantContext $ctx,
        private readonly BusinessAuthorization $auth,
        private readonly CalculateSaleTotals $calculator,
        private readonly RecordSaleService $sales,
    ) {}

    /** The cashier screen. */
    public function index(Request $request): View
    {
        $this->auth->authorize('sales.create');

        $warehouses = Warehouse::query()->active()->orderBy('name')->get();

        return view('pos.index', [
            'tenant'           => $this->ctx->tenant(),
            'warehouses'       => $warehouses,
            'defaultWarehouse' => $warehouses->first(),
            'customers'        => Customer::query()->active()->orderBy('name')->get(),
            'paymentMethods'   => config('business.sales.payment_methods', ['cash' => 'Cash']),
            // Same permission /sales/create uses for its "+ Add Customer" button, so the
            // cashier screen offers exactly what the back-office sale screen offers.
            'canCreateCustomer' => $this->auth->can('customers.create'),
            // The customer modal is the shared partial and posts to the same endpoint.
            'customerStoreUrl' => route('customers.store'),
        ]);
    }

    /**
     * Product grid for the selected warehouse.
     *
     * Stock is read from StockBalance for that warehouse only, so a cashier switching
     * location immediately sees location-correct availability. A product with no
     * balance row for the warehouse reports 0 rather than disappearing from the grid.
     */
    public function products(Request $request): JsonResponse
    {
        $this->auth->authorize('sales.create');

        $warehouseId = (int) $request->integer('warehouse_id');
        $search = trim($request->string('q')->toString());

        $query = Product::query()->where('is_active', true);

        if ($search !== '') {
            // Case-insensitive via the shared Product::scopeSearch: PostgreSQL's LIKE
            // is case-sensitive, so "Kopi" must not be the only spelling that matches.
            $query->search($search);
        }

        $products = $query->orderBy('name')->limit(30)->get();

        $balances = $products->isEmpty() || $warehouseId === 0
            ? collect()
            : StockBalance::query()
                ->where('warehouse_id', $warehouseId)
                ->whereIn('product_id', $products->modelKeys())
                ->pluck('quantity', 'product_id');

        return response()->json([
            'products' => $products->map(fn (Product $product) => [
                'id'              => $product->id,
                'name'            => $product->name,
                'sku'             => $product->sku,
                'barcode'         => $product->barcode,
                'unit'            => $product->unit ?? 'pcs',
                // selling_price is stored in CENTS. Both representations are sent: `price`
                // in cents for exact arithmetic, `price_formatted` in whole rupiah for the
                // shelf label. Checkout re-prices from the database regardless.
                'price'           => (int) $product->selling_price,
                'price_formatted' => Money::formatRupiah($product->selling_price / 100),
                'stock'           => (int) ($balances[$product->id] ?? 0),
            ])->values(),
        ]);
    }


    /**
     * Live cart total for display.
     *
     * Pure preview: nothing is persisted and nothing is trusted. It speaks the SAME
     * units as checkout() (whole rupiah) so the terminal has exactly one money
     * convention to deal with, and it reuses CalculateSaleTotals so the figure the
     * cashier watches comes from the math that will be persisted. Checkout recomputes
     * everything from scratch regardless of what this returned.
     */
    public function calculate(Request $request): JsonResponse
    {
        $this->auth->authorize('sales.create');

        $validated = $request->validate([
            'discount_type'  => ['required', Rule::in(CalculateSaleTotals::DISCOUNT_TYPES)],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'tax_percent'    => ['nullable', 'integer', 'min:0', 'max:100'],
            'items'          => ['required', 'array', 'min:1'],
            'items.*.unit_price'     => ['required', 'numeric', 'min:0'],
            'items.*.quantity'       => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.discount_type'  => ['nullable', Rule::in(CalculateSaleTotals::DISCOUNT_TYPES)],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Same display -> cents conversion RecordSaleService::record() performs, so a
        // preview can never disagree with what actually gets written.
        $lines = array_map(fn (array $item) => [
            'quantity'       => (int) $item['quantity'],
            'unit_price'     => Money::centsFromDisplay($item['unit_price']),
            'discount_type'  => $this->calculator->normalizeType($item['discount_type'] ?? CalculateSaleTotals::TYPE_FIXED),
            'discount_value' => $this->discountInCents(
                $item['discount_type'] ?? CalculateSaleTotals::TYPE_FIXED,
                $item['discount_value'] ?? 0
            ),
        ], $validated['items']);

        $totals = $this->calculator->calculate($lines, [
            'discount_type'  => $this->calculator->normalizeType($validated['discount_type']),
            'discount_value' => $this->discountInCents($validated['discount_type'], $validated['discount_value'] ?? 0),
            'tax_percent'    => (int) ($validated['tax_percent'] ?? 0),
            'shipping'       => 0,
        ]);

        return response()->json([
            'totals' => [
                'subtotal'     => $totals['subtotal'],
                'discount'     => $totals['discount'],
                'tax'          => $totals['tax'],
                'total'        => $totals['total'],
                // Formatted strings are rendered in whole rupiah: the browser shows what
                // the customer is charged, not minor units.
                'subtotal_fmt' => Money::formatRupiah($totals['subtotal'] / 100),
                'discount_fmt' => Money::formatRupiah($totals['discount'] / 100),
                'tax_fmt'      => Money::formatRupiah($totals['tax'] / 100),
                'total_fmt'    => Money::formatRupiah($totals['total'] / 100),
            ],
        ]);
    }

    /**
     * Fixed discounts arrive in rupiah and are stored in cents; percentages stay whole
     * percent. Mirrors RecordSaleService so a preview matches the persisted sale.
     */
    private function discountInCents(string $type, int|float|string $value): int
    {
        $type = $this->calculator->normalizeType($type);

        return $type === CalculateSaleTotals::TYPE_PERCENT
            ? max(0, min(100, (int) round((float) $value)))
            : Money::centsFromDisplay($value);
    }


    /**
     * Commit the cart as a sale.
     *
     * Records through RecordSaleService, so the POS writes the exact same document the
     * back-office form does: server-side totals, stock deduction inside the transaction,
     * payment + change, business invoice, usage metric and audit entry.
     *
     * Idempotency: the client sends a client_reference per checkout attempt. A repeated
     * request (double tap, retry after a dropped connection) resolves to the sale that
     * already exists instead of selling and deducting stock a second time.
     */
    public function checkout(Request $request): JsonResponse
    {
        $this->auth->authorize('sales.create');

        $tenant = $this->ctx->tenant();
        $tenantId = $tenant->id;

        $reference = (string) Str::uuid();

        $validated = $request->validate([
            'warehouse_id'      => ['required', 'integer', $this->owned('warehouses', $tenantId)],
            'customer_id'       => ['nullable', 'integer', $this->owned('customers', $tenantId)],
            'discount_type'     => ['required', Rule::in(CalculateSaleTotals::DISCOUNT_TYPES)],
            'discount_value'    => ['nullable', 'numeric', 'min:0'],
            'tax_percent'       => ['nullable', 'integer', 'min:0', 'max:100'],
            'payment_method'    => ['required', Rule::in(array_keys(config('business.sales.payment_methods')))],
            // Named payment_amount (not paid_amount) to match the sales.store contract that
            // RecordSaleService consumes, so both surfaces post an identical payload.
            'payment_amount'    => ['required', 'numeric', 'min:0'],
            'notes'             => ['nullable', 'string', 'max:1000'],
            'client_reference'  => ['nullable', 'string', 'max:64'],
            'items'             => ['required', 'array', 'min:1'],
            'items.*.product_id'     => ['required', 'integer', $this->owned('products', $tenantId)],
            'items.*.quantity'       => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_price'     => ['required', 'numeric', 'min:0'],
            'items.*.discount_type'  => ['nullable', Rule::in(CalculateSaleTotals::DISCOUNT_TYPES)],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
        ]);

        // A POS sale is always COMPLETED and always carries a channel, so it flows
        // through the same reporting, returns and invoice screens as a manual sale.
        $validated['sales_channel'] = 'pos';
        $validated['status'] = Sale::STATUS_COMPLETED;
        $validated['shipping'] = 0;
        $validated['client_reference'] = $validated['client_reference'] ?: $reference;

        if ($existing = $this->sales->findByClientReference($validated['client_reference'])) {
            return $this->saleResponse($existing, replayed: true);
        }

        try {
            $sale = $this->sales->record($tenant, $validated, auth()->id());
        } catch (ValidationException $e) {
            // Stock shortfalls and field rules surface as a 422 the cart can display.
            return response()->json([
                'success' => false,
                'message' => $e->validator->errors()->first() ?: 'The sale could not be recorded.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return $this->saleResponse($sale);
    }

    /**
     * One receipt payload for both a fresh sale and an idempotent replay, so the
     * terminal renders identical data in both cases.
     */
    private function saleResponse(Sale $sale, bool $replayed = false): JsonResponse
    {
        return response()->json([
            'success'        => true,
            'replayed'       => $replayed,
            'sale_id'        => $sale->id,
            'invoice_number' => $sale->invoice_number,
            'total'          => $sale->total,
            'total_fmt'      => Money::formatRupiah($sale->total / 100),
            'paid_amount'    => $sale->paid_amount,
            'paid_fmt'       => Money::formatRupiah($sale->paid_amount / 100),
            'change_amount'  => $sale->change_amount,
            'change_fmt'     => Money::formatRupiah($sale->change_amount / 100),
            'receipt_url'    => route('sales.print', $sale),
            'show_url'       => route('sales.show', $sale),
        ]);
    }

    /** Tenant-scoped existence rule: a foreign id must never be sellable. */
    private function owned(string $table, int $tenantId)
    {
        return Rule::exists($table, 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId));
    }
}


