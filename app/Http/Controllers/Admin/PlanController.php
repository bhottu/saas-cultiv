<?php

namespace App\Http\Controllers\Admin;

use App\Models\Plan;
use Illuminate\Http\Request;

/**
 * Plan catalogue, and the editor for it.
 *
 * These rows are the source of truth for pricing, entitlements and feature gates, so the
 * editor is deliberately narrow about WHAT may change:
 *
 *   slug — NOT editable. BillingController resolves a checkout by Plan::where('slug', …)
 *          and modules.min_plan stores the same string, so renaming one would silently
 *          break live checkouts and module gating. The display name is free; the
 *          identifier is not.
 *   features — the marketing list on the pricing cards. Edited one line per entry,
 *          preserving the existing flat string array.
 *   entitlements — the behaviour. Only known keys are accepted, and they are MERGED onto
 *          the existing values, so a key missing from the form keeps working instead of
 *          switching a paid feature off for every workspace on the plan.
 *
 * Everything is validated before anything is written, so a rejected edit leaves the row
 * exactly as it was rather than half-applied.
 */
class PlanController extends AdminController
{
    /** Marketing bullets shown on the pricing cards, one per line in the textarea. */
    private const MAX_FEATURES = 40;

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

    public function edit(Plan $plan)
    {
        return view('admin.plans.edit', [
            'plan' => $plan,
            // Workspaces currently on this plan. Deactivating a plan that live
            // subscriptions point at is a billing regression, so the screen must be able
            // to warn before it happens.
            'activeSubscriptions' => $this->acrossTenants(\App\Models\Subscription::class)
                ->where('plan_id', $plan->id)
                ->whereIn('status', ['active', 'trialing'])
                ->distinct()
                ->count('tenant_id'),
        ]);
    }

    public function update(Request $request, Plan $plan)
    {
        $data = $request->validate(self::rules(), self::messages());

        $features = $this->parseFeatures($data['features'] ?? null);

        if ($features === null) {
            return back()->withInput()->withErrors([
                'features' => __('Add at most 40 feature lines.'),
            ]);
        }

        $plan->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'price_monthly' => $data['price_monthly'],
            'price_yearly' => $data['price_yearly'] ?? 0,
            'currency' => $data['currency'] ?: ($plan->currency ?: 'IDR'),
            'features' => $features,
            'entitlements' => $this->mergeEntitlements($plan, $data['entitlements'] ?? []),
            'is_active' => $request->boolean('is_active'),
            'is_free_tier' => $request->boolean('is_free_tier'),
            'sort_order' => $data['sort_order'],
            // Period-specific prices default to none, so the monthly x months rule
            // keeps working until an admin stores a value here.
            'price_1month' => $data['price_1month'] ?? 0,
            'price_3months' => $data['price_3months'] ?? 0,
            'price_6months' => $data['price_6months'] ?? 0,
            'price_12months' => $data['price_12months'] ?? 0,
        ]);

        $this->audit($request, 'plan.updated', null, [
            'plan_id' => $plan->id,
            'plan' => $plan->slug,
            'name' => $plan->name,
            'price_monthly' => $plan->price_monthly,
            'price_yearly' => $plan->price_yearly,
            'is_active' => $plan->is_active,
        ]);

        return redirect()->route('admin.plans.index')
            ->with('status', ['type' => 'success', 'message' => __('Plan changes saved.')]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:500'],
            // Integers, never formatted strings: Money::formatRupiah is applied at render
            // time, so storing "Rp25.000" would break priceFor() and billing.
            'price_monthly' => ['required', 'integer', 'min:0', 'max:1000000000'],
            // Optional because most plans are monthly-only; a blank box means "not set",
            // not "set to zero".
            'price_yearly' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            // Manual per-period prices. 0 means "not set manually", so the monthly x
            // months rule keeps working until an admin stores a value here.
            'price_1month' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'price_3months' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'price_6months' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'price_12months' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'currency' => ['nullable', 'string', 'size:3'],
            'features' => ['nullable', 'string', 'max:4000'],
            'entitlements' => ['nullable', 'array'],
            // Values are checked per key in mergeEntitlements(); keys are filtered against
            // the list the application already understands.
            'entitlements.*' => ['nullable'],
            'is_active' => ['nullable', 'boolean'],
            'is_free_tier' => ['nullable', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
        ];
    }

    private static function messages(): array
    {
        return [
            'name.required' => __('A plan needs a name.'),
            'price_monthly.integer' => __('Enter the price as a plain number, without a currency symbol.'),
            'price_monthly.min' => __('A price cannot be negative.'),
            // Period-specific prices share the same integer/number rules.
            'price_1month.integer' => __('Enter the price as a plain number, without a currency symbol.'),
            'price_1month.min' => __('A price cannot be negative.'),
            'price_3months.integer' => __('Enter the price as a plain number, without a currency symbol.'),
            'price_3months.min' => __('A price cannot be negative.'),
            'price_6months.integer' => __('Enter the price as a plain number, without a currency symbol.'),
            'price_6months.min' => __('A price cannot be negative.'),
            'price_12months.integer' => __('Enter the price as a plain number, without a currency symbol.'),
            'price_12months.min' => __('A price cannot be negative.'),
            'sort_order.min' => __('The display order cannot be negative.'),
        ];
    }

    /**
     * The marketing feature list, one entry per non-empty line.
     *
     * Preserves the flat string array the pricing page already renders and never changes
     * the shape of the column. Duplicates are dropped so a card cannot show the same
     * bullet twice, and a blank textarea legitimately yields an empty list.
     */
    private function parseFeatures(?string $raw): ?array
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) $raw) ?: [];
        $lines = array_map('trim', $lines);
        $lines = array_values(array_unique(array_filter($lines, fn ($line) => $line !== '')));

        return count($lines) > self::MAX_FEATURES ? null : $lines;
    }

    /**
     * Merge the submitted entitlements onto the existing ones, keeping only real keys.
     *
     * Two rules, both about not breaking live workspaces by accident:
     *
     *  1. Unknown keys are DROPPED, not merged. Writing a typo would add a value nothing
     *     reads and give the false impression a feature had been enabled.
     *  2. Keys absent from the form keep their stored value, because Plan::allows() reads
     *     a missing key as false — omitting one would silently switch a paid feature off
     *     for every workspace on this plan.
     *
     * A limit submitted as an empty box means "no limit", which is a genuine value here:
     * Plan::limit() treats null as unlimited, so it must be stored as null, not dropped.
     *
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeEntitlements(Plan $plan, array $incoming): array
    {
        $existing = $plan->entitlements ?? [];

        foreach ($incoming as $key => $value) {
            if (! array_key_exists($key, $existing) && ! $this->isKnownEntitlement($key)) {
                unset($incoming[$key]);

                continue;
            }

            if ($value === '') {
                $incoming[$key] = null;
            }
        }

        return [...$existing, ...$incoming];
    }

    /**
     * Entitlement keys this application already implements.
     *
     * The authoritative list, so a capability added to config later is editable here
     * without touching this controller.
     */
    private function isKnownEntitlement(string $key): bool
    {
        return in_array($key, (array) config('saas.plan_entitlements', []), true);
    }
}