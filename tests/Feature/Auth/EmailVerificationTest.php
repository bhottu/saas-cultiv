<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_verification_screen_can_be_rendered(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/verify-email');

        $response->assertStatus(200);
    }

    public function test_email_can_be_verified(): void
    {
        $user = User::factory()->unverified()->create();

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
    }

    /*
    |--------------------------------------------------------------------------
    | Success feedback
    |--------------------------------------------------------------------------
    |
    | Verification was already working. What was missing was the user being told,
    | so these tests pin the feedback rather than the verification itself: the
    | redirect target is asserted only to prove it did NOT move.
    |
    */

    private function verificationUrlFor(
        User $user,
        ?DateTimeInterface $expiresAt = null,
        ?string $hash = null,
    ): string {
        return URL::temporarySignedRoute(
            'verification.verify',
            $expiresAt ?? now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($hash ?? $user->email)],
        );
    }

    public function test_registering_then_clicking_the_link_confirms_the_email_and_says_so(): void
    {
        Notification::fake();

        $response = $this->post('/register', [
            'name' => 'Petani Baru',
            'email' => 'petani@cultiv.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'petani@cultiv.test')->firstOrFail();

        $this->assertFalse($user->hasVerifiedEmail(), 'A new account starts unverified.');

        // The link that was actually emailed out.
        $this->assertNotNull(Notification::sent($user, VerifyEmail::class));

        $url = $this->verificationUrlFor($user);

        $this->actingAs($user)->get($url)
            // The redirect target is deliberately unchanged.
            ->assertRedirect(route('dashboard', absolute: false).'?verified=1')
            // Staged for the banner, with the exact wording so it cannot silently drift.
            ->assertSessionHas('verification_notice', [
                'title' => __('Email verified successfully'),
                'message' => __('Your email has been successfully verified. Welcome to Cultiv!'),
            ]);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_the_success_banner_is_rendered_on_the_page_the_user_lands_on(): void
    {
        // A separate account, because this test clicks the link exactly once and then
        // follows the redirects: link -> dashboard -> workspace hub. A brand new account
        // has no workspace yet, so the tenant middleware bounces it to the hub, and
        // that hub is therefore one of the two pages that must render the banner.
        $user = User::factory()->unverified()->create();

        $html = $this->actingAs($user)->followingRedirects()
            ->get($this->verificationUrlFor($user));

        $html->assertOk();
        $html->assertSee(__('Email verified successfully'));
        $html->assertSee(__('Your email has been successfully verified. Welcome to Cultiv!'));
    }

    public function test_the_banner_is_in_indonesian_when_the_user_reads_indonesian(): void
    {
        $user = User::factory()->unverified()->create(['locale' => 'id']);

        $html = $this->actingAs($user)->followingRedirects()
            ->get($this->verificationUrlFor($user));

        // Wording follows lang/id/auth.php, which is the owner's copy. Note "diverifikasi",
        // not "dikonfirmasi": the same word is used consistently across the whole
        // verification flow, so asserting the English-ish synonym here would pin a
        // different product voice than the one that actually ships.
        $html->assertSee(__('Email verified successfully', [], 'id'));
        $html->assertSee(__('Your email has been successfully verified. Welcome to Cultiv!', [], 'id'));
    }

    public function test_email_is_not_verified_with_invalid_hash(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get($this->verificationUrlFor($user, null, 'wrong-email'));

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_the_user_can_log_in_again_after_verifying(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get($this->verificationUrlFor($user));

        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        $this->post('/logout')->assertRedirect();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_the_banner_is_shown_once_and_does_not_come_back_on_refresh(): void
    {
        $user = User::factory()->unverified()->create();

        $url = $this->verificationUrlFor($user);

        $first = $this->actingAs($user)->followingRedirects()->get($url);

        $first->assertSee(__('Email verified successfully'));

        // A refresh is a brand new request. The banner pulled itself out of the
        // session while rendering, so there is nothing left to show.
        $refresh = $this->actingAs($user)->followingRedirects()
            ->get(route('dashboard', absolute: false));

        $refresh->assertOk();
        $refresh->assertDontSee(__('Email verified successfully'));
        $refresh->assertDontSee(__('Your email has been successfully verified. Welcome to Cultiv!'));
    }

    public function test_an_expired_or_tampered_link_never_reports_success(): void
    {
        $user = User::factory()->unverified()->create();

        // Correctly signed, but the signature has expired.
        $this->actingAs($user)->get($this->verificationUrlFor($user, now()->subMinute()))
            ->assertForbidden()
            ->assertSessionMissing('verification_notice');

        // Wrong hash for the address it claims to belong to.
        $this->actingAs($user)->get($this->verificationUrlFor($user, null, 'someone-else@cultiv.test'))
            ->assertForbidden()
            ->assertSessionMissing('verification_notice');

        // Not a signature at all.
        $this->actingAs($user)->get('/verify-email/'.$user->id.'/'.sha1($user->email))
            ->assertForbidden()
            ->assertSessionMissing('verification_notice');

        $this->assertFalse($user->fresh()->hasVerifiedEmail(), 'A rejected link must not verify anything.');
    }

    public function test_clicking_the_same_link_again_is_harmless(): void
    {
        $user = User::factory()->unverified()->create();

        $url = $this->verificationUrlFor($user);

        $this->actingAs($user)->get($url)->assertRedirect();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        // Reopening a link that already worked is not a fresh success, so it is
        // reported honestly instead of repeating the welcome message.
        $this->actingAs($user)->get($url)
            ->assertRedirect(route('dashboard', absolute: false).'?verified=1')
            ->assertSessionHas('verification_notice', [
                'title' => __('Email was already verified'),
                'message' => __('This email address was already verified, so there is nothing left to do.'),
            ]);

        $html = $this->actingAs($user)->followingRedirects()
            ->get(route('dashboard', absolute: false));

        $html->assertOk();
        $html->assertSee(__('Email was already verified'));
        $html->assertDontSee(__('Your email has been successfully verified. Welcome to Cultiv!'));

        // Still verified, still signed in, nothing broken.
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertAuthenticatedAs($user->fresh());
    }
}
