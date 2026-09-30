<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Shared base for the Platform Admin panel.
 *
 * The admin works on the whole platform, not on one workspace, so every query here
 * is explicitly cross-tenant. Tenant-owned models carry a `BelongsToTenant` global
 * scope; that scope is bypassed deliberately (and visibly) with withoutGlobalScopes()
 * rather than by relying on "the admin happens to have no tenant context".
 *
 * Read-only by design. The only write is restoring a soft-deleted workspace, and
 * every such action is recorded in the audit log.
 */
abstract class AdminController extends Controller
{
    protected const PER_PAGE = 25;

    /** Bypass the tenant scope for a cross-workspace admin query. */
    protected function acrossTenants(string $model): Builder
    {
        return $model::withoutGlobalScopes();
    }

    protected function paginate(Builder $query, Request $request): LengthAwarePaginator
    {
        $perPage = max(1, min(100, (int) $request->integer('per_page', self::PER_PAGE)));

        // paginate()'s fourth argument is the page NUMBER, not an options array.
        // Path and query string are configured on the resulting paginator instead.
        return $query->paginate($perPage, ['*'], 'page')
            ->withQueryString();
    }

    /** Resolve a workspace by id including soft-deleted ones. */
    protected function findWorkspace(int|string $id): Tenant
    {
        return Tenant::withTrashed()->findOrFail($id);
    }

    /**
     * Record a platform-admin action.
     *
     * The acting admin is the only reliable attribution here, so the target tenant is
     * kept in metadata rather than in tenant_id (which would wrongly imply the admin
     * is a member of that workspace).
     */
    protected function audit(Request $request, string $action, ?Tenant $target = null, array $meta = []): void
    {
        AuditLog::create([
            'tenant_id' => null,
            'user_id' => $request->user()?->id,
            'action' => $action,
            'resource_type' => 'platform_admin',
            'resource_id' => $target ? (string) $target->id : null,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'metadata' => [
                ...$meta,
                ...($target ? [
                    'target_tenant_id' => $target->id,
                    'target_tenant_name' => $target->name,
                ] : []),
            ],
        ]);
    }

    /**
     * Strip anything secret before it is rendered in the admin UI.
     * Metadata is caller-supplied, so it is never trusted to be credential-free.
     */
    protected function redact(array $metadata, int $depth = 0): array
    {
        $blocked = [
            'password', 'password_confirmation', 'secret', 'token', 'api_key', 'api_secret',
            'webhook_secret', 'authorization', 'bearer', 'plain_text', 'card_number', 'cvv',
        ];

        if ($depth > 4) {
            return ['…' => 'nested too deep'];
        }

        $safe = [];

        foreach ($metadata as $key => $value) {
            if (in_array(strtolower((string) $key), $blocked, true)) {
                $safe[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $safe[$key] = $this->redact($value, $depth + 1);
            } elseif (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            } else {
                $safe[$key] = '['.gettype($value).']';
            }
        }

        return $safe;
    }

    /** Normalise an optional id filter from the query string. */
    protected function idFilter(Request $request, string $key): ?int
    {
        $value = $request->integer($key);

        return $value > 0 ? $value : null;
    }
}