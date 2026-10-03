<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The sitemap a crawler downloads from /sitemap.xml.
 *
 * Cultiv is a SaaS behind a login, so the thing worth protecting here is not coverage
 * but silence: of 220 named routes exactly one page is reachable by a stranger, and
 * publishing any of the rest would put a tenant's invoices and stock figures into a
 * search index. These tests therefore assert mostly ABSENCE — that private routes, the
 * login flow, and the host of the request itself stay out of the document.
 */
class SitemapTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, string> */
    private function locations(string $xml): array
    {
        $parsed = simplexml_load_string($xml);

        $this->assertNotFalse($parsed, 'sitemap.xml must be parseable XML.');

        $out = [];
        foreach ($parsed->url as $url) {
            $out[] = (string) $url->loc;
        }

        return $out;
    }

    private function sitemap(): string
    {
        return $this->get('/sitemap.xml')->assertOk()->getContent();
    }

    public function test_it_is_served_with_a_200_and_an_xml_content_type(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $this->assertStringContainsString(
            'xml',
            (string) $response->headers->get('Content-Type'),
            'A crawler that gets text/html back will discard the document.'
        );
    }

    public function test_it_needs_no_authentication(): void
    {
        // No actingAs(): that is the whole point of a sitemap.
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('Login');
    }

    public function test_it_is_not_empty_and_lists_the_real_public_page(): void
    {
        $locations = $this->locations($this->sitemap());

        $this->assertNotEmpty($locations, 'An empty sitemap is a broken sitemap.');

        $this->assertContains(
            config('seo.url').'/',
            $locations,
            'The homepage is the one page a stranger can reach; it must be listed.'
        );
    }

    public function test_every_location_is_an_absolute_url_on_the_production_origin(): void
    {
        foreach ($this->locations($this->sitemap()) as $loc) {
            $this->assertStringStartsWith('https://', $loc);
            $this->assertStringStartsWith(config('seo.url'), $loc);
        }
    }

    public function test_it_never_contains_localhost_or_the_request_host(): void
    {
        $xml = $this->sitemap();

        $this->assertStringNotContainsString('localhost', $xml);
        $this->assertStringNotContainsString('127.0.0.1', $xml);
        $this->assertStringNotContainsString('ngrok', $xml);

        // config('app.url') is a tunnel URL in this checkout, which is exactly why the
        // sitemap reads config('seo.url') instead.
        $this->assertStringNotContainsString((string) config('app.url'), $xml);
    }

    public function test_it_contains_no_private_business_route(): void
    {
        $xml = $this->sitemap();

        foreach ([
            '/dashboard', '/profile', '/settings', '/team', '/tenants', '/products',
            '/sales', '/purchases', '/customers', '/suppliers', '/brands', '/categories',
            '/stock', '/pos', '/billing', '/modules', '/reports', '/analytics', '/expenses',
            '/warehouses', '/audit-logs', '/tokens', '/files', '/api/',
        ] as $private) {
            $this->assertStringNotContainsString(
                $private,
                $xml,
                "A tenant-scoped URL ({$private}) must never appear in the sitemap."
            );
        }
    }

    public function test_it_contains_no_authentication_route(): void
    {
        $xml = $this->sitemap();

        foreach ([
            '/login', '/register', '/forgot-password', '/reset-password',
            '/verify-email', '/confirm-password', '/auth/',
        ] as $auth) {
            $this->assertStringNotContainsString($auth, $xml);
        }
    }

    public function test_it_lists_no_duplicate_location(): void
    {
        $locations = $this->locations($this->sitemap());

        $this->assertSame(
            array_values(array_unique($locations)),
            $locations,
            'A duplicated <loc> makes a sitemap look spammy.'
        );
    }

    public function test_robots_txt_points_at_the_production_sitemap(): void
    {
        $robots = (string) file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('Sitemap: '.config('seo.url').'/sitemap.xml', $robots);
        $this->assertStringContainsString('User-agent: *', $robots);
    }

    public function test_no_static_sitemap_file_shadows_the_route(): void
    {
        // public/sitemap.xml would be served by the web server before Laravel ever
        // runs, so the route would look registered while never executing.
        $this->assertFileDoesNotExist(public_path('sitemap.xml'));
    }

    /**
     * The guard that makes the config safe to extend.
     */
    public function test_a_private_route_cannot_be_smuggled_in_through_the_config(): void
    {
        config()->set('seo.sitemap.routes', ['home', 'dashboard', 'products.index', 'login']);

        $locations = $this->locations($this->get('/sitemap.xml')->assertOk()->getContent());

        $this->assertSame([config('seo.url').'/'], $locations);
    }

    public function test_a_registered_public_route_is_picked_up_automatically(): void
    {
        Route::get('/halaman-publik/{slug}', fn () => 'ok')->name('public.probe');

        // RouteCollection caches its name index; a route added after boot is not in it.
        Route::getRoutes()->refreshNameLookups();

        config()->set('seo.sitemap.routes', ['home', 'public.probe']);

        $locations = $this->locations($this->get('/sitemap.xml')->assertOk()->getContent());

        $this->assertContains(config('seo.url').'/halaman-publik/{slug}', $locations);
    }
}
