<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Advanced (Pro/Business) reporting aggregations.
 *
 * Every method aggregates in SQL and scopes to a single tenant. Controllers only
 * pass a resolved ReportPeriod through, so no report re-derives numbers locally.
 */
class AdvancedReportsService
{
    // ------------------------------------------------------------------ sales

    /** @return array<string, int> */
    public function salesSummary(Tenant $tenant, ReportPeriod $period): array
    {
        $totals = $this->saleQuery($tenant, $period)
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(total),0) as gross_sales,
                         COALESCE(SUM(discount),0) as discount, COALESCE(SUM(tax),0) as tax,
                         COALESCE(SUM(refunded_amount),0) as refunds, COALESCE(SUM(total_cogs),0) as cogs')
            ->first();

        $gross = (int) ($totals->gross_sales ?? 0);
        $orders = (int) ($totals->orders ?? 0);
        $cogs = (int) ($totals->cogs ?? 0);

        return [
            'orders'          => $orders,
            'gross_sales'     => $gross,
            'discount'        => (int) ($totals->discount ?? 0),
            'tax'             => (int) ($totals->tax ?? 0),
            'refunds'         => (int) ($totals->refunds ?? 0),
            'net_sales'       => max(0, $gross - (int) ($totals->refunds ?? 0)),
            'cogs'            => $cogs,
            'gross_profit'    => $gross - $cogs,
            'avg_order_value' => $orders > 0 ? (int) round($gross / $orders) : 0,
        ];
    }

    public function salesTrend(Tenant $tenant, ReportPeriod $period, int $days = 30): Collection
    {
        $rows = $this->saleQuery($tenant, $period)->get(['sold_at', 'total']);
        $buckets = [];

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $day = $period->to->copy()->subDays($offset)->startOfDay();
            $buckets[$day->toDateString()] = ['date' => $day->toDateString(), 'total' => 0, 'orders' => 0];
        }

        foreach ($rows as $row) {
            $key = $row->sold_at?->toDateString();
            if ($key !== null && isset($buckets[$key])) {
                $buckets[$key]['total'] += (int) $row->total;
                $buckets[$key]['orders']++;
            }
        }

        return collect($buckets)->values();
    }

    public function salesByProduct(Tenant $tenant, ReportPeriod $period, int $limit = 25): Collection
    {
        return $this->saleItemQuery($tenant, $period)
            ->groupBy('sale_items.product_id', 'sale_items.product_name', 'sale_items.sku')
            ->selectRaw('sale_items.product_id, sale_items.product_name, sale_items.sku,
                         SUM(sale_items.quantity) as quantity, SUM(sale_items.subtotal) as revenue,
                         SUM(sale_items.quantity * sale_items.cost_price) as cogs')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $revenue = (int) $row->revenue;
                $cogs = (int) $row->cogs;

                return (object) [
                    'product_id'   => $row->product_id,
                    'product_name' => $row->product_name,
                    'sku'          => $row->sku,
                    'quantity'     => (int) $row->quantity,
                    'revenue'      => $revenue,
                    'cogs'         => $cogs,
                    'gross_profit' => $revenue - $cogs,
                    'margin'       => $revenue > 0 ? round(($revenue - $cogs) / $revenue * 100, 1) : 0.0,
                ];
            });
    }

    public function salesByCategory(Tenant $tenant, ReportPeriod $period): Collection
    {
        return $this->saleItemQuery($tenant, $period)
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('products.tenant_id', $tenant->id)
            ->groupBy('categories.id', 'categories.name')
            ->selectRaw('COALESCE(categories.name, ?) as category_name,
                         SUM(sale_items.quantity) as quantity, SUM(sale_items.subtotal) as revenue,
                         SUM(sale_items.quantity * sale_items.cost_price) as cogs', ['Uncategorised'])
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => (object) [
                'category_name' => $row->category_name,
                'quantity'      => (int) $row->quantity,
                'revenue'       => (int) $row->revenue,
                'gross_profit'  => (int) $row->revenue - (int) $row->cogs,
            ]);
    }

    public function salesByBrand(Tenant $tenant, ReportPeriod $period): Collection
    {
        return $this->saleItemQuery($tenant, $period)
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->where('products.tenant_id', $tenant->id)
            ->groupBy('brands.id', 'brands.name')
            ->selectRaw('COALESCE(brands.name, ?) as brand_name,
                         SUM(sale_items.quantity) as quantity, SUM(sale_items.subtotal) as revenue,
                         SUM(sale_items.quantity * sale_items.cost_price) as cogs', ['Unbranded'])
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => (object) [
                'brand_name'   => $row->brand_name,
                'quantity'     => (int) $row->quantity,
                'revenue'      => (int) $row->revenue,
                'gross_profit' => (int) $row->revenue - (int) $row->cogs,
            ]);
    }

    public function salesByCustomer(Tenant $tenant, ReportPeriod $period, int $limit = 25): Collection
    {
        // PostgreSQL rejects sales.customer_id in the SELECT list unless it is part
        // of the GROUP BY (SQLSTATE[42803]); SQLite tolerated the old, shorter form.
        // Grouping by sales.customer_id + the joined customer keeps walk-in sales
        // (customer_id NULL) grouped together under the COALESCE'd label below.
        return $this->saleQuery($tenant, $period)
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->groupBy('sales.customer_id', 'customers.id', 'customers.name')
            ->selectRaw('sales.customer_id, COALESCE(customers.name, ?) as customer_name,
                         COUNT(*) as orders, COALESCE(SUM(sales.total),0) as revenue', ['Walk-in Customer'])
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => (object) [
                'customer_id'   => $row->customer_id,
                'customer_name' => $row->customer_name,
                'orders'        => (int) $row->orders,
                'revenue'       => (int) $row->revenue,
            ]);
    }

    public function salesByChannel(Tenant $tenant, ReportPeriod $period): Collection
    {
        return $this->saleQuery($tenant, $period)
            ->groupBy('sales.sales_channel')
            ->selectRaw('sales.sales_channel, COUNT(*) as orders, COALESCE(SUM(sales.total),0) as revenue')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => (object) [
                'channel' => $row->sales_channel ?: 'manual',
                'label'   => config("business.sales.channels.{$row->sales_channel}", 'Manual'),
                'orders'  => (int) $row->orders,
                'revenue' => (int) $row->revenue,
            ]);
    }

    public function salesByUser(Tenant $tenant, ReportPeriod $period): Collection
    {
        // PostgreSQL requires every non-aggregated SELECT column in the GROUP BY
        // (SQLSTATE[42803]); sales.created_by is grouped explicitly because it is
        // selected — a LEFT JOIN does not create a functional dependency.
        return $this->saleQuery($tenant, $period)
            ->leftJoin('users', 'users.id', '=', 'sales.created_by')
            ->groupBy('sales.created_by', 'users.id', 'users.name')
            ->selectRaw('sales.created_by, COALESCE(users.name, ?) as user_name,
                         COUNT(*) as orders, COALESCE(SUM(sales.total),0) as revenue', ['Unknown'])
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => (object) [
                'created_by' => $row->created_by,
                'user_name'  => $row->user_name,
                'orders'     => (int) $row->orders,
                'revenue'    => (int) $row->revenue,
            ]);
    }

    // ------------------------------------------------------------------ products

    /** Active catalogue products that sold nothing in the window. */
    public function productsWithoutSales(Tenant $tenant, ReportPeriod $period): Collection
    {
        // The subquery is built separately: passing a closure that returns a Builder to
        // whereNotIn does not carry the select() column across, which yields "no tables".
        $soldProductIds = $this->saleItemQuery($tenant, $period)
            ->select('sale_items.product_id');

        return Product::query()
            ->where('is_active', true)
            ->whereNotIn('id', $soldProductIds)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'selling_price', 'cost_price']);
    }

    /** Sold the least during the window (slow moving). */
    public function slowMovingProducts(Tenant $tenant, ReportPeriod $period, int $limit = 25): Collection
    {
        return $this->salesByProduct($tenant, $period, 200)->sortBy('quantity')->take($limit)->values();
    }

    // ------------------------------------------------------------------ inventory

    /** @return array<string, int> */
    public function inventorySummary(Tenant $tenant): array
    {
        $balances = StockBalance::query()
            ->join('products', 'products.id', '=', 'stock_balances.product_id')
            ->where('stock_balances.tenant_id', $tenant->id)
            ->selectRaw('COALESCE(SUM(stock_balances.quantity * products.cost_price),0) as stock_value,
                         COALESCE(SUM(stock_balances.quantity),0) as units')
            ->first();

        $low = StockBalance::query()
            ->join('products', 'products.id', '=', 'stock_balances.product_id')
            ->where('stock_balances.tenant_id', $tenant->id)
            ->where('products.is_active', true)
            ->whereColumn('stock_balances.quantity', '<=', 'products.minimum_stock')
            ->count();

        return [
            'stock_value' => (int) ($balances->stock_value ?? 0),
            'units'       => (int) ($balances->units ?? 0),
            'low_stock'   => (int) $low,
        ];
    }

    public function stockMovements(Tenant $tenant, ReportPeriod $period, int $limit = 100): Collection
    {
        return StockMovement::query()
            ->with(['product:id,name,sku', 'warehouse:id,name'])
            ->where('created_at', '>=', $period->from)
            ->where('created_at', '<=', $period->to)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** Incoming vs outgoing units, plus the raw per-type totals. */
    public function movementTotals(Tenant $tenant, ReportPeriod $period): array
    {
        $rows = StockMovement::query()
            ->where('created_at', '>=', $period->from)
            ->where('created_at', '<=', $period->to)
            ->selectRaw('type, COALESCE(SUM(quantity),0) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $incoming = ['purchase', 'sale_return', 'adjustment_in', 'transfer_in'];
        $totals = ['in' => 0, 'out' => 0];

        foreach ($rows as $type => $total) {
            $in_array = in_array($type, $incoming, true);
            $totals[$in_array ? 'in' : 'out'] += (int) $total;
        }

        return $totals + ['by_type' => $rows->map(fn ($v) => (int) $v)->all()];
    }


    public function lowStockProducts(Tenant $tenant, int $limit = 25): Collection
    {
        return StockBalance::query()
            ->join('products', 'products.id', '=', 'stock_balances.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_balances.warehouse_id')
            ->where('stock_balances.tenant_id', $tenant->id)
            ->where('products.is_active', true)
            ->whereColumn('stock_balances.quantity', '<=', 'products.minimum_stock')
            ->orderBy('stock_balances.quantity')
            ->limit($limit)
            ->get(['products.name as product_name', 'products.sku', 'products.minimum_stock',
                   'products.unit', 'warehouses.name as warehouse_name', 'stock_balances.quantity'])
            ->map(fn ($row) => (object) [
                'product_name'   => $row->product_name,
                'sku'            => $row->sku,
                'unit'           => $row->unit,
                'minimum_stock'  => (int) $row->minimum_stock,
                'warehouse_name' => $row->warehouse_name,
                'quantity'       => (int) $row->quantity,
            ]);
    }

    // ------------------------------------------------------------------ purchases

    /** @return array<string, int> */
    public function purchaseSummary(Tenant $tenant, ReportPeriod $period): array
    {
        $query = $this->purchaseQuery($tenant, $period);

        $totals = (clone $query)
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(total),0) as spend, COALESCE(SUM(discount),0) as discount')
            ->first();

        return [
            'orders'   => (int) ($totals->orders ?? 0),
            'spend'    => (int) ($totals->spend ?? 0),
            'discount' => (int) ($totals->discount ?? 0),
            'received' => (clone $query)->where('status', 'received')->count(),
        ];
    }

    public function purchasesBySupplier(Tenant $tenant, ReportPeriod $period): Collection
    {
        return $this->purchaseQuery($tenant, $period)
            ->leftJoin('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->groupBy('suppliers.id', 'suppliers.name')
            ->selectRaw('COALESCE(suppliers.name, ?) as supplier_name, COUNT(*) as orders,
                         COALESCE(SUM(purchases.total),0) as spend', ['Unknown supplier'])
            ->orderByDesc('spend')
            ->get()
            ->map(fn ($row) => (object) [
                'supplier_name' => $row->supplier_name,
                'orders'        => (int) $row->orders,
                'spend'         => (int) $row->spend,
            ]);
    }

    public function purchasesByProduct(Tenant $tenant, ReportPeriod $period, int $limit = 25): Collection
    {
        return PurchaseItem::query()
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchases.tenant_id', $tenant->id)
            ->where('purchases.ordered_at', '>=', $period->from)
            ->where('purchases.ordered_at', '<=', $period->to)
            ->groupBy('purchase_items.product_name', 'purchase_items.sku')
            ->selectRaw('purchase_items.product_name, purchase_items.sku,
                         SUM(purchase_items.quantity) as quantity, SUM(purchase_items.subtotal) as spend')
            ->orderByDesc('spend')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => (object) [
                'product_name' => $row->product_name,
                'sku'          => $row->sku,
                'quantity'     => (int) $row->quantity,
                'spend'        => (int) $row->spend,
            ]);
    }

    // ------------------------------------------------------------------ customers

    /** @return array<string, int|float> */
    public function customerSummary(Tenant $tenant, ReportPeriod $period): array
    {
        $perCustomer = $this->saleQuery($tenant, $period)
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id, COUNT(*) as orders, COALESCE(SUM(total),0) as spend')
            ->groupBy('customer_id')
            ->get();

        $buyers = $perCustomer->count();
        $repeat = $perCustomer->filter(fn ($row) => (int) $row->orders > 1)->count();
        $orders = (int) $perCustomer->sum('orders');

        $newCustomers = Customer::query()
            ->where('created_at', '>=', $period->from)
            ->where('created_at', '<=', $period->to)
            ->count();

        return [
            'buying_customers'    => $buyers,
            'repeat_customers'    => $repeat,
            'new_customers'       => $newCustomers,
            'repeat_rate'         => $buyers > 0 ? round($repeat / $buyers * 100, 1) : 0.0,
            'avg_spend_per_order' => $orders > 0 ? (int) round((int) $perCustomer->sum('spend') / $orders) : 0,
        ];
    }

    // ------------------------------------------------------------------ finance

    /** Revenue vs COGS vs expenses vs net profit. */
    public function profitSummary(Tenant $tenant, ReportPeriod $period): array
    {
        $sales = $this->salesSummary($tenant, $period);

        $expenses = (int) Expense::query()
            ->where('expense_date', '>=', $period->from->toDateString())
            ->where('expense_date', '<=', $period->to->toDateString())
            ->sum('amount');

        $net = $sales['gross_profit'] - $expenses;
        $revenue = $sales['net_sales'];

        return [
            'revenue'      => $revenue,
            'cogs'         => $sales['cogs'],
            'gross_profit' => $sales['gross_profit'],
            'expenses'     => $expenses,
            'net_profit'   => $net,
            'gross_margin' => $revenue > 0 ? round($sales['gross_profit'] / $revenue * 100, 1) : 0.0,
            'net_margin'   => $revenue > 0 ? round($net / $revenue * 100, 1) : 0.0,
        ];
    }

    public function expensesByCategory(Tenant $tenant, ReportPeriod $period): Collection
    {
        return Expense::query()
            ->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.category_id')
            ->where('expenses.tenant_id', $tenant->id)
            ->where('expenses.expense_date', '>=', $period->from->toDateString())
            ->where('expenses.expense_date', '<=', $period->to->toDateString())
            ->groupBy('expenses.category_id', 'expense_categories.name')
            ->selectRaw('COALESCE(expense_categories.name, ?) as category_name, COUNT(*) as entries,
                         COALESCE(SUM(expenses.amount),0) as amount', ['General'])
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($row) => (object) [
                'category_name' => $row->category_name,
                'entries'       => (int) $row->entries,
                'amount'        => (int) $row->amount,
            ]);
    }

    // ------------------------------------------------------------------ helpers

    private function saleQuery(Tenant $tenant, ReportPeriod $period): Builder
    {
        return Sale::query()->revenue()
            ->where('sales.tenant_id', $tenant->id)
            ->where('sales.sold_at', '>=', $period->from)
            ->where('sales.sold_at', '<=', $period->to);
    }

    private function saleItemQuery(Tenant $tenant, ReportPeriod $period): Builder
    {
        return SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.tenant_id', $tenant->id)
            ->whereIn('sales.status', Sale::REVENUE_STATUSES)
            ->where('sales.sold_at', '>=', $period->from)
            ->where('sales.sold_at', '<=', $period->to);
    }

    private function purchaseQuery(Tenant $tenant, ReportPeriod $period): Builder
    {
        return Purchase::query()
            ->where('purchases.tenant_id', $tenant->id)
            ->where('purchases.ordered_at', '>=', $period->from)
            ->where('purchases.ordered_at', '<=', $period->to);
    }
}
