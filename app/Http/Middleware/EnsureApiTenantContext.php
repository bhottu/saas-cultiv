<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the WORKSPACE for an API request — from the credential, never from the
 * client (§2).
 *
 * Order of resolution:
 *
 *  1. The token's own tenant_id. A token minted for Workspace A can only ever act on
 *     Workspace A: switching the owner's browser workspace does not move the
 *     credential, and neither a header nor a query parameter is consulted at all.
 *     Membership is re-checked server-side on every request, so a member removed
 *     after issuing the token is cut off immediately.
 *  2. Legacy tokens (created before the binding existed) and Sanctum::actingAs fall
 *     back to the membership-validated `current_tenant_id` — the exact rule the web
 *     layer uses, so no browser session or pre-existing credential changes meaning.
 *
 * An API request never writes `current_tenant_id`: calling the API must not silently
 * switch the owner's selected workspace in another tab.
 */
class EnsureApiTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            // auth:sanctum rejects first; this is defence in depth.
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken && $token->tenant_id) {
            $tenant = Tenant::find($token->tenant_id);

            if (! $tenant || ! $user->membershipIn($tenant)) {
                return response()->json([
                    'message' => 'This token is not valid for the requested workspace.',
                    'code' => 'token_workspace_unavailable',
                ], 403);
            }

            app('tenant.context')->set($tenant, $user);

            return $next($request);
        }

        // Legacy / actingAs path: the validated current workspace of the owner.
        $tenantId = $user->current_tenant_id;
        $tenant = $tenantId ? Tenant::find($tenantId) : null;

        if ($tenant && $user->membershipIn($tenant)) {
            app('tenant.context')->set($tenant, $user);

            return $next($request);
        }

        // No usable workspace: the historical body for API callers (§2: never a
        // redirect, never another tenant's data).
        return response()->json(['message' => 'No tenant context.'], 403);
    }
}
