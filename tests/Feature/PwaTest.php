<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Cultiv One as an installable app (PWA) plus the global fullscreen control.
 *
 * The PWA here is the WHOLE SaaS, not a POS shell: the manifest is scoped to "/",
 * launches the dashboard, and runs the existing authenticated shell, so every module
 * is reachable from the installed app.
 *
 * The security-critical part is what the service worker is allowed to store. Cultiv
 * One pages are tenant-scoped, so a cached authenticated response can leak one
 * workspace's data to another or to the next person on a shared device. These tests
 * pin the worker to public build assets ONLY, and assert that no page or API response
 * is ever written to the cache.
 */
class PwaTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Tenant $tenant;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ModuleSeeder::class);

        $this->owner = User::create([
            'name' => 'Pwa Owner', 'email' => 'pwa-owner@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->tenant = Tenant::create([
            'name' => 'Toko Pwa', 'slug' => 'toko-pwa',
            'owner_id' => $this->owner->id, 'status' => 'active',
        ]);
        $this->tenant->users()->attach($this->owner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->warehouse = Warehouse::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Gudang Utama',
            'code' => 'MAIN', 'is_active' => true,
        ]);

        // /pos is behind the existing `module:pos` gate, so the PWA/fullscreen checks
        // for the cashier screen need the module active for this workspace.
        TenantModule::create([
            'tenant_id'    => $this->tenant->id,
            'module_id'    => Module::where('key', 'pos')->firstOrFail()->id,
            'status'       => TenantModule::STATUS_ACTIVE,
            'installed_at' => now(),
            'activated_at' => now(),
        ]);
    }

    private function asOwner()
    {
        return $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id]);
    }

    private function manifest(): array
    {
        return json_decode(file_get_contents(public_path('manifest.json')), true);
    }

    private function serviceWorker(): string
    {
        return file_get_contents(public_path('sw.js'));
    }

    // --------------------------------------------------------------- manifest

    public function test_manifest_describes_the_whole_app_and_not_a_pos_shell(): void
    {
        $manifest = $this->manifest();

        $this->assertSame('Cultiv One', $manifest['name']);
        $this->assertSame('Cultiv One', $manifest['short_name']);
        $this->assertSame('standalone', $manifest['display']);

        // Launches the main application, not a single module.
        $this->assertSame('/dashboard', $manifest['start_url']);

        // Scoped to the root so every module (/pos, /products, /sales, ...) is in scope.
        $this->assertSame('/', $manifest['scope']);
    }

    public function test_manifest_declares_theme_and_install_icons(): void
    {
        $manifest = $this->manifest();

        $this->assertNotEmpty($manifest['theme_color']);
        $this->assertNotEmpty($manifest['background_color']);

        $sizes = array_column($manifest['icons'], 'sizes');

        $this->assertContains('192x192', $sizes, 'Installable PWA needs a 192px icon.');
        $this->assertContains('512x512', $sizes, 'Installable PWA needs a 512px icon.');

        // A maskable icon is required for a clean Android launch.
        $this->assertContains('maskable', array_column($manifest['icons'], 'purpose'));

        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')), "Missing icon: {$icon['src']}");
        }
    }

    public function test_manifest_is_reachable_and_declared_by_every_app_page(): void
    {
        // Served by the web server, not the framework router, so assert the file
        // rather than an HTTP request (a test request for ANY public/ asset 404s).
        $this->assertFileExists(public_path('manifest.json'));

        // Every authenticated page (and the public one) must advertise installability.
        foreach (['/dashboard', '/products', '/sales'] as $path) {
            $html = $this->asOwner()->get($path)->assertOk()->getContent();

            $this->assertStringContainsString('rel="manifest"', $html, "{$path} does not link the manifest.");
            $this->assertStringContainsString('manifest.json', $html);
        }
    }

    // ------------------------------------------------ service worker safety

    /**
     * THE data-leak guard, verified by EXECUTION rather than by matching source text.
     *
     * A source-substring test can be satisfied by a guard that is present but no
     * longer used, so this loads the real worker into a minimal ServiceWorkerGlobalScope
     * double and drives its `fetch` handler with a spy. The assertion is behavioural:
     * for a private request the handler must not call respondWith (which is what would
     * store and replay it) and must not touch Cache Storage at all.
     */
    public function test_service_worker_never_stores_private_data(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sw').'.mjs';
        file_put_contents($file, $this->nodeWorkerHarness());

        try {
            $result = shell_exec('node '.escapeshellarg($file).' 2>&1');
        } finally {
            @unlink($file);
        }

        $this->assertNotNull(
            $result,
            'Could not execute the service worker harness. Is Node available on PATH?'
        );

        $decoded = json_decode(trim((string) $result), true);

        if (! is_array($decoded)) {
            $this->fail("Worker harness output (".strlen((string) $result)." bytes):\n".var_export($result, true));
        }

        // Private/tenant data: never intercepted, therefore never stored.
        foreach ([
            'dashboard_page'    => 'https://app.test/dashboard',
            'pos_page'          => 'https://app.test/pos',
            'products_page'     => 'https://app.test/products',
            'pos_calculate_api' => 'https://app.test/pos/calculate',
            'api_endpoint'      => 'https://app.test/api/sales',
            'cross_origin_font' => 'https://fonts.bunny.net/css?family=figtree',
        ] as $case => $url) {
            $this->assertFalse(
                $decoded[$case]['responded'],
                "Worker must NOT intercept {$url} (would risk storing private data)."
            );
            $this->assertSame(0, $decoded[$case]['cached'], "Worker cached {$url}.");
        }

        // Writes must never be intercepted at all.
        $this->assertFalse($decoded['pos_checkout_post']['responded'], 'POST /pos/checkout must never be intercepted.');
        $this->assertSame(0, $decoded['pos_checkout_post']['cached']);

        // Public, content-hashed build assets ARE cached — the worker must still work.
        $this->assertTrue($decoded['build_asset']['responded'], 'Build assets should be served from the cache.');
        $this->assertSame(1, $decoded['build_asset']['cached'], 'Build assets must be cached.');
    }

    /** Wraps public/sw.js in a Node harness that reports what the worker actually does. */
    private function nodeWorkerHarness(): string
    {
        // NOTE: the harness is written to a .mjs file because package.json sets
        // "type": "module", and the worker's absolute path is inlined so the script
        // needs no environment variables.
        $harness = <<<'JS'
            import { readFileSync } from 'node:fs';

            const CASES = [
                { name: 'dashboard_page',    url: 'https://app.test/dashboard',               method: 'GET',  mode: 'navigate' },
                { name: 'pos_page',          url: 'https://app.test/pos',                     method: 'GET',  mode: 'navigate' },
                { name: 'products_page',     url: 'https://app.test/products',                method: 'GET',  mode: 'navigate' },
                { name: 'pos_calculate_api', url: 'https://app.test/pos/calculate',           method: 'GET',  mode: 'cors' },
                { name: 'api_endpoint',      url: 'https://app.test/api/sales',              method: 'GET',  mode: 'cors' },
                { name: 'cross_origin_font', url: 'https://fonts.bunny.net/css?family=figtree', method: 'GET', mode: 'cors' },
                { name: 'pos_checkout_post', url: 'https://app.test/pos/checkout',           method: 'POST', mode: 'cors' },
                { name: 'build_asset',       url: 'https://app.test/build/assets/app-abc.js', method: 'GET', mode: 'cors' },
            ];

            const listeners = {};
            let stored = 0;

            // Minimal ServiceWorkerGlobalScope double.
            globalThis.self = {
                addEventListener: (type, fn) => { listeners[type] = fn; },
                skipWaiting: () => {},
                clients: { claim: () => {} },
                location: { origin: 'https://app.test' },
            };
            globalThis.caches = {
                open: () => Promise.resolve({
                    add: () => Promise.resolve(),
                    put: () => { stored += 1; return Promise.resolve(); },
                    match: () => Promise.resolve(undefined),
                }),
                match: () => Promise.resolve(undefined),
                keys: () => Promise.resolve([]),
                delete: () => Promise.resolve(),
            };
            globalThis.fetch = () => Promise.resolve({ ok: true, type: 'basic', clone: () => ({}) });

            // Load the REAL worker under test (absolute path is inlined by PHP).
            (0, eval)(readFileSync('__WORKER_PATH__', 'utf8'));

            const results = {};
            for (const c of CASES) {
                let responded = false;
                const before = stored;

                listeners.fetch({
                    request: { method: c.method, mode: c.mode, url: c.url },
                    respondWith: () => { responded = true; },
                    waitUntil: () => {},
                });

                // The cache write is queued asynchronously; drain microtasks so the
                // counter reflects it before the result is recorded.
                await Promise.resolve();
                await Promise.resolve();
                await new Promise((resolve) => setImmediate(resolve));

                results[c.name] = { responded, cached: stored - before };
            }

            process.stdout.write(JSON.stringify(results));
            JS;

        // The worker's absolute path is inlined as a JS string literal, so the Windows
        // path separators must be escaped for JavaScript.
        $workerPath = str_replace(
            ['\\', "'"],
            ['\\\\', "\\'"],
            public_path('sw.js')
        );

        return str_replace('__WORKER_PATH__', $workerPath, $harness);
    }


    public function test_service_worker_purges_older_caches_on_activate(): void
    {
        $sw = $this->serviceWorker();

        $this->assertStringContainsString("addEventListener('activate'", $sw);
        $this->assertStringContainsString('caches.delete', $sw, 'Stale caches must be removed on activate.');
    }

    public function test_service_worker_and_manifest_sit_at_the_root(): void
    {
        // A worker outside the root cannot control the whole app, which is what makes
        // /pos, /products, ... installable as ONE application.
        $this->assertFileExists(public_path('sw.js'));
        $this->assertFileExists(public_path('manifest.json'));
    }


    // ------------------------------------------- install / fullscreen controls

    public function test_every_app_page_offers_the_install_and_fullscreen_controls(): void
    {
        foreach (['/dashboard', '/products', '/pos'] as $path) {
            $html = $this->asOwner()->get($path)->assertOk()->getContent();

            $this->assertStringContainsString('pwaInstall', $html, "{$path} is missing the install control.");
            $this->assertStringContainsString('fullscreenToggle', $html, "{$path} is missing the fullscreen control.");
        }
    }

    public function test_install_button_is_named_for_the_whole_saas(): void
    {
        $html = $this->asOwner()->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString(__('Install Cultiv One'), $html);

        // The PWA is the whole app, so it must never be advertised as a POS-only app.
        foreach (['Install POS', 'Cultiv POS', 'Cultiv Inventory', 'POS App'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
    }

    /**
     * The controls are browser-gated and user-initiated, so the markup must keep them
     * hidden until the browser reports support — otherwise an unsupported browser
     * shows a button that cannot work.
     */
    public function test_install_and_fullscreen_controls_are_hidden_until_supported(): void
    {
        $html = $this->asOwner()->get('/dashboard')->assertOk()->getContent();

        // The install control stays hidden until the browser genuinely supports installing.
        // That is now `canInstall` (a real beforeinstallprompt arrived) OR `showIosInstructions`
        // (iOS, where there is no prompt and the entry explains Share → Add to Home Screen).
        // Both start false, so the control still never appears as a dead button.
        $this->assertMatchesRegularExpression(
            '/x-cloak[^>]*x-show="canInstall \|\| showIosInstructions"/',
            $html,
            'The install control must stay hidden until the browser can actually install.'
        );

        // The iOS branch must never be a button: there is no API to invoke, so a clickable
        // control there could do nothing.
        $this->assertMatchesRegularExpression(
            '/<button[^>]*x-show="canInstall"/',
            $html,
            'The Chromium install prompt must remain a real button.'
        );
        $this->assertStringNotContainsString(
            '<button type="button" @click="install()" x-show="showIosInstructions"',
            $html,
            'iOS must never be given a button, because nothing can be triggered there.'
        );

        $this->assertMatchesRegularExpression(
            '/x-cloak[^>]*x-show="supported"/',
            $html,
            'The fullscreen control must stay hidden until the API exists.'
        );
    }

    /**
     * iOS has no programmatic install, so the app must say so rather than imply a prompt.
     */
    public function test_ios_is_told_how_to_install_instead_of_being_given_a_dead_button(): void
    {
        $pwa = (string) file_get_contents(base_path('resources/js/pwa.js'));
        $header = (string) file_get_contents(resource_path('views/layouts/header.blade.php'));

        // One component handles both platforms — no duplicated install logic.
        $this->assertSame(
            1,
            substr_count($pwa, 'export function pwaInstall'),
            'There must be exactly one install component.'
        );
        $this->assertSame(
            1,
            substr_count($header, 'x-data="pwaInstall"'),
            'The header must instantiate the shared install component exactly once.'
        );

        $this->assertStringContainsString('function isIos()', $pwa);
        $this->assertStringContainsString('showIosInstructions', $pwa);

        // The instruction itself must be translated, not hardcoded into the template.
        $this->assertMatchesRegularExpression(
            "/__\('On iPhone and iPad[^\n]+'\)/",
            $header,
            'The iOS instruction must go through __() like every other string.'
        );

        // Platform detection must never, under any circumstance, fire the native prompt:
        // only a real beforeinstallprompt may do that.
        $this->assertStringContainsString("if (isIos())", $pwa);
    }

    public function test_the_landing_page_carries_a_floating_install_entry(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('x-data="pwaInstall"', $html);
        $this->assertStringContainsString(__('Install Cultiv'), $html);

        // Anchored to the bottom of the viewport, above the sticky header (z-40).
        $this->assertStringContainsString('fixed', $html);
        $this->assertStringContainsString('bottom-0', $html);
        $this->assertStringContainsString('z-50', $html);

        // Sized to its content, never the full viewport width.
        $this->assertStringContainsString('w-fit', $html);
        $this->assertStringNotContainsString('inset-x-0 w-full', $html);

        // Hidden while nothing is installable and while already installed.
        $this->assertStringContainsString(
            'x-show="!installed && (canInstall || showIosInstructions)"',
            $html
        );

        // A real button that does something on every platform.
        $this->assertStringContainsString('@click="activate()"', $html);
        $this->assertStringContainsString('x-show="iosHelpOpen"', $html);

        // Keyboard reachable with a visible focus ring.
        $this->assertMatchesRegularExpression('/<button[^>]*focus-visible:ring-2/', $html);
    }

    public function test_the_install_surface_exists_exactly_once_per_page(): void
    {
        // The landing page has its floating control; the application shell has its own.
        // Neither may register a second listener or a second piece of install state.
        $landing = $this->get('/')->assertOk()->getContent();
        $shell = $this->asOwner()->get('/dashboard')->assertOk()->getContent();

        $this->assertSame(1, substr_count($landing, 'x-data="pwaInstall"'));
        $this->assertSame(1, substr_count($shell, 'x-data="pwaInstall"'));

        // And the shared component is what owns every listener.
        $pwa = (string) file_get_contents(base_path('resources/js/pwa.js'));

        $this->assertSame(1, substr_count($pwa, "addEventListener('beforeinstallprompt'"));
        $this->assertSame(1, substr_count($pwa, "addEventListener('appinstalled'"));

        // Exactly one service worker registration, and one manifest.
        $this->assertSame(1, substr_count($pwa, "register('/sw.js'"));
        $this->assertFileExists(public_path('manifest.json'));
    }

    /**
     * Launching the installed app must open the dashboard.
     *
     * The manifest is the right place for this: start_url is what the OS uses when the
     * user taps the installed icon. Doing it with a global redirect instead would drag
     * ordinary web visitors to /dashboard as well, which is not wanted.
     */
    public function test_the_installed_app_launches_on_the_dashboard(): void
    {
        $manifest = $this->manifest();

        $this->assertSame('/dashboard', $manifest['start_url']);

        // scope must contain start_url, otherwise the launch is out of scope.
        $this->assertStringStartsWith(
            rtrim($manifest['scope'], '/').'/',
            $manifest['start_url'],
            'start_url falls outside the manifest scope, so the installed app could not launch it.'
        );

        $this->assertSame('/', $manifest['scope']);

        // And the target is a real, reachable page inside the application.
        $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id])
            ->get($manifest['start_url'])
            ->assertOk();
    }

    public function test_the_manifest_icons_are_real_files_at_the_sizes_it_declares(): void
    {
        $manifest = $this->manifest();

        $this->assertNotEmpty($manifest['icons'], 'The manifest declares no icons.');

        foreach ($manifest['icons'] as $icon) {
            $path = public_path(ltrim($icon['src'], '/'));

            $this->assertFileExists($path, "{$icon['src']} does not exist — the install icon would 404.");
            $this->assertGreaterThan(0, filesize($path));

            if (str_ends_with($icon['src'], '.png')) {
                // Read the IHDR width/height straight out of the PNG header rather than
                // trusting the manifest to describe its own asset correctly.
                $bytes = file_get_contents($path);

                $this->assertSame(
                    "\x89PNG\r\n\x1a\n",
                    substr($bytes, 0, 8),
                    "{$icon['src']} is not a valid PNG."
                );

                // unpack() with named keys returns an associative array, so the two
                // values are read by name rather than destructured positionally.
                $size = unpack('Nwidth/Nheight', substr($bytes, 16, 8));

                $this->assertSame(
                    $icon['sizes'],
                    $size['width'].'x'.$size['height'],
                    "{$icon['src']} does not match the size declared in the manifest."
                );
            }
        }

        // A maskable icon must be declared as maskable, and a 512px "any" icon is what
        // Chromium requires for installability.
        $sizes = array_column($manifest['icons'], 'sizes');
        $this->assertContains('192x192', $sizes);
        $this->assertContains('512x512', $sizes);
    }

    public function test_x_cloak_rule_exists_so_gated_controls_cannot_flash(): void
    {
        // Hidden-until-supported controls need the cloak rule, otherwise they are
        // briefly visible as dead buttons before Alpine boots.
        $this->assertStringContainsString('[x-cloak]', file_get_contents(base_path('resources/css/app.css')));
    }

    /**
     * The fullscreen control belongs to the GLOBAL header, not to a page's own
     * content. It must never be injected into the POS transaction area, where it
     * would compete with search, the cart, payment fields and "Complete sale".
     */
    public function test_fullscreen_lives_in_the_header_and_not_in_the_pos_transaction_area(): void
    {
        // The control is inside the header's @auth block, so assert it on a real
        // rendered page (rendering layouts/header in isolation has no tenant context
        // and legitimately renders without any authenticated controls).
        $dashboard = $this->asOwner()->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('fullscreenToggle', $dashboard);

        // It must live INSIDE the shared <header>, not somewhere on the page body.
        $header = substr($dashboard, 0, strpos($dashboard, '</header>') ?: 0);
        $this->assertStringContainsString(
            'fullscreenToggle',
            $header,
            'Fullscreen must live in the global header.'
        );

        // The POS page composes the same shell, so /pos gets the control via the
        // header rather than needing one of its own.
        $this->asOwner()->get('/pos')->assertOk();

        // The POS page's OWN markup must not add a fullscreen control: the cashier
        // screen composes the shared shell, so the header already provides it.
        // Asserted on the view SOURCE (unambiguous), so a future in-transaction
        // button cannot slip in unnoticed.
        $posSource = file_get_contents(resource_path('views/pos/index.blade.php'));
        $this->assertStringNotContainsStringIgnoringCase(
            'fullscreen',
            $posSource,
            'Fullscreen must not be added inside the POS transaction area.'
        );

        $posView = view('pos.index', [
            'tenant'           => $this->tenant,
            'warehouses'       => collect([$this->warehouse]),
            'defaultWarehouse' => $this->warehouse,
            'customers'        => collect(),
            'paymentMethods'   => config('business.sales.payment_methods', ['cash' => 'Cash']),
            // The same two keys PosController::index passes, for the shared customer
            // modal on the POS screen. Kept in step with the controller so this view
            // renders exactly the data the real screen gets.
            'canCreateCustomer' => false,
            'customerStoreUrl'   => route('customers.store'),
        ])->render();

        // Two controls only, both from the shared header: the desktop icon button and
        // the small-screen entry inside the user menu (so mobile keeps a clean
        // header). Neither is inside the POS transaction area, and the source
        // assertion above already proved the page adds none of its own.
        $this->assertSame(
            2,
            substr_count($posView, 'fullscreenToggle'),
            'POS must rely only on the two shared-header fullscreen controls.'
        );
    }

    /** POS is the page that most needs fullscreen, and it must still be fully usable. */
    public function test_pos_still_exposes_its_whole_checkout_flow(): void
    {
        $html = $this->asOwner()->get('/pos')->assertOk()->getContent();

        foreach ([
            'pos-search'        => 'product search',
            'barcodeScanner'    => 'barcode scanner',
            'pos-cart-items'    => 'cart',
            'pos-discount-value' => 'discount',
            'pos-payment-method' => 'payment',
            'pos-checkout'      => 'complete sale',
        ] as $needle => $label) {
            $this->assertStringContainsString($needle, $html, "POS lost its {$label} control.");
        }
    }


    public function test_pwa_module_is_registered_in_the_application_entrypoint(): void
    {
        $app = file_get_contents(base_path('resources/js/app.js'));

        $this->assertStringContainsString("from './pwa'", $app);
        $this->assertStringContainsString('registerServiceWorker();', $app);
        $this->assertStringContainsString("Alpine.data('pwaInstall'", $app);
        $this->assertStringContainsString("Alpine.data('fullscreenToggle'", $app);
    }

    /**
     * Both features are opt-in: the app must never force fullscreen or an install
     * prompt on load. Standalone mode is the PWA experience; fullscreen stays a user
     * choice.
     */
    public function test_install_and_fullscreen_are_never_triggered_automatically(): void
    {
        // Everything in pwa.js that is NOT inside a user-gesture handler.
        $auto = $this->sourceOutsideUserGestures();

        $this->assertStringNotContainsString(
            '.prompt()',
            $auto,
            'The install prompt must only ever run from a click.'
        );
        $this->assertStringNotContainsString(
            'requestFullscreen(',
            $auto,
            'Fullscreen must only ever be requested from a user gesture.'
        );

        // The listeners that capture the browser events are present, so the app
        // still works — they just never act unprompted.
        $this->assertStringContainsString('beforeinstallprompt', $auto);
        $this->assertStringContainsString('isSecureContext', $auto);
    }

    private function sourceOutsideUserGestures(): string
    {
        $source = file_get_contents(base_path('resources/js/pwa.js'));

        // Remove the two handlers that are only ever reached from a click.
        $stripped = preg_replace('/async install\(\)\s*\{.*?\n {8}\}/s', '', $source);
        $stripped = preg_replace('/async toggle\(\)\s*\{.*?\n {8}\}/s', '', $stripped);

        return $stripped;
    }
}
