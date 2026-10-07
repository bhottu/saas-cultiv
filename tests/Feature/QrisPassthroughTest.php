<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The QR the customer scans must be the provider's own, unmodified.
 *
 * Production reported "Merchant not found" when a banking app read the code on
 * /billing/checkout. Cultiv builds no QR: it renders QRIS.PW's resource. These tests
 * prove the value survives the whole path — HTTP response, JSON column, model accessor,
 * Blade render — byte-for-byte, and that nothing is fabricated when the provider does
 * not supply a code at all.
 */
class QrisPassthroughTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Tenant $tenant;

    /** What the fake gateway answers with; each test swaps it between checkouts. */
    private array $providerResponse = ['success' => true];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlanSeeder::class);

        // Registered once per test; a second Http::fake() for the same URL would merge
        // rather than replace, leaving the first stub in charge of every request.
        Http::fake([
            'qris.pw/*' => fn () => Http::response(
                // A fresh transaction id per call: that column is UNIQUE.
                $this->providerResponse + ['success' => true, 'transaction_id' => 'TX-'.Str::random(24)]
            ),
        ]);

        $this->owner = User::create([
            'name' => 'QR Owner', 'email' => 'qr-passthrough@test.dev',
            'password' => bcrypt('password'), 'email_verified_at' => now(),
        ]);

        $this->tenant = Tenant::create([
            'name' => 'QR Tenant', 'slug' => 'qr-tenant', 'owner_id' => $this->owner->id,
        ]);

        $this->tenant->users()->attach($this->owner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);
    }

    /**
     * A URL with a query string is the realistic case: query parameters are exactly
     * what a re-encoding or trimming step would mangle, and a mangled URL yields a
     * QR for a different (or no) transaction.
     */
    public function test_the_qr_url_reaches_the_page_byte_for_byte(): void
    {
        $url = 'https://qr.qris.pw/img/ABC123.png?size=512&v=2&token=a%2Bb%2Fc==';

        $payment = $this->checkoutReturning(['success' => true, 'qris_url' => $url]);

        // Storage kept it whole...
        $this->assertSame($url, Payment::find($payment->id)->qrImageUrl());

        // ...and the page carries it as the browser will request it. Blade escapes "&"
        // to "&amp;" for the HTML attribute, which the browser decodes back — so the
        // round trip must return the provider's URL exactly, query string included.
        $html = $this->member()->get(route('billing.pay', $payment))->assertOk()->getContent();

        $this->assertStringContainsString(e($url), $html);
        $this->assertStringContainsString('src="'.e($url).'"', $html);
        $this->assertSame($url, html_entity_decode(e($url), ENT_QUOTES));
    }

    /** Each payment renders its own code, never another one's. */
    public function test_each_checkout_renders_its_own_qr(): void
    {
        $first = $this->checkoutReturning([
            'qris_url' => 'https://qr.qris.pw/img/A.png',
        ]);

        // Settle the first one, otherwise the second checkout is (correctly) diverted
        // to the pending-payment prompt instead of creating a new payment.
        $first->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $second = $this->checkoutReturning([
            'qris_url' => 'https://qr.qris.pw/img/B.png',
        ]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('https://qr.qris.pw/img/A.png', Payment::find($first->id)->qrImageUrl());
        $this->assertSame('https://qr.qris.pw/img/B.png', $second->fresh()->qrImageUrl());

        $html = $this->member()->get(route('billing.pay', $second))->assertOk()->getContent();

        $this->assertStringContainsString('/img/B.png', $html);
        $this->assertStringNotContainsString('/img/A.png', $html);
    }

    /** A cancelled payment may show its result, but never its old checkout artefacts. */
    public function test_a_cancelled_payment_shows_its_result_without_its_qr(): void
    {
        $payment = $this->checkoutReturning(['qris_url' => 'https://qr.qris.pw/img/C.png']);

        $payment->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $this->member()->get(route('billing.pay', $payment))
            ->assertOk()
            ->assertSee('Payment could not be completed.')
            ->assertDontSee('/img/C.png');
    }

    /**
     * A QRIS payload string is read from the provider's response and never assembled
     * here. This is the field Cultiv previously ignored entirely.
     */
    public function test_a_qris_payload_string_is_read_and_rendered_verbatim(): void
    {
        $qrString = '00020101021226ID.CO.QRIS.WWW0108UMI123456789002ID1234567890123Rp79000.00IDCO.QRIS.WWW6308ID.CO.QRIS.WWW';

        $payment = $this->checkoutReturning(['qris_string' => $qrString]);

        $this->assertSame($qrString, $payment->fresh()->qrisPayloadString());

        // No image URL, so the provider's own payload is shown rather than nothing —
        // and certainly not a code Cultiv made up.
        $this->member()->get(route('billing.pay', $payment))
            ->assertOk()
            ->assertSee($qrString, escape: false)
            ->assertDontSee('QR code unavailable');
    }

    /** Nothing is invented when the provider sends no code at all. */
    public function test_no_qr_is_fabricated_when_the_provider_returns_none(): void
    {
        $payment = $this->checkoutReturning(['success' => true]);

        $this->assertNull($payment->fresh()->qrImageUrl());
        $this->assertNull($payment->fresh()->qrisPayloadString());

        $this->member()->get(route('billing.pay', $payment))
            ->assertOk()
            ->assertSee('QR code unavailable');
    }

    /**
     * The diagnostic that separates a provider problem from an application one.
     *
     * `qris_string_is_emvco` is the decisive field: a genuine EMVCo QRIS payload opens
     * with "0002". If that is true and Cultiv still shows it verbatim, the code the
     * bank rejected came from the provider and the merchant is the thing to check.
     */
    public function test_the_qr_source_diagnostic_is_recorded(): void
    {
        Log::spy();

        $this->checkoutReturning([
            'qris_string' => '00020101021226ID.CO.QRIS.WWW0108UMI1234567890',
            'qris_url' => 'https://qr.qris.pw/img/F.png',
        ]);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context = []) => $message === 'qrispw.qr_source'
                && ($context['has_image_url'] ?? null) === true
                && ($context['has_qris_string'] ?? null) === true
                && ($context['qris_string_is_emvco'] ?? null) === true)
            ->atLeast()->once();
    }

    /** A response with no code at all is called out, with the key names the provider used. */
    public function test_a_missing_qr_is_flagged_with_the_response_keys(): void
    {
        Log::spy();

        $this->checkoutReturning(['success' => true, 'some_new_field' => 'x']);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => $message === 'qrispw.qr_missing'
                && in_array('some_new_field', $context['response_keys'] ?? [], true))
            ->atLeast()->once();
    }

    // ------------------------------------------------------------------ helpers

    private function member()
    {
        return $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id]);
    }

    /**
     * Run a real checkout against a provider response the test chooses.
     *
     * The fake is registered ONCE, in setUp(), reading the response from a property.
     * Registering a second Http::fake() would merge another stub onto the same URL and
     * the first one would keep winning, so every checkout in a test would receive the
     * first response.
     */
    private function checkoutReturning(array $response): Payment
    {
        $this->providerResponse = $response;

        $this->member()->post('/billing/checkout', ['plan' => 'pro', 'cycle' => 'monthly'])
            ->assertRedirect();

        return Payment::where('status', 'pending')->latest('id')->firstOrFail();
    }
}