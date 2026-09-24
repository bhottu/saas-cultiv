<?php

namespace App\Providers;

use App\Services\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One shared context instance, reachable by class name and alias.
        $this->app->singleton(TenantContext::class);
        $this->app->alias(TenantContext::class, 'tenant.context');
    }

    public function boot(): void
    {
        // Server-side RBAC: one gate per registry permission.
        foreach (config('permissions.permissions', []) as $permission) {
            Gate::define($permission, fn ($user) => app('tenant.context')->userCan($permission));
        }

        // Owner role implicitly allowed for all tenant permissions.
        Gate::before(function ($user, $ability) {
            return app('tenant.context')->role() === config('permissions.owner_role') ? true : null;
        });

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        // General API limit.
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(60)->by($r->user()?->id ?: $r->ip()));

        // Stricter limits for expensive/sensitive operations.
        RateLimiter::for('auth', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('webhook', fn (Request $r) => Limit::perMinute(120)->by($r->ip()));
        RateLimiter::for('uploads', fn (Request $r) => Limit::perMinute(10)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('billing', fn (Request $r) => Limit::perMinute(5)->by($r->user()?->id ?: $r->ip()));
    }
}

