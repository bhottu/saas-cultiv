<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Inventory service — the authority for stock changes.
 *
 * Rules:
 *  - stock_movements is the source of truth for inventory history.
 *  - stock_balances is an optimized current-quantity cache, maintained ONLY inside the same
 *    DB transaction that creates the movement. A movement without a balance update never happens
 *    and vice versa.
 *  - Every operation locks the balance row (lockForUpdate) inside a transaction so concurrent
 *    deductions on the same product+warehouse serialize safely.
 */
class InventoryService
{
    public function __construct(private readonly DocumentNumberingService $numbering) {}

    /**
     * Atomically adjust stock for a single product+warehouse by movement type.
     */
    public function apply(
        Tenant $tenant,
        Product $product,
        int $warehouseId,
        string $type,
        int $quantity,
        ?int $createdById = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $notes = null,
        array $metadata = [],
    ): StockMovement {
        return DB::transaction(function () use (
            $tenant, $product, $warehouseId, $type, $quantity,
            $createdById, $referenceType, $referenceId, $notes, $metadata
        ) {
            $balance = StockBalance::firstOrCreate(
                ['tenant_id' => $tenant->id, 'product_id' => $product->id, 'warehouse_id' => $warehouseId],
                ['quantity' => 0, 'incoming' => 0, 'outgoing' => 0]
            );

            $balance = StockBalance::whereKey($balance->id)->lockForUpdate()->first();

            $movement = StockMovement::create([
                'tenant_id'       => $tenant->id,
                'product_id'      => $product->id,
                'warehouse_id'    => $warehouseId,
                'type'            => $type,
                'quantity'        => $quantity,
                'reference_type'  => $referenceType,
                'reference_id'    => $referenceId,
                'created_by'      => $createdById ?? $tenant->users()->first()?->id,
                'notes'           => $notes,
                'metadata'        => $metadata,
            ]);

            $this->applyToBalance($balance, $type, $quantity);

            return $movement;
        });
    }

    /**
     * Try to reserve/fulfill an outgoing quantity. Throws when insufficient stock.
     */
    public function fulfill(
        Tenant $tenant,
        Product $product,
        int $warehouseId,
        int $quantity,
        ?int $createdById = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $notes = null,
    ): StockMovement {
        return DB::transaction(function () use ($tenant, $product, $warehouseId, $quantity, $createdById, $referenceType, $referenceId, $notes) {
            $balance = StockBalance::where('tenant_id', $tenant->id)
                ->where('product_id', $product->id)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if (! $balance) {
                throw new InvalidArgumentException("No stock balance found for product {$product->id}.");
            }

            if ($balance->quantity < $quantity) {
                throw new InvalidArgumentException(
                    "Insufficient stock for product {$product->name} (need {$quantity}, have {$balance->quantity})."
                );
            }

            return $this->apply($tenant, $product, $warehouseId, 'sale', $quantity, $createdById, $referenceType, $referenceId, $notes);
        });
    }

    /**
     * Manual stock adjustment with a required reason.
     */
    public function adjust(
        Tenant $tenant,
        Product $product,
        int $warehouseId,
        int $quantity,
        string $reason,
        ?int $createdById = null,
        ?string $notes = null,
    ): StockMovement {
        return $this->apply(
            $tenant,
            $product,
            $warehouseId,
            $quantity >= 0 ? 'adjustment_in' : 'adjustment_out',
            abs($quantity),
            $createdById,
            'adjustment',
            null,
            $notes ?? $reason,
            ['reason' => $reason, 'adjustment_type' => $quantity >= 0 ? 'in' : 'out']
        );
    }

    /**
     * Find products where current stock is at/below the minimum threshold.
     */
    public function lowStockProducts(Tenant $tenant): \Illuminate\Database\Eloquent\Collection
    {
        return StockBalance::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->join('products', 'stock_balances.product_id', '=', 'products.id')
            ->where('products.is_active', true)
            ->where('products.track_inventory', true)
            ->whereColumn('stock_balances.quantity', '<=', 'products.minimum_stock')
            ->select('stock_balances.*')
            ->with('product')
            ->get();
    }

    /**
     * Ensure each active product has a stock balance row for a given warehouse.
     */
    public function seedBalancesFor(Tenant $tenant, int $warehouseId): void
    {
        $productIds = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->pluck('id');

        foreach ($productIds as $productId) {
            StockBalance::firstOrCreate(
                ['tenant_id' => $tenant->id, 'product_id' => $productId, 'warehouse_id' => $warehouseId],
                ['quantity' => 0, 'incoming' => 0, 'outgoing' => 0]
            );
        }
    }

    private function applyToBalance(StockBalance $balance, string $type, int $quantity): void
    {
        match ($type) {
            'purchase', 'purchase_return', 'adjustment_in', 'transfer_in', 'damage'
                => $balance->incoming += $quantity,
            'sale', 'sale_return', 'adjustment_out', 'transfer_out', 'loss'
                => $balance->outgoing += $quantity,
            default => throw new InvalidArgumentException("Unknown movement type: {$type}"),
        };

        $balance->quantity = $balance->incoming - $balance->outgoing;
        $balance->saveQuietly();
    }
}
