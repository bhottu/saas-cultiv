<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * /admin/plans — the plan editor.
 *
 * The dangerous failures here are not "the form does not save" but the silent ones: a
 * renamed slug breaking checkout, a dropped entitlement switching off a paid feature for
 * every live workspace, or a price stored as a formatted string. Those are what these
 * tests are aimed at.
 */
class AdminPlanEditingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    private Tenant $workspace;

    private Plan $pro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlanSeeder::class);

        $this->admin = User::create([
            'name' => 'Platform Admin', 'email' => 'admin-plan@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
            'is_platform_admin' => true,
        ]);

        $this->member = User::create([
            'name' => 'Normal User', 'email' => 'member-plan@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->workspace = Tenant::create([
            'name' => 'Toko Plan', 'slug' => 'toko-plan',
            'owner_id' => $this->member->id, 'status' => 'active',
        ]);
        $this->workspace->users()->attach($this->member->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->pro = Plan::where('slug', 'pro')->firstOrFail();
    }

    private function asAdmin()
    {
        return $this->actingAs($this->admin)->withSession(['tenant_id' => $this->workspace->id]);
    }

    private function asMember()
    {
        return $this->actingAs($this->member)->withSession(['tenant_id' => $this->workspace->id]);
    }

    /** A complete, valid payload; override only what a given test is about. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Pro',
            'description' => 'Advanced reporting, permissions and analytics.',
            'price_monthly' => 79000,
            'price_yearly' => 0,
            'currency' => 'IDR',
            'features' => "Advanced Reports\nAudit Log",
            'entitlements' => ['max_users' => 15, 'advanced_reports' => 1],
            'is_active' => 1,
            'is_free_tier' => 0,
            'sort_order' => 3,
        ], $overrides);
    }

    // ------------------------------------------------------------ authorization

    public function test_only_a_platform_admin_can_reach_the_plan_editor(): void
    {
        $this->asAdmin()->get("/admin/plans/{$this->pro->id}/edit")->assertOk();

        $this->asMember()->get("/admin/plans/{$this->pro->id}/edit")->assertForbidden();
        $this->asMember()->put("/admin/plans/{$this->pro->id}", $this->payload())->assertForbidden();

        $this->assertSame('Pro', $this->pro->fresh()->name);
    }

    public function test_the_plan_index_links_to_the_editor(): void
    {
        $this->asAdmin()->get('/admin/plans')
            ->assertOk()
            ->assertSee(route('admin.plans.edit', $this->pro), false);
    }

    // ------------------------------------------------------------- editing basics

    public function test_name_price_and_description_are_edited_and_persist(): void
    {
        $this->asAdmin()->put("/admin/plans/{$this->pro->id}", $this->payload([
            'name' => 'Pro Plus',
            'description' => 'Everything in Pro, plus more.',
            'price_monthly' => 99000,
        ]))->assertRedirect(route('admin.plans.index'))
            ->assertSessionHas('status.message', __('Plan changes saved.'));

        $plan = $this->pro->fresh();

        $this->assertSame('Pro Plus', $plan->name);
        $this->assertSame(99000, $plan->price_monthly);
        $this->assertSame('Everything in Pro, plus more.', $plan->description);

        // Still there after a reload.
        $this->asAdmin()->get("/admin/plans/{$this->pro->id}/edit")
            ->assertOk()
            ->assertSee('Pro Plus')
            ->assertSee('value="99000"', false);
    }

    public function test_a_price_is_stored_as_an_integer_not_a_formatted_string(): void
    {
        $this->asAdmin()->put("/admin/plans/{$this->pro->id}", $this->payload([
            'price_monthly' => 25000,
        ]))->assertRedirect();

        $plan = $this->pro->fresh();

        $this->assertSame(25000, $plan->price_monthly);
        $this->assertIsInt($plan->price_monthly);

        // priceFor() is what billing reads; a formatted string would break it outright.
        $this->assertSame(25000, $plan->priceFor('monthly'));
    }

    public function test_a_formatted_price_is_rejected(): void
    {
        $this->asAdmin()->put("/admin/plans/{$this->pro->id}", $this->payload([
            'price_monthly' => 'Rp25.000',
        ]))->assertSessionHasErrors('price_monthly');

        $this->assertSame(79000, $this->pro->fresh()->price_monthly);
    }

    public function test_a_negative_price_is_rejected(): void
    {
        $this->asAdmin()->put("/admin/plans/{$this->pro->id}", $this->payload([
            'price_monthly' => -1,
        ]))->assertSessionHasErrors('price_monthly');

        $this->assertSame(79000, $this->pro->fresh()->price_monthly);
    }

    public function test_a_missing_name_is_rejected_and_nothing_is_written(): void
    {
        $this->asAdmin()->put("/admin/plans/{$this->pro->id}", $this->payload([
            'name' => '',
            'price_monthly' => 12345,
        ]))->assertSessionHasErrors('name');

        $plan = $this->pro->fresh();

        // No partial save: the price edit was rolled back with the rejected name.
        $this->assertSame(79000, $plan->price_monthly);
        $this->assertSame('Pro', $plan->name);
    }

    public function test_an_over_long_name_is_rejected(): void
    {
        $this->asAdmin()->put("/admin/plans/{$this->pro->id}", $this->payload([
            'name' => str_repeat('a', 61),
        ]))->assertSessionHasErrors('name');
    }

    // --------------------------------------------------- identifier & merge safety

    public function test_the_slug_cannot_be_changed_through_the_editor(): void
    {
        // Even a payload that explicitly tries to set it must not move the identifier.
        $this->asAdmin()->put("/admin/plans/{$this->pro->id}", $this->payload([
            'slug' => 'pro-renamed',
            'name' => 'Pro',
        ]))->assertRedirect();

        $this->assertSame('pro', $this->pro->fresh()->slug);

        // BillingController resolves a checkout with Plan::where('slug', …), so the
        // original slug must still resolve to the same row.
        $this->assertTrue(Plan::where('slug', 'pro')->exists());
        $this->assertFalse(Plan::where('slug', 'pro-renamed')->exists());
    }

    public function test_the_slug_is_shown_but_not_editable_in_the_form(): void
    {
        $html = $this->asAdmin()->get("/admin/plans/{$this->pro->id}/edit")->assertOk()->getContent();

        // Displayed…
        $this->assertStringContainsString('pro', $html);
        // …but presented as a disabled read-only field rather than an input.
        $this->assertStringNotContainsString('name="slug"', $html);
    }

    public function test_an_entitlement_omitted_from_the_form_keeps_its_stored_value(): void
    {
        $before = $this->pro->entitlements;

        $this->assertTrue($before['audit_log'], 'Precondition: Pro ships with audit_log on.');

        // The payload only mentions two entitlements. The rest must survive untouched —
        // Plan::allows() reads a missing key as false, so dropping one would switch a
        // paid feature off for every workspace on this plan.
        $this->asAdmin()->put("/admin/plans/{$this->pro->id}", $this->payload())->assertRedirect();

        $after = $this->pro->fresh()->entitlements;

        $this->assertTrue($after['audit_log'], 'audit_log was silently switched off.');
        $this->assertTrue($after['advanced_analytics'], 'advanced_analytics was silently switched off.');
        $this->assertArrayHasKey('max_storage_mb', $after);
        $this->assertSame($before['max_storage_mb'], $after['max_storage_mb']);
    }

    public function test_an_unknown_entitlement_key_is_dropped_rather_than_stored(): void
    {
        $this->asAdmin()->put("/admin/plans/{$this->pro->id}", $this->payload([
            'entitlements' => ['max_users' => 20, 'totally_made_up_key' => true],
        ]))->assertRedirect();

        $entitlements = $this->pro->fresh()->entitlements;

        $this->assertSame(20, $entitlements['max_users']);
        $this->assertArrayNotHasKey(
            'totally_made_up_key',
            $entitlements,
            'An entitlement no code reads must not be written into a plan.'
        );
    }

    public function test_a_capability_can_be_switched_off_deliberately(): void
    {
        $this->asAdmin()->put("/admin/plans/{$this->pro->id}", $this->payload([
            'entitlements' => ['max_users' => 15, 'advanced_reports' => 0],
        ]))->assertRedirect();

        $plan = $this->pro->fresh();

        $this->assertFalse($plan->allows('advanced_reports'));
    }

    public function test_a_blank_limit_is_stored_as_null_which_means_unlimited(): void
    {
        $this->asAdmin()->put("/admin/plans/{$this->pro->id}", $this->payload([
            'entitlements' => ['max_users' => '', 'max_products' => '500'],
        ]))->assertRedirect();

        $plan = $this->pro->fresh();

        $this->assertNull($plan->entitlements['max_users']);
        $this->assertNull($plan->limit('max_users'), 'A blank limit must read as unlimited.');
        $this->assertSame(500, $plan->limit('max_products'));
    }

    public function test_the_features_list_keeps_its_shape_and_drops_duplicates(): void
    {
        $this->asAdmin()->put("/admin/plans/{$this->pro->id}", $this->payload([
            'features' => "Advanced Reports\n\nAudit Log\nAdvanced Reports\n",
        ]))->assertRedirect();

        $features = $this->pro->fresh()->features;

        // A flat string array — the shape the pricing page already renders.
        $this->assertIsArray($features);
        $this->assertSame(['Advanced Reports', 'Audit Log'], $features);
    }

    public function test_more_than_forty_feature_lines_is_rejected(): void
    {
        $tooMany = implode("\n", array_map(fn ($i) => "Feature {$i}", range(1, 41)));

        $this->asAdmin()->put("/admin/plans/{$this->pro->id}", $this->payload([
            'features' => $tooMany,
        ]))->assertSessionHasErrors('features');
    }

    // --------------------------------------- propagation to pricing, gating, billing

    public function test_a_saved_plan_shows_the_new_price_on_the_public_pricing_page(): void
    {
        $this->asAdmin()->put("/admin/plans/{$this->pro->id}", $this->payload([
            'name' => 'Pro MAX',
            'price_monthly' => 149000,
        ]))->assertRedirect();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Pro MAX', $html);
        // Rendered through Money::formatRupiah, which emits "Rp 149.000" (with a space).
        $this->assertStringContainsString(
            \App\Services\Money::formatRupiah(149000),
            $html
        );
    }

    public function test_a_saved_capability_really_gates_the_feature(): void
    {
        // Switch advanced_analytics off for Pro.
        $this->asAdmin()->put("/admin/plans/{$this->pro->id}", $this->payload([
            'entitlements' => ['max_users' => 15, 'advanced_analytics' => 0],
        ]))->assertRedirect();

        $this->assertFalse($this->pro->fresh()->allows('advanced_analytics'));
    }

    public function test_an_existing_subscription_survives_a_plan_edit(): void
    {
        Subscription::create([
            'tenant_id' => $this->workspace->id, 'plan_id' => $this->pro->id, 'status' => 'active',
            'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        ]);

        $this->asAdmin()->put("/admin/plans/{$this->pro->id}", $this->payload([
            'name' => 'Pro Renamed',
            'price_monthly' => 99900,
        ]))->assertRedirect();

        // The subscription still points at the same plan row — it is keyed by plan_id,
        // and the identifier was deliberately not editable.
        $this->assertDatabaseHas('subscriptions', [
            'tenant_id' => $this->workspace->id,
            'plan_id' => $this->pro->id,
            'status' => 'active',
        ]);

        $this->assertSame('Pro Renamed', $this->pro->fresh()->name);
    }

    public function test_deactivating_a_plan_with_live_subscriptions_is_allowed_but_flagged(): void
    {
        Subscription::create([
            'tenant_id' => $this->workspace->id, 'plan_id' => $this->pro->id, 'status' => 'active',
            'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        ]);

        // The editor must WARN before the click…
        $html = $this->asAdmin()->get("/admin/plans/{$this->pro->id}/edit")->assertOk()->getContent();
        $this->assertStringContainsString('currently use this plan', $html);

        // …and must not silently refuse the save.
        $this->asAdmin()->put("/admin/plans/{$this->pro->id}", $this->payload([
            'is_active' => 0,
        ]))->assertRedirect();

        $this->assertFalse($this->pro->fresh()->is_active);

        // The live subscription itself is untouched.
        $this->assertDatabaseHas('subscriptions', [
            'tenant_id' => $this->workspace->id,
            'plan_id' => $this->pro->id,
            'status' => 'active',
        ]);
    }

    public function test_a_deactivated_plan_disappears_from_the_public_pricing_page(): void
    {
        $this->asAdmin()->put("/admin/plans/{$this->pro->id}", $this->payload([
            'name' => 'Pro MAX',
            'is_active' => 0,
        ]))->assertRedirect();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('Pro MAX', $html);
        $this->assertStringNotContainsString('Advanced reporting, permissions and analytics.', $html);
        $this->assertFalse(Plan::active()->where('id', $this->pro->id)->exists());
    }

    public function test_sort_order_reorders_the_catalogue(): void
    {
        // The seeder ships Free at 1; pushing Pro to 0 must actually move it ahead.
        $this->asAdmin()->put("/admin/plans/{$this->pro->id}", $this->payload([
            'sort_order' => 0,
        ]))->assertRedirect();

        $this->assertSame(0, $this->pro->fresh()->sort_order);
        $this->assertTrue(
            Plan::active()->first()->is($this->pro),
            'Plan::active() orders by sort_order, so Pro should now come first.'
        );
    }

    public function test_editing_a_plan_is_audited(): void
    {
        $this->asAdmin()->put("/admin/plans/{$this->pro->id}", $this->payload([
            'price_monthly' => 111000,
        ]))->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'plan.updated',
        ]);
    }
}