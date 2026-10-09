<?php

namespace App\Http\Controllers;

use App\Models\ApiUsage;
use App\Services\ApiAccessService;
use App\Services\AuditLogger;
use App\Services\UsageService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Workspace API Access screen: create / inspect / rotate / revoke API tokens (§13, §25).
 *
 * What makes a token a WORKSPACE credential:
 *  - it is stamped with tenant_id at creation, so the API resolves its workspace from
 *    the token itself and never from the owner's browser selection (§2);
 *  - this screen only lists and manages tokens of the CURRENT workspace (plus legacy
 *    unbound ones), so workspace B never sees workspace A's token metadata (§2);
 *  - the scopes offered are derived from the live /admin/api capabilities, so a scope
 *    the platform has switched off can neither be granted now nor kept on rotation
 *    (§11, §12).
 *
 * The plaintext secret is shown exactly once, on create and on rotate — Sanctum only
 * stores its SHA-256 hash.
 */
class ApiTokenController extends Controller
{
    public function index(Request $request)
    {
        $tenant = app('tenant.context')->tenant();

        if (! $tenant) {
            return redirect()->route('tenants.index');
        }

        $usage = app(UsageService::class);
        $access = app(ApiAccessService::class);
        $plan = $usage->planFor($tenant);

        return view('tokens.index', [
            'tokens' => $this->visibleTokens($request, $tenant->id)
                ->orderByDesc('created_at')
                ->get(['id', 'name', 'abilities', 'tenant_id', 'last_used_at', 'created_at']),
            'apiEnabled' => $usage->allows($tenant, 'api_access'),
            'scopeMatrix' => $access->matrix(),
            'availableScopes' => $access->availableScopes(),
            'planName' => $plan?->name ?? 'Free',
            'rateLimit' => $plan?->limit('api_rate_limit'),
            'quota' => $plan?->limit('api_calls'),
            'quotaUsed' => $usage->usage($tenant, 'api_calls'),
            'recentUsage' => ApiUsage::query()
                ->where('tenant_id', $tenant->id)
                ->latest('id')
                ->take(10)
                ->get(['id', 'method', 'path', 'status_code', 'duration_ms', 'created_at']),
            'featurePlans' => $usage->featurePlans('api_access'),
        ]);
    }

    public function store(Request $request)
    {
        $tenant = app('tenant.context')->tenant();

        if (! $tenant) {
            return redirect()->route('tenants.index');
        }

        app(UsageService::class)->enforceFeature($tenant, 'api_access');

        $available = app(ApiAccessService::class)->availableScopes();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            // Only scopes the platform currently offers (§7): a scope disabled on
            // /admin/api cannot be granted here — validation rejects it server-side,
            // the checkboxes in the form are merely its visible face.
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in($available)],
        ]);

        $token = $request->user()->createToken($data['name'], $data['scopes']);

        // Bind the credential to THIS workspace (§2). The client never supplies it.
        $token->accessToken->forceFill(['tenant_id' => $tenant->id])->save();

        AuditLogger::log('api_token.created', null, [
            'name' => $data['name'],
            'scopes' => $data['scopes'],
        ]);

        // Plain text shown exactly once — only the SHA-256 hash is stored.
        return back()->with('plainTextToken', $token->plainTextToken)
            ->with('success', 'Token created — copy it now, it will not be shown again.');
    }

    public function destroy(Request $request, $tokenId)
    {
        $tenant = app('tenant.context')->tenant();

        if (! $tenant) {
            return redirect()->route('tenants.index');
        }

        // Scoped to this workspace's tokens: an id from another workspace 404s.
        $token = $this->visibleTokens($request, $tenant->id)->where('id', $tokenId)->firstOrFail();
        $token->delete();

        AuditLogger::log('api_token.revoked', null, ['token_id' => (int) $tokenId]);

        return back()->with('success', 'Token revoked.');
    }

    /**
     * Rotate: revoke the old secret and issue a replacement with the same name (§13).
     *
     * Stored scopes are re-intersected with what the platform currently allows, so a
     * capability switched off between creation and rotation is NOT re-granted (§12) —
     * a rotation can only narrow the credential, never widen it.
     */
    public function rotate(Request $request, $tokenId)
    {
        $tenant = app('tenant.context')->tenant();

        if (! $tenant) {
            return redirect()->route('tenants.index');
        }

        $old = $this->visibleTokens($request, $tenant->id)->where('id', $tokenId)->firstOrFail();

        $available = app(ApiAccessService::class)->availableScopes();
        $stored = (array) $old->abilities;

        // Legacy tokens were minted with ['*']; on rotation they become explicit.
        $scopes = in_array('*', $stored, true)
            ? $available
            : array_values(array_intersect($stored, $available));

        if ($scopes === []) {
            return back()->withErrors([
                'scopes' => 'No scopes remain available for this token. Ask an administrator to re-enable its capabilities first.',
            ]);
        }

        $old->delete();
        $new = $request->user()->createToken($old->name, $scopes);
        $new->accessToken->forceFill(['tenant_id' => $tenant->id])->save();

        AuditLogger::log('api_token.rotated', null, ['name' => $old->name, 'scopes' => $scopes]);

        return back()->with('plainTextToken', $new->plainTextToken)
            ->with('success', 'Token rotated — the old secret stopped working immediately.');
    }

    /**
     * Tokens manageable from the current workspace: its own bound tokens plus legacy
     * unbound ones (created before the binding existed). Never another workspace's.
     */
    private function visibleTokens(Request $request, int $tenantId)
    {
        return $request->user()->tokens()
            ->where(function ($query) use ($tenantId) {
                $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
            });
    }
}
