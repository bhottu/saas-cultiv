<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Notifications\SubscriptionExpiredNotification;
use App\Notifications\SubscriptionExpiringNotification;
use Illuminate\Support\Facades\DB;

/** Subscription lifecycle: trial → active → past_due/cancelled → expired, plus entitlement switching. */
class SubscriptionService
{
    /** Called by WebhookService inside the paid-payment transaction. */
    public function activateFromInvoice(Invoice $invoice): Subscription
    {
        $meta = $invoice->metadata ?? [];
        $plan = Plan::findOrFail($meta['plan_id']);
        $cycle = $meta['billing_cycle'] ?? 'monthly';

        $tenant = $invoice->tenant;
        $current = $tenant->activeSubscription()->with('plan')->first();

        if ($current && $current->plan_id === $plan->id && ! $current->ended_at) {
            $current->renew(); // renewal: extend period

            return $current;
        }

        if ($current) {
            $current->update(['status' => 'cancelled', 'cancelled_at' => now(), 'ended_at' => now()]);
        }

        $sub = Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => $cycle,
            'amount' => $invoice->amount,
            'currency' => $invoice->currency,
            'current_period_start' => now(),
            'current_period_end' => $cycle === 'yearly' ? now()->addYear() : now()->addMonth(),
        ]);

        return $sub;
    }

    /** Start a trial on a paid plan (once per tenant). */
    public function startTrial(Tenant $tenant, Plan $plan): Subscription
    {
        abort_if($tenant->trial_ends_at !== null, 422, 'Trial already used.');
        abort_if($plan->price_monthly <= 0 && $plan->price_yearly <= 0, 422, 'Plan is free.');

        $days = (int) config('saas.trial_days');

        $tenant->update(['trial_ends_at' => now()->addDays($days)]);

        return Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
            'billing_cycle' => 'monthly',
            'amount' => $plan->price_monthly,
            'currency' => $plan->currency,
            'trial_ends_at' => now()->addDays($days),
            'current_period_start' => now(),
            'current_period_end' => now()->addDays($days),
        ]);
    }

    /** Downgrade / expiry fallback: move tenant onto the free plan. */
    public function switchToFree(Tenant $tenant): Subscription
    {
        $free = Plan::where('is_free_tier', true)->firstOrFail();

        return DB::transaction(function () use ($tenant, $free) {
            $current = $tenant->activeSubscription()->first();

            if ($current) {
                $current->update(['status' => 'cancelled', 'cancelled_at' => now(), 'ended_at' => now()]);
            }

            return Subscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $free->id,
                'status' => 'active',
                'billing_cycle' => 'monthly',
                'amount' => 0,
                'currency' => $free->currency,
                'current_period_start' => now(),
                'current_period_end' => null, // free tier does not expire
            ]);
        });
    }

    public function cancel(Tenant $tenant): void
    {
        $sub = $tenant->activeSubscription()->first();

        if ($sub) {
            // Cancel at period end: keep access until current_period_end.
            $sub->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        }
    }

    /** Daily scheduler: expire finished periods, flag past-due, revert to free. */
    public function sweep(): int
    {
        $changed = 0;

        Subscription::whereIn('status', ['active', 'trialing', 'cancelled', 'past_due'])
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<', now())
            ->chunkById(100, function ($subs) use (&$changed) {
                foreach ($subs as $sub) {
                    $tenant = $sub->tenant;

                    if ($sub->status === 'trialing') {
                        // Trial over: drop to free tier, keep data.
                        $this->switchToFree($tenant);
                        $sub->update(['status' => 'expired', 'ended_at' => now()]);
                        $tenant->owner?->notify(new SubscriptionExpiredNotification($tenant));
                    } elseif ($sub->status === 'cancelled') {
                        $sub->update(['status' => 'expired', 'ended_at' => now()]);
                        $this->switchToFree($tenant); // data retained on free tier
                        $tenant->owner?->notify(new SubscriptionExpiredNotification($tenant));
                    } else {
                        $sub->update(['status' => 'past_due']);
                    }

                    $changed++;
                }
            });

        return $changed;
    }

    /** Reminder for subscriptions expiring within 7 days. */
    public function sendExpiringReminders(): int
    {
        $sent = 0;

        Tenant::whereHas('subscriptions', function ($q) {
            $q->whereIn('status', ['active', 'trialing'])
                ->whereBetween('current_period_end', [now(), now()->addDays(7)]);
        })->with('owner')->chunkById(100, function ($tenants) use (&$sent) {
            foreach ($tenants as $tenant) {
                $tenant->owner?->notify(new SubscriptionExpiringNotification($tenant));
                $sent++;
            }
        });

        return $sent;
    }
}
