<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\BusinessInvoice;
use App\Models\BusinessPayment;
use App\Models\Customer;
use App\Models\Warehouse;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\UsageRecord;
use App\Services\BusinessAuthorization;
use App\Services\BusinessUsageService;
use App\Services\CalculateSaleTotals;
use App\Services\DocumentNumberingService;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sales (POS + back-office).
 *
 * A sale is the business-sales domain, separate from SaaS subscription billing.
 * Sales are tenant-scoped via BelongsToTenant.
 *
 * Flow:
 *   validate customer
 *   validate products
 *   lock + deduct stock (InventoryService::fulfill, transactional)
 *   create sale + sale_items (prices snapshotted on line items)
 *   create business invoice + business payment record (if paid)
 *   commit
 *
 * If anything fails, the whole transaction rolls back.
 */
class SalesController extends Controller
{
    public function __construct(
        private readonly BusinessAuthorization $auth,
        private readonly InventoryService $inventory,
        private readonly DocumentNumberingService $numbering,
        private readonly CalculateSaleTotals $calculator,
        private readonly BusinessUsageService $usage,
    ) {}

    public function index(Request $request)
    {
        $this->auth->authorize('sales.view');

        $tenant = $request->user()->currentTenant;

        $query = Sale::with(['customer', 'createdBy', 'invoice']);

        if ($request->filled('status')) {
            $query->where('status', $request->getString('status'));
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', (int) $request->getString('customer_id'));
        }

        if ($request->filled('from')) {
            $query->whereDate('sold_at', '>=', $request->getString('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('sold_at', '<=', $request->getString('to'));
        }

        $sales = $query->latest('sold_at')->paginate(50);

        return view('sales.index', [
            'sales' => $sales,
            'customers' => $tenant->customers()->active()->orderBy('name')->get(),
            'statuses' => ['draft', 'completed', 'cancelled', 'refunded'],
            'filters' => $request->only(['status', 'customer_id', 'from', 'to']),
        ]);
    }

    public function show(Sale $sale)
    {
        $this->auth->authorize('sales.view');
        $this->ensureOwned($sale);

        return view('sales.show', [
            'sale' => $sale->load(['customer', 'createdBy', 'invoice', 'items.product']),
        ]);
    }

    public function cancel(Sale $sale)
    {
        $this->auth->authorize('sales.cancel');
        $this->ensureOwned($sale);

        if ($sale->status !== 'completed') {
            return back()->with('status', ['type' => 'error', 'message' => 'Only completed sales can be cancelled.']);
        }

        $sale->cancel();

        AuditLogger::log('sale.cancelled', $sale, ['invoice_number' => $sale->invoice_number]);

        return redirect()->route('sales.show', $sale)->with('status', ['type' => 'success', 'message' => 'Sale cancelled.']);
    }

    public function refund(Sale $sale)
    {
        $this->auth->authorize('sales.refund');
        $this->ensureOwned($sale);

        if ($sale->status !== 'completed') {
            return back()->with('status', ['type' => 'error', 'message' => 'Only completed sales can be refunded.']);
        }

        $sale->refund();

        AuditLogger::log('sale.refunded', $sale, ['invoice_number' => $sale->invoice_number]);

        return redirect()->route('sales.show', $sale)->with('status', ['type' => 'success', 'message' => 'Sale refunded.']);
    }

    private function ensureOwned(Sale $sale): void
    {
        // Never trust the route id: compare against the *active* tenant context.
        if (! $sale->tenant_id || $sale->tenant_id !== app('tenant.context')->tenant()?->id) {
            abort(404);
        }
    }
}
