<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /settings — the account and workspace preferences hub.
 *
 * Deliberately split from /profile by ownership:
 *
 *   /profile  personal account facts  — name, email, password, account deletion
 *   /settings preferences              — language, and per-workspace branding
 *
 * The split is what makes a white-label product possible: language is a property of
 * the PERSON (users.locale), branding of the WORKSPACE (tenants.brand_*). Two people
 * sharing one workspace can read different languages while presenting the same brand,
 * and one person moving between workspaces gets each workspace's brand.
 *
 * `/settings` is registered under `auth` but NOT under the `tenant` middleware group.
 * An account with no workspace yet must still be able to choose a language, and the
 * branding tab already renders its own "pick a workspace first" state — exactly the
 * way the existing brand-identity form did inside /profile.
 */
class SettingsController extends Controller
{
    /**
     * The tabs, in render order. A single route with a query parameter keeps every
     * section bookmarkable and shareable, and avoids four near-identical controllers
     * for four near-identical forms.
     */
    private const TABS = ['general', 'language', 'branding', 'security'];

    public function index(Request $request): View
    {
        $ctx = app('tenant.context');
        $tenant = $ctx->tenant();

        // A hand-typed ?tab= falls back to General rather than 404 or rendering empty.
        $tab = $request->query('tab');
        if (! is_string($tab) || ! in_array($tab, self::TABS, true)) {
            $tab = 'general';
        }

        return view('settings.index', [
            'user' => $request->user(),
            'tab' => $tab,
            'tabs' => self::TABS,
            'languages' => config('locale.supported'),
            // Null when the account has no workspace: the branding tab explains that
            // itself rather than the page refusing to render.
            'brandTenant' => $tenant,
            // Same existing registry entry the old /profile branding form used, so
            // Owner and Admin keep exactly the access they had before this moved.
            'canManageBranding' => $tenant !== null && $ctx->userCan('manage_settings'),
        ]);
    }

    /**
     * Persist the language choice for the signed-in account only.
     *
     * Writing to `users.locale` (never session) is what makes the choice survive a
     * logout and a new device. The locale is validated against the supported list so
     * a crafted request cannot park the account on a locale with no lang/ files, and
     * the value is applied to the current request before redirecting so the "saved"
     * confirmation page is already rendered in the new language.
     */
    public function updateLanguage(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', 'in:'.implode(',', array_keys(config('locale.supported')))],
        ], [], ['locale' => __('Language')]);

        $request->user()->forceFill(['locale' => $validated['locale']])->save();

        // Apply immediately, otherwise the redirect lands with the old locale still
        // active and the change looks like it did not take.
        app()->setLocale($validated['locale']);

        return redirect()
            ->route('settings.index', ['tab' => 'language'])
            ->with('success', __('Language updated.'));
    }
}