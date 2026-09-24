<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local-development convenience: a ready-to-login demo workspace.
 * Run: php artisan db:seed --class=DemoSeeder
 * Login: owner@demo.test / password
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlanSeeder::class);

        $user = User::updateOrCreate(
            ['email' => 'owner@demo.test'],
            [
                'name' => 'Demo Owner',
                'password' => bcrypt('password'),
                'email_verified_at' => now(), // verified so login goes straight to dashboard
            ]
        );

        $tenant = Tenant::firstOrCreate(
            ['slug' => 'demo-workspace'],
            ['name' => 'Demo Workspace', 'owner_id' => $user->id, 'status' => 'active']
        );

        TenantUser::firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id],
            ['role' => 'Owner', 'status' => 'active', 'joined_at' => now()]
        );

        if (! $tenant->activeSubscription()->exists()) {
            app(\App\Services\SubscriptionService::class)->switchToFree($tenant);
        }

        $user->update(['current_tenant_id' => $tenant->id]);
    }
}
