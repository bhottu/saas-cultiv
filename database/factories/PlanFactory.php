<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(1, true),
            'slug' => $this->faker->unique()->slug(),
            'description' => 'Seeded test plan.',
            'price_monthly' => 0,
            'price_yearly' => 0,
            'currency' => 'IDR',
            'entitlements' => ['max_users' => 10, 'max_api_calls' => 50000, 'max_storage_mb' => 1024],
            'features' => ['Feature A'],
            'is_active' => true,
            'is_free_tier' => false,
            'sort_order' => 0,
        ];
    }
}
