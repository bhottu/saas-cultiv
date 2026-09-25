<?php

namespace App\Http\Middleware;

use App\Services\UsageService;
use Closure;
use Illuminate\Http\Request;

class RequirePlanFeature
{
    public function __construct(private readonly UsageService $usage) {}

    public function handle(Request $request, Closure $next, string $feature): mixed
    {
        $tenant = app('tenant.context')->tenant();

        if (! $tenant) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Tenant context is required.'], 403);
            }

            return redirect()->route('tenants.index');
        }

        $this->usage->enforceFeature($tenant, $feature);

        return $next($request);
    }
}