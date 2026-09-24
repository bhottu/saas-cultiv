<?php

namespace App\Http\Controllers;

use App\Models\BusinessInvoice;
use App\Services\BusinessAuthorization;
use Illuminate\Http\Request;

class BusinessInvoicesController extends Controller
{
    public function __construct(private readonly BusinessAuthorization $auth) {}

    public function index(Request $request)
    {
        $this->auth->authorize('sales.view');

        $tenant = $request->user()->currentTenant;

        $query = BusinessInvoice::with(['customer', 'sale', 'supplier', 'purchase']);

        if ($request->filled('type')) {
            $type = $request->getString('type');
            if ($type === 'sale') {
                $query = $query->whereNotNull('sale_id');
            } elseif ($type === 'purchase') {
                $query = $query->whereNotNull('purchase_id');
            } else {
                $query = $query->whereNull('sale_id')->whereNull('purchase_id');
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->getString('status'));
        }

        if ($request->filled('from')) {
            $query->whereDate('issued_at', '>=', $request->getString('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('issued_at', '<=', $request->getString('to'));
        }

        $invoices = $query->latest('issued_at')->paginate(50);

        return view('invoices.index', [
            'invoices' => $invoices,
            'filters' => $request->only(['type', 'status', 'from', 'to']),
        ]);
    }

    public function show(BusinessInvoice $invoice)
    {
        $this->auth->authorize('sales.view');
        $this->ensureOwned($invoice);

        $invoice->load(['customer', 'supplier', 'sale.items.product', 'purchase.items.product', 'payments']);

        return view('invoices.show', ['invoice' => $invoice]);
    }

    private function ensureOwned(BusinessInvoice $invoice): void
    {
        // Never trust the route id: compare against the *active* tenant context.
        if (! $invoice->tenant_id || $invoice->tenant_id !== app('tenant.context')->tenant()?->id) {
            abort(404);
        }
    }
}
