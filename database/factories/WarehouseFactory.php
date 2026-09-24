<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Warehouse>
 */
class WarehouseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name'      => fake()->unique()->words(2, true).' Warehouse',
            'code'      => strtoupper(fake()->unique()->bothify('??-###')),
            'address'   => fake()->address(),
            'is_active' => true,
        ];
    }
}