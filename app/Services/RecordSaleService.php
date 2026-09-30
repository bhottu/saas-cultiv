<?php

namespace App\Services;

use App\Models\BusinessInvoice;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockBalance;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The ONE place a sale is written.
 *
 * Extracted from SalesController::store() so every sales SURFACE (the back-office
 * "New Sale" form and the POS terminal) records through exactly the same pipeline
 * instead of re-implementing money math, stock movement and payment status in a
 * second controller. SalesFlowTest pins this behaviour.
 *
 * Pipeline (order matters):
 *   resolve totals server-side (CalculateSaleTotals — a browser preview is never trusted)
 *   assert stock is available BEFORE writing anything
 *   one transaction: sale + snapshotted sale_items + payment
 *   completed sales deduct stock inside that same transaction, so a failure can
 *   never leave stock and money disagreeing
 *
 * Money: business tables store MINOR UNITS (cents); the caller passes DISPLAY units
 * (rupiah), exactly like the HTTP form posts them, and this service converts.
 */
class RecordSaleService
{
    public function __construct(
        private readonly CalculateSaleTotals $calculator,
        private readonly DocumentNumberingService $numbering,
        private readonly BusinessUsageService $usage,
    ) {}

    /**
     * Record a sale for a workspace.
     *
     * @param  array<string, mixed>  $data  Validated sale input in DISPLAY units (rupiah),
     *                                     the same shape the sales.store form posts:
     *                                     customer_id, warehouse_id, sales_channel, status,
     *                                     sold_at, discount_*, tax_percent, shipping,
     *                                     payment_method, payment_amount,
     *                                     payment_reference, notes, client_reference, items[].
     * @param  int|null  $userId  Actor recorded on the sale, payment and stock movements.
     *
     * @throws ValidationException  when stock cannot cover the lines
     */
    public function record(Tenant $tenant, array $data, ?int $userId = null): Sale
    {
        $status = $data['status'] ?? Sale::STATUS_COMPLETED;
        $soldAt = ! empty($data['sold_at']) ? Carbon::parse($data['sold_at']) : now();

        $lines = [];

        foreach ($data['items'] as $row) {
            $type = $this->calculator->normalizeType($row['discount_type'] ?? CalculateSaleTotals::TYPE_FIXED);

            $lines[] = [
                'product_id'     => (int) $row['product_id'],
                'quantity'       => (int) $row['quantity'],
                'unit_price'     => Money::centsFromDisplay($row['unit_price']),
                'discount_type'  => $type,
                'discount_value' => $this->resolveDiscountValue($type, $row['discount_value'] ?? 0),
            ];
        }

        $headerType = $this->calculator->normalizeType($data['discount_type'] ?? CalculateSaleTotals::TYPE_FIXED);

        $header = [
            'discount_type'  => $headerType,
            'discount_value' => $this->resolveDiscountValue($headerType, $data['discount_value'] ?? 0),
            'tax_percent'    => (int) ($data['tax_percent'] ?? $this->defaultTaxPercent($tenant)),
            'shipping'       => Money::centsFromDisplay($data['shipping'] ?? 0),
        ];

        $totals = $this->calculator->calculate($lines, $header);

        $products = Product::whereIn('id', collect($lines)->pluck('product_id')->unique()->all())
            ->get()->keyBy('id');

        // Refuse BEFORE writing anything: no half-recorded sale can survive a shortfall.
        $this->assertStockAvailable((int) $data['warehouse_id'], $lines);

        $paid = Money::centsFromDisplay($data['payment_amount'] ?? 0);
        $change = Sale::changeFor($totals['total'], $paid);

        // Metered plan limit: never exceed the workspace entitlement for sales.
        $this->usage->enforce($tenant, 'sales_count');

        $sale = DB::transaction(fn () => $this->persist(
            $tenant, $data, $status, $soldAt, $lines, $totals, $products, $paid, $change, $userId
        ));

        $this->usage->recordMetric($tenant, 'sales_count');
        $this->syncBusinessInvoice($sale);

        AuditLogger::log('sale.created', $sale, [
            'invoice_number' => $sale->invoice_number,
            'total'          => $sale->total,
            'status'         => $sale->status,
        ]);

        return $sale;
    }

    /**
     * The transaction body: sale header, snapshotted lines, payment and stock movement.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $totals
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     */
    private function persist(
        Tenant $tenant,
        array $data,
        string $status,
        Carbon $soldAt,
        array $lines,
        array $totals,
        $products,
        int $paid,
        int $change,
        ?int $userId,
    ): Sale {
        $sale = Sale::create([
            'tenant_id'        => $tenant->id,
            'customer_id'      => $data['customer_id'] ?? null,
            'warehouse_id'     => (int) $data['warehouse_id'],
            'invoice_number'   => $this->numbering->next('sale', $tenant->id),
            'client_reference' => $data['client_reference'] ?? null,
            'sold_at'          => $soldAt,
            'subtotal'         => $totals['subtotal'],
            'item_discount'    => $totals['item_discount'],
            'discount'         => $totals['discount'],
            'discount_type'    => $totals['discount_type'],
            'discount_value'   => $totals['discount_value'],
            'tax'              => $totals['tax'],
            'tax_percent'      => $totals['tax_percent'],
            'shipping'         => $totals['shipping'],
            'total'            => $totals['total'],
            'total_cogs'       => 0,
            'paid_amount'      => $paid,
            'change_amount'    => $change,
            'status'           => $status,
            'payment_status'   => Sale::PAYMENT_UNPAID,
            'sales_channel'    => $data['sales_channel'],
            'payment_method'   => $data['payment_method'],
            'created_by'       => $userId,
            'notes'            => $data['notes'] ?? null,
        ]);

        $cogs = 0;

        foreach ($lines as $index => $line) {
            $product = $products->get($line['product_id']);
            $resolved = $totals['lines'][$index];

            // Cost price is a SNAPSHOT: a later product price change must never rewrite
            // the COGS/profit of an existing sale.
            $costPrice = (int) ($product?->cost_price ?? 0);
            $cogs += $line['quantity'] * $costPrice;

            SaleItem::create([
                'tenant_id'      => $tenant->id,
                'sale_id'        => $sale->id,
                'product_id'     => $line['product_id'],
                'product_name'   => $product?->name ?? 'Product #'.$line['product_id'],
                'sku'            => $product?->sku,
                'unit'           => $product?->unit ?? 'pcs',
                'quantity'       => $line['quantity'],
                'selling_price'  => $line['unit_price'],
                'cost_price'     => $costPrice,
                'discount_type'  => $line['discount_type'],
                'discount_value' => $line['discount_value'],
                'discount'       => $resolved['discount'],
                'subtotal'       => $resolved['subtotal'],
            ]);
        }

        $sale->forceFill(['total_cogs' => $cogs])->save();

        if ($paid > 0) {
            SalePayment::create([
                'tenant_id'     => $tenant->id,
                'sale_id'       => $sale->id,
                'method'        => $data['payment_method'],
                'amount'        => $paid,
                'change_amount' => $change,
                'reference'     => $data['payment_reference'] ?? null,
                'paid_at'       => $soldAt,
                'created_by'    => $userId,
            ]);
        }

        $sale->refreshPaymentStatus();

        // Completed sales take the stock out now; a pending sale is deducted later by
        // complete() — still exactly once.
        if ($status === Sale::STATUS_COMPLETED) {
            $sale->applyStock($userId);
        }

        return $sale;
    }

    /**
     * Keep the business invoice mirrored with the sale.
     *
     * Public because status transitions (complete/cancel/refund) re-sync it after the
     * sale's payment status has changed.
     */
    public function syncBusinessInvoice(Sale $sale): void
    {
        $invoice = BusinessInvoice::firstOrCreate(
            ['tenant_id' => $sale->tenant_id, 'sale_id' => $sale->id],
            [
                'invoice_number' => $sale->invoice_number, 'customer_id' => $sale->customer_id,
                'status' => 'open', 'subtotal' => $sale->subtotal, 'discount' => $sale->discount,
                'tax' => $sale->tax, 'shipping' => $sale->shipping, 'total' => $sale->total,
                'amount_paid' => $sale->paid_amount,
                'outstanding' => $sale->balanceDue(), 'issued_at' => $sale->sold_at,
                'paid_at' => $sale->payment_status === Sale::PAYMENT_PAID ? $sale->sold_at : null,
                'notes' => 'Business invoice generated from sale '.$sale->invoice_number,
            ]
        );

        $invoice->update([
            'subtotal' => $sale->subtotal, 'discount' => $sale->discount, 'tax' => $sale->tax,
            'shipping' => $sale->shipping, 'total' => $sale->total, 'amount_paid' => $sale->paid_amount,
            'outstanding' => $sale->balanceDue(),
            'status' => $sale->payment_status === Sale::PAYMENT_REFUNDED ? 'refunded' : ($sale->payment_status === Sale::PAYMENT_PAID ? 'paid' : ($sale->paid_amount > 0 ? 'partial' : 'open')),
        ]);
    }

    /**
     * The sale that already carries $reference, if any.
     *
     * Double-submit guard shared by every sales surface: repeating the same request
     * resolves to the existing sale instead of selling — and deducting stock — twice.
     */
    public function findByClientReference(string $reference): ?Sale
    {
        return $reference === '' ? null : Sale::where('client_reference', $reference)->first();
    }

    // ------------------------------------------------------------------ internals

    /**
     * Refuse the sale before writing anything when the warehouse cannot cover it.
     *
     * @param  array<int, array<string, mixed>>  $lines
     *
     * @throws ValidationException
     */
    private function assertStockAvailable(int $warehouseId, array $lines): void
    {
        // Inventory policy is a business setting: when negative stock is allowed the
        // warehouse is not a constraint at all (InventoryService::fulfill() stays the
        // hard guarantee and simply does not refuse).
        if (config('business.sales.allow_negative_stock')) {
            return;
        }

        $needed = [];

        foreach ($lines as $line) {
            $needed[$line['product_id']] = ($needed[$line['product_id']] ?? 0) + $line['quantity'];
        }

        $products = Product::whereIn('id', array_keys($needed))->get()->keyBy('id');

        $balances = StockBalance::query()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', array_keys($needed))
            ->pluck('quantity', 'product_id');

        $errors = [];

        foreach ($needed as $productId => $quantity) {
            $product = $products->get($productId);

            if (! $product || ! $product->track_inventory) {
                continue;
            }

            $available = (int) ($balances[$productId] ?? 0);

            if ($quantity > $available) {
                $errors["items.{$productId}"] = __(
                    'Not enough stock for :product: requested :requested, available :available.',
                    ['product' => $product->name, 'requested' => $quantity, 'available' => $available]
                );
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** Fixed discounts are entered in rupiah and stored as cents; percentages stay whole percent. */
    private function resolveDiscountValue(string $type, int|float|string $value): int
    {
        $value = (float) $value;

        return $this->calculator->normalizeType($type) === CalculateSaleTotals::TYPE_PERCENT
            ? max(0, min(100, (int) round($value)))
            : Money::centsFromDisplay($value);
    }

    /** Tax rate is tenant-configurable (tenants.settings.tax_percent); nothing is hard-coded. */
    private function defaultTaxPercent(Tenant $tenant): int
    {
        $settings = $tenant->settings ?? [];

        return (int) ($settings['tax_percent'] ?? config('business.sales.tax_percent', 0));
    }
}

