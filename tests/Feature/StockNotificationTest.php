<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The outcome of a stock adjustment has to be VISIBLE on the page the user lands back on.
 *
 * The action itself always worked: the movement was written, the balance moved, and
 * back()->with('status', ...) flashed a message. The banner just never showed any text.
 *
 * The cause was in stock/index.blade.php, not the controller. The guard read the flash
 * through the session() helper, but the body printed `$session['status']['message']` —
 * and $session is not a Blade variable, so it resolved to null and the `?? ''` fallback
 * silently produced an empty green box. Every successful adjustment therefore looked
 * like no notification at all, and every failure looked like a silent one too.
 *
 * Each test here follows the redirect and asserts the MESSAGE TEXT is in the HTML, which
 * is what the fix was actually about. Asserting only on the session would pass against
 * the broken template.
 */
class StockNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'Stock Owner',
            'email' => 'stock-owner@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $this->tenant = Tenant::create([
            'name' => 'Stock Workspace',
            'slug' => 'stock-workspace',
            'owner_id' => $this->owner->id,
        ]);

        $this->tenant->users()->attach($this->owner->id, [
            'role' => 'Owner',
            'status' => TenantUser::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);

        $this->warehouse = Warehouse::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Gudang Utama',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kopi Susu',
            'sku' => 'KP-001',
            'unit' => 'pcs',
            'purchase_price' => 1_000_000,
            'selling_price' => 1_500_000,
            'cost_price' => 1_000_000,
            'track_inventory' => true,
            'is_active' => true,
        ]);
    }

    private function member(?Tenant $tenant = null, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->owner)
            ->withSession(['tenant_id' => ($tenant ?? $this->tenant)->id]);
    }

    private function balance(): int
    {
        return (int) StockBalance::withoutGlobalScopes()
            ->where('product_id', $this->product->id)
            ->value('quantity');
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'direction' => 'in',
            'quantity' => 10,
            'reason' => 'Opening stock',
        ], $overrides);
    }

    // ------------------------------------------------------- the regression itself

    public function test_adding_stock_shows_a_success_notification_on_the_stock_page(): void
    {
        $this->member()
            ->from('/stock')
            ->post('/stock/adjust', $this->payload(['direction' => 'in', 'quantity' => 25]))
            ->assertRedirect('/stock');

        $html = $this->member()->get('/stock')->assertOk()->getContent();

        // The text, not merely the session key: an empty banner is what users reported.
        $this->assertStringContainsString('Stock added successfully.', $html);
        $this->assertStringContainsString('role="status"', $html);
    }

    public function test_reducing_stock_shows_a_success_notification(): void
    {
        $this->member()->from('/stock')->post('/stock/adjust', $this->payload(['quantity' => 40]));
        $this->assertSame(40, $this->balance());

        $this->member()
            ->from('/stock')
            ->post('/stock/adjust', $this->payload(['direction' => 'out', 'quantity' => 15]))
            ->assertRedirect('/stock');

        $html = $this->member()->get('/stock')->assertOk()->getContent();

        $this->assertStringContainsString('Stock reduced successfully.', $html);
        $this->assertSame(25, $this->balance());
    }

    public function test_the_two_directions_are_announced_differently(): void
    {
        $this->member()->from('/stock')->post('/stock/adjust', $this->payload());

        $added = $this->member()->get('/stock')->assertOk()->getContent();
        $this->assertStringContainsString('Stock added successfully.', $added);
        $this->assertStringNotContainsString('Stock reduced successfully.', $added);

        $this->member()->from('/stock')->post('/stock/adjust', $this->payload(['direction' => 'out']));

        $reduced = $this->member()->get('/stock')->assertOk()->getContent();
        $this->assertStringContainsString('Stock reduced successfully.', $reduced);
        $this->assertStringNotContainsString('Stock added successfully.', $reduced);
    }

    public function test_the_banner_reports_the_new_balance(): void
    {
        $this->member()->from('/stock')->post('/stock/adjust', $this->payload(['quantity' => 12]));

        $this->assertStringContainsString(
            __('New balance: :balance.', ['balance' => '12 pcs']),
            $this->member()->get('/stock')->assertOk()->getContent()
        );
    }

    // ------------------------------------------------- /stock?product_id={id} flow

    public function test_the_notification_appears_when_arriving_with_a_product_id(): void
    {
        $url = '/stock?product_id='.$this->product->id;

        $this->member()
            ->from($url)
            ->post('/stock/adjust', $this->payload(['direction' => 'in']))
            ->assertRedirect($url);

        $html = $this->member()->get($url)->assertOk()->getContent();

        $this->assertStringContainsString('Stock added successfully.', $html);
        // The shortcut context survives the round trip.
        $this->assertStringContainsString(
            '<option value="'.$this->product->id.'" selected',
            $html
        );
    }

    public function test_reducing_from_the_product_shortcut_keeps_both_the_context_and_the_notice(): void
    {
        $this->member()->from('/stock')->post('/stock/adjust', $this->payload(['quantity' => 30]));

        $url = '/stock?product_id='.$this->product->id;

        $this->member()
            ->from($url)
            ->post('/stock/adjust', $this->payload(['direction' => 'out', 'quantity' => 8]))
            ->assertRedirect($url);

        $html = $this->member()->get($url)->assertOk()->getContent();

        $this->assertStringContainsString('Stock reduced successfully.', $html);
        $this->assertStringContainsString(
            '<option value="'.$this->product->id.'" selected',
            $html
        );
        $this->assertSame(22, $this->balance());
    }

    // ------------------------------------------------------------- failure paths

    public function test_an_insufficient_reduction_reports_an_error_and_writes_no_movement(): void
    {
        $before = StockMovement::withoutGlobalScopes()->count();

        $this->member()
            ->from('/stock')
            ->post('/stock/adjust', $this->payload(['direction' => 'out', 'quantity' => 5]))
            ->assertRedirect('/stock');

        $html = $this->member()->get('/stock')->assertOk()->getContent();

        // No false success, ever.
        $this->assertStringNotContainsString('Stock reduced successfully.', $html);
        $this->assertStringContainsString('Failed to reduce stock.', $html);
        $this->assertStringContainsString('role="alert"', $html);

        // InventoryService raises inside its transaction before the insert, so nothing
        // is left behind — this asserts that rather than trusting the comment.
        $this->assertSame($before, StockMovement::withoutGlobalScopes()->count());
        $this->assertSame(0, $this->balance());
    }

    public function test_a_product_that_does_not_track_inventory_reports_an_error(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Jasa Konsultasi',
            'sku' => 'SVC-001',
            'unit' => 'hour',
            'track_inventory' => false,
            'is_active' => true,
        ]);

        $this->member()
            ->from('/stock')
            ->post('/stock/adjust', $this->payload([
                'product_id' => $product->id,
                'direction' => 'in',
            ]))
            ->assertRedirect('/stock');

        $html = $this->member()->get('/stock')->assertOk()->getContent();

        $this->assertStringNotContainsString('Stock added successfully.', $html);
        $this->assertStringContainsString('Failed to add stock.', $html);
        $this->assertStringContainsString(__('This product does not track inventory.'), $html);
    }

    public function test_validation_failures_do_not_produce_a_success_notification(): void
    {
        $this->member()
            ->from('/stock')
            ->post('/stock/adjust', $this->payload(['quantity' => 0]))
            ->assertSessionHasErrors('quantity');

        $html = $this->member()->get('/stock')->assertOk()->getContent();

        $this->assertStringNotContainsString('Stock added successfully.', $html);
        $this->assertStringNotContainsString('Stock reduced successfully.', $html);
        $this->assertSame(0, $this->balance());
    }

    public function test_a_missing_reason_is_rejected_without_touching_stock(): void
    {
        $payload = $this->payload();
        unset($payload['reason']);

        $this->member()
            ->from('/stock')
            ->post('/stock/adjust', $payload)
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, $this->balance());
    }

    public function test_the_notification_is_shown_once_and_not_sticky(): void
    {
        $this->member()->from('/stock')->post('/stock/adjust', $this->payload());

        $first = $this->member()->get('/stock')->assertOk()->getContent();
        $this->assertStringContainsString('Stock added successfully.', $first);

        // A refresh must not replay the same banner.
        $second = $this->member()->get('/stock')->assertOk()->getContent();
        $this->assertStringNotContainsString('Stock added successfully.', $second);
    }

    // --------------------------------------------------------------- isolation

    public function test_stock_from_another_workspace_cannot_be_adjusted(): void
    {
        $stranger = User::create([
            'name' => 'Stranger',
            'email' => 'stock-stranger@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $other = Tenant::create([
            'name' => 'Other Workspace',
            'slug' => 'other-workspace',
            'owner_id' => $stranger->id,
        ]);

        $other->users()->attach($stranger->id, [
            'role' => 'Owner',
            'status' => TenantUser::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);

        $this->member($other, $stranger)
            ->from('/stock')
            ->post('/stock/adjust', [
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'direction' => 'in',
                'quantity' => 5,
                'reason' => 'Nope',
            ])
            ->assertSessionHasErrors('product_id');

        $this->assertSame(0, $this->balance());
    }

    // ------------------------------------------------------------ localization

    public function test_the_stock_outcomes_are_translated_in_both_locales(): void
    {
        $this->assertSame('Stock added successfully.', __('Stock added successfully.', [], 'en'));
        $this->assertSame('Stock reduced successfully.', __('Stock reduced successfully.', [], 'en'));
        $this->assertSame('Failed to add stock.', __('Failed to add stock.', [], 'en'));
        $this->assertSame('Failed to reduce stock.', __('Failed to reduce stock.', [], 'en'));

        $this->assertSame('Stok berhasil ditambahkan.', __('Stock added successfully.', [], 'id'));
        $this->assertSame('Stok berhasil dikurangi.', __('Stock reduced successfully.', [], 'id'));
        $this->assertSame('Gagal menambahkan stok.', __('Failed to add stock.', [], 'id'));
        $this->assertSame('Gagal mengurangi stok.', __('Failed to reduce stock.', [], 'id'));
    }

    public function test_an_indonesian_reader_sees_the_indonesian_success_notice(): void
    {
        $reader = User::create([
            'name' => 'Pembaca',
            'email' => 'stock-id@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $reader->forceFill(['locale' => 'id'])->save();

        $this->tenant->users()->attach($reader->id, [
            'role' => 'Owner',
            'status' => TenantUser::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);

        $this->member(null, $reader)->from('/stock')->post('/stock/adjust', $this->payload());

        $html = $this->member(null, $reader)->get('/stock')->assertOk()->getContent();

        $this->assertStringContainsString('Stok berhasil ditambahkan.', $html);
        $this->assertStringNotContainsString('Stock added successfully.', $html);
    }

    public function test_the_broken_variable_is_gone_from_the_view(): void
    {
        // `$session` was never a Blade variable. Guard the source so the exact defect
        // cannot come back through a well-meaning "cleanup". Comments are stripped
        // first: this file documents the very mistake it guards against, and a naive
        // substring search would match its own explanation.
        $view = (string) file_get_contents(resource_path('views/stock/index.blade.php'));

        $executable = preg_replace('/\{\{--.*?--\}\}/s', '', $view);
        $executable = preg_replace('#/\*.*?\*/#s', '', (string) $executable);

        $this->assertStringNotContainsString('$session[', (string) $executable);
        $this->assertStringNotContainsString('$session', (string) $executable);
        $this->assertStringContainsString("session('status')", $view);
    }
}