<?php

namespace App\Providers;

use App\Services\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

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

        // Global API capability layer (/admin/api). Singleton so the capability rows
        // are read at most once per request no matter how many middlewares ask.
        $this->app->singleton(\App\Services\ApiAccessService::class);
    }

    public function boot(): void
    {
        // Sanctum tokens carry the workspace binding (tenant_id). Registering our
        // subclass means every findToken()/actingAs() result is the binding-aware model.
        Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);

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
        // Public API: one bucket PER WORKSPACE, sized by that workspace's plan
        // (Starter 60, Pro 300, Business 1000 requests/minute). Keying by user or IP
        // would be wrong on both ends: one integration sharing an IP across workspaces
        // would throttle unrelated tenants together, and one workspace with several
        // users would get several times its plan's budget.
        //
        // Requests rejected here are never counted as usage — ApiQuota runs AFTER this
        // limiter, so a throttled request consumes none of the monthly quota.
        RateLimiter::for('api', function (Request $request) {
            $tenant = app('tenant.context')->tenant();

            $limit = $tenant
                ? max(1, (int) app(\App\Services\UsageService::class)->limit($tenant, 'api_rate_limit'))
                : 30; // authenticated but tenantless: minimal bucket, denied further down anyway

            return Limit::perMinute($limit)
                ->by($tenant ? 'api:tenant:'.$tenant->id : 'api:user:'.($request->user()?->id ?: $request->ip()))
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'API rate limit exceeded.',
                        'code' => 'rate_limit_exceeded',
                    ], 429, $headers);
                });
        });

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

