<?php

namespace App\Http\Controllers;

use App\Models\ExpenseCategory;
use App\Services\AuditLogger;
use App\Services\BusinessAuthorization;
use Illuminate\Http\Request;

/**
 * Expense-category CRUD (tenant-scoped).
 *
 * The model, the `expense_categories` table and the FK on `expenses` have existed since
 * the business-finance migration, but nothing ever let a user create one: the expense
 * form offered a category dropdown that could only ever be empty. This controller is the
 * missing management surface.
 *
 * Deliberately a separate resource from CategoriesController, because these are two
 * different concepts: `categories` classifies PRODUCTS for the catalogue, while
 * `expense_categories` classifies SPEND. Sharing one table would mean creating a "Beverages"
 * category silently starts offering it as an expense category. They stay apart.
 *
 * Authorization reuses the existing expenses.* registry verbs rather than introducing a
 * new permission, so role configuration carries over unchanged.
 */
class ExpenseCategoriesController extends Controller
{
    public function __construct(private readonly BusinessAuthorization $auth) {}

    public function index()
    {
        $this->auth->authorize('expenses.view');

        return view('expense-categories.index', [
            'categories' => ExpenseCategory::query()
                ->withCount('expenses')
                ->orderBy('name')
                ->get(),
            // Passed in rather than resolved with @can() in the view: Gate is only
            // populated with the config/permissions.php registry, and these are
            // business actions from config/business.php, so @can('expenses.create')
            // would silently evaluate false for everybody.
            'canCreate' => $this->auth->can('expenses.create'),
            'canUpdate' => $this->auth->can('expenses.update'),
            'canDelete' => $this->auth->can('expenses.delete'),
        ]);
    }

    public function create()
    {
        $this->auth->authorize('expenses.create');

        return view('expense-categories.form', [
            'category' => new ExpenseCategory(['is_active' => true]),
            'pageTitle' => __('Add Expense Category'),
            'submitUrl' => route('expense-categories.store'),
        ]);
    }

    public function store(Request $request)
    {
        $this->auth->authorize('expenses.create');

        $validated = $request->validate($this->rules($request, new ExpenseCategory));

        // The global scope stamps tenant_id, so a category can only ever be written
        // into the caller's own workspace.
        $category = ExpenseCategory::create($validated);

        AuditLogger::log('expense_category.created', $category, ['name' => $category->name]);

        return redirect()->route('expense-categories.index')
            ->with('status', ['type' => 'success', 'message' => __('Expense category created.')]);
    }

    public function edit(ExpenseCategory $expenseCategory)
    {
        $this->auth->authorize('expenses.update');
        $this->ensureOwned($expenseCategory);

        return view('expense-categories.form', [
            'category' => $expenseCategory,
            'pageTitle' => __('Edit Expense Category'),
            'submitUrl' => route('expense-categories.update', $expenseCategory),
        ]);
    }

    public function update(Request $request, ExpenseCategory $expenseCategory)
    {
        $this->auth->authorize('expenses.update');
        $this->ensureOwned($expenseCategory);

        $expenseCategory->update($request->validate($this->rules($request, $expenseCategory)));

        AuditLogger::log('expense_category.updated', $expenseCategory, ['name' => $expenseCategory->name]);

        return redirect()->route('expense-categories.index')
            ->with('status', ['type' => 'success', 'message' => __('Expense category updated.')]);
    }

    public function destroy(ExpenseCategory $expenseCategory)
    {
        $this->auth->authorize('expenses.delete');
        $this->ensureOwned($expenseCategory);

        // The FK is nullOnDelete, so deleting a used category would silently strip the
        // category off historic expenses. Refuse instead, matching the categories
        // resource's behaviour.
        if ($expenseCategory->expenses()->exists()) {
            return back()->with('status', [
                'type' => 'error',
                'message' => __('This category is used by expenses. Deactivate it instead of deleting.'),
            ]);
        }

        AuditLogger::log('expense_category.deleted', $expenseCategory, ['name' => $expenseCategory->name]);

        $expenseCategory->delete();

        return redirect()->route('expense-categories.index')
            ->with('status', ['type' => 'success', 'message' => __('Expense category deleted.')]);
    }

    /**
     * `unique` is scoped to the workspace, mirroring the composite unique index on the
     * table, so two workspaces may both have "Utilities" without colliding.
     */
    private function rules(Request $request, ExpenseCategory $category): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                \Illuminate\Validation\Rule::unique('expense_categories', 'name')
                    ->where(fn ($query) => $query->where('tenant_id', $request->user()->currentTenant->id))
                    // $category is unsaved on create, so its id is null and nothing is
                    // excluded — which is exactly the rule wanted for a brand new name.
                    ->ignore($category->exists ? $category->id : null),
            ],
            'is_active' => ['boolean'],
        ];
    }

    private function ensureOwned(ExpenseCategory $category): void
    {
        // Never trust the route id: compare against the *active* tenant context.
        if (! $category->tenant_id || $category->tenant_id !== app('tenant.context')->tenant()?->id) {
            abort(404);
        }
    }
}