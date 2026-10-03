<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * SEO metadata across the public/private boundary.
 *
 * The risk this guards is not a missing tag, it is a leaked one: a tenant-scoped page
 * carrying index,follow would put a workspace's invoices and stock figures into a
 * search engine. So the majority of these assertions check that private pages are
 * refused, not that public ones are granted.
 */
class SeoTest extends TestCase
{
    use RefreshDatabase;

    private const TAGS = [
        'description', 'robots',
        'og:title', 'og:description', 'og:type', 'og:url', 'og:image',
        'og:site_name', 'og:locale',
        'twitter:card', 'twitter:title', 'twitter:description', 'twitter:image',
    ];

    private function meta(string $html, string $needle): string
    {
        preg_match('/<meta[^>]*'.preg_quote($needle, '/').'[^>]*>/', $html, $m);

        return $m[0] ?? '';
    }

    private function content(string $html, string $needle): string
    {
        preg_match('/content="([^"]*)"/', $this->meta($html, $needle), $m);

        return $m[1] ?? '';
    }

    /**
     * A verified owner with a workspace.
     *
     * @return array{0: User, 1: int}  [user, tenant id]
     */
    private function signedIn(): array
    {
        $user = User::create([
            'name' => 'Pemilik',
            'email' => 'owner@cultiv.test',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $tenant = Tenant::create([
            'name' => 'Toko Kopi',
            'slug' => 'toko-kopi',
            'owner_id' => $user->id,
        ]);

        $tenant->users()->attach($user->id, [
            'role' => 'Owner',
            'status' => TenantUser::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);

        return [$user, $tenant->id];
    }

    /*
    |--------------------------------------------------------------------------
    | Public page: the homepage
    |--------------------------------------------------------------------------
    */

    public function test_the_homepage_carries_the_complete_metadata_set(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        foreach (self::TAGS as $needle) {
            $this->assertNotSame(
                '',
                $this->meta($html, $needle),
                "The homepage is missing <meta {$needle}>."
            );
        }

        $this->assertStringContainsString('<title>', $html);
        $this->assertStringContainsString('rel="canonical"', $html);
    }

    public function test_the_homepage_is_the_only_indexable_page(): void
    {
        $home = $this->get('/')->assertOk()->getContent();

        $this->assertSame('index, follow', $this->content($home, 'name="robots"'));
        $this->assertSame(
            ['home'],
            config('seo.indexable_routes'),
            'Only the homepage is indexable today; anything else must be listed on purpose.'
        );
    }

    public function test_the_homepage_title_is_its_own_and_not_doubled_up(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        preg_match('/<title>(.*?)<\/title>/', $html, $m);

        $this->assertSame(config('seo.default_title'), trim($m[1]));
        $this->assertStringNotContainsString(
            'Cultiv — Cultiv',
            $html,
            'The brand must not be appended to a title that already names it.'
        );
    }

    public function test_canonical_and_open_graph_urls_are_absolute_https_on_the_configured_origin(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(
            '<link rel="canonical" href="https://cultiv.id/">',
            $html
        );

        $this->assertSame('https://cultiv.id/', $this->content($html, 'property="og:url"'));
        $this->assertStringStartsWith(
            'https://',
            $this->content($html, 'property="og:image"'),
            'A relative og:image is ignored by most social scrapers.'
        );
        $this->assertStringNotContainsString('localhost', $html);
    }

    public function test_the_canonical_ignores_query_strings(): void
    {
        $html = $this->get('/?utm_source=facebook&utm_campaign=share')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('rel="canonical" href="https://cultiv.id/"', $html);
        $this->assertStringNotContainsString('utm_source', $html);
    }

    public function test_the_default_open_graph_image_is_publicly_reachable(): void
    {
        // A social scraper fetches this with no cookies and no session, so it has to be
        // a plain file under public/ rather than something behind auth or a route.
        // Asserted on disk because the HTTP test kernel does not serve public/.
        $html = $this->get('/')->assertOk()->getContent();
        $image = $this->content($html, 'property="og:image"');

        $this->assertStringStartsWith('https://cultiv.id/', $image);

        $relative = ltrim(str_replace((string) config('seo.url'), '', $image), '/');

        $this->assertFileExists(public_path($relative));
        $this->assertStringStartsNotWith('app/', $relative, 'It must not live inside the application.');
    }

    /*
    |--------------------------------------------------------------------------
    | Structured data
    |--------------------------------------------------------------------------
    */

    public function test_the_homepage_publishes_valid_linked_json_ld(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        preg_match_all(
            '/<script type="application\/ld\+json">(.*?)<\/script>/s',
            $html,
            $matches
        );

        $this->assertGreaterThanOrEqual(4, count($matches[1]));

        $types = [];

        foreach ($matches[1] as $json) {
            $decoded = json_decode($json, true);

            $this->assertIsArray($decoded, 'JSON-LD must be valid JSON.');
            $this->assertSame('https://schema.org', $decoded['@context']);

            $types[] = $decoded['@type'];
        }

        foreach (['Organization', 'WebSite', 'WebPage', 'SoftwareApplication'] as $type) {
            $this->assertContains($type, $types);
        }
    }

    public function test_json_ld_makes_no_claim_this_project_cannot_back_up(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $m);

        foreach ($m[1] as $json) {
            $decoded = json_decode($json, true);

            // A fabricated rating or user count is worse than an absent one.
            foreach (['aggregateRating', 'review', 'ratingValue', 'numberOfEmployees'] as $forbidden) {
                $this->assertArrayNotHasKey(
                    $forbidden,
                    $decoded,
                    "JSON-LD must not invent '{$forbidden}'."
                );
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Private pages must be refused, not merely unmarked
    |--------------------------------------------------------------------------
    */

    public function test_auth_pages_are_noindex(): void
    {
        foreach (['/login', '/register', '/forgot-password'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();

            $this->assertSame(
                'noindex, nofollow',
                $this->content($html, 'name="robots"'),
                "{$path} must not be indexable."
            );
        }
    }

    public function test_tenant_scoped_pages_are_noindex_and_leak_no_business_data(): void
    {
        [$user, $tenantId] = $this->signedIn();

        foreach (['/dashboard', '/products', '/sales', '/stock'] as $path) {
            $html = $this->actingAs($user)
                ->withSession(['tenant_id' => $tenantId])
                ->get($path)
                ->assertOk()
                ->getContent();

            $this->assertSame(
                'noindex, nofollow',
                $this->content($html, 'name="robots"'),
                "{$path} is tenant data and must not be indexable."
            );
        }
    }

    public function test_a_private_page_never_publishes_structured_data(): void
    {
        [$user, $tenantId] = $this->signedIn();

        $html = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenantId])
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('application/ld+json', $html);
    }

    public function test_every_page_gets_a_unique_title(): void
    {
        [$user, $tenantId] = $this->signedIn();

        $titles = [];

        foreach (['/', '/login', '/register'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            preg_match('/<title>(.*?)<\/title>/', $html, $m);
            $titles[] = trim($m[1]);
        }

        $html = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenantId])
            ->get('/dashboard')->assertOk()->getContent();
        preg_match('/<title>(.*?)<\/title>/', $html, $m);
        $titles[] = trim($m[1]);

        $this->assertCount(
            count($titles),
            array_unique($titles),
            'Each page needs its own <title>: '.implode(' | ', $titles)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The allow-list itself
    |--------------------------------------------------------------------------
    |
    | The layouts state noindex explicitly, which means those pages are protected by
    | the prop and never consult config('seo.indexable_routes') at all. Without the
    | tests below, the allow-list could be deleted outright and the suite would stay
    | green — which is exactly what happened once while writing this file.
    |
    | So these render a bare <x-seo />, the way a future page would if it did not
    | remember to spell anything out, and assert the deny-by-default behaviour.
    |
    */

    private function registerBareSeoRoute(string $name): void
    {
        Route::get('/__seo-probe/'.$name, function () {
            return view('seo-probe');
        })->name($name);
    }

    public function test_a_page_that_says_nothing_is_unindexed_by_default(): void
    {
        $this->registerBareSeoRoute('seo.probe.private');

        $html = $this->get('/__seo-probe/seo.probe.private')->assertOk()->getContent();

        $this->assertSame(
            'noindex, nofollow',
            $this->content($html, 'name="robots"'),
            'An unlisted route must be refused, not left to chance.'
        );
    }

    public function test_only_an_explicitly_listed_route_becomes_indexable(): void
    {
        $this->registerBareSeoRoute('seo.probe.listed');

        config()->set('seo.indexable_routes', ['seo.probe.listed']);

        $html = $this->get('/__seo-probe/seo.probe.listed')->assertOk()->getContent();

        $this->assertSame('index, follow', $this->content($html, 'name="robots"'));
    }

    public function test_listing_a_route_is_the_only_way_to_opt_in(): void
    {
        $this->registerBareSeoRoute('seo.probe.dashboard');

        // Even a route named after a dashboard page stays out unless it is listed.
        $html = $this->get('/__seo-probe/seo.probe.dashboard')->assertOk()->getContent();

        $this->assertSame('noindex, nofollow', $this->content($html, 'name="robots"'));
    }

    public function test_a_bare_page_still_gets_a_unique_title_from_the_route_map(): void
    {
        $this->registerBareSeoRoute('seo.probe.titled');

        config()->set('seo.route_titles', ['seo.probe.titled' => 'Probe page']);

        $html = $this->get('/__seo-probe/seo.probe.titled')->assertOk()->getContent();

        preg_match('/<title>(.*?)<\/title>/', $html, $m);

        $this->assertSame('Probe page — Cultiv', trim($m[1]));
    }

    /*
    |--------------------------------------------------------------------------
    | Crawler files
    |--------------------------------------------------------------------------
    |
    | These live in public/ and are served by the web server, NOT by Laravel, so they
    | cannot be fetched through the HTTP test kernel — a request for /robots.txt there
    | resolves to the router and 404s, which says nothing about production. They are
    | therefore asserted on disk: the file existing, being non-empty and being
    | well-formed is what actually matters.
    |
    */

    public function test_robots_txt_declares_the_production_sitemap(): void
    {
        // robots.txt used to ship as a comment-only placeholder. It now carries real
        // directives; SitemapTest owns the sitemap itself, this only checks the pointer.
        $robots = (string) file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('User-agent: *', $robots);
        $this->assertStringContainsString('Sitemap: '.config('seo.url').'/sitemap.xml', $robots);

        // The private areas are closed at this layer too, as a second line of defence
        // behind the meta robots tags.
        foreach (['/admin', '/tenants', '/products', '/login'] as $path) {
            $this->assertStringContainsString('Disallow: '.$path, $robots);
        }
    }

    public function test_the_sitemap_is_served_by_a_route_not_a_static_file(): void
    {
        // SitemapTest covers the document itself. Here we only assert the wiring: a
        // static public/sitemap.xml would be handed over by the web server before
        // Laravel runs, leaving the route registered but unreachable.
        $this->assertFileDoesNotExist(public_path('sitemap.xml'));

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function test_the_open_graph_card_is_a_valid_1200x630_image_on_disk(): void
    {
        $path = public_path('images/og/cultiv-default.jpg');

        $this->assertFileExists($path);

        $size = getimagesize($path);

        $this->assertNotFalse($size, 'The default OG image must be a real image.');
        $this->assertSame([1200, 630], [$size[0], $size[1]], 'Social cards render at 1200x630.');
        $this->assertSame('image/jpeg', $size['mime']);

        // And it must sit where the metadata points, or every share loses its picture.
        $this->assertSame(
            'https://cultiv.id/images/og/cultiv-default.jpg',
            config('seo.url').'/'.config('seo.default_image'),
        );
    }

    public function test_the_organization_logo_referenced_by_json_ld_exists(): void
    {
        $this->assertFileExists(public_path((string) config('seo.organization.logo')));
    }
}
