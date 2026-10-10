<?php

namespace App\Http\Middleware;

use App\Models\ApiUsage;
use App\Models\PersonalAccessToken;
use App\Services\ApiAccessService;
use App\Services\UsageService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The per-request gate + meter for /api/v1. Declared on EVERY v1 route:
 *
 *     ->middleware('api.guard:products,read')   resource endpoint
 *     ->middleware('api.guard')                 meta endpoints (me, usage)
 *
 * Checks in this exact order, so that a request rejected earlier consumes nothing:
 *
 *  1. ADMIN CAPABILITY (/admin/api) — the platform layer. A capability switched off
 *     denies every token instantly, without reissuing anything (§12), and it is
 *     checked BEFORE the token's own scopes so a token can never outrank the
 *     platform configuration (§8).
 *  2. TOKEN SCOPE — least privilege. `*` only exists on legacy tokens minted before
 *     scopes; new tokens carry explicit `resource:operation` abilities.
 *  3. MONTHLY QUOTA — per workspace, per billing/month period. Denial is 429 with
 *     `monthly_quota_exceeded` and does NOT increment the counter.
 *     (Rate limiting already happened in `throttle:api` ABOVE this middleware, so a
 *      throttled request never reaches here and never counts — §4.)
 *  4. Serve, then log the request (token ID, never the secret) with duration.
 */
class ApiGuard
{
    public function __construct(
        private readonly ApiAccessService $access,
        private readonly UsageService $usage,
    ) {}

    public function handle(Request $request, Closure $next, ?string $resource = null, ?string $operation = null): Response
    {
        $tenant = app('tenant.context')->tenant();

        if (! $tenant) {
            return response()->json(['message' => 'No tenant context.'], 403);
        }

        $isResource = $resource !== null && $operation !== null;

        if ($isResource) {
            if (! $this->access->allows($resource, $operation)) {
                return response()->json([
                    'message' => match ($operation) {
                        'write' => "Writing {$resource} is disabled for API access.",
                        'delete' => "Deleting {$resource} is disabled for API access.",
                        default => "Reading {$resource} is disabled for API access.",
                    },
                    'code' => 'api_capability_disabled',
                ], 403);
            }

            if (! $this->scopeGranted($request, $resource, $operation)) {
                return response()->json([
                    'message' => "Missing scope {$resource}:{$operation}.",
                    'code' => 'missing_scope',
                ], 403);
            }
        }

        // Monthly quota, per workspace. A plan without the key falls back to the
        // configured default (Plan::limit), never to "unlimited" by accident.
        $plan = $this->usage->planFor($tenant);
        $limit = $plan?->limit('api_calls');

        if ($limit !== null) {
            $used = $this->usage->usage($tenant, 'api_calls');

            if ($used >= $limit) {
                return response()->json([
                    'message' => 'Monthly API quota exceeded.',
                    'code' => 'monthly_quota_exceeded',
                    'quota' => [
                        'limit' => $limit,
                        'used' => $used,
                        'remaining' => 0,
                        'resets_at' => now()->startOfMonth()->addMonth()->toIso8601String(),
                    ],
                ], 429, [
                    'Retry-After' => (string) max(1, now()->startOfMonth()->addMonth()->diffInSeconds(now())),
                ]);
            }
        }

        $this->usage->record($tenant, 'api_calls');

        $start = hrtime(true);
        $response = $next($request);
        $this->log($request, $tenant->id, $response, (int) round((hrtime(true) - $start) / 1_000_000));

        return $response;
    }

    /** Effective scope = stored abilities ∩ (admin capability, checked above). */
    private function scopeGranted(Request $request, string $resource, string $operation): bool
    {
        $user = $request->user();

        // Sanctum's own idiom: a real token carries explicit abilities (or '*'),
        // Sanctum::actingAs stubs `can()` on its mock. Never read `abilities` directly
        // off a token object — that bypasses Sanctum and behaves differently per
        // authentication style.
        return (bool) $user?->tokenCan("{$resource}:{$operation}");
    }

    /** Best-effort: metering must never break a response that already succeeded. */
    private function log(Request $request, int $tenantId, Response $response, int $durationMs): void
    {
        try {
            $token = $request->user()?->currentAccessToken();
            $tokenId = $token instanceof PersonalAccessToken ? $token->getKey() : null;

            ApiUsage::query()->create([
                'tenant_id' => $tenantId,
                // The token's database id only — never the secret itself (§23).
                'personal_access_token_id' => $tokenId > 0 ? (int) $tokenId : null,
                'user_id' => $request->user()?->id,
                'method' => $request->getMethod(),
                'path' => '/'.$request->path(),
                'status_code' => $response->getStatusCode(),
                'duration_ms' => max(0, $durationMs),
            ]);
        } catch (\Throwable $e) {
            Log::warning('api.usage.log_failed', ['error' => $e->getMessage()]);
        }
    }
}
