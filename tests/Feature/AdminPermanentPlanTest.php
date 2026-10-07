<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ModuleManager;
use App\Services\UsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Platform administrators hold the Business plan permanently.
 *
 * The rule lives in exactly one place — Tenant::effectivePlan() — because feature
 * gating, limit checks and the billing screen all read through it. These tests assert
 * that ONE rule is honoured by every consumer, and just as importantly that an ordinary
 * user is completely unaffected.
 */
class AdminPermanentPlanTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $workspace;

    private User $admin;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);

        $this->member = User::create([
            'name' => 'Member', 'email' => 'plan-member@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->admin = User::create([
            'name' => 'Platform Admin', 'email' => 'plan-admin@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
            'is_platform_admin' => true,
        ]);

        $this->workspace = Tenant::create([
            'name' => 'Toko Admin', 'slug' => 'toko-admin',
            'owner_id' => $this->member->id, 'status' => 'active',
        ]);
    }

    private function addMember(Tenant $tenant, User $user, string $role = 'Admin'): void
    {
        $tenant->users()->attach($user->id, [
            'role' => $role, 'status' => 'active', 'joined_at' => now(),
        ]);
    }

    private function subscribe(string $slug, string $status = 'active', array $dates = []): Subscription
    {
        return Subscription::create(array_merge([
            'tenant_id' => $this->workspace->id,
            'plan_id' => Plan::where('slug', $slug)->firstOrFail()->id,
            'status' => $status,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ], $dates));
    }

    private function billingHtml(User $user): string
    {
        return $this->actingAs($user)
            ->withSession(['tenant_id' => $this->workspace->id])
            ->get('/billing')
            ->assertOk()
            ->getContent();
    }

    // -------------------------------------------------------------------- admin

    public function test_a_workspace_with_a_platform_admin_resolves_the_business_plan(): void
    {
        $this->addMember($this->workspace, $this->admin);

        $this->assertTrue($this->workspace->hasPlatformAdmin());
        $this->assertSame(
            'business',
            $this->workspace->effectivePlan()?->slug,
            'An admin workspace must resolve the Business plan.'
        );
    }

    public function test_the_admin_plan_holds_even_with_no_subscription_row_at_all(): void
    {
        $this->addMember($this->workspace, $this->admin);

        $this->assertNull($this->workspace->activeSubscription);
        $this->assertSame('business', $this->workspace->effectivePlan()?->slug);
    }

    public function test_the_admin_plan_survives_a_lapsed_subscription(): void
    {
        $this->addMember($this->workspace, $this->admin);

        $this->subscribe('starter', 'expired', [
            'current_period_start' => now()->subYear(),
            'current_period_end' => now()->subDay(),
            'ended_at' => now()->subDay(),
        ]);

        // The historical row still records what was bought…
        $this->assertSame('starter', $this->workspace->subscriptions()->first()->plan->slug);

        // …but it no longer governs an admin's workspace.
        $this->assertSame('business', $this->workspace->effectivePlan()?->slug);
    }

    public function test_business_entitlements_are_granted_to_an_admin_workspace(): void
    {
        $this->addMember($this->workspace, $this->admin);

        $usage = app(UsageService::class);
        $business = Plan::where('slug', 'business')->firstOrFail();

        // Business's ceilings, read through the same limit() the UI and middleware use.
        $this->assertSame($business->limit('max_users'), $usage->limit($this->workspace, 'max_users'));

        // And the capability gates, which is what "has Business features" means. These
        // throw SubscriptionLimitException when refused, so simply reaching the next
        // line proves the gate opened.
        $usage->enforceFeature($this->workspace, 'advanced_reports');
        $usage->enforceFeature($this->workspace, 'api_access');
        $usage->enforceFeature($this->workspace, 'audit_log');

        $this->assertSame('business', $usage->planFor($this->workspace)?->slug);
    }

    public function test_module_gating_also_sees_the_admin_plan(): void
    {
        $modules = app(ModuleManager::class);

        // The shipped catalogue declares no min_plan gates at all, so one is created
        // here to exercise the gate rather than asserting nothing.
        $module = Module::query()->where('key', 'pos')->firstOrFail();
        $module->update(['min_plan' => 'pro']);

        // A plain member on Starter is below the gate.
        $this->addMember($this->workspace, $this->member, 'Owner');
        $this->subscribe('starter');

        $this->assertNotNull(
            $modules->planGate($module->fresh(), $this->workspace),
            'A Starter workspace must still be gated out of a Pro module.'
        );

        // The same member promoted to Pro is let through — proving the gate is real.
        $this->workspace->subscriptions()->update(['plan_id' => Plan::where('slug', 'pro')->firstOrFail()->id]);

        $this->assertNull($modules->planGate($module->fresh(), $this->workspace->fresh()));
    }

    public function test_an_admin_workspace_passes_every_module_gate_without_one(): void
    {
        $modules = app(ModuleManager::class);
        $module = Module::query()->where('key', 'pos')->firstOrFail();
        $module->update(['min_plan' => 'pro']);

        $this->addMember($this->workspace, $this->admin);

        // Business sits above Pro, so the admin is never gated — even with no
        // subscription row on the workspace at all.
        $this->assertNull(
            $modules->planGate($module->fresh(), $this->workspace),
            'An admin workspace must never be locked out of a module by its plan.'
        );
    }

    public function test_the_billing_screen_says_active_forever_for_an_admin(): void
    {
        $this->addMember($this->workspace, $this->admin);

        $html = $this->billingHtml($this->admin);

        $this->assertStringContainsString(__('Active Forever'), $html);

        // The resolved plan is shown, and no renewal date is offered.
        $this->assertStringContainsString('Business', $html);
        $this->assertStringNotContainsString('Renews', $html);
    }

    public function test_the_billing_screen_is_labelled_subscription_not_billing(): void
    {
        $this->addMember($this->workspace, $this->admin);

        $html = $this->billingHtml($this->admin);

        $this->assertStringContainsString(__('Subscription'), $html);

        // Asserted as a literal rather than __('Billing'): a dot-less key that happens
        // to match a translation GROUP name resolves to that whole file as an array —
        // the failure LangSyncCommand exists to prevent.
        $this->assertStringNotContainsString('Billing', $html);

        // The route is unchanged, so only the wording moved.
        $this->assertStringContainsString(route('billing.index'), $html);
    }

    // ---------------------------------------------------------------- non-admin

    public function test_an_ordinary_member_does_not_get_the_business_plan(): void
    {
        $this->addMember($this->workspace, $this->member, 'Owner');

        $this->assertFalse($this->workspace->hasPlatformAdmin());
        $this->assertNull($this->workspace->effectivePlan(), 'No subscription means no plan at all.');
    }

    public function test_an_ordinary_member_keeps_its_own_subscribed_plan(): void
    {
        $this->addMember($this->workspace, $this->member, 'Owner');
        $this->subscribe('starter');

        $this->assertSame('starter', $this->workspace->effectivePlan()?->slug);
    }

    public function test_an_ordinary_member_still_sees_the_normal_status_not_active_forever(): void
    {
        $this->addMember($this->workspace, $this->member, 'Owner');
        $this->subscribe('starter');

        $html = $this->billingHtml($this->member);

        $this->assertStringNotContainsString(__('Active Forever'), $html);
        $this->assertStringContainsString('Renews', $html);
    }

    public function test_an_ordinary_member_is_still_refused_a_paid_feature(): void
    {
        $this->addMember($this->workspace, $this->member, 'Owner');
        $this->subscribe('starter');

        // Starter has api_access = false. The gate must still refuse.
        $this->expectException(\App\Exceptions\SubscriptionLimitException::class);

        app(UsageService::class)->enforceFeature($this->workspace, 'api_access');
    }

    public function test_a_removed_admin_member_stops_granting_the_plan(): void
    {
        $this->addMember($this->workspace, $this->admin);

        $this->assertTrue($this->workspace->hasPlatformAdmin());

        $this->workspace->memberships()
            ->where('user_id', $this->admin->id)
            ->update(['status' => 'removed']);

        $this->assertFalse(
            $this->workspace->fresh()->hasPlatformAdmin(),
            'A removed member must not keep granting a permanent plan.'
        );
    }

    public function test_another_workspaces_admin_does_not_leak_across(): void
    {
        $other = Tenant::create([
            'name' => 'Toko Ordinary', 'slug' => 'toko-ordinary',
            'owner_id' => $this->member->id, 'status' => 'active',
        ]);
        $this->addMember($other, $this->member, 'Owner');

        $this->addMember($this->workspace, $this->admin);

        $this->assertTrue($this->workspace->hasPlatformAdmin());
        $this->assertFalse($other->hasPlatformAdmin());
        $this->assertNull($other->effectivePlan());
    }

    // ------------------------------------------------------------------ labels

    public function test_the_navigation_entry_is_renamed_to_subscription(): void
    {
        $navigation = file_get_contents(base_path('resources/views/layouts/navigation.blade.php'));

        $this->assertStringContainsString("__('Subscription')", $navigation);
        $this->assertStringNotContainsString("__('Billing')", $navigation);
    }

    public function test_the_route_is_deliberately_unchanged(): void
    {
        // The label moved; the URL did not. Renaming /billing would break every stored
        // link, bookmark and redirect for what is only a wording change.
        $this->assertSame('/billing', route('billing.index', [], false));
    }

    public function test_invoice_and_payment_wording_is_not_renamed(): void
    {
        $this->addMember($this->workspace, $this->admin);

        $html = $this->billingHtml($this->admin);

        // These sections are genuinely invoices and payments, so they keep their own
        // terminology — the rename is scoped to plan/subscription management only.
        // Asserted as literals because these headings are currently plain text rather
        // than __() keys. The invoice list is always rendered; the payment notice is a
        // modal that only exists when a payment is outstanding.
        $this->assertStringContainsString('>Invoices<', $html);
        $this->assertStringContainsString('>Payment history<', $html);
    }
}