<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StockMovement>
 */
class StockMovementFactory extends Factory
{
    public function definition(): array
    {
        $tenant = Tenant::factory();
        $warehouse = Warehouse::factory(['tenant_id' => $tenant]);
        $product = ProductFactory::new(['tenant_id' => $tenant])->create();

        return [
            'tenant_id'       => $tenant->id,
            'product_id'      => $product->id,
            'warehouse_id'    => $warehouse->id,
            'type'            => 'purchase',
            'quantity'        => 10,
            'reference_type'  => null,
            'reference_id'    => null,
            'created_by'      => null,
            'notes'           => null,
            'metadata'        => null,
        ];
    }

    public function purchase(int $quantity = 10): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'purchase',
            'quantity' => $quantity,
            'reference_type' => 'purchase',
        ]);
    }

    public function sale(int $quantity = 1): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'sale',
            'quantity' => $quantity,
            'reference_type' => 'sale',
        ]);
    }

    public function adjustmentIn(int $quantity = 5, string $reason = 'restock'): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'adjustment_in',
            'quantity' => $quantity,
            'reference_type' => 'adjustment',
            'metadata' => ['reason' => $reason, 'adjustment_type' => 'in'],
        ]);
    }

    public function adjustmentOut(int $quantity = 3, string $reason = 'damaged'): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'adjustment_out',
            'quantity' => $quantity,
            'reference_type' => 'adjustment',
            'metadata' => ['reason' => $reason, 'adjustment_type' => 'out'],
        ]);
    }
}