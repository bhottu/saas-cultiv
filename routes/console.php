<?php

use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(\Illuminate\Foundation\Inspiring::quote());
})->purpose('Display an inspiring quote');

// --- Scheduled SaaS maintenance (queue worker + cron required in production) ---

// Expire finished periods, move cancelled → expired, revert tenants to free.
Schedule::call(fn () => app(SubscriptionService::class)->sweep())->dailyAt('00:30')->name('subscription-sweep');

// Remind owners of subscriptions expiring within 7 days.
Schedule::call(fn () => app(SubscriptionService::class)->sendExpiringReminders())->dailyAt('08:00')->name('subscription-reminders');

// Payment status fallback for pending QRIS payments (webhook retry safety net).
Schedule::command('payments:reconcile')->everyTenMinutes()->name('payment-reconcile');

// Purge tenant data past the retention window (soft-deleted workspaces).
Schedule::command('tenants:purge')->dailyAt('01:30')->name('tenant-purge');

