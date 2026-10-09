@props(['plan'])

{{--
    The plan card's capability list, shared by the pricing page (/), /billing and
    /admin/plans so all three present a plan identically.

    Two clearly separated groups:

      FEATURES  what the plan itself includes (limits + included capabilities)
      MODULES   add-on application modules, available to every plan that clears the
                module's own plan gate

    Modules are deliberately rendered LIGHTER than features — smaller, muted, with a
    plain bullet instead of a checkmark, under a small caps label. It reads as part of
    the same card, not as a separate package or a second card.

    The MODULE LIST is data-driven from the `modules` catalogue, so adding a module to
    the catalogue surfaces it here with no change to this component. Modules are NOT
    plan entitlements: they carry their own `min_plan` gate, which is already enforced
    by ModuleManager::planGate() at install/activation time. Nothing here changes any
    entitlement.
--}}

@php
    // Entitlement-backed limits, rendered from the plan itself (never from a list of
    // hard-coded strings) so the numbers can never drift from what is enforced.
    $limits = [
        'workspaces' => $plan->displayLimit('max_workspaces'),
        'users'      => $plan->displayLimit('max_users'),
        'products'   => $plan->displayLimit('max_products'),
    ];

    $pluralise = static function (string $limit, string $noun): string {
        return $limit === '1' ? '1 '.$noun : $limit.' '.$noun.'s';
    };

    $headline = [
        $pluralise($limits['workspaces'], 'Workspace'),
        $pluralise($limits['users'], 'User'),
        $limits['products'] === 'Unlimited' ? 'Unlimited Products' : $limits['products'].' Products',
        'Unlimited Customers',
    ];

    // Capability labels, derived from the plan's own entitlements so a plan can never
    // advertise a capability it does not have. The internal identifier is unchanged
    // (basic_sales etc.) — only the label shown to users differs.
    $capabilities = [
        'basic_sales'           => 'Sales Management',
        'basic_stock'           => 'Inventory Management',
        'basic_purchases'       => 'Purchase Management',
        'basic_reports'         => 'Standard Reports',
        'advanced_reports'      => 'Advanced Reports',
        'advanced_permissions'  => 'Advanced Permissions',
        'api_access'            => 'API Access',
        'audit_log'             => 'Audit Log',
        'advanced_analytics'    => 'Advanced Analytics',
    ];

    $included = [];
    foreach ($capabilities as $key => $label) {
        if ($plan->allows($key)) {
            $included[] = $label;

            // API Access is sold as a RATE and a QUOTA, not as a bare checkbox, so the
            // card states both numbers — read from the plan's own entitlements so what
            // is advertised can never drift from what the server enforces (§28).
            if ($key === 'api_access') {
                $included[] = number_format((int) $plan->limit('api_rate_limit')).' requests/minute';
                $included[] = $plan->displayLimit('max_api_calls').' requests/month';
            }
        }
    }

    // Any extra capability string stored on the plan that is not already represented
    // (a limit above or a mapped capability) is still shown, so a newly seeded
    // entitlement is never hidden.
    $known = array_merge($headline, $capabilities);
    $extra = array_values(array_filter(
        $plan->features ?? [],
        static fn ($feature) => ! in_array($feature, $known, true)
            && ! str_starts_with($feature, 'Modules:')
            && ! str_starts_with($feature, 'Module:')
    ));

    $features = array_merge($headline, $included, $extra);

    // Only advertise modules this plan can actually install. Module feature
    // entitlements are distinct from workspace installation state.
    $availableModules = \App\Models\Module::query()->available()->ordered()->get()
        ->filter(function (\App\Models\Module $module) use ($plan) {
            $planFeature = config("modules.manifests.{$module->key}.plan_feature");
            if ($planFeature && ! $plan->allows($planFeature)) {
                return false;
            }

            if (! $module->min_plan) {
                return true;
            }

            $required = \App\Models\Plan::query()->where('slug', $module->min_plan)->first();

            return $required && $plan->sort_order >= $required->sort_order;
        })
        ->map(fn (\App\Models\Module $module) => $module->name)
        ->values();
@endphp

<div class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-1.5">
    {{ __('Features') }}
</div>

<ul class="space-y-1.5 text-sm text-gray-600">
    @foreach ($features as $feature)
        <li class="flex gap-2">
            <span class="text-green-500" aria-hidden="true">&#10003;</span>
            <span class="min-w-0 break-words">{{ $feature }}</span>
        </li>
    @endforeach
</ul>

@if ($availableModules->isNotEmpty())
    {{-- Lighter than the features above: muted, smaller, plain bullets, tight
         leading. Deliberately not a card and not a checkmark list, so it cannot be
         mistaken for a separate package. --}}
    <div class="mt-4 border-t border-gray-100 pt-3">
        <div class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">
            {{ __('Modules') }}
        </div>
        <ul class="mt-1.5 space-y-1 text-xs text-gray-500">
            @foreach ($availableModules as $moduleName)
                <li class="flex gap-1.5">
                    <span aria-hidden="true">&bull;</span>
                    <span class="min-w-0 break-words">{{ $moduleName }}</span>
                </li>
            @endforeach
        </ul>
    </div>
@endif
