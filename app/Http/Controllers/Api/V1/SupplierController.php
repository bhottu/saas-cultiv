<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends ApiController
{
    public function index(Request $request)
    {
        $this->auth->authorize('suppliers.view');

        $query = Supplier::query()->withCount('purchases');

        $this->applySearch($query, $request, ['name', 'phone', 'email']);

        if (($active = $this->isActiveFilter($request)) !== null) {
            $query->where('is_active', $active);
        }

        $suppliers = $query->orderBy('name')->paginate($this->perPage($request))->withQueryString();

        return $this->withMeta(SupplierResource::collection($suppliers), $request);
    }

    public function show(Request $request, int $supplier)
    {
        $this->auth->authorize('suppliers.view');

        return SupplierResource::make(
            Supplier::query()->withCount('purchases')->findOrFail($supplier)
        );
    }
}