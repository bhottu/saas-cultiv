<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\BrandResource;
use App\Models\Brand;
use Illuminate\Http\Request;

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
}