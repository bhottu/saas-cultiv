<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\WarehouseResource;
use App\Models\Warehouse;
use Illuminate\Http\Request;

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
}