<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sales return / refund document.
 * Always references the original sale; quantities are validated against what was sold.
 */
class SaleReturn extends Model
{
    use BelongsToTenant, HasFactory;

    protected $guarded = [];

    protected $casts = [
        'returned_at'   => 'datetime',
        'refund_amount' => 'integer',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function totalQuantity(): int
    {
        return (int) $this->items->sum('quantity');
    }

    /** COGS credited back by this return (uses the ORIGINAL cost snapshot). */
    public function returnedCogs(): int
    {
        return (int) $this->items->sum(
            fn (SaleReturnItem $item) => (int) $item->quantity * (int) ($item->saleItem?->cost_price ?? 0)
        );
    }
}