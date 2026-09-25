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
                'description' => 'Core business tools for a small workspace.',
                'price_monthly' => 0, 'price_yearly' => 0,
                'entitlements' => [
                    'max_workspaces' => 1,
                    'max_users' => 1,
                    'max_products' => 100,
                    'max_customers' => null,
                    'max_storage_mb' => 100,
                    'max_api_calls' => 0,
                    'basic_sales' => true,
                    'basic_stock' => true,
                    'basic_purchases' => true,
                    'basic_reports' => true,
                    'advanced_reports' => false,
                    'advanced_permissions' => false,
                    'api_access' => false,
                    'audit_log' => false,
                    'advanced_analytics' => false,
                ],
                'features' => ['1 Workspace', '1 User', '100 Products', 'Unlimited Customers', 'Basic Sales', 'Basic Stock', 'Basic Purchase', 'Basic Reports'],
                'is_active' => true, 'is_free_tier' => true, 'sort_order' => 1,
            ],
            [
                'name' => 'Starter', 'slug' => 'starter',
                'description' => 'Multi-user tools for a growing business.',
                'price_monthly' => 39000, 'price_yearly' => 0,
                'entitlements' => [
                    'max_workspaces' => 3,
                    'max_users' => 5,
                    'max_products' => null,
                    'max_customers' => null,
                    'max_storage_mb' => 1024,
                    'max_api_calls' => 0,
                    'basic_sales' => true,
                    'basic_stock' => true,
                    'basic_purchases' => true,
                    'basic_reports' => true,
                    'advanced_reports' => false,
                    'advanced_permissions' => false,
                    'api_access' => false,
                    'audit_log' => false,
                    'advanced_analytics' => false,
                ],
                'features' => ['3 Workspaces', '5 Users', 'Unlimited Products', 'Unlimited Customers', 'Basic Sales', 'Basic Stock', 'Basic Purchase', 'Basic Reports', 'Multi-user'],
                'is_active' => true, 'is_free_tier' => false, 'sort_order' => 2,
            ],
            [
                'name' => 'Pro', 'slug' => 'pro',
                'description' => 'Advanced reporting, permissions and analytics.',
                'price_monthly' => 79000, 'price_yearly' => 0,
                'entitlements' => [
                    'max_workspaces' => 10,
                    'max_users' => 15,
                    'max_products' => null,
                    'max_customers' => null,
                    'max_storage_mb' => 5120,
                    'max_api_calls' => 0,
                    'basic_sales' => true,
                    'basic_stock' => true,
                    'basic_purchases' => true,
                    'basic_reports' => true,
                    'advanced_reports' => true,
                    'advanced_permissions' => true,
                    'api_access' => false,
                    'audit_log' => true,
                    'advanced_analytics' => true,
                ],
                'features' => ['10 Workspaces', '15 Users', 'Unlimited Products', 'Unlimited Customers', 'Basic Sales', 'Basic Stock', 'Basic Purchase', 'Basic Reports', 'Advanced Reports', 'Advanced Permissions', 'Audit Log', 'Advanced Analytics'],
                'is_active' => true, 'is_free_tier' => false, 'sort_order' => 3,
            ],
            [
                'name' => 'Business', 'slug' => 'business',
                'description' => 'Full platform access with API access.',
                'price_monthly' => 149000, 'price_yearly' => 0,
                'entitlements' => [
                    'max_workspaces' => null,
                    'max_users' => 50,
                    'max_products' => null,
                    'max_customers' => null,
                    'max_storage_mb' => 51200,
                    'max_api_calls' => null,
                    'basic_sales' => true,
                    'basic_stock' => true,
                    'basic_purchases' => true,
                    'basic_reports' => true,
                    'advanced_reports' => true,
                    'advanced_permissions' => true,
                    'api_access' => true,
                    'audit_log' => true,
                    'advanced_analytics' => true,
                ],
                'features' => ['Unlimited Workspaces', '50 Users', 'Unlimited Products', 'Unlimited Customers', 'Basic Sales', 'Basic Stock', 'Basic Purchase', 'Basic Reports', 'Advanced Reports', 'Advanced Permissions', 'API', 'Audit Log', 'Advanced Analytics'],
                'is_active' => true, 'is_free_tier' => false, 'sort_order' => 4,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan + ['currency' => 'IDR']);
        }

        // Keep legacy rows for historical foreign keys, but expose only the four final
        // purchasable plans. No plan, invoice, subscription, or tenant data is deleted.
        Plan::whereNotIn('slug', ['free', 'starter', 'pro', 'business'])->update(['is_active' => false]);
    }
}
