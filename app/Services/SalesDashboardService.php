<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleItem;

/**
 * Read-only sales overview shared by the main dashboard and the detailed
 * sales dashboard. Keeping the aggregation here prevents the two pages from
 * drifting apart and preserves the tenant scopes on Sale and SaleItem.
 */
class SalesDashboardService
{
    /**
     * @return array{summary: array<string, int>, series: array<string, array<string, int|string>>, maxDayTotal: int, topProducts: mixed, recentSales: mixed}
     */
    public function data(): array
    {
        $now = now();
        $todayStart = $now->copy()->startOfDay();
        $monthStart = $now->copy()->startOfMonth();

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

        // Last 7 days, aggregated in PHP so the query remains database-agnostic.
        $rows = Sale::query()->revenue()
            ->where('sold_at', '>=', $now->copy()->subDays(6)->startOfDay())
            ->get(['sold_at', 'total']);

        $series = [];

        for ($offset = 6; $offset >= 0; $offset--) {
            $day = $now->copy()->subDays($offset);
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
            ->where('sales.tenant_id', app('tenant.context')->tenant()->id)
            ->whereIn('sales.status', Sale::REVENUE_STATUSES)
            ->where('sales.sold_at', '>=', $monthStart)
            ->groupBy('sale_items.product_id', 'sale_items.product_name', 'sale_items.sku')
            ->selectRaw('sale_items.product_id, sale_items.product_name, sale_items.sku, SUM(sale_items.quantity) as quantity, SUM(sale_items.subtotal) as revenue, SUM(sale_items.quantity * sale_items.cost_price) as cogs')
            ->orderByDesc('quantity')
            ->limit(5)
            ->get();

        return [
            'summary'     => $summary,
            'series'      => $series,
            'maxDayTotal' => max(1, max(array_column($series, 'total'))),
            'topProducts' => $topProducts,
            'recentSales' => Sale::with(['customer', 'createdBy'])->latest('sold_at')->limit(8)->get(),
        ];
    }
}
