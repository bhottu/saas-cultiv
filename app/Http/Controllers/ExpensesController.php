<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Tenant;
use App\Services\BusinessAuthorization;
use App\Services\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpensesController extends Controller
{
    public function __construct(private readonly BusinessAuthorization $auth) {}

    public function index(Request $request)
    {
        $this->auth->authorize('expenses.view');

        $tenant = $request->user()->currentTenant;

        $query = Expense::with(['category', 'createdBy'])->where('tenant_id', $tenant->id);

        if ($request->filled('from')) {
            $query->whereDate('expense_date', '>=', $request->string('from')->toString());
        }

        if ($request->filled('to')) {
            $query->whereDate('expense_date', '<=', $request->string('to')->toString());
        }

        $expenses = $query->latest('expense_date')->paginate(50);

        return view('expenses.index', [
            'expenses' => $expenses,
            'categories' => ExpenseCategory::where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'filters' => $request->only(['from', 'to']),
        ]);
    }

    public function create()
    {
        $this->auth->authorize('expenses.create');

        $tenant = request()->user()->currentTenant;

        return view('expenses.form', [
            'expense' => new Expense(),
            'categories' => $this->activeCategories($tenant),
            // The dropdown alone never explained where categories come from. This flag
            // lets the form offer the management link only when it would actually work.
            'canManageCategories' => $this->auth->can('expenses.update') || $this->auth->can('expenses.create'),
            'pageTitle' => __('Add Expense'),
            'submitUrl' => route('expenses.store'),
        ]);
    }

    /**
     * Active categories for the picker.
     *
     * Deactivated categories are hidden so they cannot be attached to new expenses,
     * matching how suppliers and warehouses are filtered. `expense_categories` is a
     * separate concept from the product `categories` and is never mixed with it.
     */
    private function activeCategories(Tenant $tenant)
    {
        return ExpenseCategory::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request)
    {
        $this->auth->authorize('expenses.create');

        $validated = $request->validate($this->rules($request));

        $tenant = $request->user()->currentTenant;

        $expense = DB::transaction(function () use ($tenant, $validated) {
            return Expense::create([
                'tenant_id' => $tenant->id,
                // `category_id` is nullable, so Laravel omits the key entirely when the
                // field is left blank. Reading $validated['category_id'] directly threw
                // "Undefined array key" and turned every category-less expense into a 500.
                'category_id' => $validated['category_id'] ?? null,
                'description' => $validated['description'],
                'amount' => \App\Services\Money::centsFromDisplay($validated['amount']),
                'expense_date' => $validated['expense_date'],
                'payment_method' => $validated['payment_method'] ?? 'cash',
                'notes' => $validated['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);
        });

        AuditLogger::log('expense.created', $expense, ['amount' => $expense->amount, 'category' => $expense->category?->name]);

        return redirect()->route('expenses.index')->with('status', ['type' => 'success', 'message' => __('Expense recorded.')]);
    }

    public function show(Expense $expense)
    {
        $this->auth->authorize('expenses.view');
        $this->ensureOwned($expense);

        return view('expenses.show', [
            'expense' => $expense->load(['category', 'createdBy']),
        ]);
    }

    public function edit(Expense $expense)
    {
        $this->auth->authorize('expenses.update');
        $this->ensureOwned($expense);

        $tenant = request()->user()->currentTenant;

        // Same reasoning as the purchase form: a category deactivated after the expense
        // was recorded still belongs to it, so it must stay selectable or saving would
        // silently drop the association.
        $categories = $this->activeCategories($tenant);

        if ($expense->category && ! $categories->contains('id', $expense->category_id)) {
            $categories = $categories->concat(collect([$expense->category]));
        }

        return view('expenses.form', [
            'expense' => $expense->load('category'),
            'categories' => $categories,
            'canManageCategories' => $this->auth->can('expenses.update') || $this->auth->can('expenses.create'),
            'pageTitle' => __('Edit Expense'),
            'submitUrl' => route('expenses.update', $expense),
        ]);
    }

    public function update(Request $request, Expense $expense)
    {
        $this->auth->authorize('expenses.update');
        $this->ensureOwned($expense);

        $validated = $request->validate($this->rules($request));

        $expense->update(array_merge($validated, [
            'amount' => \App\Services\Money::centsFromDisplay($validated['amount']),
        ]));

        AuditLogger::log('expense.updated', $expense, ['amount' => $expense->amount]);

        return redirect()->route('expenses.index')->with('status', ['type' => 'success', 'message' => __('Expense updated.')]);
    }

    public function destroy(Request $request, Expense $expense)
    {
        $this->auth->authorize('expenses.delete');
        $this->ensureOwned($expense);

        AuditLogger::log('expense.deleted', $expense, ['amount' => $expense->amount]);

        $expense->delete();

        return redirect()->route('expenses.index')->with('status', ['type' => 'success', 'message' => __('Expense deleted.')]);
    }

    private function rules(Request $request): array
    {
        return [
            'category_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('expense_categories', 'id')->where(fn ($query) => $query->where('tenant_id', $request->user()->currentTenant->id))],
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'expense_date' => 'required|date',
            'payment_method' => 'nullable|in:cash,bank,transfer,qris,other',
            'notes' => 'nullable|string|max:65535',
        ];
    }

    private function tenantId(Request $request): int
    {
        return $request->user()->currentTenant->id;
    }

    private function ensureOwned(Expense $expense): void
    {
        // Never trust the route id: compare against the *active* tenant context.
        if (! $expense->tenant_id || $expense->tenant_id !== app('tenant.context')->tenant()?->id) {
            abort(404);
        }
    }
}
