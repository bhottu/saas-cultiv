<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sale line item.
 *
 * selling_price and cost_price are HISTORICAL SNAPSHOTS taken when the sale was created.
 * Later product price changes must never alter an existing invoice or its profit:
 * COGS / gross profit is always computed from these columns.
 */
class SaleItem extends Model
{
    use BelongsToTenant, HasFactory;

    protected $guarded = [];

    protected $casts = [
        'quantity'          => 'integer',
        'selling_price'     => 'integer',
        'cost_price'        => 'integer',
        'discount_value'    => 'integer',
        'discount'          => 'integer',
        'subtotal'          => 'integer',
        'returned_quantity' => 'integer',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function returnItems(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    /** Units that may still be returned (never more than what was sold). */
    public function remainingQuantity(): int
    {
        return max(0, (int) $this->quantity - (int) $this->returned_quantity);
    }

    /** Line COGS from the cost snapshot. */
    public function cogs(): int
    {
        return (int) $this->quantity * (int) $this->cost_price;
    }

    /** Line profit, from snapshots only. */
    public function profit(): int
    {
        return (int) $this->subtotal - $this->cogs();
    }
}