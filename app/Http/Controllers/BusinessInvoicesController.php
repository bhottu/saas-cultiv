<?php

namespace App\Http\Controllers;

use App\Models\BusinessInvoice;
use App\Services\AuditLogger;
use App\Services\BusinessAuthorization;
use App\Services\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusinessInvoicesController extends Controller
{
    public function __construct(private readonly BusinessAuthorization $auth) {}

    public function index(Request $request)
    {
        $this->auth->authorize('sales.view');

        $tenant = $request->user()->currentTenant;

        $query = BusinessInvoice::with(['customer', 'sale', 'supplier', 'purchase']);

        if ($request->filled('type')) {
            $type = $request->string('type')->toString();
            if ($type === 'sale') {
                $query = $query->whereNotNull('sale_id');
            } elseif ($type === 'purchase') {
                $query = $query->whereNotNull('purchase_id');
            } else {
                $query = $query->whereNull('sale_id')->whereNull('purchase_id');
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('from')) {
            $query->whereDate('issued_at', '>=', $request->string('from')->toString());
        }

        if ($request->filled('to')) {
            $query->whereDate('issued_at', '<=', $request->string('to')->toString());
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

    public function pay(Request $request, BusinessInvoice $invoice)
    {
        $this->auth->authorize('payments.create');
        $this->ensureOwned($invoice);
        abort_if($invoice->status === 'cancelled', 422, 'Cancelled invoices cannot receive payment.');

        $data = $request->validate([
            'method' => ['required', 'string', 'max:30'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:65535'],
        ]);
        $amount = Money::centsFromDisplay($data['amount']);
        abort_if($amount > $invoice->outstanding, 422, 'Payment cannot exceed the outstanding amount.');

        DB::transaction(function () use ($request, $invoice, $data, $amount) {
            $locked = BusinessInvoice::withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->id);
            abort_if($amount > $locked->outstanding, 422, 'Payment cannot exceed the outstanding amount.');
            $locked->payments()->create(['tenant_id' => $locked->tenant_id, 'method' => $data['method'],
                'amount' => $amount, 'reference' => $data['reference'] ?? null,
                'paid_at' => $data['paid_at'] ?? now(), 'created_by' => $request->user()->id, 'notes' => $data['notes'] ?? null]);
            $paid = (int) $locked->payments()->sum('amount');
            $locked->update(['amount_paid' => $paid, 'outstanding' => max(0, (int) $locked->total - $paid),
                'status' => $paid >= (int) $locked->total ? 'paid' : 'partial',
                'paid_at' => $paid >= (int) $locked->total ? now() : null]);
        });

        AuditLogger::log('business_invoice.payment.created', $invoice, ['amount' => $amount, 'method' => $data['method']]);
        return back()->with('status', ['type' => 'success', 'message' => 'Invoice payment recorded.']);
    }

    private function ensureOwned(BusinessInvoice $invoice): void
    {
        // Never trust the route id: compare against the *active* tenant context.
        if (! $invoice->tenant_id || $invoice->tenant_id !== app('tenant.context')->tenant()?->id) {
            abort(404);
        }
    }
}
