<?php

namespace App\Http\Controllers;

use App\Services\AdvancedReportsService;
use App\Services\BusinessAuthorization;
use App\Services\ReportPeriod;
use App\Services\UsageService;
use Illuminate\Http\Request;

/**
 * Advanced analytics (Pro/Business entitlement).
 *
 * Analytics reuses the reporting aggregations instead of inventing new numbers,
 * and adds period-over-period comparison on top of them.
 */
class AnalyticsController extends Controller
{
    public function __construct(
        private readonly BusinessAuthorization $auth,
        private readonly AdvancedReportsService $reports,
        private readonly UsageService $usage,
    ) {}

    public function index(Request $request)
    {
        $this->auth->authorize('reports.view');

        $ctx = app('tenant.context');
        $ctx->check();
        $tenant = $ctx->tenant();

        $period = ReportPeriod::resolve(
            $request->string('period')->toString(),
            $request->string('from')->toString() ?: null,
            $request->string('to')->toString() ?: null,
        );

        $previous = $period->previous();
        $salesNow = $this->reports->salesSummary($tenant, $period);
        $salesPrev = $this->reports->salesSummary($tenant, $previous);
        $profitNow = $this->reports->profitSummary($tenant, $period);

        return view('analytics.index', [
            'period'  => $period,
            'filters' => [
                'period' => $request->string('period')->toString() ?: 'month',
                'from'   => $request->string('from')->toString(),
                'to'     => $request->string('to')->toString(),
            ],
            'sales'        => $this->compare($salesNow, $salesPrev),
            'profit'       => $profitNow,
            'trend'        => $this->reports->salesTrend($tenant, $period),
            'customers'    => $this->reports->customerSummary($tenant, $period),
            'inventory'    => $this->reports->inventorySummary($tenant),
            'movements'    => $this->reports->movementTotals($tenant, $period),
            'topProducts'  => $this->reports->salesByProduct($tenant, $period, 10),
            'slowMoving'   => $this->reports->slowMovingProducts($tenant, $period, 10),
            'byChannel'    => $this->reports->salesByChannel($tenant, $period),
            'lowStock'     => $this->reports->lowStockProducts($tenant, 10),
            'plan'         => $this->usage->planFor($tenant),
        ]);
    }

    /**
     * Attach a percentage change against the previous window.
     * A zero baseline has no meaningful percentage, so it is reported as null
     * rather than an invented 100% or divide-by-zero.
     *
     * @param  array<string, int>  $now
     * @param  array<string, int>  $prev
     * @return array<string, int|float|null>
     */
    private function compare(array $now, array $prev): array
    {
        $out = [];

        foreach ($now as $key => $value) {
            $baseline = (int) ($prev[$key] ?? 0);
            $out[$key] = $value;
            $out[$key.'_prev'] = $baseline;
            $out[$key.'_change'] = $baseline > 0
                ? round((($value - $baseline) / $baseline) * 100, 1)
                : null;
        }

        return $out;
    }
}