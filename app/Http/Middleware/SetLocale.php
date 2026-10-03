<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Apply the signed-in account's language preference for the whole request.
 *
 * Precedence is deliberately narrow:
 *
 *   1. the user's own stored preference (users.locale)
 *   2. the application default (config/locale.php default, Indonesian)
 *
 * There is NO Accept-Language negotiation here. A brand new account, or an existing
 * one that never chose, gets Indonesian regardless of what the browser or OS asks
 * for — an English-locale browser must not be able to silently flip the product's
 * default language, because that would make the UI language depend on machine setup
 * rather than on a choice the user actually made.
 *
 * The stored value is still checked against the supported list before it is applied,
 * so a hand-edited or stale row degrades to the default instead of rendering a locale
 * that has no translation files.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale;

        if (! is_string($locale) || ! array_key_exists($locale, config('locale.supported'))) {
            $locale = config('locale.default');
        }

        App::setLocale($locale);

        // Share it with the layout so the switcher can mark the active option and so
        // <html lang=""> reflects the language actually rendered, not the app default.
        view()->share('appLocale', $locale);

        return $next($request);
    }
}