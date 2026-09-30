<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local-development convenience: a ready-to-login account already on the top Business
 * plan, for trying out the unlimited-workspaces / 50-user side of the app.
 * Run: php artisan db:seed --class=BusinessSeeder
 * Login: bisnis@cultiv.id / password
 */
class BusinessSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlanSeeder::class);

        $business = Plan::where('slug', 'business')->firstOrFail();

        $user = User::updateOrCreate(
            ['email' => 'bisnis@cultiv.id'],
            [
                'name' => 'Business Account',
                'password' => bcrypt('password'),
                'email_verified_at' => now(), // verified so login goes straight to dashboard
            ]
        );

        $tenant = Tenant::firstOrCreate(
            ['slug' => 'bisnis-workspace'],
            ['name' => 'Bisnis Workspace', 'owner_id' => $user->id, 'status' => 'active']
        );

        TenantUser::firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id],
            ['role' => 'Owner', 'status' => 'active', 'joined_at' => now()]
        );

        $this->activateBusiness($tenant, $business);

        $user->update(['current_tenant_id' => $tenant->id]);
    }

    /**
     * Put the workspace on the Business plan, shaped exactly like a settled invoice
     * (SubscriptionService::activateFromInvoice). Re-running refreshes the billing period
     * instead of stacking a second active subscription.
     */
    private function activateBusiness(Tenant $tenant, Plan $plan): void
    {
        $current = $tenant->activeSubscription()->first();

        if ($current && $current->plan_id === $plan->id) {
            $current->update([
                'status' => 'active',
                'billing_cycle' => 'monthly',
                'amount' => $plan->price_monthly,
                'currency' => $plan->currency,
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
                'cancelled_at' => null,
                'ended_at' => null,
            ]);

            return;
        }

        if ($current) {
            $current->update(['status' => 'cancelled', 'cancelled_at' => now(), 'ended_at' => now()]);
        }

        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'amount' => $plan->price_monthly,
            'currency' => $plan->currency,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);
    }
}