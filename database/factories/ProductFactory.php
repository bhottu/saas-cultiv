<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id'        => Tenant::factory(),
            'category_id'      => null,
            'brand_id'         => null,
            'sku'              => 'SKU-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(8)),
            'barcode'          => null,
            'name'             => fake()->unique()->words(3, true),
            'description'      => fake()->sentence(),
            'unit'             => 'pcs',
            'purchase_price'   => 500000,   // 5000.00 IDR (cents)
            'selling_price'    => 750000,   // 7500.00 IDR (cents)
            'cost_price'       => 500000,
            'minimum_stock'    => 5,
            'max_stock'        => null,
            'track_inventory'  => true,
            'is_active'        => true,
            'image'            => null,
        ];
    }

    public function withCategory(Category $category): static
    {
        return $this->state(fn (array $attributes) => ['category_id' => $category->id]);
    }

    public function withBrand(Brand $brand): static
    {
        return $this->state(fn (array $attributes) => ['brand_id' => $brand->id]);
    }

    public function withWarehouse(Warehouse $warehouse): static
    {
        return $this->state(fn (array $attributes) => [
            'minimum_stock' => 10,
        ]);
    }
}