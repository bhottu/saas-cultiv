<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Services\InventoryService;
use App\Services\SaleReturnService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sales document (header) — the business-sales domain, separate from SaaS billing.
 *
 * Working rules:
 *  - stock is never written directly here; every unit out/in is a stock_movements row created
 *    through InventoryService inside the surrounding DB transaction;
 *  - both inventory side effects are stamped (stock_applied_at / stock_reverted_at) while the
 *    sale row is locked, so a retry or double submit can never deduct or restore twice;
 *  - money is cents only; line items keep the historical selling/cost price snapshot.
 *
 * Order status (status) and money status (payment_status) are deliberately separate fields.
 */
class Sale extends Model
{
    use BelongsToTenant, HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_REFUNDED,
    ];

    /** Statuses that consumed stock and count as revenue. */
    public const REVENUE_STATUSES = [self::STATUS_COMPLETED, self::STATUS_REFUNDED];

    public const PAYMENT_UNPAID = 'unpaid';
    public const PAYMENT_PARTIAL = 'partial';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_REFUNDED = 'refunded';

    public const PAYMENT_STATUSES = [
        self::PAYMENT_UNPAID,
        self::PAYMENT_PARTIAL,
        self::PAYMENT_PAID,
        self::PAYMENT_REFUNDED,
    ];

    protected $guarded = [];

    protected $casts = [
        'sold_at'           => 'datetime',
        'stock_applied_at'  => 'datetime',
        'stock_reverted_at' => 'datetime',
        'completed_at'      => 'datetime',
        'cancelled_at'      => 'datetime',
        'refunded_at'       => 'datetime',
        'subtotal'          => 'integer',
        'item_discount'     => 'integer',
        'discount'          => 'integer',
        'discount_value'    => 'integer',
        'tax'               => 'integer',
        'tax_percent'       => 'integer',
        'shipping'          => 'integer',
        'total'             => 'integer',
        'total_cogs'        => 'integer',
        'paid_amount'       => 'integer',
        'refunded_amount'   => 'integer',
        'change_amount'     => 'integer',
    ];

    // ---------------------------------------------------------------- relations

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'reference_id')
            ->where('reference_type', 'sale');
    }

    // ---------------------------------------------------------------- scopes

    public function scopeRevenue(Builder $query): Builder
    {
        return $query->whereIn('status', self::REVENUE_STATUSES);
    }

    public function scopeBetween(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('sold_at', [$from, $to]);
    }

    public function scopeChannel(Builder $query, ?string $channel): Builder
    {
        return $channel ? $query->where('sales_channel', $channel) : $query;
    }

    // ---------------------------------------------------------------- state helpers

    public function isWalkIn(): bool
    {
        return $this->customer_id === null;
    }

    public function customerName(): string
    {
        return $this->customer?->name ?: Customer::walkInLabel();
    }

    /** Gross billed amount minus refunds already paid back (cents). */
    public function netTotal(): int
    {
        return max(0, (int) $this->total - (int) $this->refunded_amount);
    }

    /** Goods revenue: net line totals minus the document discount (tax/shipping excluded). */
    public function netRevenue(): int
    {
        return max(0, (int) $this->subtotal - (int) $this->discount);
    }

    public function totalDiscount(): int
    {
        return (int) $this->item_discount + (int) $this->discount;
    }

    /** Gross profit from the snapshotted cost price (never today's product cost). */
    public function grossProfit(): int
    {
        return $this->netRevenue() - (int) $this->total_cogs;
    }

    public function balanceDue(): int
    {
        return max(0, (int) $this->total - (int) $this->paid_amount);
    }

    public function totalQuantity(): int
    {
        return (int) $this->items->sum('quantity');
    }

    public function returnedQuantity(): int
    {
        return (int) $this->items->sum('returned_quantity');
    }

    /** True when at least one unit can still be returned. */
    public function hasReturnableItems(): bool
    {
        return $this->items->contains(fn (SaleItem $item) => $item->remainingQuantity() > 0);
    }

    /** Consumed by the BelongsToBusinessSale money-attachment trait. */
    public function isFullyPaid(): bool
    {
        return $this->payment_status === self::PAYMENT_PAID;
    }

    // ---------------------------------------------------------------- inventory

    /**
     * Deduct stock for every line, exactly once. The sale row is locked and
     * stock_applied_at is stamped inside the same transaction, so a retry is a no-op.
     */
    public function applyStock(?int $userId = null): void
    {
        DB::transaction(function () use ($userId) {
            $locked = static::withoutGlobalScopes()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->stock_applied_at !== null) {
                return;
            }

            if (! $locked->warehouse_id) {
                throw new RuntimeException('Cannot deduct stock: the sale has no warehouse.');
            }

            $tenant = Tenant::findOrFail($locked->tenant_id);
            $inventory = app(InventoryService::class);

            foreach ($locked->items()->get() as $item) {
                $product = $item->product_id
                    ? Product::withoutGlobalScopes()->find($item->product_id)
                    : null;

                if (! $product || ! $product->track_inventory) {
                    continue; // services / non-stock items never touch inventory
                }

                // Guarantees a balance row exists, so a shortfall surfaces as
                // "insufficient stock" instead of a missing-balance error.
                $inventory->ensureBalance($tenant, $product, (int) $locked->warehouse_id);

                $inventory->fulfill(
                    $tenant,
                    $product,
                    (int) $locked->warehouse_id,
                    (int) $item->quantity,
                    $userId,
                    'sale',
                    $locked->id,
                    "Sale {$locked->invoice_number}"
                );
            }

            $locked->forceFill(['stock_applied_at' => now()])->saveQuietly();
        });

        $this->refresh();
    }

    /**
     * Put back the units that are still out (sold minus already-returned ones).
     * Cancel uses this; a return goes through SaleReturnService so it stays auditable.
     */
    public function revertStock(?int $userId = null, ?string $notes = null): void
    {
        DB::transaction(function () use ($userId, $notes) {
            $locked = static::withoutGlobalScopes()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->stock_applied_at === null || $locked->stock_reverted_at !== null) {
                return; // nothing was deducted, or it has already been put back
            }

            if (! $locked->warehouse_id) {
                throw new RuntimeException('Cannot restore stock: the sale has no warehouse.');
            }

            $tenant = Tenant::findOrFail($locked->tenant_id);
            $inventory = app(InventoryService::class);

            foreach ($locked->items()->get() as $item) {
                $quantity = $item->remainingQuantity();

                $product = $item->product_id
                    ? Product::withoutGlobalScopes()->find($item->product_id)
                    : null;

                if ($quantity < 1 || ! $product || ! $product->track_inventory) {
                    continue;
                }

                $inventory->ensureBalance($tenant, $product, (int) $locked->warehouse_id);
                $inventory->return(
                    $tenant,
                    $product,
                    (int) $locked->warehouse_id,
                    (int) $quantity,
                    $userId,
                    'sale_return',
                    $locked->id,
                    $notes ?? "Sale {$locked->invoice_number} stock restored"
                );
            }

            $locked->forceFill(['stock_reverted_at' => now()])->saveQuietly();
        });

        $this->refresh();
    }

    // ---------------------------------------------------------------- lifecycle

    /** Complete a draft/pending sale: deduct stock once, then flip the status. */
    public function complete(?int $userId = null): void
    {
        if ($this->status === self::STATUS_COMPLETED) {
            return;
        }

        if (! in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_PROCESSING], true)) {
            throw new RuntimeException('Only draft, pending or processing sales can be completed.');
        }

        DB::transaction(function () use ($userId) {
            $this->applyStock($userId);

            $this->forceFill([
                'status'       => self::STATUS_COMPLETED,
                'completed_at' => $this->completed_at ?? now(),
            ])->save();
        });

        $this->refresh();
    }

    /** Cancel a sale and put its (not yet returned) units back into stock — once. */
    public function cancel(?int $userId = null): void
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return;
        }

        $cancellable = [
            self::STATUS_DRAFT,
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_COMPLETED,
        ];

        if (! in_array($this->status, $cancellable, true)) {
            throw new RuntimeException('This sale cannot be cancelled.');
        }

        DB::transaction(function () use ($userId) {
            $this->revertStock($userId, "Sale {$this->invoice_number} cancelled");

            $this->forceFill([
                'status'       => self::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ])->save();

            $this->refreshPaymentStatus();
        });

        $this->refresh();
    }

    /**
     * Full refund: every remaining unit is returned through an auditable sale_returns document
     * (stock + refunded_amount + payment status all updated by SaleReturnService).
     * Partial refunds call SaleReturnService::record() directly.
     */
    public function refund(?int $userId = null): void
    {
        if ($this->status === self::STATUS_REFUNDED) {
            return;
        }

        if ($this->status !== self::STATUS_COMPLETED) {
            throw new RuntimeException('Only completed sales can be refunded.');
        }

        if ($this->hasReturnableItems()) {
            app(SaleReturnService::class)->refundAll($this, $userId);
        }

        $this->refresh();
    }

    /** Recompute payment_status from the money actually collected and refunded. */
    public function refreshPaymentStatus(): void
    {
        $status = $this->calculatePaymentStatus();

        if ($this->payment_status !== $status) {
            $this->forceFill(['payment_status' => $status])->saveQuietly();
            $this->payment_status = $status;
        }
    }

    public function calculatePaymentStatus(): string
    {
        $paid = (int) $this->paid_amount;

        if ((int) $this->refunded_amount > 0 && (int) $this->refunded_amount >= $paid) {
            return self::PAYMENT_REFUNDED;
        }

        if ($paid <= 0) {
            return self::PAYMENT_UNPAID;
        }

        return $paid >= (int) $this->total ? self::PAYMENT_PAID : self::PAYMENT_PARTIAL;
    }

    /** Cash change handed back for the collected amount (cents). */
    public static function changeFor(int $total, int $paid): int
    {
        return max(0, $paid - $total);
    }
}