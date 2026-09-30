<?php

namespace App\Http\Controllers\Admin;

use App\Models\Plan;
use Illuminate\Http\Request;

/**
 * Plan catalogue. Read-only on purpose: pricing and entitlements are the source of
 * truth in the plans table and are not edited from the admin UI.
 */
class PlanController extends AdminController
{
    public function index(Request $request)
    {
        $plans = Plan::orderBy('sort_order')->get()->map(function (Plan $plan) {
            return [
                'plan' => $plan,
                'workspaces' => $this->acrossTenants(\App\Models\Subscription::class)
                    ->where('plan_id', $plan->id)
                    ->whereIn('status', ['active', 'trialing'])
                    ->distinct()
                    ->count('tenant_id'),
            ];
        });

        return view('admin.plans.index', ['plans' => $plans]);
    }
}