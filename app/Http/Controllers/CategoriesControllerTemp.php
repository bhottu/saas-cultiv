    public function index(Request $request)
    {
        $this->auth->authorize('categories.view');

        $tenant = $request->user()->currentTenant;

        $query = Category::withCount(['products', 'childCategories'])
            ->where('tenant_id', $tenant->id);

        $categories = $query->orderBy('position')->paginate(50);

        return view('categories.index', [
            'categories' => $categories,
        ]);
    }

    public function create()
    {
        $this->auth->authorize('categories.create');

        $tenant = request()->user()->currentTenant;

        return view('categories.form', [
            'category' => new Category(),
            'maxPosition' => Category::where('tenant_id', $tenant->id)->max('position') ?? 0,
            'availableParents' => Category::where('tenant_id', $tenant->id)->get(),
            'pageTitle' => 'Add Category',
        ]);
    }

    public function store(Request $request)
    {
        $this->auth->authorize('categories.create');

        $tenantId = $this->tenantId($request);

        $input = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:categories,slug,tenant_id,'.$tenantId,
            'description' => 'nullable|string|max:255',
            'parent_id' => 'nullable|integer|exists:categories,id',
            'position' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        $parentId = $input['parent_id'];

        $category = DB::transaction(function () use ($tenantId, $input, $parentId) {
            return Category::create([
                'tenant_id' => $tenantId,
                'name' => $input['name'],
                'slug' => $input['slug'] ?? Str::slug($input['name']),
                'description' => $input['description'],
                'parent_id' => $parentId,
                'position' => $input['position'] ?? ($this->nextPosition($tenantId) + 1),
                'is_active' => (bool) ($input['is_active'] ?? true),
                'lft' => Category::nextTreeLeft($tenantId),
                'rgt' => Category::nextTreeRight($tenantId),
                'depth' => $parentId ? Category::withoutGlobalScopes()->findOrFail($parentId)->depth + 1 : 0,
            ]);
        });

        AuditLogger::log('category.created', $category, ['name' => $category->name]);

        return redirect()->route('categories.index')->with('status', ['type' => 'success', 'message' => 'Category created.']);
    }

    public function edit(Category $category)
    {
        $this->auth->authorize('categories.update');
        $this->ensureOwned($category);

        $tenant = $category->tenant;

        return view('categories.form', [
            'category' => $category,
            'maxPosition' => Category::where('tenant_id', $tenant->id)->max('position') ?? 0,
            'availableParents' => Category::where('tenant_id', $tenant->id)->where('id', '!=', $category->id)->get(),
            'pageTitle' => 'Edit Category',
        ]);
    }

    public function update(Request $request, Category $category)
    {
        $this->auth->authorize('categories.update');
        $this->ensureOwned($category);

        $tenantId = $this->tenantId($request);

        $input = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:categories,slug,tenant_id,'.$tenantId.',id,'.$category->id,
            'description' => 'nullable|string|max:255',
            'parent_id' => 'nullable|integer|exists:categories,id',
            'position' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        $category->update([
            'name' => $input['name'],
            'slug' => $input['slug'] ?? Str::slug($input['name']),
            'description' => $input['description'],
            'parent_id' => $input['parent_id'] ?? $category->parent_id,
            'position' => $input['position'] ?? $category->position,
            'is_active' => (bool) ($input['is_active'] ?? true),
        ]);

        AuditLogger::log('category.updated', $category, ['name' => $category->name]);

        return redirect()->route('categories.index')->with('status', ['type' => 'success', 'message' => 'Category updated.']);
    }

    public function destroy(Request $request, Category $category)
    {
        $this->auth->authorize('categories.delete');
        $this->ensureOwned($category);

        if ($category->products()->exists()) {
            return back()->with('status', ['type' => 'error', 'message' => 'Cannot delete a category with products.']);
        }

        if ($category->childCategories()->exists()) {
            return back()->with('status', ['type' => 'error', 'message' => 'Cannot delete a category with subcategories.']);
        }

        AuditLogger::log('category.deleted', $category, ['name' => $category->name]);
        $category->delete();

        return redirect()->route('categories.index')->with('status', ['type' => 'success', 'message' => 'Category deleted.']);
    }

    private function tenantId(Request $request): int
    {
        return $request->user()->currentTenant->id;
    }

    private function nextPosition(int $tenantId): int
    {
        return Category::where('tenant_id', $tenantId)->max('position') ?? 0;
    }

    private function ensureOwned(Category $category): void
    {
        if ($category->tenant_id !== $category->tenant?->id) {
            abort(404);
        }
    }
}

use App\Services\AuditLogger;
use App\Models\Category;
use App\Services\BusinessAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CategoriesController extends Controller
{
    public function __construct(private readonly BusinessAuthorization $auth) {}

    public function index(Request $request)
    {
        $this->auth->authorize('categories.view');

        $tenant = $request->user()->currentTenant;

        $query = Category::withCount(['products', 'childCategories'])
            ->where('tenant_id', $tenant->id);

        $categories = $query->orderBy('position')->paginate(50);

        return view('categories.index', [
            'categories' => $categories,
        ]);
    }

    public function create()
    {
        $this->auth->authorize('categories.create');

        $tenant = request()->user()->currentTenant;

        return view('categories.form', [
            'category' => new Category(),
            'maxPosition' => Category::where('tenant_id', $tenant->id)->max('position') ?? 0,
            'availableParents' => Category::where('tenant_id', $tenant->id)->get(),
            'pageTitle' => 'Add Category',
        ]);
    }

    public function store(Request $request)
    {
        $this->auth->authorize('categories.create');

        $tenantId = $this->tenantId($request);

        $input = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:categories,slug,tenant_id,'.$tenantId,
            'description' => 'nullable|string|max:255',
            'parent_id' => 'nullable|integer|exists:categories,id',
            'position' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        $parentId = $input['parent_id'];

        $category = DB::transaction(function () use ($tenantId, $input, $parentId) {
            return Category::create([
                'tenant_id' => $tenantId,
                'name' => $input['name'],
                'slug' => $input['slug'] ?? Str::slug($input['name']),
                'description' => $input['description'],
                'parent_id' => $parentId,
                'position' => $input['position'] ?? ($this->nextPosition($tenantId) + 1),
                'is_active' => (bool) ($input['is_active'] ?? true),
                'lft' => Category::nextTreeLeft($tenantId),
                'rgt' => Category::nextTreeRight($tenantId),
                'depth' => $parentId ? Category::withoutGlobalScopes()->findOrFail($parentId)->depth + 1 : 0,
            ]);
        });

        AuditLogger::log('category.created', $category, ['name' => $category->name]);

        return redirect()->route('categories.index')->with('status', ['type' => 'success', 'message' => 'Category created.']);
    }

    public function edit(Category $category)
    {
        $this->auth->authorize('categories.update');
        $this->ensureOwned($category);

        $tenant = $category->tenant;

        return view('categories.form', [
            'category' => $category,
            'maxPosition' => Category::where('tenant_id', $tenant->id)->max('position') ?? 0,
            'availableParents' => Category::where('tenant_id', $tenant->id)->where('id', '!=', $category->id)->get(),
            'pageTitle' => 'Edit Category',
        ]);
    }

    public function update(Request $request, Category $category)
    {
        $this->auth->authorize('categories.update');
        $this->ensureOwned($category);

        $tenantId = $this->tenantId($request);

        $input = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:categories,slug,tenant_id,'.$tenantId.',id,'.$category->id,
            'description' => 'nullable|string|max:255',
            'parent_id' => 'nullable|integer|exists:categories,id',
            'position' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        $category->update([
            'name' => $input['name'],
            'slug' => $input['slug'] ?? Str::slug($input['name']),
            'description' => $input['description'],
            'parent_id' => $input['parent_id'] ?? $category->parent_id,
            'position' => $input['position'] ?? $category->position,
            'is_active' => (bool) ($input['is_active'] ?? true),
        ]);

        AuditLogger::log('category.updated', $category, ['name' => $category->name]);

        return redirect()->route('categories.index')->with('status', ['type' => 'success', 'message' => 'Category updated.']);
    }

    public function destroy(Request $request, Category $category)
    {
        $this->auth->authorize('categories.delete');
        $this->ensureOwned($category);

        if ($category->products()->exists()) {
            return back()->with('status', ['type' => 'error', 'message' => 'Cannot delete a category with products.']);
        }

        if ($category->childCategories()->exists()) {
            return back()->with('status', ['type' => 'error', 'message' => 'Cannot delete a category with subcategories.']);
        }

        AuditLogger::log('category.deleted', $category, ['name' => $category->name]);
        $category->delete();

        return redirect()->route('categories.index')->with('status', ['type' => 'success', 'message' => 'Category deleted.']);
    }

    private function tenantId(Request $request): int
    {
        return $request->user()->currentTenant->id;
    }

    private function nextPosition(int $tenantId): int
    {
        return Category::where('tenant_id', $tenantId)->max('position') ?? 0;
    }

    private function ensureOwned(Category $category): void
    {
        if ($category->tenant_id !== $category->tenant?->id) {
            abort(404);
        }
    }
}
