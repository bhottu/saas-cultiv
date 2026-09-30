<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Plan-locked navigation entries.
 *
 * Premium surfaces stay VISIBLE on a lower plan — a Free user must be able to see
 * what the higher plans add — but carry a lock marker and an upgrade hint naming the
 * plans that include the feature. The marker is a visual indication only: the routes
 * are still gated server-side by the plan.feature middleware / the feature checks in
 * the controllers, so a locked entry can never be turned into access by clicking it.
 */
class PremiumNavLockTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->owner = User::create([
            'name' => 'Lock Owner',
            'email' => 'lock-owner@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $this->tenant = Tenant::create([
            'name' => 'Lock Workspace',
            'slug' => 'lock-workspace',
            'owner_id' => $this->owner->id,
        ]);

        $this->tenant->users()->attach($this->owner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);
    }

    /** Put the workspace on $planSlug. Without a call the Free tier applies. */
    private function subscribe(string $planSlug): void
    {
        $plan = Plan::where('slug', $planSlug)->firstOrFail();

        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'amount' => $plan->price_monthly,
            'currency' => $plan->currency,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);
    }

    private function asOwner()
    {
        return $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id]);
    }

    /** The rendered desktop sidebar of $url, isolated from the page content. */
    private function sidebar(string $url = '/dashboard'): string
    {
        $html = $this->asOwner()->get($url)->assertOk()->getContent();

        preg_match('/<aside id="sidebar".*?<\/aside>/s', $html, $matches);

        $this->assertNotEmpty($matches, "The shared shell sidebar is missing on {$url}.");

        return $matches[0];
    }

    /**
     * Assert the anchor for $href carries the plan-lock marker (same tag, so the lock
     * can never be attached to a different entry than the link it belongs to).
     *
     * @param list<string> $hrefs
     */
    private function assertLockMarked(string $sidebar, array $hrefs): void
    {
        foreach ($hrefs as $href) {
            $this->assertMatchesRegularExpression(
                '/<a [^>]*href="'.preg_quote($href, '/').'"[^>]*data-plan-locked="1"/',
                $sidebar,
                "The menu entry for {$href} is not lock-marked."
            );
        }
    }

    public function test_a_free_workspace_keeps_premium_entries_visible_but_lock_marked(): void
    {
        $sidebar = $this->sidebar();

        // Visible and still clickable: the label is neither hidden nor blurred.
        foreach (['Advanced Reports', 'Analytics', 'Audit Log', 'API Tokens'] as $label) {
            $this->assertStringContainsString($label, $sidebar);
        }

        // Every entitlement-gated entry carries the marker, including the one that only
        // needs manage_settings (Audit Log) and the Business-only API Tokens page.
        $this->assertLockMarked($sidebar, [
            route('reports.index'),
            route('analytics.index'),
            route('audit-logs.index'),
            route('tokens.index'),
        ]);

        // The hint names the plans that actually include the feature (UsageService is the
        // single source for that mapping, shared with the server-side enforcement message).
        $this->assertStringContainsString('Included in Pro and Business plans. Upgrade to unlock it.', $sidebar);
        $this->assertStringContainsString('Included in Business plans. Upgrade to unlock it.', $sidebar);
    }

    public function test_each_plan_only_locks_what_it_does_not_include(): void
    {
        // Pro has advanced reports, analytics and the audit log — but not API access.
        $this->subscribe('pro');
        $sidebar = $this->sidebar();

        $this->assertLockMarked($sidebar, [route('tokens.index')]);
        $this->assertSame(1, substr_count($sidebar, 'data-plan-locked="1"'));

        foreach ([route('reports.index'), route('analytics.index'), route('audit-logs.index')] as $href) {
            $this->assertStringContainsString('href="'.$href.'"', $sidebar);
        }
    }

    public function test_an_entitled_workspace_sees_the_same_entries_without_any_lock(): void
    {
        $this->subscribe('business');

        $sidebar = $this->sidebar();

        $this->assertStringNotContainsString('data-plan-locked="1"', $sidebar);
        $this->assertStringNotContainsString('Upgrade to unlock it', $sidebar);

        foreach ([route('reports.index'), route('analytics.index'), route('audit-logs.index'), route('tokens.index')] as $href) {
            $this->assertStringContainsString('href="'.$href.'"', $sidebar);
        }
    }

    public function test_the_lock_is_visual_only_because_the_backend_still_refuses(): void
    {
        // A Free workspace: the menu advertises the pages, the server still says no.
        $this->asOwner()->get('/reports')->assertForbidden();
        $this->asOwner()->get('/reports/sales')->assertForbidden();
        $this->asOwner()->get('/analytics')->assertForbidden();

        // API tokens render a page (with the upgrade notice), but creating one is refused.
        $this->asOwner()->get('/tokens')->assertOk()
            ->assertSee('API Access is available on the Business plan.', false);
        $this->asOwner()->post('/tokens', ['name' => 'CLI'])->assertForbidden();
        $this->assertSame(0, $this->owner->tokens()->count());
    }

    public function test_the_mobile_drawer_uses_the_same_locked_navigation_source(): void
    {
        $html = $this->asOwner()->get('/dashboard')->assertOk()->getContent();

        preg_match('/<aside id="sidebar".*?<\/aside>/s', $html, $matches);
        $this->assertNotEmpty($matches);

        $desktop = substr_count($matches[0], 'data-plan-locked="1"');

        // One navigation source, rendered twice (desktop sidebar + mobile drawer).
        $this->assertGreaterThan(0, $desktop, 'A Free workspace should have locked entries at all.');
        $this->assertSame($desktop * 2, substr_count($html, 'data-plan-locked="1"'));
    }
}
