<?php

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Platform-wide audit trail. AuditLog has no tenant scope, so this is a genuine
 * cross-workspace view — metadata is redacted before rendering because it is
 * caller-supplied and may contain credentials.
 */
class AuditLogController extends AdminController
{
    public function index(Request $request)
    {
        $query = AuditLog::query()->with('user:id,name,email');

        if (($term = $request->string('search')->toString()) !== '') {
            $query->where(fn ($q) => $q->where('action', 'like', "%{$term}%")
                ->orWhere('resource_type', 'like', "%{$term}%")
                ->orWhere('resource_id', 'like', "%{$term}%"));
        }

        if ($this->idFilter($request, 'tenant_id')) {
            $query->where('tenant_id', $this->idFilter($request, 'tenant_id'));
        }

        if ($this->idFilter($request, 'user_id')) {
            $query->where('user_id', $this->idFilter($request, 'user_id'));
        }

        if ($request->filled('action_group')) {
            $query->where('action', 'like', $request->string('action_group')->toString().'.%');
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->string('from')->toString());
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->string('to')->toString());
        }

        $logs = $this->paginate($query->latest('id'), $request);

        // Redaction happens before the view sees the data, not inside the template.
        $logs->getCollection()->transform(fn ($log) => $log->setAttribute('safe_metadata', $this->redact($log->metadata ?? [])));

        return view('admin.audit-logs.index', [
            'logs' => $logs,
            'filters' => $request->only(['search', 'tenant_id', 'user_id', 'action_group', 'from', 'to']),
            'workspaces' => Tenant::withTrashed()->orderBy('name')->get(['id', 'name', 'deleted_at']),
            'users' => User::orderBy('name')->get(['id', 'name']),
            'groups' => AuditLog::query()->distinct()->pluck('action')
                ->map(fn ($a) => explode('.', $a)[0])->unique()->sort()->values(),
        ]);
    }
}