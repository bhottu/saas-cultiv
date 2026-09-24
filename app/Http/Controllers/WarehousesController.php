<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Models\Warehouse;
use App\Services\BusinessAuthorization;
use Illuminate\Http\Request;

/**
 * Warehouse / location CRUD (tenant-scoped).
 */
class WarehousesController extends Controller
{
    public function __construct(private readonly BusinessAuthorization $auth) {}

    public function index(Request $request)
    {
        $this->auth->authorize('warehouses.view');

        $warehouses = Warehouse::withCount('products')
            ->when(! $request->boolean('inactive'), fn ($q) => $q->where('is_active', true))
            ->when($request->boolean('inactive'), fn ($q) => $q)
            ->orderBy('name')
            ->paginate(50);

        return view('warehouses.index', [
            'warehouses' => $warehouses,
            'showInactive' => $request->boolean('inactive'),
        ]);
    }

    public function create()
    {
        $this->auth->authorize('warehouses.create');

        return view('warehouses.form', [
            'warehouse' => new Warehouse(),
            'pageTitle' => 'Add Warehouse',
            'submitUrl' => route('warehouses.store'),
        ]);
    }

    public function store(Request $request)
    {
        $this->auth->authorize('warehouses.create');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:30|unique:warehouses,code,tenant_id,'.$this->tenantId($request),
            'address' => 'nullable|string|max:65535',
            'is_active' => 'boolean',
        ]);

        $warehouse = Warehouse::create($validated);

        AuditLogger::log('warehouse.created', $warehouse, ['name' => $warehouse->name, 'code' => $warehouse->code]);

        return redirect()->route('warehouses.index')->with('status', ['type' => 'success', 'message' => 'Warehouse created.']);
    }

    public function edit(Warehouse $warehouse)
    {
        $this->auth->authorize('warehouses.update');
        $this->ensureOwned($warehouse);

        return view('warehouses.form', [
            'warehouse' => $warehouse,
            'pageTitle' => 'Edit Warehouse',
            'submitUrl' => route('warehouses.update', $warehouse),
        ]);
    }

    public function update(Request $request, Warehouse $warehouse)
    {
        $this->auth->authorize('warehouses.update');
        $this->ensureOwned($warehouse);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:30|unique:warehouses,code,tenant_id,'.$this->tenantId($request).',id,'.$warehouse->id,
            'address' => 'nullable|string|max:65535',
            'is_active' => 'boolean',
        ]);

        $warehouse->update($validated);

        AuditLogger::log('warehouse.updated', $warehouse, ['name' => $warehouse->name, 'code' => $warehouse->code]);

        return redirect()->route('warehouses.index')->with('status', ['type' => 'success', 'message' => 'Warehouse updated.']);
    }

    public function destroy(Request $request, Warehouse $warehouse)
    {
        $this->auth->authorize('warehouses.delete');
        $this->ensureOwned($warehouse);

        $usedBy = $warehouse->stockBalances()->exists();
        if ($usedBy) {
            return back()->with('status', ['type' => 'error', 'message' => 'Warehouse has stock balances. Remove or transfer stock first.']);
        }

        AuditLogger::log('warehouse.deleted', $warehouse, ['name' => $warehouse->name, 'code' => $warehouse->code]);
        $warehouse->delete();

        return redirect()->route('warehouses.index')->with('status', ['type' => 'success', 'message' => 'Warehouse deleted.']);
    }

    private function tenantId(Request $request): int
    {
        return $request->user()->currentTenant->id;
    }

    private function ensureOwned(Warehouse $warehouse): void
    {
        // Never trust the route id: compare against the *active* tenant context.
        if (! $warehouse->tenant_id || $warehouse->tenant_id !== app('tenant.context')->tenant()?->id) {
            abort(404);
        }
    }
}
