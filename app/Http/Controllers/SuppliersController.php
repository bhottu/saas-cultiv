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

        $query = Supplier::where('tenant_id', $tenant->id);

        if ($request->filled('search')) {
            $term = $request->getString('search');
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
            });
        }

        $suppliers = $query->latest('created_at')->paginate(50);

        return view('suppliers.index', [
            'suppliers' => $suppliers,
            'search' => $request->getString('search', ''),
        ]);
    }

    public function create()
    {
        $this->auth->authorize('suppliers.create');

        return view('suppliers.form', [
            'supplier' => new Supplier(),
            'pageTitle' => 'Add Supplier',
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

    public function edit(Supplier $supplier)
    {
        $this->auth->authorize('suppliers.update');
        $this->ensureOwned($supplier);

        return view('suppliers.form', [
            'supplier' => $supplier,
            'pageTitle' => 'Edit Supplier',
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
