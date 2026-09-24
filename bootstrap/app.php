<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'tenant' => \App\Http\Middleware\EnsureTenantContext::class,
            'platform.admin' => \App\Http\Middleware\EnsurePlatformAdmin::class,
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
