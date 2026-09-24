<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        // Aggregated platform metrics only — no casual tenant data exposure.
        return view('admin.dashboard', [
            'tenantCount' => \App\Models\Tenant::count(),
            'userCount' => \App\Models\User::count(),
            'activeSubs' => \App\Models\Subscription::whereIn('status', ['active', 'trialing'])->count(),
            'mrr' => \App\Models\Subscription::where('status', 'active')->where('billing_cycle', 'monthly')->sum('amount')
                + (int) (\App\Models\Subscription::where('status', 'active')->where('billing_cycle', 'yearly')->sum('amount') / 12),
            'pendingPayments' => \App\Models\Payment::where('status', 'pending')->count(),
            'failedPayments' => \App\Models\Payment::whereIn('status', ['failed', 'expired'])->count(),
            'openInvoices' => \App\Models\Invoice::where('status', 'open')->count(),
            'webhookEvents' => \App\Models\WebhookEvent::latest()->take(15)->get(),
            'recentAudit' => \App\Models\AuditLog::latest()->take(15)->get(),
        ]);
    }
}
