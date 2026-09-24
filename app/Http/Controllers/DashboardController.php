<?php

namespace App\Http\Controllers;

use App\Services\UsageService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $ctx = app('tenant.context');
        $ctx->check();

        $tenant = $ctx->tenant()->load('activeSubscription.plan');
        $subscription = $tenant->activeSubscription()->with('plan')->first();
        $usage = app(UsageService::class);

        return view('dashboard', [
            'tenant' => $tenant,
            'subscription' => $subscription,
            'usage' => collect(['max_users' => $usage->usage($tenant, 'max_users')]),
            'apiUsage' => ['used' => $usage->usage($tenant, 'api_calls'), 'limit' => $usage->limit($tenant, 'api_calls')],
            'recentPayments' => $tenant->payments()->latest()->take(5)->get(),
            'recentInvoices' => $tenant->invoices()->latest()->take(5)->get(),
            'notifications' => $request->user()->notifications()->latest()->take(8)->get(),
        ]);
    }
}
