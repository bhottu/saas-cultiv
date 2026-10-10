<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\WarehouseResource;
use App\Models\Warehouse;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseController extends ApiController
{
    public function index(Request $request)
    {
        $this->auth->authorize('warehouses.view');

        $query = Warehouse::query();

        $this->applySearch($query, $request, ['name', 'code']);

        if (($active = $this->isActiveFilter($request)) !== null) {
            $query->where('is_active', $active);
        }

        $warehouses = $query->orderBy('name')->paginate($this->perPage($request))->withQueryString();

        return $this->withMeta(WarehouseResource::collection($warehouses), $request);
    }

    public function show(Request $request, int $warehouse)
    {
        $this->auth->authorize('warehouses.view');

        return WarehouseResource::make(Warehouse::query()->findOrFail($warehouse));
    }

    /** Create a warehouse inside the token's workspace. Mirrors WarehousesController::store. */
    public function store(Request $request)
    {
        $this->auth->authorize('warehouses.create');

        $tenant = $this->tenant($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:30', $this->codeRule($request, $tenant->id)],
            'address' => ['nullable', 'string', 'max:65535'],
            'is_active' => ['boolean'],
        ]);

        // tenant_id is stamped from the token's workspace, never from the payload.
        $validated['tenant_id'] = $tenant->id;

        $warehouse = Warehouse::create($validated);

        AuditLogger::log('warehouse.created', $warehouse, ['name' => $warehouse->name, 'code' => $warehouse->code]);

        return WarehouseResource::make($warehouse)->additional([
            'message' => 'Warehouse created.',
        ])->response()->setStatusCode(201);
    }

    /** Update a warehouse the token's workspace owns. Mirrors WarehousesController::update. */
    public function update(Request $request, int $warehouse)
    {
        $this->auth->authorize('warehouses.update');

        $tenant = $this->tenant($request);
        $model = Warehouse::query()->findOrFail($warehouse);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:30', $this->codeRule($request, $tenant->id, $model->id)],
            'address' => ['nullable', 'string', 'max:65535'],
            'is_active' => ['boolean'],
        ]);

        unset($validated['tenant_id']);

        $model->fill($validated)->save();

        AuditLogger::log('warehouse.updated', $model, ['name' => $model->name, 'code' => $model->code]);

        return WarehouseResource::make($model)->additional([
            'message' => 'Warehouse updated.',
        ]);
    }

    /**
     * Delete a warehouse, but only while nothing references it.
     *
     * Same guard as the web UI: a warehouse holding stock balances or a movement
     * history is a historical record and is refused rather than silently orphaning
     * stock rows.
     */
    public function destroy(Request $request, int $warehouse)
    {
        $this->auth->authorize('warehouses.delete');

        $model = Warehouse::query()->findOrFail($warehouse);

        if ($model->stockBalances()->exists() || $model->stockMovements()->exists()) {
            return response()->json([
                'message' => 'Warehouse has stock balances. Remove or transfer stock first.',
                'code' => 'warehouse_not_empty',
            ], 422);
        }

        AuditLogger::log('warehouse.deleted', $model, ['name' => $model->name, 'code' => $model->code]);

        $model->delete();

        return response()->json(['message' => 'Warehouse deleted.']);
    }

    /**
     * Unique-code rule scoped to the workspace (and to every row but this one).
     *
     * The web controller builds the same rule inline; both must agree or the API would
     * accept a code the UI rejects.
     */
    private function codeRule(Request $request, int $tenantId, ?int $ignoreId = null)
    {
        return Rule::unique('warehouses', 'code')
            ->where(fn ($query) => $query->where('tenant_id', $tenantId))
            ->ignore($ignoreId);
    }
}