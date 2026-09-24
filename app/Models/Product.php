<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory, BelongsToTenant;

    protected $guarded = [];

    protected $casts = [
        'purchase_price'   => 'integer',
        'selling_price'    => 'integer',
        'cost_price'       => 'integer',
        'minimum_stock'    => 'integer',
        'max_stock'        => 'integer',
        'track_inventory'  => 'boolean',
        'is_active'        => 'boolean',
    ];

    /**
     * Current balance for the default warehouse (read-only summary).
     * The authoritative source is stock_balances + stock_movements.
     */
    public function currentStock(int $warehouseId = null): int
    {
        $warehouseId ??= $this->tenant?->settings['default_warehouse_id']
            ?? $this->warehouses()->first()?->id;

        if (! $warehouseId) {
            return 0;
        }

        return (int) \App\Models\StockBalance::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant_id)
            ->where('product_id', $this->id)
            ->where('warehouse_id', $warehouseId)
            ->value('quantity');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function warehouses(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function purchaseItems(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}