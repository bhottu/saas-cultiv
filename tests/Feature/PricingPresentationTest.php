<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Presentation-layer regression for the pricing cards.
 *
 * Every plan card is split into two groups:
 *
 *   FEATURES  what the plan includes (limits + entitlement-backed capabilities)
 *   MODULES   catalogue add-ons (POS today, barcode scanner and friends later),
 *             rendered lighter: smaller type, muted colour, plain bullets
 *
 * These assertions pin that structure so the two groups can never be merged back
 * together, and — more importantly — they prove the restyle never touched
 * entitlement identifiers: `basic_sales` and friends still gate access exactly as
 * before, and the cards read the module catalogue instead of hard-coding POS.
 */
class PricingPresentationTest extends TestCase
{
    use RefreshDatabase;

    /** Class of the feature list — the heavier, checkmark group. */
    private const FEATURES_LIST = 'space-y-1.5 text-sm text-gray-600';

    /** Class of the modules list — the lighter, bullet group. */
    private const MODULES_LIST = 'mt-1.5 space-y-1 text-xs text-gray-500';

    /** Class shared by the small-caps group labels ("Features" / "Modules"). */
    private const SECTION_LABEL = 'text-[11px] font-semibold uppercase tracking-wider';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->seed(ModuleSeeder::class);
    }

    public function test_pricing_page_splits_features_from_a_lighter_modules_section(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        // Capability labels are user-facing now; the internal identifier is not.
        $this->assertStringContainsString('Sales Management', $html);
        $this->assertStringContainsString('Inventory Management', $html);
        $this->assertStringContainsString('Purchase Management', $html);
        $this->assertStringContainsString('Standard Reports', $html);
        $this->assertStringNotContainsString('Basic Sales', $html);

        // Both groups exist, one per plan card.
        $features = $this->listsWith($html, self::FEATURES_LIST);
        $modules = $this->listsWith($html, self::MODULES_LIST);
        $this->assertCount(Plan::active()->count(), $features, 'each plan card needs a features list');
        $this->assertCount(Plan::active()->count(), $modules, 'each plan card needs a modules list');

        // ...and every card labels both groups, in order: Features first, Modules second.
        foreach ($this->labelsPerCard($html) as $pair) {
            $this->assertSame(['Features', 'Modules'], $pair, 'the two groups must stay labelled and ordered');
        }

        // POS lives ONLY in the modules group — it is not a feature and not a plan.
        foreach ($features as $list) {
            $this->assertStringNotContainsString('Point of Sale (POS)', $list);
        }
        foreach ($modules as $list) {
            $this->assertStringContainsString('Point of Sale (POS)', $list);
        }
        // One mention per plan card, plus one in the dedicated Modules section — the
        // page now explains modules in their own right, so a single extra mention is
        // expected. What still must hold is the per-card split checked above: POS lives
        // in the modules group and never in the features group.
        $this->assertSame(
            Plan::active()->count() + 1,
            substr_count($html, 'Point of Sale (POS)'),
            'POS should appear once per plan card, plus once in the Modules section'
        );
        $this->assertStringNotContainsString('Choose Point of Sale', $html, 'POS must not look like a subscribable plan');
    }

    public function test_modules_section_is_data_driven_from_the_catalogue(): void
    {
        // Adding a module to the platform catalogue must surface it on every card
        // without any change to the pricing component.
        Module::create([
            'key' => 'barcode',
            'slug' => 'barcode',
            'name' => 'Barcode Scanner',
            'description' => 'Scan barcodes from a phone camera.',
            'is_active' => true,
            'min_plan' => null,
            'sort_order' => 20,
        ]);

        $html = $this->get(route('home'))->assertOk()->getContent();

        foreach ($this->listsWith($html, self::MODULES_LIST) as $list) {
            $this->assertStringContainsString('Barcode Scanner', $list);
            $this->assertStringContainsString('Point of Sale (POS)', $list);
        }

        // ...and a deactivated module disappears from the cards.
        Module::where('key', 'barcode')->update(['is_active' => false]);
        $html = $this->get(route('home'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Barcode Scanner', $html);
    }

    public function test_billing_page_uses_the_same_structure(): void
    {
        $owner = User::create([
            'name' => 'Billing Buyer', 'email' => 'billing-buyer@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);
        $this->actingAs($owner)
            ->post('/tenants', ['name' => 'Billing Co'])
            ->assertRedirect('/dashboard');

        $html = $this->get(route('billing.index'))->assertOk()->getContent();

        $features = $this->listsWith($html, self::FEATURES_LIST);
        $modules = $this->listsWith($html, self::MODULES_LIST);
        $this->assertCount(Plan::active()->count(), $features);
        $this->assertCount(Plan::active()->count(), $modules);
        foreach ($modules as $list) {
            $this->assertStringContainsString('Point of Sale (POS)', $list);
        }
        $this->assertStringContainsString('Sales Management', $html);
        $this->assertStringNotContainsString('Basic Sales', $html);
    }

    public function test_admin_plans_page_shows_the_catalogue_modules_per_plan(): void
    {
        $admin = User::create([
            'name' => 'Plans Admin', 'email' => 'plans-admin@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
            'is_platform_admin' => true,
        ]);

        $html = $this->actingAs($admin)->get(route('admin.plans.index'))->assertOk()->getContent();

        // Every seeded plan row (active or legacy) lists the same modules the
        // pricing cards show, so admin and marketing never disagree.
        $this->assertSame(
            Plan::count(),
            substr_count($html, 'Point of Sale (POS)'),
            'each plan row should list POS, matching the pricing cards'
        );
    }

    public function test_entitlement_identifiers_are_untouched_by_the_redesign(): void
    {
        // The restyle must not rename, drop or retype a single entitlement key: the
        // server-side gates (SubscriptionLimitsTest et al.) read these exact names.
        $expected = [
            'max_workspaces', 'max_users', 'max_products', 'max_customers',
            'max_storage_mb', 'max_api_calls', 'api_rate_limit', 'basic_sales', 'basic_stock',
            'basic_purchases', 'basic_reports', 'advanced_reports',
            'advanced_permissions', 'api_access', 'audit_log', 'advanced_analytics',
        ];

        foreach (Plan::query()->get() as $plan) {
            $this->assertSame($expected, array_keys($plan->entitlements), "entitlement keys changed for {$plan->slug}");
        }

        $free = Plan::where('slug', 'free')->firstOrFail();
        $this->assertTrue($free->allows('basic_sales'));
        $this->assertTrue($free->allows('basic_stock'));
        $this->assertTrue($free->allows('basic_purchases'));
        $this->assertTrue($free->allows('basic_reports'));

        // POS is a catalogue module, not a plan entitlement — the redesign must not
        // have invented an entitlement key for it.
        $this->assertArrayNotHasKey('pos', $free->entitlements);
        $pos = Module::where('key', 'pos')->firstOrFail();
        $this->assertNull($pos->min_plan, 'POS stays available to every plan');
    }

    /**
     * Extract every list body with the given class attribute, so the test can check
     * which group a string landed in rather than only that it is somewhere on the page.
     *
     * @return array<int, string>
     */
    private function listsWith(string $html, string $class): array
    {
        preg_match_all('/<ul class="'.preg_quote($class, '/').'">(.*?)<\/ul>/s', $html, $matches);

        return $matches[1];
    }

    /**
     * The small-caps group labels, chunked into per-card pairs so the test also pins
     * the order: Features above, Modules below.
     *
     * @return array<int, array<int, string>>
     */
    private function labelsPerCard(string $html): array
    {
        preg_match_all(
            '/<div class="'.preg_quote(self::SECTION_LABEL, '/').'[^"]*">(.*?)<\/div>/s',
            $html,
            $matches
        );

        $labels = array_map(
            static fn (string $label): string => trim(preg_replace('/\s+/', ' ', strip_tags($label))),
            $matches[1]
        );

        return array_chunk($labels, 2);
    }
}
