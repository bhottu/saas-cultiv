<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\BusinessAuthorization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Base for the public business API.
 *
 * Three guarantees live here so no individual endpoint can forget them:
 *
 *  1. TENANT SCOPE — every model touched by these controllers uses the
 *     `BelongsToTenant` global scope, so the active workspace is applied by Eloquent
 *     and cannot be widened by a query string. Route-model binding is resolved after
 *     `EnsureTenantContext` (see bootstrap/app.php), so ids from another workspace
 *     resolve to 404 rather than leaking a row.
 *  2. AUTHORISATION — the same BusinessAuthorization/RBAC registry the web UI uses.
 *     API access never bypasses role permissions.
 *  3. PAGINATION — a single per_page resolver with a hard ceiling, so a client cannot
 *     ask for the whole table.
 */
abstract class ApiController extends Controller
{
    public const MAX_PER_PAGE = 100;
    public const DEFAULT_PER_PAGE = 25;

    public function __construct(protected readonly BusinessAuthorization $auth) {}

    /** The active workspace, validated server-side against the token owner's membership. */
    protected function tenant(Request $request): Tenant
    {
        $ctx = app('tenant.context');
        $ctx->check();

        return $ctx->tenant();
    }

    /** Clamp client-supplied paging parameters. */
    protected function perPage(Request $request): int
    {
        $requested = (int) $request->integer('per_page', self::DEFAULT_PER_PAGE);

        return max(1, min(self::MAX_PER_PAGE, $requested ?: self::DEFAULT_PER_PAGE));
    }

    /**
     * Attach the money/currency contract to a paginated response so clients can
     * interpret every integer amount without guessing.
     *
     * The payload is nested under "meta" on purpose: Laravel merges `additional()`
     * into the same level as the paginator's own meta, so passing a flat array would
     * surface `currency` as a sibling of `meta` instead of inside it.
     *
     * @param  AnonymousResourceCollection  $collection
     * @return AnonymousResourceCollection
     */
    protected function withMeta(AnonymousResourceCollection $collection, Request $request, array $extra = []): AnonymousResourceCollection
    {
        return $collection->additional([
            'meta' => [
                'currency' => 'IDR',
                'money_unit' => 'cents',
                'workspace_id' => $request->user()?->current_tenant_id,
                ...$extra,
            ],
        ]);
    }

    /** Apply the free-text search a client sent, when present. */
    protected function applySearch(Builder $query, Request $request, array $columns): Builder
    {
        $term = $request->string('search')->toString();

        if ($term === '') {
            return $query;
        }

        // LOWER(col) LIKE ? — PostgreSQL's LIKE is case-sensitive while MySQL's and
        // SQLite's are not, so folding both sides keeps search results identical on
        // every supported driver. Column names are hard-coded by the call sites
        // (never user input); only the term is bound as a parameter.
        $needle = '%'.mb_strtolower($term).'%';

        return $query->where(function (Builder $q) use ($needle, $columns) {
            foreach ($columns as $index => $column) {
                $index === 0
                    ? $q->whereRaw("LOWER({$column}) LIKE ?", [$needle])
                    : $q->orWhereRaw("LOWER({$column}) LIKE ?", [$needle]);
            }
        });
    }

    protected function isActiveFilter(Request $request): ?bool
    {
        if ($request->boolean('active_only')) {
            return true;
        }

        if ($request->boolean('inactive_only')) {
            return false;
        }

        return null;
    }
}