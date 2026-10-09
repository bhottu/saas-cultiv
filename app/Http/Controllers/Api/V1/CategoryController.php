<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\AuditLogger;
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

    /**
     * Create a category with the SAME validation the web form applies (§15).
     *
     * tenant_id is never read from the payload: BelongsToTenant stamps it from the
     * token's workspace context, so a client cannot create a row in another tenant.
     */
    public function store(Request $request)
    {
        $this->auth->authorize('categories.create');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:65535',
            'is_active' => 'boolean',
        ]);

        $category = Category::create($validated);

        AuditLogger::log('category.created', $category, ['name' => $category->name]);

        return CategoryResource::make($category)
            ->additional(['message' => 'Category created.'])
            ->response()
            ->setStatusCode(201);
    }

    /** Update a category (PUT/PATCH). Foreign workspace id ⇒ 404 via tenant scope. */
    public function update(Request $request, int $category)
    {
        $this->auth->authorize('categories.update');

        $model = Category::query()->findOrFail($category);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:65535',
            'is_active' => 'boolean',
        ]);

        $model->update($validated);

        AuditLogger::log('category.updated', $model, ['name' => $model->name]);

        return CategoryResource::make($model)->additional(['message' => 'Category updated.']);
    }
}