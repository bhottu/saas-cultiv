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

        // Stateless service: it reads the workspace's installs on every call, so a
        // singleton is safe and keeps the alias usable from Blade and middleware.
        $this->app->singleton(\App\Services\ModuleManager::class);
        $this->app->alias(\App\Services\ModuleManager::class, 'modules');
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

        // Billing used to be a single 5/min bucket covering the page view AND the calls to
        // QRIS.PW. Because the /billing page itself counted against it, a customer who
        // reloaded the page a few times got "429 Too Many Attempts" on the page and then
        // again the moment they pressed Subscribe. The two concerns are split instead of
        // simply raising one number.
        //
        // billing.read     5 -> 60/min. Read-only screens, no provider traffic. 60 is a
        //                    runaway-loop guard, not a real cap on human browsing.
        // billing.checkout 5 -> 20/min. Still a hard ceiling in front of the payment
        //                    provider (which allows 100/min), so checkout abuse is still
        //                    contained while a burst of clicks no longer self-inflicts a 429.
        //                    Duplicate protection is NOT left to this limiter: the server
        //                    re-checks for an active pending payment inside a locked
        //                    transaction, which is the guarantee that actually matters.
        RateLimiter::for('billing.read', fn (Request $r) => Limit::perMinute(60)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('billing.checkout', fn (Request $r) => Limit::perMinute(20)->by($r->user()?->id ?: $r->ip()));
    }
}

