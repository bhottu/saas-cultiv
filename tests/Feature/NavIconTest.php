<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * nav-icon.blade.php renders an SVG only when the name exists in its $paths map —
 * there is NO fallback, so a missing key silently renders nothing at all. That is
 * exactly the Insights-menu bug: navigation asked for `document-report` and
 * `chart-pie`, got an empty string, and the menu showed bare text.
 */
class NavIconTest extends TestCase
{
    public function test_every_navigation_icon_name_renders_an_svg_with_a_path(): void
    {
        $navigation = file_get_contents(resource_path('views/layouts/navigation.blade.php'));

        preg_match_all("/'icon'\s*=>\s*'([a-z0-9-]+)'/", $navigation, $matches);
        $names = array_unique($matches[1]);

        // The Insights group keys that were missing from the map.
        $this->assertContains('document-report', $names);
        $this->assertContains('chart-pie', $names);

        foreach ($names as $name) {
            $svg = Blade::render('<x-nav-icon :name="$name" />', ['name' => $name]);

            $this->assertStringContainsString('<svg', $svg, "Icon [{$name}] must render an <svg> element.");
            $this->assertStringContainsString('<path', $svg, "Icon [{$name}] must render a <path> element.");
        }
    }
}