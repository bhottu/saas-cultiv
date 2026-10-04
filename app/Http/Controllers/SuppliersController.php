<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Models\Supplier;
use App\Services\BusinessAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuppliersController extends Controller
{
    public function __construct(private readonly BusinessAuthorization $auth) {}

    public function index(Request $request)
    {
        $this->auth->authorize('suppliers.view');

        $tenant = $request->user()->currentTenant;

        $query = Supplier::where('tenant_id', $tenant->id)
            ->search($request->string('search')->toString());

        // withQueryString() so the pager keeps the active search. Without it, page 2
        // silently dropped the keyword and showed the unfiltered list from the top —
        // the classic "pagination ignores my filter" bug.
        $suppliers = $query->latest('created_at')->paginate(50)->withQueryString();

        return view('suppliers.index', [
            'suppliers' => $suppliers,
            'search' => $request->string('search')->toString(),
        ]);
    }

    public function create()
    {
        $this->auth->authorize('suppliers.create');

        return view('suppliers.form', [
            'supplier' => new Supplier(['is_active' => true]),
            'pageTitle' => 'Add Supplier',
            'submitUrl' => route('suppliers.store'),
        ]);
    }

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

        $supplier = DB::transaction(function () use ($validated) {
            return Supplier::create(array_merge($validated, [
                'tenant_id' => auth()->user()->currentTenant->id,
            ]));
        });

        AuditLogger::log('supplier.created', $supplier, ['name' => $supplier->name]);

        return redirect()->route('suppliers.index')->with('status', [
            'type' => 'success',
            'message' => 'Supplier created.',
        ]);
    }

    public function show(Supplier $supplier)
    {
        $this->auth->authorize('suppliers.view');
        $this->ensureOwned($supplier);

        return view('suppliers.show', [
            'supplier' => $supplier,
            'purchases' => $supplier->purchases()->latest('ordered_at')->paginate(25),
        ]);
    }

    public function edit(Supplier $supplier)
    {
        $this->auth->authorize('suppliers.update');
        $this->ensureOwned($supplier);

        return view('suppliers.form', [
            'supplier' => $supplier,
            'pageTitle' => 'Edit Supplier',
            'submitUrl' => route('suppliers.update', $supplier),
        ]);
    }

    public function update(Request $request, Supplier $supplier)
    {
        $this->auth->authorize('suppliers.update');
        $this->ensureOwned($supplier);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:65535',
            'is_active' => 'boolean',
        ]);

        $supplier->update($validated);

        AuditLogger::log('supplier.updated', $supplier, ['name' => $supplier->name]);

        return redirect()->route('suppliers.index')->with('status', [
            'type' => 'success',
            'message' => 'Supplier updated.',
        ]);
    }

    public function destroy(Request $request, Supplier $supplier)
    {
        $this->auth->authorize('suppliers.delete');
        $this->ensureOwned($supplier);

        if ($supplier->purchases()->where('status', 'received')->exists()) {
            return back()->with('status', [
                'type' => 'error',
                'message' => 'Cannot delete a supplier with received purchases.',
            ]);
        }

        AuditLogger::log('supplier.deleted', $supplier, ['name' => $supplier->name]);

        $supplier->delete();

        return redirect()->route('suppliers.index')->with('status', [
            'type' => 'success',
            'message' => 'Supplier deleted.',
        ]);
    }

    private function ensureOwned(Supplier $supplier): void
    {
        // Never trust the route id: compare against the *active* tenant context.
        if (! $supplier->tenant_id || $supplier->tenant_id !== app('tenant.context')->tenant()?->id) {
            abort(404);
        }
    }
}
