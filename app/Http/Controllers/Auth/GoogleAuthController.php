<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Sign in with a Google account, via Laravel Socialite.
 *
 * Design rules this controller holds to:
 *
 *  - IDENTITY IS SERVER-SIDE ONLY. Every value used here (sub, email, name,
 *    email_verified) comes from the OAuth token exchange Socialite performed with
 *    Google. Nothing is read from the request, so a user cannot hand-craft a callback
 *    to attach someone else's Google account.
 *
 *  - `sub` IS THE LINK. Google `sub` is the stable OpenID Connect identifier for one
 *    Google account; email is only a matching hint, because an email can change.
 *
 *  - EMAIL VERIFICATION IS NOT DOWNGRADED. Only `email_verified = true` from Google
 *    marks the Cultiv account verified. Anything else keeps the existing verification
 *    flow untouched.
 *
 *  - NOTHING EXISTING IS OVERWRITTEN. Linking never touches a tenant, role,
 *    permission, subscription or profile beyond the two linking fields.
 *
 * The user is then handed to the ordinary session + middleware chain, so /tenants or
 * the dashboard resolves the destination exactly as it does for a password login.
 */
class GoogleAuthController extends Controller
{
    /** Identity only. Cultiv One never needs Gmail, Drive, Calendar or contacts. */
    private const SCOPES = ['openid', 'profile', 'email'];

    public function redirect(): SymfonyRedirect
    {
        return Socialite::driver('google')
            ->scopes(self::SCOPES)
            ->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            // Session-based (not stateless): this keeps OAuth `state` validation on,
            // which is what stops a third party feeding us somebody else's code.
            $googleUser = Socialite::driver('google')->scopes(self::SCOPES)->user();
            $identity = $this->identityFrom($googleUser);
        } catch (Throwable $e) {
            // Covers the user pressing "Cancel" on Google's consent screen, a failed
            // token exchange, an invalid/expired state, and a misconfigured client.
            Log::warning('auth.google.callback_failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->failure();
        }

        try {
            $user = DB::transaction(fn () => $this->resolveUser($identity));
        } catch (Throwable $e) {
            Log::error('auth.google.failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                // The sub is safe to log and is what support will need; never the
                // access token, never the client secret.
                'google_sub' => $identity['sub'] ?? null,
            ]);

            return $this->failure();
        }

        // Regenerate the session id: the account is now authenticated, so the old
        // pre-login session id must not survive (session fixation).
        Auth::login($user, remember: true);

        Log::info('auth.google.signed_in', [
            'user_id' => $user->id,
            'google_sub' => $identity['sub'],
        ]);

        // `intended()` is the existing mechanism, so a user who bounced off a guarded
        // page lands back there. Without a stored destination it goes to the dashboard,
        // and the tenant middleware sends a user with no workspace to /tenants.
        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Pull the trusted fields out of the OAuth response.
     *
     * `getId()` is Google's `sub` — NOT the email. The verification flag is read from
     * the raw userinfo payload and must be a real boolean true; anything missing,
     * false, or a truthy string is treated as NOT verified.
     *
     * @return array{sub: string, email: string, name: string, verified: bool}
     */
    private function identityFrom(SocialiteUser $googleUser): array
    {
        $sub = trim((string) $googleUser->getId());
        $email = trim((string) ($googleUser->getEmail() ?? ''));

        if ($sub === '') {
            throw new \UnexpectedValueException('Google did not return a subject identifier.');
        }

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \UnexpectedValueException('Google did not return a usable email address.');
        }

        $raw = $googleUser->getRaw() ?? [];

        return [
            'sub' => $sub,
            'email' => mb_strtolower($email),
            'name' => trim((string) ($googleUser->getName() ?: $email)) ?: $email,
            // Strictly boolean true. A missing key means we do not know, and not knowing
            // must never mean verified.
            'verified' => ($raw['email_verified'] ?? false) === true,
        ];
    }

    /**
     * Link or create the Cultiv account for this Google identity.
     *
     * Three cases, in priority order:
     *   A. `google_sub` already known -> that user, nothing touched.
     *   B. email already known        -> link `google_sub` onto that account.
     *   C. neither known              -> create a new account.
     *
     * @param  array{sub: string, email: string, name: string, verified: bool}  $identity
     */
    private function resolveUser(array $identity): User
    {
        // Case A — this Google account is already linked. Trust the link, not the email:
        // if a Google account later changes its email, the sub still identifies the
        // right person, and we must not silently re-point the session at whoever now
        // owns that address.
        if ($linked = User::where('google_sub', $identity['sub'])->first()) {
            if (! $linked->hasVerifiedEmail() && $identity['verified']) {
                // Google vouches for this address; no second Cultiv verification email.
                $linked->forceFill(['email_verified_at' => now()])->save();
            }

            return $linked->fresh();
        }

        // Case B/C — match on email, case-insensitively.
        $existing = User::whereRaw('lower(email) = ?', [$identity['email']])->first();

        if ($existing) {
            return $this->link($existing, $identity);
        }

        return $this->create($identity);
    }

    /**
     * Case B: attach the Google identity to the account that already owns the email.
     *
     * Only ever called with an identity that came straight from Google's token
     * exchange, which is what makes email-based linking safe here.
     */
    private function link(User $user, array $identity): User
    {
        if ($user->google_sub !== null && $user->google_sub !== $identity['sub']) {
            // The unique index would reject this anyway; failing loudly beats a
            // confusing database error.
            throw new \UnexpectedValueException('This account is already linked to a different Google account.');
        }

        $user->google_sub = $identity['sub'];

        if ($identity['verified'] && ! $user->hasVerifiedEmail()) {
            $user->email_verified_at = now();
        }

        $user->save();

        Log::info('auth.google.linked', [
            'user_id' => $user->id,
            'google_sub' => $identity['sub'],
        ]);

        return $user->fresh();
    }

    /**
     * Case C: first sign-in for this person.
     *
     * The users table requires a password, so a random one is generated and hashed.
     * It is never shown, emailed, or usable — the account is reached through Google.
     * The User model casts `password` to `hashed`, so it is stored as a hash.
     */
    private function create(array $identity): User
    {
        $user = User::create([
            'name' => $identity['name'],
            'email' => $identity['email'],
            'google_sub' => $identity['sub'],
            // Unusable, randomly generated, stored only as a hash.
            'password' => Hash::make(Str::random(64)),
            // Google already verified this address; skip Cultiv's verification email.
            'email_verified_at' => $identity['verified'] ? now() : null,
        ]);

        // Only fire Registered when Google did NOT verify the address — that event is
        // what sends Cultiv's own verification email. Firing it for an already-verified
        // address would send a redundant second verification, which this flow must avoid.
        if (! $user->hasVerifiedEmail()) {
            event(new Registered($user));
        }

        return $user->fresh();
    }

    /** A safe, actionable message; the technical detail is already in the log. */
    private function failure(): RedirectResponse
    {
        return redirect()
            ->route('login')
            ->withErrors(['google' => __('Unable to sign in with Google. Please try again.')]);
    }
}
