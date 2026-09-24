<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Models\Category;
use App\Services\BusinessAuthorization;
use Illuminate\Http\Request;

/**
 * Category CRUD (tenant-scoped).
 */
class CategoriesController extends Controller
{
    public function __construct(private readonly BusinessAuthorization $auth) {}

    public function index(Request $request)
    {
        $this->auth->authorize('categories.view');

        $tenant = $request->user()->currentTenant;

        $categories = Category::withCount('products')
            ->where('tenant_id', $tenant->id)
            ->orderBy('name')
            ->paginate(50);

        return view('categories.index', [
            'categories' => $categories,
        ]);
    }

    public function create()
    {
        $this->auth->authorize('categories.create');

        return view('categories.form', [
            'category' => new Category(),
            'pageTitle' => 'Add Category',
            'submitUrl' => route('categories.store'),
        ]);
    }

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

        return redirect()->route('categories.index')->with('status', ['type' => 'success', 'message' => 'Category created.']);
    }

    public function edit(Category $category)
    {
        $this->auth->authorize('categories.update');
        $this->ensureOwned($category);

        return view('categories.form', [
            'category' => $category,
            'pageTitle' => 'Edit Category',
            'submitUrl' => route('categories.update', $category),
        ]);
    }

    public function update(Request $request, Category $category)
    {
        $this->auth->authorize('categories.update');
        $this->ensureOwned($category);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:65535',
            'is_active' => 'boolean',
        ]);

        $category->update($validated);

        AuditLogger::log('category.updated', $category, ['name' => $category->name]);

        return redirect()->route('categories.index')->with('status', ['type' => 'success', 'message' => 'Category updated.']);
    }

    public function destroy(Request $request, Category $category)
    {
        $this->auth->authorize('categories.delete');
        $this->ensureOwned($category);

        $usedBy = $category->products()->exists();
        if ($usedBy) {
            return back()->with('status', ['type' => 'error', 'message' => 'Category is used by products. Remove products first.']);
        }

        AuditLogger::log('category.deleted', $category, ['name' => $category->name]);
        $category->delete();

        return redirect()->route('categories.index')->with('status', ['type' => 'success', 'message' => 'Category deleted.']);
    }

    private function ensureOwned(Category $category): void
    {
        // Never trust the route id: compare against the *active* tenant context.
        if (! $category->tenant_id || $category->tenant_id !== app('tenant.context')->tenant()?->id) {
            abort(404);
        }
    }
}
