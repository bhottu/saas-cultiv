<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The two shortcuts from a product page into the EXISTING stock flow.
 *
 * Nothing here introduces stock behaviour. Both entry points are plain links to
 * stock.index carrying a product_id, and stock.index already owned the adjustment
 * form. What is pinned here:
 *
 *   - the link sits directly under "Product created", and only there
 *   - it is a text link, not a button: no background, border or card styling
 *   - it carries the product so the adjustment form arrives pre-selected
 *   - neither entry point is offered to somebody lacking the matching permission
 *   - a product_id from another workspace resolves to nothing rather than to data
 */
class ProductStockShortcutTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    private Tenant $otherTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->account('owner@test.dev');
        $this->tenant = $this->workspace($this->owner, 'Toko Kopi');

        $this->otherTenant = $this->workspace($this->account('stranger@test.dev'), 'Toko Lain');
    }

    private function account(string $email): User
    {
        return User::create([
            'name' => 'User '.explode('@', $email)[0],
            'email' => $email,
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
    }

    private function workspace(User $owner, string $name): Tenant
    {
        $tenant = Tenant::create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'owner_id' => $owner->id,
        ]);

        $tenant->users()->attach($owner->id, [
            'role' => 'Owner',
            'status' => TenantUser::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);

        return $tenant;
    }

    private function asMember(Tenant $tenant, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->owner)->withSession(['tenant_id' => $tenant->id]);
    }

    private function memberWithRole(string $role, string $slug): User
    {
        $user = $this->account($slug.'@test.dev');

        $this->tenant->users()->attach($user->id, [
            'role' => $role,
            'status' => TenantUser::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);

        return $user;
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
            'cost_price' => 1_000_000,
            'is_active' => true,
            'track_inventory' => true,
        ], $attributes));
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Gudang Utama',
            'is_active' => true,
        ]);
    }

    /** Render the product page as though the user had just created the product. */
    private function justCreated(Product $product, ?User $user = null): string
    {
        return $this->asMember($this->tenant, $user)
            ->withSession([
                'status' => ['type' => 'success', 'message' => 'Product created.'],
                'product_just_created' => true,
            ])
            ->get(route('products.show', $product))
            ->assertOk()
            ->getContent();
    }

    /*
    |--------------------------------------------------------------------------
    | 1. Link under "Product created"
    |--------------------------------------------------------------------------
    */

    public function test_creating_a_product_offers_a_link_to_stock_for_that_product(): void
    {
        $response = $this->asMember($this->tenant)->post(route('products.store'), [
            'name' => 'Kopi Arabika',
            'sku' => 'AR-100',
            'unit' => 'pcs',
            'purchase_price' => 25000,
            'selling_price' => 35000,
            'track_inventory' => 1,
            'is_active' => 1,
        ]);

        $product = Product::where('sku', 'AR-100')->firstOrFail();

        $response->assertRedirect(route('products.show', $product));
        $response->assertSessionHas('product_just_created');

        $html = $this->asMember($this->tenant)
            ->get(route('products.show', $product))
            ->assertOk()
            ->getContent();

        // The banner that was already there is untouched.
        $this->assertStringContainsString('Product created.', $html);
        $this->assertStringContainsString(
            'href="'.route('stock.index', ['product_id' => $product->id]).'"',
            $html
        );
    }

    public function test_the_link_appears_directly_below_the_product_created_banner(): void
    {
        $product = $this->makeProduct();

        $html = $this->justCreated($product);

        $bannerAt = strpos($html, 'Product created.');
        $linkAt = strpos($html, route('stock.index', ['product_id' => $product->id]));

        $this->assertNotFalse($bannerAt);
        $this->assertNotFalse($linkAt);
        $this->assertGreaterThan(
            $bannerAt,
            $linkAt,
            'The stock link must come after the "Product created" banner.'
        );

        // Close enough to read as part of that message rather than a separate block.
        $this->assertLessThan(600, $linkAt - $bannerAt);
    }

    public function test_the_link_is_plain_text_and_not_a_button(): void
    {
        $product = $this->makeProduct();

        $html = $this->justCreated($product);

        $this->assertSame(
            1,
            preg_match(
                '/<a\b[^>]*href="'.preg_quote(route('stock.index', ['product_id' => $product->id]), '/').'"[^>]*>/',
                $html,
                $matches
            ),
            'Exactly one anchor should point at stock for this product.'
        );

        $anchor = $matches[0];

        // No button dressing: no background, border, padding or shadow utilities.
        foreach (['bg-', 'border', 'rounded', 'shadow', 'px-', 'py-'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $anchor,
                "The stock link must not carry button styling ('{$forbidden}')."
            );
        }

        // It must still look clickable.
        $this->assertStringContainsString('underline', $anchor);
        $this->assertStringContainsString('text-indigo-600', $anchor);
        $this->assertStringContainsString('hover:', $anchor);
    }

    public function test_the_link_is_absent_on_a_plain_visit_and_after_an_update(): void
    {
        $product = $this->makeProduct();

        // Asserted on the link TEXT, not the URL: the "Add stock" button deliberately
        // points at the same stock.index?product_id=... destination, so checking for
        // the URL would pass only by hiding the button as well.
        $linkText = 'Manage stock for this item or add another product';

        // No creation flag at all.
        $this->asMember($this->tenant)
            ->get(route('products.show', $product))
            ->assertOk()
            ->assertDontSee($linkText);

        // An update reuses the same banner without the creation flag.
        $this->asMember($this->tenant)
            ->from(route('products.edit', $product))
            ->put(route('products.update', $product), [
                'name' => 'Kopi Susu Besar',
                'unit' => 'pcs',
                'track_inventory' => 1,
                'is_active' => 1,
            ])
            ->assertRedirect(route('products.show', $product));

        $html = $this->asMember($this->tenant)
            ->get(route('products.show', $product))
            ->assertOk()
            ->getContent();

        // The update banner is showing...
        $this->assertStringContainsString('Product updated.', $html);
        // ...but the creation-only link is not.
        $this->assertStringNotContainsString($linkText, $html);
    }

    /*
    |--------------------------------------------------------------------------
    | 2. "Add stock" on the Stock on hand card
    |--------------------------------------------------------------------------
    */

    public function test_the_stock_on_hand_card_carries_an_add_stock_button(): void
    {
        $product = $this->makeProduct();

        $html = $this->asMember($this->tenant)
            ->get(route('products.show', $product))
            ->assertOk()
            ->getContent();

        $expected = route('stock.index', ['product_id' => $product->id]);

        $this->assertStringContainsString('Stock on hand', $html);
        $this->assertStringContainsString('href="'.$expected.'"', $html);
        $this->assertStringContainsString('>Add stock</a>', $html);

        // Rendered inside the card, between its heading and the movement table.
        $cardAt = strpos($html, 'Stock on hand');
        $buttonAt = strpos($html, $expected);
        $tableAt = strpos($html, 'Recent stock movements');

        $this->assertGreaterThan($cardAt, $buttonAt);
        $this->assertLessThan($tableAt, $buttonAt);
    }

    public function test_add_stock_is_hidden_for_a_product_that_does_not_track_inventory(): void
    {
        $product = $this->makeProduct(['track_inventory' => false]);

        $this->asMember($this->tenant)
            ->get(route('products.show', $product))
            ->assertOk()
            ->assertDontSee(route('stock.index', ['product_id' => $product->id]));
    }

    /*
    |--------------------------------------------------------------------------
    | 3. Authorization
    |--------------------------------------------------------------------------
    */

    public function test_a_viewer_sees_the_stock_page_link_but_not_the_add_stock_button(): void
    {
        // Viewer holds view_records (inventory.view) but not update_records (stock.adjust).
        $viewer = $this->memberWithRole('Viewer', 'viewer');
        $product = $this->makeProduct();

        $html = $this->justCreated($product, $viewer);

        // Reading stock is allowed, so the link to the stock page is fine...
        $this->assertStringContainsString(route('stock.index', ['product_id' => $product->id]), $html);

        // ...but the Viewer must not be handed an action that would 403 on submit.
        $this->assertStringNotContainsString('>Add stock</a>', $html);
        $this->assertStringNotContainsString('>Tambah stok</a>', $html);
    }

    public function test_a_staff_member_gets_the_add_stock_button(): void
    {
        // Staff holds view_records and update_records: both gates pass.
        $staff = $this->memberWithRole('Staff', 'staff');
        $product = $this->makeProduct();

        $this->asMember($this->tenant, $staff)
            ->get(route('products.show', $product))
            ->assertOk()
            ->assertSee(route('stock.index', ['product_id' => $product->id]))
            ->assertSee('>Add stock</a>', false);
    }

    /*
    |--------------------------------------------------------------------------
    | 4. Tenant isolation
    |--------------------------------------------------------------------------
    */

    public function test_a_product_id_from_another_workspace_is_not_reachable(): void
    {
        $foreign = Product::create([
            'tenant_id' => $this->otherTenant->id,
            'name' => 'Rahasia Tetangga',
            'sku' => 'RAH-001',
            'unit' => 'pcs',
            'is_active' => true,
            'track_inventory' => true,
        ]);

        // The product page itself stays closed...
        $this->asMember($this->tenant)->get(route('products.show', $foreign))->assertNotFound();

        // ...and the stock page does not pre-select it for a foreign id.
        $html = $this->asMember($this->tenant)
            ->get(route('stock.index', ['product_id' => $foreign->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Rahasia Tetangga', $html);
        $this->assertStringNotContainsString('<option value="'.$foreign->id.'" selected', $html);
        $this->assertStringNotContainsString('selected value="'.$foreign->id.'"', $html);
    }

    /*
    |--------------------------------------------------------------------------
    | 5. Product context survives the hand-off
    |--------------------------------------------------------------------------
    */

    public function test_the_stock_page_arrives_with_the_product_already_selected(): void
    {
        $this->warehouse();
        $product = $this->makeProduct();

        $html = $this->asMember($this->tenant)
            ->get(route('stock.index', ['product_id' => $product->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            '<option value="'.$product->id.'" selected',
            $html,
            'The adjustment form must open with this product already chosen.'
        );
    }

    public function test_the_stock_page_still_works_without_the_parameter(): void
    {
        $this->warehouse();
        $this->makeProduct();

        $this->asMember($this->tenant)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('Stock adjustment / opening balance');
    }

    public function test_an_adjustment_made_from_the_shortcut_shows_up_on_the_product_page(): void
    {
        $warehouse = $this->warehouse();
        $product = $this->makeProduct();

        // The whole flow the shortcut exists for: product page -> stock -> back again.
        $this->asMember($this->tenant)
            ->post(route('stock.adjust'), [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'direction' => 'in',
                'quantity' => 25,
                'reason' => 'Opening stock',
            ])
            ->assertRedirect();

        $html = $this->asMember($this->tenant)
            ->get(route('products.show', $product))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('25', $html);
        $this->assertStringContainsString('Gudang Utama', $html);
    }

    /*
    |--------------------------------------------------------------------------
    | 6. Localization
    |--------------------------------------------------------------------------
    */

    public function test_the_new_strings_exist_in_both_locales(): void
    {
        $this->assertSame(
            'Manage stock for this item or add another product',
            __('Manage stock for this item or add another product', [], 'en')
        );

        $this->assertSame(
            'Atur stok untuk barang ini atau tambahkan produk lainnya',
            __('Manage stock for this item or add another product', [], 'id')
        );

        $this->assertSame('Add stock', __('Add stock', [], 'en'));
        $this->assertSame('Tambah stok', __('Add stock', [], 'id'));
    }

    public function test_an_indonesian_reader_sees_the_indonesian_shortcuts(): void
    {
        $reader = $this->account('indonesian@test.dev');
        $reader->forceFill(['locale' => 'id'])->save();

        $this->tenant->users()->attach($reader->id, [
            'role' => 'Owner',
            'status' => TenantUser::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);

        $product = $this->makeProduct();
        $html = $this->justCreated($product, $reader);

        $this->assertStringContainsString('Atur stok untuk barang ini atau tambahkan produk lainnya', $html);
        $this->assertStringContainsString('Tambah stok', $html);
        $this->assertStringNotContainsString('Manage stock for this item or add another product', $html);
    }
}
