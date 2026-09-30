<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\BusinessAuthorization;
use Illuminate\Http\Request;

/**
 * Tenant audit log explorer (Pro/Business entitlement).
 *
 * AuditLog is deliberately NOT tenant-scoped by a global scope — the platform
 * admin panel must read across workspaces — so every query here filters
 * `tenant_id` explicitly. That is the single isolation guarantee for this screen.
 */
class AuditLogController extends Controller
{
    /** Keys whose values are never rendered, whatever a caller stored in metadata. */
    private const REDACTED_KEYS = [
        'password', 'password_confirmation', 'secret', 'token', 'api_key', 'api_secret',
        'webhook_secret', 'authorization', 'bearer', 'credit_card', 'cvv', 'card_number',
    ];

    public function __construct(private readonly BusinessAuthorization $auth) {}

    public function index(Request $request)
    {
        $this->auth->authorize('audit.view');
        $tenant = $this->tenant($request);

        $query = AuditLog::query()->where('audit_logs.tenant_id', $tenant->id);

        $filters = [
            'action'        => $request->string('action')->toString(),
            'action_group'  => $request->string('action_group')->toString(),
            'user_id'       => $request->integer('user_id') ?: null,
            'resource_type' => $request->string('resource_type')->toString(),
            'from'          => $request->string('from')->toString(),
            'to'            => $request->string('to')->toString(),
            'search'        => $request->string('search')->toString(),
        ];

        if ($filters['action'] !== '') {
            $query->where('action', $filters['action']);
        }

        // "sale" matches sale.created / sale.refunded but never customer.created.
        if ($filters['action_group'] !== '') {
            $query->where('action', 'like', $filters['action_group'].'.%');
        }

        if ($filters['user_id']) {
            $query->where('user_id', $filters['user_id']);
        }

        if ($filters['resource_type'] !== '') {
            $query->where('resource_type', $filters['resource_type']);
        }

        if ($filters['from'] !== '') {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if ($filters['to'] !== '') {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        if ($filters['search'] !== '') {
            $term = $filters['search'];
            $query->where(function ($q) use ($term) {
                $q->where('action', 'like', "%{$term}%")
                    ->orWhere('resource_type', 'like', "%{$term}%")
                    ->orWhere('resource_id', 'like', "%{$term}%");
            });
        }

        return view('audit-logs.index', [
            'entries' => $query->with('user:id,name,email')
                ->latest('id')
                ->paginate(50)
                ->withQueryString(),

            'filters' => $filters,
            'actions' => $this->actionOptions($tenant->id),
            'groups'  => $this->groupOptions($tenant->id),
            'users'   => $tenant->users()->orderBy('name')->get(['users.id', 'users.name', 'users.email']),
            'summary' => $this->summary($tenant->id),
        ]);
    }

    public function show(Request $request, int $entry)
    {
        $this->auth->authorize('audit.view');
        $tenant = $this->tenant($request);

        $log = AuditLog::with('user:id,name,email')
            ->where('tenant_id', $tenant->id)
            ->findOrFail($entry);

        return view('audit-logs.show', [
            'entry'    => $log,
            'metadata' => $this->redact($log->metadata ?? []),
        ]);
    }

    /** @return array<string, int> */
    private function summary(int $tenantId): array
    {
        return [
            'total'     => AuditLog::where('tenant_id', $tenantId)->count(),
            'today'     => AuditLog::where('tenant_id', $tenantId)->where('created_at', '>=', now()->startOfDay())->count(),
            'deletions' => AuditLog::where('tenant_id', $tenantId)->where('action', 'like', '%.deleted')->count(),
            'people'    => AuditLog::where('tenant_id', $tenantId)->whereNotNull('user_id')->distinct()->count('user_id'),
        ];
    }

    /** @return array<int, string> */
    private function actionOptions(int $tenantId): array
    {
        return AuditLog::where('tenant_id', $tenantId)
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->all();
    }

    /** @return array<string, string> */
    private function groupOptions(int $tenantId): array
    {
        return AuditLog::where('tenant_id', $tenantId)
            ->distinct()
            ->pluck('action')
            ->map(fn ($action) => explode('.', $action)[0])
            ->unique()
            ->sort()
            ->mapWithKeys(fn ($group) => [$group => ucfirst(str_replace('_', ' ', $group))])
            ->all();
    }

    /**
     * Recursively strip sensitive values. Metadata is caller-supplied, so the view
     * must never trust it to be free of credentials.
     *
     * @param  array<mixed, mixed>  $metadata
     * @return array<mixed, mixed>
     */
    private function redact(array $metadata, int $depth = 0): array
    {
        if ($depth > 5) {
            return ['…' => 'nested too deep'];
        }

        $safe = [];

        foreach ($metadata as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED_KEYS, true)) {
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

    private function tenant(Request $request): \App\Models\Tenant
    {
        $ctx = app('tenant.context');
        $ctx->check();

        return $ctx->tenant();
    }
}
