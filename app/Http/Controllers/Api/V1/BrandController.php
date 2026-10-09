<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\BrandResource;
use App\Models\Brand;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BrandController extends ApiController
{
    public function index(Request $request)
    {
        $this->auth->authorize('brands.view');

        $query = Brand::query()->withCount('products');

        $this->applySearch($query, $request, ['name']);

        if (($active = $this->isActiveFilter($request)) !== null) {
            $query->where('is_active', $active);
        }

        $brands = $query->orderBy('name')->paginate($this->perPage($request))->withQueryString();

        return $this->withMeta(BrandResource::collection($brands), $request);
    }

    public function show(Request $request, int $brand)
    {
        $this->auth->authorize('brands.view');

        return BrandResource::make(Brand::query()->withCount('products')->findOrFail($brand));
    }

    /** Create a brand — the same rules as the web form, incl. per-workspace slug uniqueness. */
    public function store(Request $request)
    {
        $this->auth->authorize('brands.create');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => ['nullable', 'string', 'max:255', $this->uniqueSlug($request)],
            'description' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        $brand = Brand::create($validated);

        AuditLogger::log('brand.created', $brand, ['name' => $brand->name]);

        return BrandResource::make($brand)
            ->additional(['message' => 'Brand created.'])
            ->response()
            ->setStatusCode(201);
    }

    /** Update a brand (PUT/PATCH). Tenant-scoped lookup: a foreign id is a 404. */
    public function update(Request $request, int $brand)
    {
        $this->auth->authorize('brands.update');

        $model = Brand::query()->findOrFail($brand);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => ['nullable', 'string', 'max:255', $this->uniqueSlug($request, $model->id)],
            'description' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        $model->update($validated);

        AuditLogger::log('brand.updated', $model, ['name' => $model->name]);

        return BrandResource::make($model)->additional(['message' => 'Brand updated.']);
    }

    /** Slugs are unique PER WORKSPACE — the same scoped rule the web controller uses. */
    private function uniqueSlug(Request $request, ?int $ignoreId = null)
    {
        return Rule::unique('brands', 'slug')
            ->where(fn ($query) => $query->where('tenant_id', $this->tenant($request)->id))
            ->ignore($ignoreId);
    }
}