<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends ApiController
{
    public function index(Request $request)
    {
        $this->auth->authorize('categories.view');

        $query = Category::query()->withCount('products');

        $this->applySearch($query, $request, ['name']);

        if (($active = $this->isActiveFilter($request)) !== null) {
            $query->where('is_active', $active);
        }

        $categories = $query->orderBy('name')->paginate($this->perPage($request))->withQueryString();

        return $this->withMeta(CategoryResource::collection($categories), $request);
    }

    public function show(Request $request, int $category)
    {
        $this->auth->authorize('categories.view');

        return CategoryResource::make(
            Category::query()->withCount('products')->findOrFail($category)
        );
    }
}