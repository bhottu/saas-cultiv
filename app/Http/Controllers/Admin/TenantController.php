<?php

namespace App\Http\Controllers\Admin;

use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TenantController extends AdminController
{
    public function index(Request $request)
    {
        $query = Tenant::withTrashed()
            ->with('owner:id,name,email')
            ->with(['activeSubscription.plan'])
            // Counts come from the DB, never a query per workspace. Tenant has no
            // products() relation, so that one is a correlated subquery instead.
            ->withCount(['users', 'customers', 'sales'])
            ->selectSub(
                \Illuminate\Support\Facades\DB::table('products')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('products.tenant_id', 'tenants.id'),
                'products_count'
            );

        if (($term = $request->string('search')->toString()) !== '') {
            $query->where('name', 'like', "%{$term}%");
        }

        if ($request->boolean('deleted')) {
            $query->onlyTrashed();
        } elseif ($request->boolean('active')) {
            $query->whereNull('deleted_at');
        }

        return view('admin.workspaces.index', [
            'workspaces' => $this->paginate($query->latest('id'), $request),
            'filters' => $request->only(['search', 'active', 'deleted']),
        ]);
    }

    public function show(Request $request, int $tenant)
    {
        $model = Tenant::withTrashed()
            ->with('owner:id,name,email')
            ->with('activeSubscription.plan')
            // Tenant has no products() relation, so that count is taken separately.
            ->withCount(['users', 'customers', 'sales', 'purchases', 'warehouses'])
            ->findOrFail($tenant);

        $this->audit($request, 'admin.viewed_workspace', $model);

        return view('admin.workspaces.show', [
            'workspace' => $model,
            'productCount' => $this->acrossTenants(\App\Models\Product::class)
                ->where('tenant_id', $model->id)->count(),
            'warehouses' => $this->acrossTenants(\App\Models\Warehouse::class)
                ->where('tenant_id', $model->id)->orderBy('name')->get(),
            'inventoryUnits' => (int) $this->acrossTenants(\App\Models\StockBalance::class)
                ->where('tenant_id', $model->id)->sum('quantity'),
            'salesValue' => (int) $this->acrossTenants(\App\Models\Sale::class)
                ->where('tenant_id', $model->id)->whereIn('status', \App\Models\Sale::REVENUE_STATUSES)->sum('total'),
            'purchaseValue' => (int) $this->acrossTenants(\App\Models\Purchase::class)
                ->where('tenant_id', $model->id)->where('status', 'received')->sum('total'),
            'members' => $model->users()->orderBy('name')->get(),
        ]);
    }

    /**
     * Restore a soft-deleted workspace.
     *
     * Subscription rules are re-checked first: a workspace must not come back in a
     * state its own plan cannot sustain (for example going over the plan's workspace
     * allowance after the account already filled the freed slot).
     */
    public function restore(Request $request, int $tenant)
    {
        $model = $this->findWorkspace($tenant);

        abort_if($model->trashed() === false, 422, 'This workspace is already active.');

        $limit = app(\App\Services\UsageService::class)->workspaceLimitFor($model->owner);
        $owned = $model->owner->ownedTenants()->where('status', 'active')->count();

        if ($limit !== null && $owned + 1 > $limit) {
            return back()->with('status', [
                'type' => 'error',
                'message' => "Cannot restore: the owner's plan allows {$limit} workspace(s) and {$owned} are already active.",
            ]);
        }

        $model->restore();
        $model->forceFill(['status' => 'active', 'data_retention_until' => null])->save();

        $this->audit($request, 'admin.restored_workspace', $model);

        return back()->with('status', ['type' => 'success', 'message' => 'Workspace restored.']);
    }
}