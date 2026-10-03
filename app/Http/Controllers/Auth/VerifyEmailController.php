<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     *
     * Verification itself was already working; the only thing missing was that the
     * user was told. The redirect below kept `?verified=1` on the URL, but nothing
     * ever read that query string, so a correct verification produced exactly the
     * same blank screen as a no-op. The redirect target is deliberately unchanged —
     * the feedback travels as a session flash, which is how every other action in
     * this application reports success, and which disappears on the next request so
     * a refresh can never show the banner twice.
     *
     * Flashes happen only where something actually happened. An invalid or expired
     * link never reaches this method: EmailVerificationRequest::authorize() rejects
     * the bad signature first, so the existing 403 stands and no success banner can
     * be produced by a link that did not verify anything.
     *
     * The notice is put in the session rather than flashed. A flash lasts exactly one
     * request, and the newest account — the one that has just registered and is
     * verifying — has no workspace yet, so following the redirect means TWO hops:
     * the link lands on /dashboard, the tenant middleware bounces that to the
     * workspace hub, and only then is a page actually rendered. A flash is consumed
     * by that first hop and silently dropped, so precisely the users who most need
     * the confirmation would never see it. The value is pulled from the session by
     * the banner when it renders, which is what keeps it to a single appearance: a
     * refresh finds nothing left.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        $user = $request->user();

        // The first two branches are the verification logic exactly as it was before;
        // the notice is derived from which one ran, not the other way round, so nothing
        // about verifying an address depends on wanting to tell the user about it.
        if ($user->hasVerifiedEmail()) {
            // Reopening a link that already worked. Reporting this as a fresh success
            // would be a lie about what just happened, so it gets its own wording.
            $notice = [
                'title' => __('Email was already verified'),
                'message' => __('This email address was already verified, so there is nothing left to do.'),
            ];
        } elseif ($user->markEmailAsVerified()) {
            event(new Verified($user));

            $notice = [
                'title' => __('Email verified successfully'),
                'message' => __('Your email has been successfully verified. Welcome to Cultiv!'),
            ];
        } else {
            // markEmailAsVerified() only declines when the address was already verified,
            // which the first branch handles. Reaching here means something unexpected
            // happened, so it is reported as a failure rather than dressed up as success.
            $notice = [
                'type' => 'error',
                'title' => __('We could not confirm your email address'),
                'message' => __('We could not confirm your email address. Please request a new verification link.'),
            ];
        }

        $request->session()->put('verification_notice', $notice);

        return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
    }
}
