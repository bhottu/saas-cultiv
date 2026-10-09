<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use App\Services\AuditLogger;
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

    /** Create a supplier — the same validation the web form applies (§15). */
    public function store(Request $request)
    {
        $this->auth->authorize('suppliers.create');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:65535',
            'is_active' => 'boolean',
        ]);

        $supplier = Supplier::create($validated);

        AuditLogger::log('supplier.created', $supplier, ['name' => $supplier->name]);

        return SupplierResource::make($supplier)
            ->additional(['message' => 'Supplier created.'])
            ->response()
            ->setStatusCode(201);
    }

    /** Update a supplier (PUT/PATCH). Tenant-scoped lookup: a foreign id is a 404. */
    public function update(Request $request, int $supplier)
    {
        $this->auth->authorize('suppliers.update');

        $model = Supplier::query()->findOrFail($supplier);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:65535',
            'is_active' => 'boolean',
        ]);

        $model->update($validated);

        AuditLogger::log('supplier.updated', $model, ['name' => $model->name]);

        return SupplierResource::make($model)->additional(['message' => 'Supplier updated.']);
    }
}