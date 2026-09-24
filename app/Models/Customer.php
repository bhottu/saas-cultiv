<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Customer (tenant-owned master data).
 *
 * A sale may be created without a customer ("Walk-in Customer"), which is why
 * sales.customer_id is nullable and the sales relation is optional.
 *
 * Money (credit_limit) is stored as cents, following App\Services\Money.
 */
class Customer extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'credit_limit' => 'integer',
        'is_active'    => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** All sales (any status) of this customer. */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /** Revenue-generating sales: completed + refunded documents. */
    public function completedSales(): HasMany
    {
        return $this->sales()->whereIn('status', Sale::REVENUE_STATUSES);
    }

    /** Label used on invoices/receipts when no customer was chosen. */
    public static function walkInLabel(): string
    {
        return __('Walk-in Customer');
    }

    public function displayName(): string
    {
        return $this->name ?: self::walkInLabel();
    }

    /** Customer detail metric: number of revenue-generating orders. */
    public function totalOrders(): int
    {
        return $this->completedSales()->count();
    }

    /** Customer detail metric: lifetime spend, net of refunds (cents). */
    public function totalSpent(): int
    {
        return (int) $this->completedSales()->sum('total')
            - (int) $this->completedSales()->sum('refunded_amount');
    }

    /** Customer detail metric: last purchase timestamp. */
    public function lastPurchaseAt(): ?Carbon
    {
        $latest = $this->completedSales()->max('sold_at');

        return $latest ? Carbon::parse($latest) : null;
    }
}