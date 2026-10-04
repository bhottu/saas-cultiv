<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The purchase form's usability contract.
 *
 * Two defects are pinned here, both of which made the page look finished while being
 * unusable:
 *
 *  - "Add item" did nothing. The Alpine component was registered inside
 *    `@push('scripts')`, but no layout in this project renders `@stack('scripts')`, so the
 *    script was never emitted and `purchaseForm` never existed. The x-data expression
 *    threw, the subtree never initialised, and the button silently failed.
 *
 *  - A rejected submit came back with no visible reason. The form had `required`
 *    attributes and a server-side ruleset, but rendered neither an error summary nor
 *    per-field messages, so a user pressing "Create purchase" on an empty form simply saw
 *    their own page again.
 *
 * Assertions read the rendered HTML rather than the session, because both bugs lived in
 * the template: a session-only assertion passes against the broken page.
 */
class PurchaseFormUxTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->account('purchase-owner@test.dev');
        $this->tenant = $this->workspace($this->owner, 'Kopi Kita');

        $this->warehouse = Warehouse::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Gudang Utama',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kopi Arabika',
            'sku' => 'ARB-01',
            'unit' => 'pcs',
            'purchase_price' => 100000,
            'selling_price' => 150000,
            'cost_price' => 100000,
            'track_inventory' => true,
            'is_active' => true,
        ]);
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

    private function member(?Tenant $tenant = null, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->owner)
            ->withSession(['tenant_id' => ($tenant ?? $this->tenant)->id]);
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

    private function supplier(array $overrides = []): Supplier
    {
        return Supplier::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'name' => 'PT Kopi Nusantara',
            'is_active' => true,
        ], $overrides));
    }

    /** A payload the server-side ruleset accepts. */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'supplier_id' => $this->supplier()->id,
            'warehouse_id' => $this->warehouse->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_cost' => 10000]],
        ], $overrides);
    }

    // --------------------------------------------------- the Add Item regression

    public function test_the_alpine_component_is_actually_emitted(): void
    {
        $this->supplier();

        $html = $this->member()->get('/purchases/create')->assertOk()->getContent();

        // Inline registration, parsed before the deferred app bundle. This is the exact
        // thing that was missing: the component must exist in the document for
        // `x-data="purchaseForm(...)"` to resolve and Add item to do anything.
        $this->assertStringContainsString('window.purchaseForm', $html);
        $this->assertStringContainsString('x-data="purchaseForm(', $html);
        $this->assertStringContainsString('@click="addRow()"', $html);
        $this->assertStringContainsString('x-for="(row, index) in items"', $html);
    }

    public function test_the_component_does_not_rely_on_an_unrendered_stack(): void
    {
        $this->supplier();

        $html = $this->member()->get('/purchases/create')->assertOk()->getContent();

        // A @push into a stack no layout renders is a silent no-op, which is exactly how
        // the button ended up dead. Nothing should depend on one any more.
        $this->assertStringNotContainsString("@push('scripts')", $html);
    }

    public function test_the_form_opens_with_one_empty_row(): void
    {
        $this->supplier();

        $html = $this->member()->get('/purchases/create')->assertOk()->getContent();

        // No remembered items and no saved purchase: init() must still seed one row,
        // otherwise the required "Items *" rule cannot be satisfied at all.
        $this->assertStringContainsString('if (this.items.length === 0)', $html);
        $this->assertStringContainsString('this.addRow();', $html);
    }

    public function test_remove_row_keeps_the_form_submittable(): void
    {
        $this->supplier();

        $html = $this->member()->get('/purchases/create')->assertOk()->getContent();

        $this->assertStringContainsString('@click="removeRow(row.key)"', $html);
        // Deleting the last row resets it rather than leaving an empty grid.
        $this->assertStringContainsString('if (this.items.length === 1)', $html);
    }

    public function test_the_total_actually_reacts_to_discount_tax_and_shipping(): void
    {
        $this->supplier();

        $html = $this->member()->get('/purchases/create')->assertOk()->getContent();

        // These three inputs were never bound, so the running total silently ignored
        // every amount typed into them while still submitting the values.
        foreach (['discountValue', 'taxValue', 'shippingValue'] as $model) {
            $this->assertStringContainsString('x-model="'.$model.'"', $html);
        }
    }

    // ------------------------------------------------------ empty supplier state

    public function test_no_supplier_explains_itself_and_offers_the_call_to_action(): void
    {
        $html = $this->member()->get('/purchases/create')->assertOk()->getContent();

        $this->assertStringContainsString('No suppliers yet.', $html);
        $this->assertStringContainsString('Add a supplier first to create a purchase.', $html);

        // The CTA points at the EXISTING supplier create route — no new route invented.
        $this->assertStringContainsString('href="'.url('/suppliers/create').'"', $html);
        $this->assertStringContainsString('Add supplier', $html);

        // And there is no pointless empty dropdown left in its place.
        $this->assertStringNotContainsString('id="supplier_id"', $html);
    }

    public function test_the_call_to_action_is_withheld_without_permission(): void
    {
        // The permission registry maps BOTH purchases.create and suppliers.create onto the
        // single `create_records` verb, so no stock role can reach this page while being
        // denied supplier creation. The branch is therefore driven with a substituted
        // authorization service — which is exactly what proves the page never advertises a
        // control the user could not have used.
        $denied = new class(app(\App\Services\TenantContext::class)) extends \App\Services\BusinessAuthorization
        {
            public function can(string $action): bool
            {
                return $action === 'suppliers.create' ? false : parent::can($action);
            }
        };

        $this->app->instance(\App\Services\BusinessAuthorization::class, $denied);

        $html = $this->member()->get('/purchases/create')->assertOk()->getContent();

        // The information still shows...
        $this->assertStringContainsString('No suppliers yet.', $html);

        // ...but no link to a page that would answer 403.
        $this->assertStringNotContainsString('href="'.url('/suppliers/create').'"', $html);
    }

    public function test_a_single_available_supplier_is_selectable(): void
    {
        $supplier = $this->supplier();

        $html = $this->member()->get('/purchases/create')->assertOk()->getContent();

        $this->assertStringContainsString('<select id="supplier_id"', $html);
        $this->assertStringContainsString('value="'.$supplier->id.'"', $html);
        $this->assertStringContainsString('PT Kopi Nusantara', $html);
        $this->assertStringNotContainsString('No suppliers yet.', $html);
    }

    public function test_many_suppliers_are_offered_and_inactive_ones_are_not(): void
    {
        $this->supplier(['name' => 'PT A']);
        $this->supplier(['name' => 'PT B']);
        $this->supplier(['name' => 'PT Closed', 'is_active' => false]);

        $html = $this->member()->get('/purchases/create')->assertOk()->getContent();

        $this->assertStringContainsString('PT A', $html);
        $this->assertStringContainsString('PT B', $html);
        $this->assertStringNotContainsString('PT Closed', $html);
    }

    // --------------------------------------------------------- validation UX

    public function test_submitting_an_empty_form_explains_what_is_missing(): void
    {
        $this->supplier();

        $response = $this->member()->from('/purchases/create')->post('/purchases');

        $response->assertRedirect('/purchases/create');
        $response->assertSessionHasErrors(['supplier_id', 'warehouse_id', 'items']);

        $this->assertSame(0, Purchase::withoutGlobalScopes()->count(), 'No purchase may be created.');

        $html = $this->member()->get('/purchases/create')->assertOk()->getContent();

        // The summary the user could not get before.
        $this->assertStringContainsString('Please complete the required fields.', $html);
        $this->assertStringContainsString('role="alert"', $html);

        // No false success anywhere.
        $this->assertStringNotContainsString('Purchase order created', $html);
    }

    public function test_a_failed_submit_keeps_what_the_user_had_entered(): void
    {
        $supplier = $this->supplier();

        $this->member()
            ->from('/purchases/create')
            ->post('/purchases', [
                'supplier_id' => $supplier->id,
                'warehouse_id' => $this->warehouse->id,
                // A real row, but quantity 0 breaks the min:1 rule.
                'items' => [['product_id' => $this->product->id, 'quantity' => 0, 'unit_cost' => 1000]],
            ])
            ->assertSessionHasErrors('items.0.quantity');

        $html = $this->member()->get('/purchases/create')->assertOk()->getContent();

        // The choice the user already made comes back selected, and the template feeds
        // old('items') into the Alpine component so the rejected row is not thrown away.
        $this->assertStringContainsString('value="'.$supplier->id.'" selected', $html);
        $this->assertStringContainsString('Please complete the required fields.', $html);
    }

    public function test_a_supplier_from_another_workspace_is_rejected(): void
    {
        $stranger = $this->account('purchase-stranger@test.dev');
        $other = $this->workspace($stranger, 'Toko Lain');

        $foreignSupplier = Supplier::create([
            'tenant_id' => $other->id, 'name' => 'PT Asing', 'is_active' => true,
        ]);

        $this->member()
            ->from('/purchases/create')
            ->post('/purchases', $this->validPayload(['supplier_id' => $foreignSupplier->id]))
            ->assertSessionHasErrors('supplier_id');

        $this->assertSame(0, Purchase::withoutGlobalScopes()->count());
    }

    // ---------------------------------------------------------- notifications

    public function test_a_successful_purchase_reports_success(): void
    {
        $this->member()
            ->from('/purchases/create')
            ->post('/purchases', $this->validPayload())
            ->assertRedirect();

        $purchase = Purchase::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->tenant->id, $purchase->tenant_id);

        $this->member()->get(route('purchases.show', $purchase))
            ->assertOk()
            ->assertSee(__('Purchase order created. Receive it to increase stock.'));
    }

    public function test_a_refused_action_shows_an_error_notice(): void
    {
        $this->member()->from('/purchases/create')->post('/purchases', $this->validPayload());

        $purchase = Purchase::withoutGlobalScopes()->firstOrFail();
        $showUrl = route('purchases.show', $purchase);

        // A new purchase is created as `ordered`, so receiving it is legal and stock moves.
        $this->member()->from($showUrl)->post(route('purchases.receive', $purchase))->assertRedirect();

        $this->member()->get($showUrl)
            ->assertOk()
            ->assertSee(__('Purchase received. Stock updated.'));

        // Receiving it a second time is refused. That refusal has to be visible rather
        // than a silent no-op, which is what purchases/show used to do with every flash.
        $this->member()->from($showUrl)->post(route('purchases.receive', $purchase))->assertRedirect();

        $html = $this->member()->get($showUrl)->assertOk()->getContent();

        $this->assertStringContainsString(__('Only ordered purchases can be received.'), $html);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringNotContainsString(__('Purchase received. Stock updated.'), $html);
    }

    public function test_guests_cannot_reach_the_purchase_pages(): void
    {
        foreach (['/purchases', '/purchases/create'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    // -------------------------------------------------- the "null" in the item dropdown

    public function test_a_product_without_a_sku_never_renders_the_word_null(): void
    {
        // The label used to be composed in the browser as a template literal:
        //   `${product.name} · ${product.sku}`
        // JavaScript stringifies null inside a template literal, so a product with no
        // SKU showed the literal text "null" right beside its name.
        $withoutSku = Product::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Monstera',
            'sku' => null, 'unit' => 'pcs',
            'track_inventory' => true, 'is_active' => true,
        ]);

        $withSku = Product::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Monstera_large', 'sku' => 'LARGE',
            'unit' => 'pcs', 'track_inventory' => true, 'is_active' => true,
        ]);

        $html = $this->member()->get('/purchases/create')->assertOk()->getContent();

        // Decode exactly what the browser receives, in the SAME two steps the browser does.
        //
        // @js() emits JSON.parse('...'); its inner JSON escapes quotes and non-ASCII, and
        // because that JSON then sits inside a JavaScript single-quoted string, every
        // backslash is itself escaped too. JSON.parse only resolves the bullet on its
        // second pass, so unescaping just once would assert on the literal escape text
        // instead of the character the user actually sees.
        //
        // chr(92) keeps the backslashes unambiguous in source.
        $bs = chr(92);

        $this->assertSame(1, preg_match("/purchaseForm\(JSON\.parse\('(.*)'\)\)/s", $html, $m));

        $jsLiteral = str_replace([$bs.$bs, $bs."'"], [$bs, "'"], $m[1]);
        $json = str_replace([$bs.'u0022', $bs.'u0027'], ['"', "'"], $jsLiteral);
        $config = json_decode($json, true);

        $this->assertIsArray($config, 'The purchase form payload must be valid JSON.');

        $labels = array_column($config['products'], 'label', 'id');

        // No SKU → the name alone, with no separator and no nullish token.
        $this->assertSame('Monstera', $labels[$withoutSku->id]);

        // SKU present → "name • sku", matching the original format.
        $this->assertSame('Monstera_large • LARGE', $labels[$withSku->id]);

        foreach ($labels as $label) {
            foreach (['null', 'undefined', 'N/A'] as $token) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $token,
                    $label,
                    "An item label leaked a {$token} token."
                );
            }
        }

        // The option binds the finished label rather than concatenating in the browser,
        // so the rule holds for the first row and every row added by Add item.
        $this->assertStringContainsString('x-text="product.label"', $html);
        $this->assertStringNotContainsString('product.name', $html);
    }

    public function test_the_item_option_reads_the_precomputed_label(): void
    {
        $html = $this->member()->get('/purchases/create')->assertOk()->getContent();

        // Every option binds the server-composed label instead of concatenating in JS,
        // so the same rule applies to the initial row and to any row added by Add item.
        $this->assertStringContainsString('x-text="product.label"', $html);
    }

    // ------------------------------------------------------ submit loading state

    public function test_the_create_button_arms_a_loading_state(): void
    {
        $html = $this->member()->get('/purchases/create')->assertOk()->getContent();

        // x-on:submit is what flips `submitting`. Without it the flag stayed false and
        // the button never showed progress. The global submit loader deliberately skips
        // Alpine-owned buttons, so nothing else would have covered for it.
        $this->assertStringContainsString('x-on:submit="submitting = true"', $html);
        $this->assertStringContainsString('x-bind:disabled="submitting"', $html);
        $this->assertStringContainsString('x-show="submitting"', $html);

        // "Saving…" is the project's established busy wording.
        $this->assertStringContainsString(__('Saving…'), $html);
        $this->assertStringContainsString('animate-spin', $html);
    }

    public function test_the_busy_state_cannot_get_stuck(): void
    {
        $html = $this->member()->get('/purchases/create')->assertOk()->getContent();

        // Same two releases as sales/create: Back/Forward and a safety timeout for a
        // request that never navigates.
        $this->assertStringContainsString("addEventListener('pageshow'", $html);
        $this->assertStringContainsString("\$watch('submitting'", $html);
        $this->assertStringContainsString('20000', $html);
    }
}
