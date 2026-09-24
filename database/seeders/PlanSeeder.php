<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Free', 'slug' => 'free',
                'description' => 'Get started — perfect for individuals.',
                'price_monthly' => 0, 'price_yearly' => 0,
                'entitlements' => ['max_users' => 2, 'max_storage_mb' => 100, 'max_api_calls' => 1000],
                'features' => ['1 workspace', 'Community support'],
                'is_free_tier' => true, 'sort_order' => 1,
            ],
            [
                'name' => 'Pro', 'slug' => 'pro',
                'description' => 'For growing teams that need more power.',
                'price_monthly' => 149000, 'price_yearly' => 1490000,
                'entitlements' => ['max_users' => 10, 'max_storage_mb' => 5120, 'max_api_calls' => 50000],
                'features' => ['10 team members', 'Email support', 'Advanced reports'],
                'sort_order' => 2,
            ],
            [
                'name' => 'Business', 'slug' => 'business',
                'description' => 'Advanced features for larger organizations.',
                'price_monthly' => 499000, 'price_yearly' => 4990000,
                'entitlements' => ['max_users' => 50, 'max_storage_mb' => 51200, 'max_api_calls' => 500000],
                'features' => ['50 team members', 'Priority support', 'API access', 'Audit logs'],
                'sort_order' => 3,
            ],
            [
                'name' => 'Enterprise', 'slug' => 'enterprise',
                'description' => 'Custom limits and dedicated support.',
                'price_monthly' => 1999000, 'price_yearly' => 19990000,
                'entitlements' => ['max_users' => 500, 'max_storage_mb' => 512000, 'max_api_calls' => null],
                'features' => ['Unlimited API calls', 'Dedicated support', 'SLA', 'Custom onboarding'],
                'sort_order' => 4,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
