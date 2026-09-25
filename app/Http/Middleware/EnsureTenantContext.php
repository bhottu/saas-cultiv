<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Resolve the active tenant from the session and validate membership server-side. */
class EnsureTenantContext
{
    public function handle(Request $request, Closure $next)
    {
        $ctx = app(TenantContext::class);

        if (($user = $request->user() ?? Auth::user()) !== null) {

            // Never trust the browser: validate that the tenant id belongs to this user.
            $tenantId = session('tenant_id') ?: $user->current_tenant_id;

            $tenant = $tenantId ? \App\Models\Tenant::find($tenantId) : null;
            $membership = $tenant ? $user->membershipIn($tenant) : null;

            if ($tenant && $membership) {
                $ctx->set($tenant, $user);
                $user->forceFill(['current_tenant_id' => $tenant->id])->saveQuietly();
            } else {
                // No valid tenant: allow auth-only routes (onboarding/tenant list).
                $ctx->set(null, $user);
            }
        }

        return $next($request);
    }
}
