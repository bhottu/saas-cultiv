<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Resolve the active tenant from the session and validate membership server-side.
 *
 * A missing workspace is an ACCOUNT state, not an authorization failure. Removing
 * somebody from a team only ends their MEMBERSHIP: the user account survives and can
 * still sign in. Therefore a request that needs a workspace but finds none is sent to
 * the workspace hub (/tenants) instead of the dead "403 Access denied" page — while
 * every route that does have a validated context keeps its normal permission checks.
 */
class EnsureTenantContext
{
    public function handle(Request $request, Closure $next)
    {
        $ctx = app(TenantContext::class);

        if (($user = $request->user() ?? Auth::user()) !== null) {

            // Never trust the browser: validate that the tenant id belongs to this user.
            $tenantId = session('tenant_id') ?: $user->current_tenant_id;

            $tenant = $tenantId ? Tenant::find($tenantId) : null;
            $membership = $tenant ? $user->membershipIn($tenant) : null;

            if ($tenant && $membership) {
                $ctx->set($tenant, $user);
                $user->forceFill(['current_tenant_id' => $tenant->id])->saveQuietly();
            } else {
                // The session/user still points at a workspace that is no longer
                // usable — deleted (soft-deleted), purged, or membership revoked.
                // Stale ids are cleared, otherwise every later request would keep
                // resolving to nothing and the account would sit on a dead 403.
                $this->clearStaleTenant($request, $user, $tenantId);

                // No valid tenant: allow auth-only routes (onboarding/tenant list).
                $ctx->set(null, $user);

                // ...but a tenant-scoped route cannot render without one. Send the
                // account to the workspace hub instead of aborting 403.
                if ($this->needsTenant($request)) {
                    return $this->toWorkspaceHub($request);
                }
            }
        }

        return $next($request);
    }

    /**
     * True when the resolved route lives inside the `tenant` middleware group.
     *
     * Route middleware is already gathered (group aliases included) before the
     * pipeline runs, so the group can be detected here instead of repeating a
     * "do I have a workspace?" guard in every controller action.
     */
    private function needsTenant(Request $request): bool
    {
        $route = $request->route();

        return $route !== null && in_array('tenant', $route->middleware(), true);
    }

    /**
     * Hand the account over to the workspace hub. API/JSON callers keep the historical
     * 403 body: they have no browser session to redirect and expect a status code.
     */
    private function toWorkspaceHub(Request $request): RedirectResponse
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            abort(403, 'No tenant context.');
        }

        return redirect()->route('tenants.index');
    }

    /**
     * Forget a workspace id that no longer resolves, so the account is not stuck.
     */
    private function clearStaleTenant(Request $request, User $user, mixed $tenantId): void
    {
        if (! $tenantId) {
            return;
        }

        if ($user->current_tenant_id == $tenantId) {
            $user->forceFill(['current_tenant_id' => null])->saveQuietly();
        }

        if (session('tenant_id') == $tenantId) {
            $request->session()->forget('tenant_id');
        }
    }
}

