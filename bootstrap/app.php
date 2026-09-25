<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // ngrok terminates HTTPS and forwards to the local PHP server over loopback.
        // Trust only the configured local proxy and its forwarded scheme/host headers;
        // this prevents mixed-content asset URLs without trusting arbitrary proxies.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '127.0.0.1'),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->alias([
            'tenant' => \App\Http\Middleware\EnsureTenantContext::class,
            'platform.admin' => \App\Http\Middleware\EnsurePlatformAdmin::class,
            'plan.feature' => \App\Http\Middleware\RequirePlanFeature::class,
        ]);
        // Route-model binding resolves *after* this middleware, otherwise the
        // BelongsToTenant global scope is not applied yet and a row belonging to
        // another tenant could be resolved by guessing its id.
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\EnsureTenantContext::class,
        );
        $middleware->web(append: [
            \App\Http\Middleware\EnsureTenantContext::class,
        ]);
        $middleware->api(prepend: [
            \App\Http\Middleware\EnsureTenantContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\App\Exceptions\SubscriptionLimitException $e, \Illuminate\Http\Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => 'subscription_limit_reached',
                    'upgrade_required' => true,
                    'limit' => $e->context(),
                ], $e->getStatusCode());
            }

            // Browser form submissions carry Referer and should return to the form.
            // A direct request without Referer keeps the HTTP status (429/403) so API
            // clients and existing quota tests do not get an unrelated redirect.
            if ($request->headers->get('referer')) {
                return back()
                    ->withInput()
                    ->withErrors([$e->field => $e->getMessage()])
                    ->with('upgrade_required', $e->context());
            }

            return null;
        });

        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            // Log with full context; never leak stack traces to end users.
            \Illuminate\Support\Facades\Log::error('app.exception', [
                'request_id' => $request->header('X-Request-Id', (string) \Illuminate\Support\Str::uuid()),
                'user_id' => $request->user()?->id,
                'tenant_id' => app('tenant.context')->tenant()?->id,
                'route' => $request->path(),
                'method' => $request->method(),
                'exception' => $e->getMessage(),
            ]);
        });
    })->create();
