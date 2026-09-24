<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * End-to-end coverage for the tenant-scoped catalogue taxonomy:
 * Brands and Categories index (incl. the index filters), create/edit/update/delete,
 * role gating and cross-tenant isolation.
 */
class CategoryBrandCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $otherOwner;
    private Tenant $tenant;
    private Tenant $otherTenant;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->owner, $this->otherOwner] = collect(['owner@test.dev', 'other@test.dev'])
            ->map(fn ($email) => User::create([
                'name' => 'Owner '.strtoupper($email[0]),
                'email' => $email,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]))->all();

        foreach ([$this->owner, $this->otherOwner] as $index => $owner) {
            $tenant = Tenant::create([
                'name' => 'Tenant '.($index + 1),
                'slug' => 'tenant-'.($index + 1),
                'owner_id' => $owner->id,
            ]);

            $tenant->users()->attach($owner->id, [
                'role' => 'Owner',
                'status' => 'active',
                'joined_at' => now(),
            ]);

            $index === 0 ? $this->tenant = $tenant : $this->otherTenant = $tenant;
        }
    }

    /** Authenticate as a member of $tenant for the current request. */
    private function asMember(Tenant $tenant, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->owner)->withSession(['tenant_id' => $tenant->id]);
    }

    private function makeCategory(array $attributes = []): Category
    {
        return Category::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'name' => 'Minuman',
            'is_active' => true,
        ], $attributes));
    }

    private function makeBrand(array $attributes = []): Brand
    {
        return Brand::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kopi Nusantara',
            'is_active' => true,
        ], $attributes));
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kopi Susu',
            'sku' => 'KP-001',
            'unit' => 'pcs',
            'purchase_price' => 1_000_000,
            'selling_price' => 1_500_000,
            'is_active' => true,
        ], $attributes));
    }

    public function test_brands_index_renders_and_lists_only_current_tenant_brands(): void
    {
        $this->makeBrand();
        $this->makeBrand(['tenant_id' => $this->otherTenant->id, 'name' => 'Brand Tenant Lain']);

        $this->asMember($this->tenant)->get('/brands')
            ->assertOk()
            ->assertSee('Add brand')
            ->assertSee('Kopi Nusantara')
            // Shared application shell renders the navigation with Brands marked active.
            ->assertSee('border-indigo-400', false)
            ->assertSee('href="'.route('categories.index').'"', false)
            ->assertDontSee('Brand Tenant Lain');
    }

    /**
     * Regression: the index used Illuminate\Http\Request::getString(), which does not
     * exist and made every request to /brands fail before the view was rendered.
     */
    public function test_brands_index_applies_search_and_status_filters(): void
    {
        $this->makeBrand(['name' => 'Kopi Nusantara']);
        $this->makeBrand(['name' => 'Teh Botol', 'is_active' => false]);

        $this->asMember($this->tenant)->get('/brands?search=Kopi')
            ->assertOk()
            ->assertSee('Kopi Nusantara')
            ->assertDontSee('Teh Botol');

        $this->asMember($this->tenant)->get('/brands?active_only=1')
            ->assertOk()
            ->assertSee('Kopi Nusantara')
            ->assertDontSee('Teh Botol');

        $this->asMember($this->tenant)->get('/brands?show_inactive=1')
            ->assertOk()
            ->assertSee('Teh Botol')
            ->assertDontSee('Kopi Nusantara');
    }

    public function test_brand_can_be_created_updated_and_deleted(): void
    {
        $this->asMember($this->tenant)->get('/brands/create')
            ->assertOk()
            ->assertSee('Add Brand');

        $this->asMember($this->tenant)->post('/brands', [
            'name' => 'Kapal Api',
            'description' => 'Kopi bubuk',
            'is_active' => '1',
        ])->assertRedirect(route('brands.index'));

        $brand = Brand::withoutGlobalScopes()->where('name', 'Kapal Api')->firstOrFail();
        $this->assertSame($this->tenant->id, $brand->tenant_id);
        $this->assertNotEmpty($brand->slug);

        $this->asMember($this->tenant)->get("/brands/{$brand->id}/edit")
            ->assertOk()
            ->assertSee('Edit Brand');

        $this->asMember($this->tenant)->put("/brands/{$brand->id}", [
            'name' => 'Kapal Api Laut',
            'description' => 'Kopi bubuk',
            'is_active' => '0',
        ])->assertRedirect(route('brands.index'));

        $brand->refresh();
        $this->assertSame('Kapal Api Laut', $brand->name);
        $this->assertFalse($brand->is_active);

        $this->asMember($this->tenant)->delete("/brands/{$brand->id}")
            ->assertRedirect(route('brands.index'));
        $this->assertDatabaseMissing('brands', ['id' => $brand->id]);
    }

    public function test_brand_used_by_products_cannot_be_deleted(): void
    {
        $brand = $this->makeBrand();
        $this->makeProduct(['brand_id' => $brand->id]);

        $this->asMember($this->tenant)->delete("/brands/{$brand->id}")->assertRedirect();

        $this->assertDatabaseHas('brands', ['id' => $brand->id]);
    }

    public function test_categories_index_renders_and_lists_only_current_tenant_categories(): void
    {
        $category = $this->makeCategory();
        $this->makeProduct(['category_id' => $category->id]);
        $this->makeCategory(['tenant_id' => $this->otherTenant->id, 'name' => 'Kategori Tenant Lain']);

        $this->asMember($this->tenant)->get('/categories')
            ->assertOk()
            ->assertSee('Add category')
            ->assertSee('Minuman')
            // Shared application shell renders the navigation with Categories marked active.
            ->assertSee('border-indigo-400', false)
            ->assertSee('href="'.route('brands.index').'"', false)
            ->assertDontSee('Kategori Tenant Lain');
    }

    public function test_category_can_be_created_updated_and_deleted(): void
    {
        $this->asMember($this->tenant)->get('/categories/create')
            ->assertOk()
            ->assertSee('Add Category');

        $this->asMember($this->tenant)->post('/categories', [
            'name' => 'Makanan',
            'description' => 'Snack dan lain-lain',
            'is_active' => '1',
        ])->assertRedirect(route('categories.index'));

        $category = Category::withoutGlobalScopes()->where('name', 'Makanan')->firstOrFail();
        $this->assertSame($this->tenant->id, $category->tenant_id);
        $this->assertSame('makanan', $category->slug);

        $this->asMember($this->tenant)->get("/categories/{$category->id}/edit")
            ->assertOk()
            ->assertSee('Edit Category');

        $this->asMember($this->tenant)->put("/categories/{$category->id}", [
            'name' => 'Makanan Ringan',
            'description' => 'Snack',
            'is_active' => '0',
        ])->assertRedirect(route('categories.index'));

        $category->refresh();
        $this->assertSame('Makanan Ringan', $category->name);
        $this->assertFalse($category->is_active);

        $this->asMember($this->tenant)->delete("/categories/{$category->id}")
            ->assertRedirect(route('categories.index'));
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_category_used_by_products_cannot_be_deleted(): void
    {
        $category = $this->makeCategory();
        $this->makeProduct(['category_id' => $category->id]);

        $this->asMember($this->tenant)->delete("/categories/{$category->id}")->assertRedirect();

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_other_tenant_cannot_open_or_modify_brands_and_categories(): void
    {
        $brand = $this->makeBrand();
        $category = $this->makeCategory();

        $this->asMember($this->otherTenant, $this->otherOwner)
            ->get("/brands/{$brand->id}/edit")->assertNotFound();

        $this->asMember($this->otherTenant, $this->otherOwner)
            ->put("/brands/{$brand->id}", ['name' => 'Hijacked'])->assertNotFound();

        $this->asMember($this->otherTenant, $this->otherOwner)
            ->delete("/brands/{$brand->id}")->assertNotFound();

        $this->asMember($this->otherTenant, $this->otherOwner)
            ->get("/categories/{$category->id}/edit")->assertNotFound();

        $this->asMember($this->otherTenant, $this->otherOwner)
            ->put("/categories/{$category->id}", ['name' => 'Hijacked'])->assertNotFound();

        $this->asMember($this->otherTenant, $this->otherOwner)
            ->delete("/categories/{$category->id}")->assertNotFound();

        $this->assertSame('Kopi Nusantara', Brand::withoutGlobalScopes()->findOrFail($brand->id)->name);
        $this->assertSame('Minuman', Category::withoutGlobalScopes()->findOrFail($category->id)->name);
    }

    public function test_viewer_can_read_but_not_write_brands_and_categories(): void
    {
        $brand = $this->makeBrand();
        $category = $this->makeCategory();

        $viewer = User::create([
            'name' => 'Viewer',
            'email' => 'viewer@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $this->tenant->users()->attach($viewer->id, [
            'role' => 'Viewer',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->asMember($this->tenant, $viewer)->get('/brands')->assertOk();
        $this->asMember($this->tenant, $viewer)->get("/brands/{$brand->id}/edit")->assertForbidden();
        $this->asMember($this->tenant, $viewer)->get('/categories')->assertOk();
        $this->asMember($this->tenant, $viewer)->get("/categories/{$category->id}/edit")->assertForbidden();

        $this->asMember($this->tenant, $viewer)
            ->post('/brands', ['name' => 'Nope'])->assertForbidden();
        $this->asMember($this->tenant, $viewer)
            ->post('/categories', ['name' => 'Nope'])->assertForbidden();

        $this->asMember($this->tenant, $viewer)
            ->delete("/brands/{$brand->id}")->assertForbidden();
        $this->asMember($this->tenant, $viewer)
            ->delete("/categories/{$category->id}")->assertForbidden();

        $this->assertDatabaseHas('brands', ['id' => $brand->id]);
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/brands')->assertRedirect('/login');
        $this->get('/categories')->assertRedirect('/login');
    }
}