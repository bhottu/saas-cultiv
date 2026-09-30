<?php

namespace App\Http\Controllers\Admin;

use App\Models\Sale;
use App\Models\Tenant;
use Illuminate\Http\Request;

class SaleController extends AdminController
{
    public function index(Request $request)
    {
        $query = $this->acrossTenants(Sale::class)
            ->with(['tenant:id,name', 'customer:id,name'])
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

        if ($request->filled('payment_status')) {
            $query->whereIn('payment_status', (array) $request->input('payment_status'));
        }

        if ($request->filled('sales_channel')) {
            $query->where('sales_channel', $request->string('sales_channel')->toString());
        }

        if ($request->filled('from')) {
            $query->whereDate('sold_at', '>=', $request->string('from')->toString());
        }

        if ($request->filled('to')) {
            $query->whereDate('sold_at', '<=', $request->string('to')->toString());
        }

        return view('admin.sales.index', [
            // Read-only: no amount, status or line item is ever mutated from here.
            'sales' => $this->paginate($query->latest('sold_at')->orderByDesc('id'), $request),
            'filters' => $request->only(['search', 'tenant_id', 'status', 'payment_status', 'sales_channel', 'from', 'to']),
            'workspaces' => Tenant::orderBy('name')->get(['id', 'name']),
            'statuses' => Sale::STATUSES,
            'paymentStatuses' => [Sale::PAYMENT_UNPAID, Sale::PAYMENT_PARTIAL, Sale::PAYMENT_PAID, Sale::PAYMENT_REFUNDED],
        ]);
    }
}