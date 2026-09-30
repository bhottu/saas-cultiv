<?php

namespace App\Http\Controllers\Admin;

use App\Models\Purchase;
use App\Models\Tenant;
use Illuminate\Http\Request;

class PurchaseController extends AdminController
{
    public function index(Request $request)
    {
        $query = $this->acrossTenants(Purchase::class)
            ->with(['tenant:id,name', 'supplier:id,name'])
            ->withCount('items');

        if (($term = $request->string('search')->toString()) !== '') {
            $query->where('invoice_number', 'like', "%{$term}%");
        }

        if ($this->idFilter($request, 'tenant_id')) {
            $query->where('tenant_id', $this->idFilter($request, 'tenant_id'));
        }

        if ($request->filled('status')) {
            $query->whereIn('status', (array) $request->input('status'));
        }

        if ($request->filled('from')) {
            $query->whereDate('ordered_at', '>=', $request->string('from')->toString());
        }

        if ($request->filled('to')) {
            $query->whereDate('ordered_at', '<=', $request->string('to')->toString());
        }

        return view('admin.purchases.index', [
            'purchases' => $this->paginate($query->orderByDesc('ordered_at')->orderByDesc('id'), $request),
            'filters' => $request->only(['search', 'tenant_id', 'status', 'from', 'to']),
            'workspaces' => Tenant::orderBy('name')->get(['id', 'name']),
            'statuses' => Purchase::STATUSES,
        ]);
    }
}