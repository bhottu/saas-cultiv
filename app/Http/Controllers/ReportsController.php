<?php

namespace App\Http\Controllers;

use App\Services\AdvancedReportsService;
use App\Services\BusinessAuthorization;
use App\Services\ReportPeriod;
use Illuminate\Http\Request;

/**
 * Advanced reports (Pro/Business entitlement).
 *
 * The controller resolves the window and delegates every aggregation to
 * AdvancedReportsService, so a report can never compute its own totals and
 * drift away from the other reports.
 */
class ReportsController extends Controller
{
    public function __construct(
        private readonly BusinessAuthorization $auth,
        private readonly AdvancedReportsService $reports,
    ) {}

    public function index(Request $request)
    {
        $this->auth->authorize('reports.view');

        return view('reports.index');
    }

    public function sales(Request $request)
    {
        $this->auth->authorize('reports.view');
        $tenant = $this->tenant($request);
        $period = $this->period($request);

        $current = $this->reports->salesSummary($tenant, $period);
        $previous = $this->reports->salesSummary($tenant, $period->previous());

        return view('reports.sales', [
            'period'        => $period,
            'filters'       => $this->filters($request),
            'current'       => $current,
            'previous'      => $previous,
            'trend'         => $this->reports->salesTrend($tenant, $period),
            'byProduct'     => $this->reports->salesByProduct($tenant, $period),
            'byCategory'    => $this->reports->salesByCategory($tenant, $period),
            'byBrand'       => $this->reports->salesByBrand($tenant, $period),
            'byCustomer'    => $this->reports->salesByCustomer($tenant, $period),
            'byChannel'     => $this->reports->salesByChannel($tenant, $period),
            'byUser'        => $this->reports->salesByUser($tenant, $period),
            'noSales'       => $this->reports->productsWithoutSales($tenant, $period),
            'slowMoving'    => $this->reports->slowMovingProducts($tenant, $period),
        ]);
    }

    public function inventory(Request $request)
    {
        $this->auth->authorize('reports.view');
        $tenant = $this->tenant($request);
        $period = $this->period($request);

        return view('reports.inventory', [
            'period'    => $period,
            'filters'   => $this->filters($request),
            'summary'   => $this->reports->inventorySummary($tenant),
            'movements' => $this->reports->movementTotals($tenant, $period),
            'lowStock'  => $this->reports->lowStockProducts($tenant),
            'log'       => $this->reports->stockMovements($tenant, $period, 100),
        ]);
    }

    public function purchases(Request $request)
    {
        $this->auth->authorize('reports.view');
        $tenant = $this->tenant($request);
        $period = $this->period($request);

        return view('reports.purchases', [
            'period'     => $period,
            'filters'    => $this->filters($request),
            'summary'    => $this->reports->purchaseSummary($tenant, $period),
            'bySupplier' => $this->reports->purchasesBySupplier($tenant, $period),
            'byProduct'  => $this->reports->purchasesByProduct($tenant, $period),
        ]);
    }

    public function customers(Request $request)
    {
        $this->auth->authorize('reports.view');
        $tenant = $this->tenant($request);
        $period = $this->period($request);

        return view('reports.customers', [
            'period'    => $period,
            'filters'   => $this->filters($request),
            'summary'   => $this->reports->customerSummary($tenant, $period),
            'byCustomer' => $this->reports->salesByCustomer($tenant, $period, 50),
        ]);
    }

    public function profit(Request $request)
    {
        $this->auth->authorize('reports.view');
        $tenant = $this->tenant($request);
        $period = $this->period($request);

        return view('reports.profit', [
            'period'      => $period,
            'filters'     => $this->filters($request),
            'current'     => $this->reports->profitSummary($tenant, $period),
            'previous'    => $this->reports->profitSummary($tenant, $period->previous()),
            'expenses'    => $this->reports->expensesByCategory($tenant, $period),
        ]);
    }

    private function tenant(Request $request): \App\Models\Tenant
    {
        $ctx = app('tenant.context');
        $ctx->check();

        return $ctx->tenant();
    }

    private function period(Request $request): ReportPeriod
    {
        return ReportPeriod::resolve(
            $request->string('period')->toString(),
            $request->string('from')->toString() ?: null,
            $request->string('to')->toString() ?: null,
        );
    }

    /** @return array<string, string> */
    private function filters(Request $request): array
    {
        return [
            'period' => $request->string('period')->toString() ?: 'month',
            'from'   => $request->string('from')->toString(),
            'to'     => $request->string('to')->toString(),
        ];
    }
}