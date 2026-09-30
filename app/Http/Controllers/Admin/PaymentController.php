<?php

namespace App\Http\Controllers\Admin;

use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * QRIS / billing payment monitor.
 *
 * Reuses the existing payment + QRIS records. Only payment metadata is rendered:
 * the provider payload holds the QR string and any provider identifiers, and the
 * view deliberately never prints the API key, secret or webhook secret.
 */
class PaymentController extends AdminController
{
    public function index(Request $request)
    {
        $query = $this->acrossTenants(Payment::class)
            ->with(['user:id,name,email', 'tenant' => fn ($q) => $q->withTrashed()->select(['tenants.id', 'tenants.name'])]);

        if (($term = $request->string('search')->toString()) !== '') {
            $query->where(fn ($q) => $q->where('order_id', 'like', "%{$term}%")
                ->orWhere('provider_transaction_id', 'like', "%{$term}%"));
        }

        if ($this->idFilter($request, 'tenant_id')) {
            $query->where('tenant_id', $this->idFilter($request, 'tenant_id'));
        }

        if ($request->filled('status')) {
            $query->whereIn('status', (array) $request->input('status'));
        }

        if ($request->filled('provider')) {
            $query->where('provider', $request->string('provider')->toString());
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->string('from')->toString());
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->string('to')->toString());
        }

        return view('admin.payments.index', [
            'payments' => $this->paginate($query->latest('id'), $request),
            'filters' => $request->only(['search', 'tenant_id', 'status', 'provider', 'from', 'to']),
            'workspaces' => Tenant::withTrashed()->orderBy('name')->get(['id', 'name', 'deleted_at']),
            'statuses' => ['pending', 'paid', 'failed', 'expired', 'cancelled'],
        ]);
    }
}