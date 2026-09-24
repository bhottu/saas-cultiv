<?php

namespace Database\Factories;

use App\Models\StockBalance;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StockBalance>
 */
class StockBalanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id'   => Tenant::factory(),
            'product_id'  => \App\Models\Product::factory(['tenant_id' => Tenant::factory()]),
            'warehouse_id'=> Warehouse::factory(['tenant_id' => Tenant::factory()]),
            'quantity'    => 0,
            'incoming'    => 0,
            'outgoing'    => 0,
        ];
    }
}