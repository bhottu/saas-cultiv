<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
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
     * Case-insensitive partial search over name, sku and barcode.
     *
     * PostgreSQL's LIKE is case-sensitive (MySQL's and SQLite's are not), so every
     * product search in the app routes through this ONE scope: LOWER(col) LIKE ?
     * with a lowercased binding behaves identically on every supported driver, making
     * "kopi", "KOPI" and "Kopi" equivalent. The term is always bound, never interpolated.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $needle = '%'.mb_strtolower($term).'%';

        return $query->where(function (Builder $q) use ($needle) {
            $q->whereRaw('LOWER(name) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(sku) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(barcode) LIKE ?', [$needle]);
        });
    }

    /**
     * Current balance for the default warehouse (read-only summary).
     * The authoritative source is stock_balances + stock_movements.
     */
    public function currentStock(int $warehouseId = null): int
    {
        $warehouseId ??= $this->tenant?->settings['default_warehouse_id']
            ?? $this->tenant?->warehouses()->first()?->id;

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