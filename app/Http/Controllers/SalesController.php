<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\StockBalance;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\AuditLogger;
use App\Services\BusinessAuthorization;
use App\Services\BusinessUsageService;
use App\Services\CalculateSaleTotals;
use App\Services\DocumentNumberingService;
use App\Services\InventoryService;
use App\Services\Money;
use App\Services\SaleReturnService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Sales (POS + back-office).
 *
 * A sale is the BUSINESS-sales domain, deliberately separate from SaaS subscription billing.
 * Everything is tenant-scoped: models use BelongsToTenant, every product/customer/warehouse is
 * validated against the active tenant, and route models are re-checked against the tenant
 * context so a foreign id can never be read or modified.
 *
 * Flow (store):
 *   validate input (products/customers/warehouses must belong to this tenant)
 *   compute totals server-side (CalculateSaleTotals — the browser preview is never trusted)
 *   create sale + sale_items (selling/cost price SNAPSHOTTED per line)
 *   deduct stock through InventoryService (one movement per line, inside the transaction)
 *   record the payment (unpaid/partial/cash-with-change are all supported)
 *   commit — any failure rolls everything back, so stock and money can never disagree.
 */
class SalesController extends Controller
{
    public function __construct(
        private readonly BusinessAuthorization $auth,
        private readonly InventoryService $inventory,
        private readonly DocumentNumberingService $numbering,
        private readonly CalculateSaleTotals $calculator,
        private readonly BusinessUsageService $usage,
        private readonly SaleReturnService $returns,
    ) {}

    // ------------------------------------------------------------------ dashboard

    /** Sales dashboard: today/month KPIs, 7-day trend, recent sales and top products. */
    public function dashboard(Request $request)
    {
        $this->auth->authorize('sales.view');

        $todayStart = now()->startOfDay();
        $monthStart = now()->startOfMonth();

        $monthTotals = Sale::query()->revenue()->where('sold_at', '>=', $monthStart)
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(total), 0) as total, COALESCE(SUM(subtotal - discount), 0) as revenue, COALESCE(SUM(total_cogs), 0) as cogs, COALESCE(SUM(refunded_amount), 0) as refunded')
            ->first();

        $summary = [
            'sales_today'      => (int) Sale::query()->revenue()->where('sold_at', '>=', $todayStart)->sum('total'),
            'orders_today'     => (int) Sale::query()->revenue()->where('sold_at', '>=', $todayStart)->count(),
            'sales_month'      => (int) ($monthTotals->total ?? 0),
            'orders_month'     => (int) ($monthTotals->orders ?? 0),
            'paid_orders'      => (int) Sale::query()->revenue()->where('sold_at', '>=', $monthStart)
                ->where('payment_status', Sale::PAYMENT_PAID)->count(),
            'unpaid_orders'    => (int) Sale::query()->revenue()->where('sold_at', '>=', $monthStart)
                ->whereIn('payment_status', [Sale::PAYMENT_UNPAID, Sale::PAYMENT_PARTIAL])->count(),
            'cancelled_orders' => (int) Sale::query()->where('status', Sale::STATUS_CANCELLED)
                ->where('sold_at', '>=', $monthStart)->count(),
            'gross_profit'     => (int) ($monthTotals->revenue ?? 0) - (int) ($monthTotals->cogs ?? 0),
            'refunded_month'   => (int) ($monthTotals->refunded ?? 0),
        ];

        // Last 7 days, aggregated in PHP so the query stays database-agnostic.
        $rows = Sale::query()->revenue()
            ->where('sold_at', '>=', now()->subDays(6)->startOfDay())
            ->get(['sold_at', 'total']);

        $series = [];

        for ($offset = 6; $offset >= 0; $offset--) {
            $day = now()->subDays($offset);
            $series[$day->toDateString()] = [
                'label'  => $day->format('D'),
                'date'   => $day->toDateString(),
                'total'  => 0,
                'orders' => 0,
            ];
        }

        foreach ($rows as $row) {
            $key = $row->sold_at?->toDateString();

            if ($key !== null && isset($series[$key])) {
                $series[$key]['total'] += (int) $row->total;
                $series[$key]['orders']++;
            }
        }

        $topProducts = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereIn('sales.status', Sale::REVENUE_STATUSES)
            ->where('sales.sold_at', '>=', $monthStart)
            ->groupBy('sale_items.product_id', 'sale_items.product_name', 'sale_items.sku')
            ->selectRaw('sale_items.product_id, sale_items.product_name, sale_items.sku, SUM(sale_items.quantity) as quantity, SUM(sale_items.subtotal) as revenue, SUM(sale_items.quantity * sale_items.cost_price) as cogs')
            ->orderByDesc('quantity')
            ->limit(5)
            ->get();

        return view('sales.dashboard', [
            'summary'     => $summary,
            'series'      => $series,
            'maxDayTotal' => max(1, max(array_column($series, 'total'))),
            'topProducts' => $topProducts,
            'recentSales' => Sale::with(['customer', 'createdBy'])->latest('sold_at')->limit(8)->get(),
        ]);
    }

    // ------------------------------------------------------------------ orders

    public function index(Request $request)
    {
        $this->auth->authorize('sales.view');

        $tenant = $this->tenant($request);

        $query = Sale::with(['customer', 'createdBy']);

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($customer) => $customer
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->string('payment_status')->toString());
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->integer('customer_id'));
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

        return view('sales.index', [
            'sales'           => $query->withCount('items')->latest('sold_at')->paginate(50)->withQueryString(),
            'customers'       => $tenant->customers()->orderBy('name')->get(),
            'statuses'        => Sale::STATUSES,
            'paymentStatuses' => Sale::PAYMENT_STATUSES,
            'channels'        => config('business.sales.channels'),
            'filters'         => $request->only(['search', 'status', 'payment_status', 'customer_id', 'sales_channel', 'from', 'to']),
        ]);
    }

    // ------------------------------------------------------------------ create

    public function create(Request $request)
    {
        $this->auth->authorize('sales.create');

        $tenant = $this->tenant($request);
        $warehouse = $this->defaultWarehouse($tenant);

        $products = Product::where('is_active', true)->orderBy('name')->get();

        $balances = $products->isEmpty() ? collect() : StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->whereIn('product_id', $products->pluck('id'))
            ->pluck('quantity', 'product_id');

        return view('sales.create', [
            'pageTitle'        => 'New Sale',
            'submitUrl'        => route('sales.store'),
            'customers'        => $tenant->customers()->active()->orderBy('name')->get(),
            'warehouses'       => $tenant->warehouses()->active()->orderBy('name')->get(),
            'defaultWarehouse' => $warehouse,
            'channels'         => config('business.sales.channels'),
            'paymentMethods'   => config('business.sales.payment_methods'),
            'taxPercent'       => $this->defaultTaxPercent($tenant),
            'shippingEnabled'  => (bool) config('business.sales.shipping_enabled'),
            'clientReference'  => (string) Str::uuid(),
            'canCreateCustomer' => $this->auth->can('customers.create'),
            'selectedCustomerId' => old('customer_id', ''),
            'customerPayload'  => $tenant->customers()->active()->orderBy('name')->get()
                ->map(fn ($customer) => [
                    'id'    => (int) $customer->id,
                    'name'  => $customer->name,
                    'phone' => $customer->phone,
                ])->values(),
            'productPayload'   => $products->map(fn (Product $product) => [
                'id'              => $product->id,
                'name'            => $product->name,
                'sku'             => $product->sku,
                'unit'            => $product->unit,
                'price'           => $product->selling_price / 100,
                'stock'           => $product->track_inventory ? (int) ($balances[$product->id] ?? 0) : null,
                'stock_recorded'  => $product->track_inventory && $balances->has($product->id),
                'minimum_stock'   => (int) $product->minimum_stock,
                'track_inventory' => (bool) $product->track_inventory,
            ])->values(),
        ]);
    }

    // ------------------------------------------------------------------ store

    public function store(Request $request)
    {
        $this->auth->authorize('sales.create');

        $tenant = $this->tenant($request);
        $tenantId = $tenant->id;

        // Double-submit guard: repeating the same form resolves to the sale that already
        // exists instead of selling — and deducting stock — twice.
        if ($reference = $request->string('client_reference')->toString()) {
            if ($existing = Sale::where('client_reference', $reference)->first()) {
                return redirect()->route('sales.show', $existing)
                    ->with('status', ['type' => 'success', 'message' => 'This sale was already recorded.']);
            }
        }

        $channels = array_keys(config('business.sales.channels'));
        $methods = array_keys(config('business.sales.payment_methods'));

        $validated = $request->validate([
            'customer_id'            => ['nullable', 'integer', $this->owned('customers', $tenantId)],
            'warehouse_id'           => ['required', 'integer', $this->owned('warehouses', $tenantId)],
            'sales_channel'          => ['required', Rule::in($channels)],
            'status'                 => ['nullable', Rule::in([Sale::STATUS_COMPLETED, Sale::STATUS_PENDING])],
            'sold_at'                => ['nullable', 'date'],
            'discount_type'          => ['required', Rule::in(CalculateSaleTotals::DISCOUNT_TYPES)],
            'discount_value'         => ['nullable', 'numeric', 'min:0'],
            'tax_percent'            => ['nullable', 'integer', 'min:0', 'max:100'],
            'shipping'               => ['nullable', 'numeric', 'min:0'],
            'payment_method'         => ['required', Rule::in($methods)],
            'payment_amount'         => ['nullable', 'numeric', 'min:0'],
            'payment_reference'      => ['nullable', 'string', 'max:100'],
            'notes'                  => ['nullable', 'string', 'max:65535'],
            'client_reference'       => ['nullable', 'string', 'max:64'],
            'items'                  => ['required', 'array', 'min:1'],
            'items.*.product_id'     => ['required', 'integer', $this->owned('products', $tenantId)],
            'items.*.quantity'       => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_price'     => ['required', 'numeric', 'min:0'],
            'items.*.discount_type'  => ['nullable', Rule::in(CalculateSaleTotals::DISCOUNT_TYPES)],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
        ]);

        $status = $validated['status'] ?? Sale::STATUS_COMPLETED;
        $soldAt = ! empty($validated['sold_at']) ? Carbon::parse($validated['sold_at']) : now();

        $lines = [];

        foreach ($validated['items'] as $index => $row) {
            $type = $this->calculator->normalizeType($row['discount_type'] ?? CalculateSaleTotals::TYPE_FIXED);

            $lines[] = [
                'product_id'     => (int) $row['product_id'],
                'quantity'       => (int) $row['quantity'],
                'unit_price'     => Money::centsFromDisplay($row['unit_price']),
                'discount_type'  => $type,
                'discount_value' => $this->resolveDiscountValue($type, $row['discount_value'] ?? 0),
            ];
        }

        $header = [
            'discount_type'  => $this->calculator->normalizeType($validated['discount_type']),
            'discount_value' => $this->resolveDiscountValue($validated['discount_type'], $validated['discount_value'] ?? 0),
            'tax_percent'    => (int) ($validated['tax_percent'] ?? $this->defaultTaxPercent($tenant)),
            'shipping'       => Money::centsFromDisplay($validated['shipping'] ?? 0),
        ];

        $totals = $this->calculator->calculate($lines, $header);

        $productIds = collect($lines)->pluck('product_id')->unique()->all();
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

        $this->assertStockAvailable((int) $validated['warehouse_id'], $lines);

        $paid = Money::centsFromDisplay($validated['payment_amount'] ?? 0);
        $change = Sale::changeFor($totals['total'], $paid);

        // Metered plan limit: never exceed the workspace entitlement for sales.
        $this->usage->enforce($tenant, 'sales_count');

        try {
            $sale = DB::transaction(function () use ($tenant, $validated, $status, $soldAt, $lines, $totals, $products, $paid, $change) {
                $sale = Sale::create([
                    'tenant_id'        => $tenant->id,
                    'customer_id'      => $validated['customer_id'] ?? null,
                    'warehouse_id'     => (int) $validated['warehouse_id'],
                    'invoice_number'   => $this->numbering->next('sale', $tenant->id),
                    'client_reference' => $validated['client_reference'] ?? null,
                    'sold_at'          => $soldAt,
                    'subtotal'         => $totals['subtotal'],
                    'item_discount'    => $totals['item_discount'],
                    'discount'         => $totals['discount'],
                    'discount_type'    => $totals['discount_type'],
                    'discount_value'   => $totals['discount_value'],
                    'tax'              => $totals['tax'],
                    'tax_percent'      => $totals['tax_percent'],
                    'shipping'         => $totals['shipping'],
                    'total'            => $totals['total'],
                    'total_cogs'       => 0,
                    'paid_amount'      => $paid,
                    'change_amount'    => $change,
                    'status'           => $status,
                    'payment_status'   => Sale::PAYMENT_UNPAID,
                    'sales_channel'    => $validated['sales_channel'],
                    'payment_method'   => $validated['payment_method'],
                    'created_by'       => auth()->id(),
                    'notes'            => $validated['notes'] ?? null,
                ]);

                $cogs = 0;

                foreach ($lines as $index => $line) {
                    $product = $products->get($line['product_id']);
                    $resolved = $totals['lines'][$index];

                    // Cost price is a SNAPSHOT: a later product price change must never rewrite
                    // the COGS/profit of an existing sale.
                    $costPrice = (int) ($product?->cost_price ?? 0);
                    $cogs += $line['quantity'] * $costPrice;

                    SaleItem::create([
                        'tenant_id'      => $tenant->id,
                        'sale_id'        => $sale->id,
                        'product_id'     => $line['product_id'],
                        'product_name'   => $product?->name ?? 'Product #'.$line['product_id'],
                        'sku'            => $product?->sku,
                        'unit'           => $product?->unit ?? 'pcs',
                        'quantity'       => $line['quantity'],
                        'selling_price'  => $line['unit_price'],
                        'cost_price'     => $costPrice,
                        'discount_type'  => $line['discount_type'],
                        'discount_value' => $line['discount_value'],
                        'discount'       => $resolved['discount'],
                        'subtotal'       => $resolved['subtotal'],
                    ]);
                }

                $sale->forceFill(['total_cogs' => $cogs])->save();

                if ($paid > 0) {
                    SalePayment::create([
                        'tenant_id'     => $tenant->id,
                        'sale_id'       => $sale->id,
                        'method'        => $validated['payment_method'],
                        'amount'        => $paid,
                        'change_amount' => $change,
                        'reference'     => $validated['payment_reference'] ?? null,
                        'paid_at'       => $soldAt,
                        'created_by'    => auth()->id(),
                    ]);
                }

                $sale->refreshPaymentStatus();

                // Completed sales take the stock out now; a pending sale is deducted later by
                // complete() — still exactly once.
                if ($status === Sale::STATUS_COMPLETED) {
                    $sale->applyStock(auth()->id());
                }

                return $sale;
            });
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->with('status', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        $this->usage->recordMetric($tenant, 'sales_count');

        AuditLogger::log('sale.created', $sale, [
            'invoice_number' => $sale->invoice_number,
            'total'          => $sale->total,
            'status'         => $sale->status,
        ]);

        $message = "Sale {$sale->invoice_number} recorded.";

        if ($sale->change_amount > 0) {
            $message .= ' Change: '.Money::format($sale->change_amount);
        }

        return redirect()->route('sales.show', $sale)->with('status', ['type' => 'success', 'message' => $message]);
    }

    // ------------------------------------------------------------------ show

    public function show(Sale $sale)
    {
        $this->auth->authorize('sales.view');
        $this->ensureOwned($sale);

        $sale->load(['customer', 'warehouse', 'createdBy', 'items.product', 'payments.createdBy', 'returns.items.saleItem']);

        return view('sales.show', ['sale' => $sale]);
    }

    /** Printable invoice (standalone layout, no application shell). */
    public function invoice(Sale $sale)
    {
        $this->auth->authorize('sales.view');
        $this->ensureOwned($sale);

        $sale->load(['customer', 'warehouse', 'createdBy', 'items', 'payments', 'returns.items']);

        return view('sales.print', [
            'sale'   => $sale,
            'tenant' => app('tenant.context')->tenant(),
        ]);
    }

    // ------------------------------------------------------------------ status actions

    public function complete(Sale $sale)
    {
        $this->auth->authorize('sales.complete');
        $this->ensureOwned($sale);

        try {
            $sale->complete(auth()->id());
        } catch (RuntimeException|InvalidArgumentException $e) {
            return back()->with('status', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        AuditLogger::log('sale.completed', $sale, ['invoice_number' => $sale->invoice_number]);

        return redirect()->route('sales.show', $sale)
            ->with('status', ['type' => 'success', 'message' => 'Sale completed. Stock updated.']);
    }

    public function cancel(Sale $sale)
    {
        $this->auth->authorize('sales.cancel');
        $this->ensureOwned($sale);

        try {
            $sale->cancel(auth()->id());
        } catch (RuntimeException|InvalidArgumentException $e) {
            return back()->with('status', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        AuditLogger::log('sale.cancelled', $sale, ['invoice_number' => $sale->invoice_number]);

        return redirect()->route('sales.show', $sale)
            ->with('status', ['type' => 'success', 'message' => 'Sale cancelled. Stock restored.']);
    }

    public function refund(Sale $sale)
    {
        $this->auth->authorize('sales.refund');
        $this->ensureOwned($sale);

        try {
            $sale->refund(auth()->id());
        } catch (RuntimeException|InvalidArgumentException $e) {
            return back()->with('status', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        AuditLogger::log('sale.refunded', $sale, [
            'invoice_number'  => $sale->invoice_number,
            'refunded_amount' => $sale->refunded_amount,
        ]);

        return redirect()->route('sales.show', $sale)
            ->with('status', ['type' => 'success', 'message' => 'Sale refunded. Stock and refund total updated.']);
    }

    // ------------------------------------------------------------------ returns

    /** All returns/refunds of the workspace. */
    public function returns(Request $request)
    {
        $this->auth->authorize('sales.view');

        $query = SaleReturn::with(['sale.customer', 'items.saleItem', 'createdBy']);

        if ($request->filled('from')) {
            $query->whereDate('returned_at', '>=', $request->string('from')->toString());
        }

        if ($request->filled('to')) {
            $query->whereDate('returned_at', '<=', $request->string('to')->toString());
        }

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('return_number', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhereHas('sale', fn ($sale) => $sale->where('invoice_number', 'like', "%{$search}%"));
            });
        }

        return view('sales.returns', [
            'returns' => $query->latest('returned_at')->paginate(50)->withQueryString(),
            'filters' => $request->only(['search', 'from', 'to']),
        ]);
    }

    /** Return form for one invoice (choose lines + quantities). */
    public function returnForm(Sale $sale)
    {
        $this->auth->authorize('sales.return');
        $this->ensureOwned($sale);

        $sale->load(['customer', 'items.product']);

        if (! $sale->hasReturnableItems()) {
            return redirect()->route('sales.show', $sale)
                ->with('status', ['type' => 'error', 'message' => 'This sale has nothing left to return.']);
        }

        return view('sales.return', [
            'sale'           => $sale,
            'paymentMethods' => config('business.sales.payment_methods'),
        ]);
    }

    public function storeReturn(Request $request, Sale $sale)
    {
        $this->auth->authorize('sales.return');
        $this->ensureOwned($sale);

        $validated = $request->validate([
            'quantities'    => ['required', 'array'],
            'quantities.*'  => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'reason'        => ['required', 'string', 'max:255'],
            'refund_method' => ['required', Rule::in(array_keys(config('business.sales.payment_methods')))],
            'notes'         => ['nullable', 'string', 'max:65535'],
        ]);

        try {
            $return = $this->returns->record(
                $sale,
                $validated['quantities'],
                [
                    'reason'        => $validated['reason'],
                    'refund_method' => $validated['refund_method'],
                    'notes'         => $validated['notes'] ?? null,
                ],
                auth()->id()
            );
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->with('status', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        AuditLogger::log('sale.returned', $sale, [
            'return_number' => $return->return_number,
            'refund_amount' => $return->refund_amount,
        ]);

        return redirect()->route('sales.show', $sale)->with('status', [
            'type'    => 'success',
            'message' => "Return {$return->return_number} recorded. Refund ".Money::format($return->refund_amount).'.',
        ]);
    }

    // ------------------------------------------------------------------ report

    /** Sales report: date presets, KPIs and per product/category/brand/customer/channel splits. */
    public function report(Request $request)
    {
        $this->auth->authorize('reports.view');

        $tenant = $this->tenant($request);

        [$preset, $from, $to, $fromInput, $toInput] = $this->resolveRange($request);

        $totals = Sale::query()->revenue()->whereBetween('sold_at', [$from, $to])
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(total), 0) as total, COALESCE(SUM(subtotal), 0) as subtotal, COALESCE(SUM(item_discount), 0) as item_discount, COALESCE(SUM(discount), 0) as discount, COALESCE(SUM(tax), 0) as tax, COALESCE(SUM(shipping), 0) as shipping, COALESCE(SUM(total_cogs), 0) as cogs')
            ->first();

        $refundTotal = (int) DB::table('sale_returns')
            ->where('tenant_id', $tenant->id)
            ->whereBetween('returned_at', [$from, $to])
            ->sum('refund_amount');

        $returnedCogs = (int) DB::table('sale_return_items')
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
            ->join('sale_items', 'sale_items.id', '=', 'sale_return_items.sale_item_id')
            ->where('sale_returns.tenant_id', $tenant->id)
            ->whereBetween('sale_returns.returned_at', [$from, $to])
            ->sum(DB::raw('sale_return_items.quantity * sale_items.cost_price'));

        $orders = (int) ($totals->orders ?? 0);

        // Goods revenue excludes tax and shipping; refunded goods and their COGS are credited
        // back so a return can never leave profit overstated.
        $netGoodsRevenue = (int) ($totals->subtotal ?? 0) - (int) ($totals->discount ?? 0) - $refundTotal;
        $netCogs = (int) ($totals->cogs ?? 0) - $returnedCogs;

        $report = [
            'orders'        => $orders,
            'total_sales'   => (int) ($totals->total ?? 0),
            'average_order' => $orders > 0 ? intdiv((int) ($totals->total ?? 0), $orders) : 0,
            'discount'      => (int) ($totals->item_discount ?? 0) + (int) ($totals->discount ?? 0),
            'tax'           => (int) ($totals->tax ?? 0),
            'shipping'      => (int) ($totals->shipping ?? 0),
            'refunds'       => $refundTotal,
            'cogs'          => $netCogs,
            'gross_profit'  => $netGoodsRevenue - $netCogs,
        ];

        return view('sales.report', [
            'report'     => $report,
            'preset'     => $preset,
            'from'       => $fromInput,
            'to'         => $toInput,
            'byProduct'  => $this->reportByProduct($from, $to),
            'byCategory' => $this->reportByCategory($from, $to),
            'byBrand'    => $this->reportByBrand($from, $to),
            'byCustomer' => $this->reportByCustomer($from, $to),
            'byChannel'  => $this->reportByChannel($from, $to),
        ]);
    }

    // ------------------------------------------------------------------ report breakdowns

    private function reportByProduct(Carbon $from, Carbon $to)
    {
        return SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereIn('sales.status', Sale::REVENUE_STATUSES)
            ->whereBetween('sales.sold_at', [$from, $to])
            ->groupBy('sale_items.product_id', 'sale_items.product_name', 'sale_items.sku')
            ->selectRaw('sale_items.product_id, sale_items.product_name, sale_items.sku, SUM(sale_items.quantity) as quantity, SUM(sale_items.subtotal) as revenue, SUM(sale_items.quantity * sale_items.cost_price) as cogs')
            ->orderByDesc('revenue')
            ->limit(50)
            ->get();
    }

    /** Sales by category, reported through sale_items -> products -> categories. */
    private function reportByCategory(Carbon $from, Carbon $to)
    {
        return SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->whereIn('sales.status', Sale::REVENUE_STATUSES)
            ->whereBetween('sales.sold_at', [$from, $to])
            ->groupBy('categories.id', 'categories.name')
            ->selectRaw('categories.id as category_id, categories.name as category_name, SUM(sale_items.quantity) as quantity, SUM(sale_items.subtotal) as revenue, SUM(sale_items.quantity * sale_items.cost_price) as cogs')
            ->orderByDesc('revenue')
            ->get();
    }

    /** Sales by brand: sale_items -> products -> brands. */
    private function reportByBrand(Carbon $from, Carbon $to)
    {
        return SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->whereIn('sales.status', Sale::REVENUE_STATUSES)
            ->whereBetween('sales.sold_at', [$from, $to])
            ->groupBy('brands.id', 'brands.name')
            ->selectRaw('brands.id as brand_id, brands.name as brand_name, SUM(sale_items.quantity) as quantity, SUM(sale_items.subtotal) as revenue, SUM(sale_items.quantity * sale_items.cost_price) as cogs')
            ->orderByDesc('revenue')
            ->get();
    }

    private function reportByCustomer(Carbon $from, Carbon $to)
    {
        return Sale::query()
            ->revenue()
            ->whereBetween('sold_at', [$from, $to])
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->groupBy('sales.customer_id', 'customers.name')
            ->selectRaw('sales.customer_id, customers.name as customer_name, COUNT(*) as orders, SUM(sales.total) as total, SUM(sales.refunded_amount) as refunded')
            ->orderByDesc('total')
            ->limit(50)
            ->get();
    }

    private function reportByChannel(Carbon $from, Carbon $to)
    {
        return Sale::query()
            ->revenue()
            ->whereBetween('sold_at', [$from, $to])
            ->groupBy('sales.sales_channel')
            ->selectRaw('sales.sales_channel, COUNT(*) as orders, SUM(sales.total) as total, SUM(sales.refunded_amount) as refunded')
            ->orderByDesc('total')
            ->get();
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Friendly pre-flight stock check. InventoryService::fulfill() stays the hard guarantee
     * (it locks the balance row and rejects a shortfall) — this only turns the failure into a
     * field-level validation message before anything is written.
     */
    private function assertStockAvailable(int $warehouseId, array $lines): void
    {
        if (config('business.sales.allow_negative_stock')) {
            return;
        }

        $needed = [];

        foreach ($lines as $line) {
            $needed[$line['product_id']] = ($needed[$line['product_id']] ?? 0) + $line['quantity'];
        }

        $products = Product::whereIn('id', array_keys($needed))->get()->keyBy('id');

        $balances = StockBalance::query()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', array_keys($needed))
            ->pluck('quantity', 'product_id');

        $errors = [];

        foreach ($needed as $productId => $quantity) {
            $product = $products->get($productId);

            if (! $product || ! $product->track_inventory) {
                continue;
            }

            $available = (int) ($balances[$productId] ?? 0);

            if ($quantity > $available) {
                $errors["items.{$productId}"] = __(
                    'Not enough stock for :product: requested :requested, available :available.',
                    ['product' => $product->name, 'requested' => $quantity, 'available' => $available]
                );
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** Fixed discounts are entered in rupiah and stored as cents; percentages stay whole percent. */
    private function resolveDiscountValue(string $type, $value): int
    {
        $value = (float) $value;

        return $this->calculator->normalizeType($type) === CalculateSaleTotals::TYPE_PERCENT
            ? max(0, min(100, (int) round($value)))
            : Money::centsFromDisplay($value);
    }

    /** Tax rate is tenant-configurable (tenants.settings.tax_percent); nothing is hard-coded. */
    private function defaultTaxPercent(Tenant $tenant): int
    {
        $settings = $tenant->settings ?? [];

        return (int) ($settings['tax_percent'] ?? config('business.sales.tax_percent', 0));
    }

    /**
     * Stock always moves through a warehouse. The inventory migration documents a default
     * warehouse per tenant, so create one on demand instead of blocking the first sale.
     */
    private function defaultWarehouse(Tenant $tenant): Warehouse
    {
        return $tenant->warehouses()->orderBy('id')->first()
            ?? Warehouse::create([
                'tenant_id' => $tenant->id,
                'name'      => 'Main Warehouse',
                'code'      => 'MAIN',
                'is_active' => true,
            ]);
    }

    /** @return array{0:string,1:Carbon,2:Carbon,3:string,4:string} */
    private function resolveRange(Request $request): array
    {
        $preset = $request->string('range')->toString() ?: 'month';
        $fromInput = $request->string('from')->toString();
        $toInput = $request->string('to')->toString();

        if ($preset === 'custom' && $fromInput && $toInput) {
            return ['custom', Carbon::parse($fromInput)->startOfDay(), Carbon::parse($toInput)->endOfDay(), $fromInput, $toInput];
        }

        [$start, $end] = match ($preset) {
            'today'     => [now()->startOfDay(), now()->endOfDay()],
            'yesterday' => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()],
            'week'      => [now()->startOfWeek(), now()->endOfWeek()],
            default     => [now()->startOfMonth(), now()->endOfMonth()],
        };

        return [$preset, $start, $end, $start->toDateString(), $end->toDateString()];
    }

    /** Tenant-scoped existence rule (same pattern as the product/category controllers). */
    private function owned(string $table, int $tenantId)
    {
        return Rule::exists($table, 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId));
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = app('tenant.context')->tenant() ?? $request->user()?->currentTenant;

        abort_unless($tenant instanceof Tenant, 403, 'No tenant context.');

        return $tenant;
    }

    private function ensureOwned(Sale $sale): void
    {
        // Never trust the route id: compare against the *active* tenant context.
        if (! $sale->tenant_id || $sale->tenant_id !== app('tenant.context')->tenant()?->id) {
            abort(404);
        }
    }
}