<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Plan;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public landing page.
 *
 * These are structural guarantees rather than pixel checks: one H1, the sections in
 * the order a reader is meant to walk them, working CTAs, and a pricing grid that is
 * still driven by the real plan records.
 */
class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->seed(ModuleSeeder::class);
    }

    private function html(): string
    {
        return $this->get('/')->assertOk()->getContent();
    }

    public function test_it_renders_for_a_guest_with_seo_and_one_h1(): void
    {
        $html = $this->html();

        $this->assertSame(1, substr_count($html, '<h1'), 'The page must have exactly one H1.');
        $this->assertStringContainsString('Run your business', $html);
        $this->assertStringContainsString('name="description"', $html);
        $this->assertStringContainsString('Cultiv One — The Smarter Way to Manage Your Business', $html);
    }

    public function test_the_sections_appear_in_reading_order(): void
    {
        $html = $this->html();

        // The reading order the page is written for: value -> problem -> features ->
        // audiences -> modules -> how it works -> pricing -> FAQ -> CTA.
        $order = [
            'id="features"',
            'id="modules"',
            'id="how-it-works"',
            'id="pricing"',
            'id="faq"',
            'Ready to run your business smarter?',
            '<footer',
        ];

        $last = -1;
        foreach ($order as $anchor) {
            $at = strpos($html, $anchor);
            $this->assertNotFalse($at, "Missing section: {$anchor}");
            $this->assertGreaterThan($last, $at, "Section out of order: {$anchor}");
            $last = $at;
        }
    }

    public function test_the_ctas_point_at_real_routes(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('href="'.route('register').'"', $html);
        $this->assertStringContainsString('href="'.route('login').'"', $html);
    }

    public function test_no_anchor_is_dead(): void
    {
        $html = $this->html();

        preg_match_all('/href="#([a-z-]+)"/i', $html, $matches);

        foreach (array_unique($matches[1]) as $id) {
            $this->assertStringContainsString(
                'id="'.$id.'"',
                $html,
                "Anchor #{$id} is linked but nothing carries that id."
            );
        }
    }

    public function test_pricing_is_rendered_from_the_real_plans(): void
    {
        $html = $this->html();

        foreach (Plan::where('is_active', true)->get() as $plan) {
            $this->assertStringContainsString($plan->name, $html);
        }

        $this->assertStringContainsString('Start free', $html);
        $this->assertStringContainsString('Choose', $html);
    }

    public function test_pricing_is_progressive_rather_than_four_identical_lists(): void
    {
        $html = $this->html();

        // Free is the baseline and inherits nothing, so exactly three cards — Starter,
        // Pro and Business — carry an "everything in X" block.
        $this->assertSame(3, substr_count($html, 'Everything in'));

        $this->assertStringContainsString('Everything in Free, plus:', $html);
        $this->assertStringContainsString('Everything in Starter, plus:', $html);
        $this->assertStringContainsString('Everything in Pro, plus:', $html);

        // Free must never claim to inherit from something below it.
        $this->assertStringNotContainsString('Everything in Free, plus: <nothing', $html);

        // The highlights are real capabilities of the higher plans.
        $this->assertStringContainsString('Advanced Reports', $html);
        $this->assertStringContainsString('API', $html);
    }

    public function test_modules_are_rendered_from_the_real_catalogue(): void
    {
        $html = $this->html();

        foreach (Module::where('is_active', true)->get() as $module) {
            $this->assertStringContainsString($module->name, $html);
        }

        // Nothing invented.
        $this->assertStringNotContainsString('Payroll', $html);
    }

    public function test_the_faq_uses_a_native_accordion(): void
    {
        $html = $this->html();

        // <details> works with no JavaScript and is keyboard accessible by default.
        $this->assertSame(7, substr_count($html, '<details'));
        $this->assertStringContainsString('What is a Workspace?', $html);
        $this->assertStringContainsString('What are Modules?', $html);
    }

    public function test_there_is_no_broken_legal_link(): void
    {
        $html = $this->html();

        // Those routes do not exist, so linking them would 404.
        $this->assertStringNotContainsString('privacy', strtolower($html));
        $this->assertStringNotContainsString('terms', strtolower($html));
    }

    public function test_the_tagline_is_never_used_as_a_repeated_heading(): void
    {
        $html = $this->html();
        $tagline = 'The smarter way to manage your business';

        // It may appear in the title, in brand lockups and in the footer — but it must
        // never be promoted to a heading, which is what made the old page feel repetitive.
        preg_match_all('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', $html, $headings);

        foreach ($headings[1] as $heading) {
            $this->assertStringNotContainsStringIgnoringCase(
                $tagline,
                strip_tags($heading),
                'The brand tagline must not be repeated as a section heading.'
            );
        }

        // The hero headline is the only H1, and it is not the tagline.
        $this->assertStringNotContainsStringIgnoringCase($tagline, strip_tags($headings[1][0]));
    }
}