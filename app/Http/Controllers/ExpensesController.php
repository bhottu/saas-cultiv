<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\BusinessAuthorization;
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
            $query->whereDate('expense_date', '>=', $request->getString('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('expense_date', '<=', $request->getString('to'));
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
            'categories' => ExpenseCategory::where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'pageTitle' => 'Add Expense',
            'submitUrl' => route('expenses.store'),
        ]);
    }

    public function store(Request $request)
    {
        $this->auth->authorize('expenses.create');

        $validated = $request->validate([
            'category_id' => 'nullable|exists:expense_categories,id',
            'description' => 'required|string|max:255',
            'amount' => 'required|integer|min:1',
            'expense_date' => 'required|date',
            'payment_method' => 'nullable|in:cash,bank,transfer,qris,other',
            'notes' => 'nullable|string|max:65535',
        ]);

        $tenant = $request->user()->currentTenant;

        $expense = DB::transaction(function () use ($tenant, $validated) {
            return Expense::create([
                'tenant_id' => $tenant->id,
                'category_id' => $validated['category_id'],
                'description' => $validated['description'],
                'amount' => (int) $validated['amount'],
                'expense_date' => $validated['expense_date'],
                'payment_method' => $validated['payment_method'],
                'notes' => $validated['notes'],
                'created_by' => auth()->id(),
            ]);
        });

        AuditLogger::log('expense.created', $expense, ['amount' => $expense->amount, 'category' => $expense->category?->name]);

        return redirect()->route('expenses.index')->with('status', ['type' => 'success', 'message' => 'Expense recorded.']);
    }

    public function edit(Expense $expense)
    {
        $this->auth->authorize('expenses.update');
        $this->ensureOwned($expense);

        $tenant = request()->user()->currentTenant;

        return view('expenses.form', [
            'expense' => $expense->load('category'),
            'categories' => ExpenseCategory::where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'pageTitle' => 'Edit Expense',
            'submitUrl' => route('expenses.update', $expense),
        ]);
    }

    public function update(Request $request, Expense $expense)
    {
        $this->auth->authorize('expenses.update');
        $this->ensureOwned($expense);

        $validated = $request->validate([
            'category_id' => 'nullable|exists:expense_categories,id',
            'description' => 'required|string|max:255',
            'amount' => 'required|integer|min:1',
            'expense_date' => 'required|date',
            'payment_method' => 'nullable|in:cash,bank,transfer,qris,other',
            'notes' => 'nullable|string|max:65535',
        ]);

        $expense->update($validated);

        AuditLogger::log('expense.updated', $expense, ['amount' => $expense->amount]);

        return redirect()->route('expenses.index')->with('status', ['type' => 'success', 'message' => 'Expense updated.']);
    }

    public function destroy(Request $request, Expense $expense)
    {
        $this->auth->authorize('expenses.delete');
        $this->ensureOwned($expense);

        AuditLogger::log('expense.deleted', $expense, ['amount' => $expense->amount]);

        $expense->delete();

        return redirect()->route('expenses.index')->with('status', ['type' => 'success', 'message' => 'Expense deleted.']);
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
