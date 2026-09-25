<?php

namespace App\Http\Controllers;

use App\Services\BusinessAuthorization;
use App\Services\SalesDashboardService;
use App\Services\UsageService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly BusinessAuthorization $business,
        private readonly SalesDashboardService $salesDashboard,
    ) {}

    public function __invoke(Request $request)
    {
        $ctx = app('tenant.context');
        $ctx->check();

        $tenant = $ctx->tenant()->load('activeSubscription.plan');
        $subscription = $tenant->activeSubscription()->with('plan')->first();
        $usage = app(UsageService::class);
        $apiEnabled = $usage->allows($tenant, 'api_access');

        // Sales visibility remains permission-aware. Users without sales.view keep the
        // existing dashboard without receiving a new, unauthorized business summary.
        $salesOverview = $this->business->can('sales.view')
            ? $this->salesDashboard->data()
            : null;

        return view('dashboard', [
            'tenant' => $tenant,
            'subscription' => $subscription,
            'usage' => collect(['max_users' => $usage->usage($tenant, 'max_users')]),
            'apiUsage' => ['used' => $usage->usage($tenant, 'api_calls'), 'limit' => $usage->limit($tenant, 'api_calls')],
            'apiEnabled' => $apiEnabled,
            'recentPayments' => $tenant->payments()->latest()->take(5)->get(),
            'recentInvoices' => $tenant->invoices()->latest()->take(5)->get(),
            'notifications' => $request->user()->notifications()->latest()->take(8)->get(),
            'salesOverview' => $salesOverview,
        ]);
    }
}
