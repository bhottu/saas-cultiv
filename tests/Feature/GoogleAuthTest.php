<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

/**
 * Google sign-in, end to end through the real Socialite provider contract.
 *
 * The identity used here is built the way Google's userinfo response actually looks:
 * `sub` is the stable account id and `email_verified` is a boolean. That distinction
 * is the whole point of the feature, so every test keys on `sub`, never on email as an
 * identifier.
 */
class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    /** Test 1 — the redirect route sends the browser to Google. */
    public function test_redirect_sends_the_browser_to_google(): void
    {
        $this->get('/auth/google/redirect')->assertRedirectContains('accounts.google.com');
    }

    /** Only identity scopes are ever requested — never Gmail, Drive or Calendar. */
    public function test_only_identity_scopes_are_requested(): void
    {
        // Driven through the route so the session exists: Socialite stores the OAuth
        // `state` in it, which is exactly the protection under test elsewhere.
        $target = urldecode($this->get('/auth/google/redirect')->headers->get('Location'));

        $this->assertStringContainsString('accounts.google.com', $target);
        $this->assertStringContainsString('openid', $target);
        $this->assertStringContainsString('email', $target);

        foreach (['gmail', 'drive', 'calendar', 'contacts', 'youtube'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $target, "Scope \"{$forbidden}\" must never be requested.");
        }
    }

    /** Case A — the sub is already linked: sign that user in, create nothing. */
    public function test_an_existing_linked_google_account_signs_in_without_creating_a_user(): void
    {
        $user = $this->user('ada@gmail.com', verified: true);
        $user->update(['google_sub' => 'sub-123']);

        $this->signInWithGoogle(['sub' => 'sub-123', 'email' => 'ada@gmail.com', 'verified' => true])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame(1, User::count());
    }

    /**
     * The sub, not the email, is the identity.
     *
     * If Google later reports a different email for an already-linked account, the
     * link must still win: the original person lands in their own account, not in
     * whatever account happens to own that address now.
     */
    public function test_the_linked_sub_wins_even_when_google_reports_a_different_email(): void
    {
        $user = $this->user('ada@gmail.com', verified: true);
        $user->update(['google_sub' => 'sub-123']);
        $other = $this->user('ada.new@gmail.com', verified: true);

        $this->signInWithGoogle(['sub' => 'sub-123', 'email' => 'ada.new@gmail.com', 'verified' => true]);

        $this->assertSame($user->id, auth()->id());
        $this->assertNotSame($other->id, auth()->id());
        $this->assertSame(2, User::count());
    }

    /** Case B — the email already exists: link it, never duplicate the account. */
    public function test_an_existing_email_account_is_linked_instead_of_duplicated(): void
    {
        $user = $this->user('grace@gmail.com', verified: true);
        $this->assertNull($user->google_sub);

        $this->signInWithGoogle(['sub' => 'sub-777', 'email' => 'grace@gmail.com', 'verified' => true])
            ->assertRedirect(route('dashboard'));

        $this->assertSame(1, User::count(), 'Linking must never create a second account.');
        $this->assertSame('sub-777', $user->fresh()->google_sub);
        $this->assertAuthenticatedAs($user);
    }

    /** Email matching is case-insensitive, so casing cannot fork an account. */
    public function test_email_matching_ignores_case(): void
    {
        $user = $this->user('Grace@Gmail.com', verified: true);

        $this->signInWithGoogle(['sub' => 'sub-888', 'email' => 'grace@gmail.com', 'verified' => true]);

        $this->assertSame(1, User::count());
        $this->assertSame('sub-888', $user->fresh()->google_sub);
    }

    /** Linking must not disturb the workspace, role or billing side of the account. */
    public function test_linking_preserves_the_existing_workspace_and_role(): void
    {
        $user = $this->user('grace@gmail.com', verified: true);
        $tenant = Tenant::create([
            'name' => 'Grace WS', 'slug' => 'grace-ws', 'owner_id' => $user->id, 'status' => 'active',
        ]);
        $tenant->users()->attach($user->id, ['role' => 'Owner', 'status' => 'active', 'joined_at' => now()]);

        $this->signInWithGoogle(['sub' => 'sub-999', 'email' => 'grace@gmail.com', 'verified' => true]);

        $user->refresh();

        $this->assertSame('sub-999', $user->google_sub);
        $this->assertSame(1, $user->tenants()->count(), 'The workspace must survive the link.');
        $this->assertSame('Owner', $user->tenants()->first()->pivot->role);
    }

    /**
     * Test 5 — Google says the address is verified.
     *
     * The Cultiv account is verified immediately and NO second Cultiv verification
     * email is sent. The Registered event is what triggers that email, so it must not
     * fire for an address Google has already vouched for.
     */
    public function test_a_google_verified_email_skips_cultiv_verification_entirely(): void
    {
        Event::fake([Registered::class]);
        Notification::fake();

        $this->signInWithGoogle(['sub' => 'sub-v', 'email' => 'verified@gmail.com', 'verified' => true]);

        $user = User::firstOrFail();

        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->hasVerifiedEmail());

        // The whole point: no redundant verification round-trip.
        Event::assertNotDispatched(Registered::class);
        Notification::assertNothingSent();
    }

    /**
     * Test 6 — Google does NOT vouch for the address.
     *
     * Cultiv must not treat it as verified; the existing flow must run instead. Firing
     * Registered is exactly how this scaffold sends the verification email.
     */
    public function test_an_unverified_google_email_falls_back_to_the_existing_verification_flow(): void
    {
        Event::fake([Registered::class]);

        $this->signInWithGoogle(['sub' => 'sub-u', 'email' => 'unverified@gmail.com', 'verified' => false]);

        $user = User::firstOrFail();

        $this->assertNull($user->email_verified_at, 'An unverified Google email must not be trusted.');
        $this->assertFalse($user->hasVerifiedEmail());
        Event::assertDispatched(Registered::class);
    }

    /** A missing email_verified key is "unknown", and unknown is never verified. */
    public function test_a_missing_verification_flag_is_treated_as_unverified(): void
    {
        $this->signInWithGoogle(['sub' => 'sub-x', 'email' => 'noflag@gmail.com', 'verified' => null]);

        $this->assertNull(User::firstOrFail()->email_verified_at);
    }

    /** Google vouching for the address can complete verification of an older account. */
    public function test_linking_marks_a_previously_unverified_account_verified(): void
    {
        $user = $this->user('old@gmail.com', verified: false);
        $this->assertFalse($user->hasVerifiedEmail());

        $this->signInWithGoogle(['sub' => 'sub-link', 'email' => 'old@gmail.com', 'verified' => true]);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    /** Test 7 — repeating the same callback must never produce a second user. */
    public function test_repeating_the_callback_never_creates_a_duplicate(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->post('/logout');

            $this->signInWithGoogle(['sub' => 'sub-same', 'email' => 'repeat@gmail.com', 'verified' => true]);
        }

        $this->assertSame(1, User::count());
        $this->assertSame('sub-same', User::firstOrFail()->google_sub);
    }

    /** Test 8 — one Google account cannot end up on two Cultiv users. */
    public function test_a_google_sub_cannot_be_owned_by_two_users(): void
    {
        User::create([
            'name' => 'First', 'email' => 'first@gmail.com',
            'password' => Hash::make('secret1234'), 'google_sub' => 'sub-dup',
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        User::create([
            'name' => 'Second', 'email' => 'second@gmail.com',
            'password' => Hash::make('secret1234'), 'google_sub' => 'sub-dup',
        ]);
    }

    /** Existing manual accounts are untouched: google_sub stays null, login still works. */
    public function test_manual_registration_is_untouched(): void
    {
        $this->post('/register', [
            'name' => 'Manual', 'email' => 'manual@example.com',
            'password' => 'password-123', 'password_confirmation' => 'password-123',
        ])->assertRedirect(route('dashboard'));

        $user = User::firstOrFail();
        $this->assertNull($user->google_sub);
        $this->assertNotNull($user->password);

        // And password login still works for them.
        $this->post('/logout');
        $this->post('/login', ['email' => 'manual@example.com', 'password' => 'password-123'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    /** A Google user gets a random, unusable password — never Google's, never plaintext. */
    public function test_a_google_user_gets_an_unusable_random_password(): void
    {
        $this->signInWithGoogle(['sub' => 'sub-pw', 'email' => 'pw@gmail.com', 'verified' => true]);

        $user = User::firstOrFail();

        $this->assertNotNull($user->password);
        $this->assertTrue(Hash::isHashed($user->password), 'The stored password must be a hash.');
        // The column is hidden from serialization, so it can never leak into a response.
        $this->assertArrayNotHasKey('password', $user->toArray());
    }

    /** Test 9 — tenant context is untouched; no workspace means the existing onboarding. */
    public function test_a_google_user_without_a_workspace_is_sent_to_the_existing_onboarding(): void
    {
        $this->signInWithGoogle(['sub' => 'sub-t', 'email' => 'nows@gmail.com', 'verified' => true]);

        $this->assertSame(0, Tenant::count(), 'Google sign-in must not invent a workspace.');

        // The existing tenant middleware decides the destination.
        $this->get('/dashboard')->assertRedirect(route('tenants.index'));
    }

    public function test_a_google_user_with_a_workspace_reaches_the_dashboard(): void
    {
        $user = $this->user('ws@gmail.com', verified: true);
        $tenant = Tenant::create([
            'name' => 'WS', 'slug' => 'ws-goog', 'owner_id' => $user->id, 'status' => 'active',
        ]);
        $tenant->users()->attach($user->id, ['role' => 'Owner', 'status' => 'active', 'joined_at' => now()]);

        $this->signInWithGoogle(['sub' => 'sub-ws', 'email' => 'ws@gmail.com', 'verified' => true]);

        $this->actingAs($user->fresh())
            ->withSession(['tenant_id' => $tenant->id])
            ->get('/dashboard')
            ->assertOk();
    }

    /** Cancelling on Google's consent screen is a normal outcome, not a 500. */
    public function test_a_cancelled_or_failed_oauth_returns_a_safe_message(): void
    {
        Socialite::shouldReceive('driver->scopes->user')
            ->andThrow(new \RuntimeException('User rejected the request.'));

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('google');
        $this->assertGuest();

        // Internals stay out of the message.
        $this->assertStringNotContainsString('User rejected', session('errors')->first('google'));
    }

    /** Google returning no email must not produce a half-created account. */
    public function test_a_google_response_without_an_email_is_refused(): void
    {
        Socialite::shouldReceive('driver->scopes->user')
            ->andReturn($this->socialiteUser(['sub' => 'sub-noemail', 'email' => '', 'verified' => true]));

        $this->get('/auth/google/callback')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('google');

        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    /** Both guest screens offer Google, with the double-click guard wired in. */
    public function test_login_and_register_pages_offer_google_with_a_loading_guard(): void
    {
        foreach (['/login', '/register'] as $screen) {
            $html = $this->get($screen)->assertOk()->getContent();

            $this->assertStringContainsString(route('auth.google.redirect'), $html);
            $this->assertStringContainsString('Continue with Google', $html);
            // One click starts one OAuth request; the second is ignored.
            $this->assertStringContainsString('if (loading) { $event.preventDefault(); return; } loading = true;', $html);
        }
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Drive the callback with a faked Socialite identity.
     *
     * The PROVIDER is stubbed, never the request: no test input can decide which
     * identity is used, which is the security property this whole flow depends on.
     */
    private function signInWithGoogle(array $identity)
    {
        Socialite::shouldReceive('driver->scopes->user')
            ->andReturn($this->socialiteUser($identity));

        return $this->get('/auth/google/callback');
    }

    private function socialiteUser(array $identity): SocialiteUser
    {
        $user = new SocialiteUser;
        $user->id = $identity['sub'];
        $user->email = $identity['email'] ?: null;
        $user->name = $identity['name'] ?? 'Google User';
        $user->user = [
            'sub' => $identity['sub'],
            'email' => $identity['email'],
            'email_verified' => $identity['verified'],
        ];

        return $user;
    }

    private function user(string $email, bool $verified): User
    {
        return User::create([
            'name' => 'Existing',
            'email' => $email,
            'password' => Hash::make('password-123'),
            'email_verified_at' => $verified ? now() : null,
        ]);
    }
}
