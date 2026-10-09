<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Shared application shell: every tenant page must render the same sidebar + header
 * (workspace switcher, notifications, user menu), mark the current section as active and
 * only link to modules that actually render a view.
 */
class ApplicationShellTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'Shell Owner',
            'email' => 'shell-owner@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $this->tenant = Tenant::create([
            'name' => 'Shell Workspace',
            'slug' => 'shell-workspace',
            'owner_id' => $this->owner->id,
        ]);

        $this->tenant->users()->attach($this->owner->id, [
            'role' => 'Owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    /** Authenticate as a member of $tenant for the current request. */
    private function asMember(?User $user = null)
    {
        return $this->actingAs($user ?? $this->owner)->withSession(['tenant_id' => $this->tenant->id]);
    }

    /** Rendered sidebar markup of $url, isolated from the page content. */
    private function sidebar(string $url, ?User $user = null): string
    {
        $html = $this->asMember($user)->get($url)->assertOk()->getContent();

        preg_match('/<aside id="sidebar".*?<\/aside>/s', $html, $matches);

        $this->assertNotEmpty($matches, "The shared shell sidebar is missing on {$url}.");

        return $matches[0];
    }

    public function test_every_tenant_page_renders_the_shared_shell(): void
    {
        $pages = [
            '/dashboard',
            '/products',
            '/stock',
            '/categories',
            '/categories/create',
            '/brands',
            '/brands/create',
            '/sales',
            '/sales/dashboard',
            '/sales/returns',
            '/sales/report',
            '/customers',
            '/team',
            '/billing',
        ];

        foreach ($pages as $url) {
            $this->asMember()->get($url)
                ->assertOk()
                // Sidebar + mobile drawer share one navigation source.
                ->assertSee('id="sidebar"', false)
                ->assertSee('id="mobile-sidebar"', false)
                // The shared content wrapper. It no longer carries `space-y-6`: the
                // upgrade banner used to stack that 24px on top of every page's own
                // `py-12`, and the banner now brings its own top margin instead.
                ->assertSee('min-w-0 px-4 sm:px-0', false)
                ->assertSee(__('Main navigation'))
                // Shared brand: desktop sidebar and mobile drawer both use the same lockup.
                ->assertSee('Cultiv One')
                ->assertSee('The smarter way to manage your business')
                // Header keeps the workspace switcher, notifications and user menu.
                ->assertSee('Shell Workspace')
                ->assertSee('Switch workspace')
                ->assertSee('Notifications')
                ->assertSee('Log Out');
        }
    }

    public function test_active_menu_tracks_the_whole_route_family(): void
    {
        $routes = [
            '/dashboard' => 'dashboard',
            '/products' => 'products.index',
            '/stock' => 'stock.index',
            '/categories' => 'categories.index',
            '/categories/create' => 'categories.index',
            '/brands' => 'brands.index',
            '/brands/create' => 'brands.index',
            '/sales' => 'sales.index',
            '/sales/dashboard' => 'sales.index',
            '/sales/returns' => 'sales.returns',
            '/sales/report' => 'sales.report',
            '/customers' => 'customers.index',
            '/team' => 'team.index',
            '/billing' => 'billing.index',
            '/tenants' => 'tenants.index',
            '/profile' => 'profile.edit',
        ];

        foreach ($routes as $url => $routeName) {
            $sidebar = $this->sidebar($url);

            $this->assertMatchesRegularExpression(
                '/href="'.preg_quote(route($routeName), '/').'"[^>]*aria-current="page"/',
                $sidebar,
                "The {$routeName} entry must be the active menu item on {$url}."
            );

            $this->assertSame(
                1,
                substr_count($sidebar, 'aria-current="page"'),
                "Exactly one menu item may be active on {$url}."
            );
        }
    }

    public function test_navigation_only_links_to_modules_that_render_a_view(): void
    {
        $sidebar = $this->sidebar('/dashboard');

        // Purchasing, suppliers, expenses and business invoices each ship a controller,
        // a view and a permission, and are reachable from the menu. They used to be
        // asserted absent on the grounds that "their views do not exist" — that premise
        // stopped being true when the views landed, and leaving the assertion in place
        // would have kept a finished feature permanently unreachable from the UI.
        foreach ([
            'purchases.index', 'suppliers.index', 'expenses.index', 'business-invoices.index',
        ] as $routeName) {
            $this->assertStringContainsString(
                'href="'.route($routeName).'"',
                $sidebar,
                "Navigation must link to {$routeName}."
            );
        }

        foreach ([
            'products.index', 'stock.index', 'categories.index', 'brands.index',
            'sales.index', 'sales.returns', 'sales.report', 'customers.index',
            'team.index', 'billing.index', 'tenants.index',
            'profile.edit', 'tokens.index',
        ] as $routeName) {
            $this->assertStringContainsString(
                'href="'.route($routeName).'"',
                $sidebar,
                "Navigation must link to {$routeName}."
            );
        }

        $this->assertStringNotContainsString(
            'href="'.route('sales.dashboard').'"',
            $sidebar,
            'The detailed sales dashboard must remain available without appearing as a second sidebar dashboard.'
        );

        foreach (['Overview', 'Sales', 'Inventory', 'Workspace', 'Account'] as $group) {
            $this->assertStringContainsString($group, $sidebar);
        }

        // 'Subscription' replaced 'Billing' in this list: the sidebar entry leads to the plan
        // and subscription screen, so it carries the subscription wording. The /billing
        // route itself is deliberately unchanged. 'API Access' likewise replaced 'API Tokens'
        // as the sidebar wording for the same /tokens screen; the route is unchanged.
        foreach (['Dashboard', 'Orders', 'Customers', 'Returns', 'Reports', 'Products', 'Stock', 'Categories', 'Brands', 'Team', 'Subscription', 'Workspaces', 'Profile', 'API Access'] as $label) {
            $this->assertStringContainsString($label, $sidebar);
        }

        $groupOrder = array_map(
            fn (string $group): int => strpos($sidebar, $group),
            ['Overview', 'Sales', 'Inventory', 'Workspace', 'Account']
        );
        $sortedGroups = $groupOrder;
        sort($sortedGroups);
        $this->assertSame($sortedGroups, $groupOrder, 'Navigation groups must follow the business information hierarchy.');
    }

    public function test_navigation_is_scoped_to_the_active_workspace(): void
    {
        // A user without an active membership has no tenant context.
        $stranger = User::create([
            'name' => 'No Workspace',
            'email' => 'no-workspace@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $html = $this->actingAs($stranger)->get('/tenants')->assertOk()->getContent();

        preg_match('/<aside id="sidebar".*?<\/aside>/s', $html, $matches);
        $this->assertNotEmpty($matches, 'The shared shell sidebar is missing on /tenants.');
        $sidebar = $matches[0];

        $this->assertStringContainsString('href="'.route('tenants.index').'"', $sidebar);
        $this->assertStringContainsString('Select a workspace', $sidebar);

        foreach (['products.index', 'categories.index', 'brands.index', 'team.index', 'billing.index'] as $routeName) {
            $this->assertStringNotContainsString('href="'.route($routeName).'"', $sidebar);
        }
    }

    public function test_viewer_keeps_the_catalogue_it_is_allowed_to_read(): void
    {
        $viewer = User::create([
            'name' => 'Shell Viewer',
            'email' => 'shell-viewer@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $this->tenant->users()->attach($viewer->id, [
            'role' => 'Viewer',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $sidebar = $this->sidebar('/products', $viewer);

        // view_records comes from the existing RBAC registry, so the menu stays usable...
        foreach (['products.index', 'categories.index', 'brands.index'] as $routeName) {
            $this->assertStringContainsString('href="'.route($routeName).'"', $sidebar);
        }

        // ...while writes stay blocked server-side (covered by CategoryBrandCrudTest).
        $this->assertSame(1, substr_count($sidebar, 'aria-current="page"'));
    }

    public function test_platform_admin_link_is_only_rendered_for_platform_admins(): void
    {
        $this->assertStringNotContainsString('href="'.route('admin.dashboard').'"', $this->sidebar('/dashboard'));

        $this->owner->forceFill(['is_platform_admin' => true])->save();

        $this->assertStringContainsString('href="'.route('admin.dashboard').'"', $this->sidebar('/dashboard'));
    }

    public function test_every_link_in_the_sidebar_actually_opens_a_page(): void
    {
        $sidebar = $this->sidebar('/dashboard');

        // Pull the sidebar's own hrefs out of the rendered markup rather than a hardcoded
        // list, so this keeps covering the menu as it grows. `route()` produced them, so
        // each one is a real route by construction; what is asserted here is that the page
        // behind it renders for a real member of the workspace — the "no dead links"
        // promise the sidebar comment makes.
        //
        // Plan-locked entries are excluded, and deliberately so. The shell keeps those
        // visible so a Free workspace can see what higher plans add, and clicking one is
        // documented to land on the upgrade prompt rather than on data — so a non-200 is
        // the correct result there, not a broken link. They are marked in the markup with
        // data-plan-locked, which is what this filter keys on.
        preg_match_all('/<a\b[^>]*href="([^"]+)"[^>]*>/', $sidebar, $matches, PREG_SET_ORDER);

        $appHost = parse_url(config('app.url'), PHP_URL_HOST);
        $hrefs = [];

        foreach ($matches as $match) {
            if (str_contains($match[0], 'data-plan-locked')) {
                continue;
            }

            $href = $match[1];
            $host = parse_url($href, PHP_URL_HOST);

            if ($host !== null && $host !== $appHost) {
                continue;
            }

            $path = parse_url($href, PHP_URL_PATH) ?: '';

            if ($path !== '' && $path !== '/') {
                $hrefs[$path] = true;
            }
        }

        $hrefs = array_keys($hrefs);

        $this->assertNotEmpty($hrefs, 'The sidebar rendered no internal links at all.');

        foreach ($hrefs as $href) {
            // assertOk() cannot carry a message, and "which link 403'd?" is the whole
            // point of this test, so the status is compared directly to name it.
            $response = $this->asMember()->get($href);

            $this->assertSame(
                200,
                $response->getStatusCode(),
                "Sidebar link {$href} did not open a page."
            );
        }
    }

    public function test_plan_gated_pages_refuse_a_workspace_without_the_entitlement(): void
    {
        // The security invariant behind the lock marker: a page the plan gate protects
        // must answer a Free workspace with a refusal, never with records.
        //
        // Scope note: this drives off the ROUTE's middleware rather than the lock icon,
        // because the two are not equivalent in the current shell. /tokens renders a lock
        // marker but its route carries no plan.feature middleware, so it opens normally.
        // That mismatch is pre-existing and cosmetic — the page is genuinely usable — so
        // it is reported rather than encoded here as either a pass or a failure. Changing
        // the route would alter existing behaviour and is out of scope for this work.
        $sidebar = $this->sidebar('/dashboard');

        // No lock-marker filter here, on purpose: in this shell a plan-gated page IS a
        // lock-marked one, so filtering them out would leave nothing to check.
        preg_match_all('/<a\b[^>]*href="([^"]+)"[^>]*>/', $sidebar, $matches, PREG_SET_ORDER);

        $appHost = parse_url(config('app.url'), PHP_URL_HOST);
        $checked = 0;

        foreach ($matches as $match) {
            $host = parse_url($match[1], PHP_URL_HOST);

            if ($host !== null && $host !== $appHost) {
                continue;
            }

            $path = parse_url($match[1], PHP_URL_PATH) ?: '';

            if ($path === '' || $path === '/') {
                continue;
            }

            $route = collect(Route::getRoutes())
                ->first(fn ($candidate) => $candidate->uri() === ltrim($path, '/'));

            if ($route === null) {
                continue;
            }

            $gated = collect($route->gatherMiddleware())
                ->contains(fn ($middleware) => str_starts_with((string) $middleware, 'plan.feature'));

            if (! $gated) {
                continue;
            }

            $checked++;

            $this->assertNotSame(
                200,
                $this->asMember()->get($path)->getStatusCode(),
                "Plan-gated page {$path} served data to a workspace without the entitlement."
            );
        }

        $this->assertGreaterThan(
            0,
            $checked,
            'No plan-gated page was found in the sidebar, so the entitlement gate went unchecked.'
        );
    }

    public function test_the_newly_linked_pages_are_reachable_and_render_fully(): void
    {
        // Each of these shipped with a controller and a view but had no menu entry, so
        // they could only be reached by typing the URL. Being linked is worthless if the
        // page behind the link is broken, so the page itself is opened here too.
        foreach ([
            '/purchases' => 'Purchase Orders',
            '/suppliers' => 'Suppliers',
            '/expenses' => 'Expenses',
            '/business-invoices' => 'Invoices',
        ] as $url => $expected) {
            $html = $this->asMember()->get($url)->assertOk()->getContent();

            $this->assertStringContainsString($expected, $html, "{$url} did not render its own heading.");
            // The shared shell is still the frame around the new page.
            $this->assertStringContainsString('id="sidebar"', $html);
            $this->assertStringContainsString('id="mobile-sidebar"', $html);
        }
    }

    public function test_the_purchasing_group_appears_in_the_sidebar_and_keeps_the_group_order(): void
    {
        $sidebar = $this->sidebar('/dashboard');

        foreach (['Purchase Orders', 'Suppliers', 'Expenses', 'Invoices'] as $label) {
            $this->assertStringContainsString($label, $sidebar, "The sidebar is missing {$label}.");
        }

        // Purchasing sits between Sales and Insights, mirroring how the business reads:
        // sell, then buy, then analyse.
        $groupOrder = array_map(
            fn (string $group): int => strpos($sidebar, $group),
            ['Sales', 'Purchasing', 'Insights', 'Inventory']
        );

        $sorted = $groupOrder;
        sort($sorted);
        $this->assertSame($sorted, $groupOrder, 'Navigation groups must keep their business hierarchy.');
    }

    public function test_a_user_without_a_workspace_does_not_see_the_new_entries(): void
    {
        $stranger = User::create([
            'name' => 'No Workspace',
            'email' => 'purchasing-no-workspace@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $html = $this->actingAs($stranger)->get('/tenants')->assertOk()->getContent();

        preg_match('/<aside id="sidebar".*?<\/aside>/s', $html, $matches);
        $this->assertNotEmpty($matches);

        foreach (['purchases.index', 'suppliers.index', 'expenses.index', 'business-invoices.index'] as $routeName) {
            $this->assertStringNotContainsString('href="'.route($routeName).'"', $matches[0]);
        }
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/brands')->assertRedirect('/login');
    }
}
