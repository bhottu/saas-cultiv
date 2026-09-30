<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local-development convenience: a ready-to-login account already on a paid plan.
 * Run: php artisan db:seed --class=StarterSeeder
 * Login: starter@cultiv.id / password
 */
class StarterSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlanSeeder::class);

        $starter = Plan::where('slug', 'starter')->firstOrFail();

        $user = User::updateOrCreate(
            ['email' => 'starter@cultiv.id'],
            [
                'name' => 'Starter Account',
                'password' => bcrypt('password'),
                'email_verified_at' => now(), // verified so login goes straight to dashboard
            ]
        );

        $tenant = Tenant::firstOrCreate(
            ['slug' => 'starter-workspace'],
            ['name' => 'Starter Workspace', 'owner_id' => $user->id, 'status' => 'active']
        );

        TenantUser::firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id],
            ['role' => 'Owner', 'status' => 'active', 'joined_at' => now()]
        );

        $this->activateStarter($tenant, $starter);

        $user->update(['current_tenant_id' => $tenant->id]);
    }

    /**
     * Put the workspace on the paid Starter plan, shaped exactly like a settled invoice
     * (SubscriptionService::activateFromInvoice). Re-running refreshes the billing period
     * instead of stacking a second active subscription.
     */
    private function activateStarter(Tenant $tenant, Plan $plan): void
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
