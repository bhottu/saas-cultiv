<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Blade markup and the compiled CSS ship as two separate artefacts.
 *
 * `public/build` is gitignored, so a deploy that only runs `git pull` updates every
 * Blade view but leaves the old compiled stylesheet in place. That is silent and
 * nasty: the new markup renders, but the spacing and layout utilities it relies on
 * were never in the old stylesheet, so Tailwind simply omits them and the page
 * collapses together — "the cards look cramped in production but fine on localhost".
 *
 * This test is the tripwire for that. It reads the CSS that `manifest.json` actually
 * points at, and asserts every class the landing page uses was emitted into it.
 * It skips when no build exists at all, because that is a legitimate state for a
 * fresh checkout mid-development; it only fails when a build exists and is stale.
 */
class ViteBuildFreshnessTest extends TestCase
{
    /** Characters Tailwind escapes inside a class selector. */
    private const SELECTOR_SPECIALS = ".:/[]%(),#!\\";

    public function test_the_compiled_css_contains_every_class_the_landing_page_uses(): void
    {
        $css = $this->compiledCss();

        if ($css === null) {
            $this->markTestSkipped('No production asset build found; run `npm run build` to check this.');
        }

        $missing = [];

        foreach ($this->landingPageClasses() as $class) {
            if (! str_contains($css, $this->cssSelector($class))) {
                $missing[] = $class;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'These classes are used in the landing page but absent from the compiled CSS — '
            .'the asset build on this machine is stale. Run `npm run build` and redeploy '
            .'`public/build`, or production will render without them.'
        );
    }

    public function test_the_manifest_points_at_a_css_file_that_exists(): void
    {
        $css = $this->compiledCss();

        if ($css === null) {
            $this->markTestSkipped('No production asset build found.');
        }

        $this->assertNotEmpty($css, 'The compiled stylesheet is empty.');
    }

    /**
     * The compiled CSS behind `resources/css/app.css`, exactly as the manifest routes it.
     */
    private function compiledCss(): ?string
    {
        $manifestPath = public_path('build/manifest.json');

        if (! is_file($manifestPath)) {
            return null;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $file = $manifest['resources/css/app.css']['file'] ?? null;

        if (! is_string($file) || ! is_file(public_path('build/'.$file))) {
            return null;
        }

        return (string) file_get_contents(public_path('build/'.$file));
    }

    /**
     * Every literal utility class on the landing page, including its partials.
     *
     * @return array<int, string>
     */
    private function landingPageClasses(): array
    {
        $files = array_merge(
            [resource_path('views/welcome.blade.php')],
            glob(resource_path('views/welcome/partials/*.blade.php')) ?: []
        );

        $classes = [];

        foreach ($files as $file) {
            preg_match_all('/class="([^"]*)"/', (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $bag) {
                foreach (preg_split('/\s+/', trim($bag)) as $class) {
                    // Blade fragments ({{ ... }}, ternaries, $vars) carry braces, quotes or
                    // $ and so already fail this pattern. Deliberately NOT excluding ':' —
                    // responsive utilities such as sm:py-24 and lg:text-6xl are exactly the
                    // ones that vanish from a stale build, so they must be checked too.
                    //
                    // The alphanumeric floor drops the lone ':' that splitting a Blade
                    // ternary (`cond ? 'a' : 'b'`) leaves behind.
                    if ($class !== ''
                        && preg_match('/^[A-Za-z0-9_:.\/\[\]%-]+$/', $class)
                        && preg_match('/[A-Za-z0-9]/', $class)) {
                        $classes[$class] = true;
                    }
                }
            }
        }

        $classes = array_keys($classes);
        sort($classes);

        return $classes;
    }

    /**
     * A class as it appears in the compiled stylesheet: `.lg:text-6xl` becomes
     * `.lg\:text-6xl`, because a literal `.` in a class name must be escaped in CSS.
     */
    private function cssSelector(string $class): string
    {
        $selector = '.';

        foreach (str_split($class) as $char) {
            $selector .= str_contains(self::SELECTOR_SPECIALS, $char) ? '\\'.$char : $char;
        }

        return $selector;
    }
}