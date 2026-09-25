<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
            '/files',
            '/team',
            '/billing',
        ];

        foreach ($pages as $url) {
            $this->asMember()->get($url)
                ->assertOk()
                // Sidebar + mobile drawer share one navigation source.
                ->assertSee('id="sidebar"', false)
                ->assertSee('id="mobile-sidebar"', false)
                ->assertSee('min-w-0 space-y-6 px-4 sm:px-0', false)
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
            '/files' => 'files.index',
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

        // Routes exist for these modules, but their views do not — they must not be linked.
        foreach (['purchases.index', 'suppliers.index'] as $routeName) {
            $this->assertStringNotContainsString(
                'href="'.route($routeName).'"',
                $sidebar,
                "Navigation must not link to {$routeName} while it has no view."
            );
        }

        foreach ([
            'products.index', 'stock.index', 'categories.index', 'brands.index',
            'sales.index', 'sales.returns', 'sales.report', 'customers.index',
            'files.index', 'team.index', 'billing.index', 'tenants.index',
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

        foreach (['Dashboard', 'Orders', 'Customers', 'Returns', 'Reports', 'Products', 'Stock', 'Categories', 'Brands', 'Files', 'Team', 'Billing', 'Workspaces', 'Profile', 'API Tokens'] as $label) {
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

        foreach (['products.index', 'categories.index', 'brands.index', 'files.index', 'team.index', 'billing.index'] as $routeName) {
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

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/brands')->assertRedirect('/login');
    }
}