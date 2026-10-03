<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

/**
 * Sitemap for the pages a search engine is meant to see.
 *
 * Cultiv is a SaaS application behind a login, so "every indexable page" turns out to
 * be a very short list: of 220 named routes, exactly one is a page a stranger can reach.
 * Everything else sits behind Authenticate, EnsureEmailIsVerified, EnsureTenantContext
 * or EnsurePlatformAdmin, and none of that belongs in a sitemap — a URL listed here is
 * an invitation to a search engine to store somebody's workspace data in an index.
 *
 * The list is therefore built from config('seo.sitemap.routes') and then CHECKED against
 * the live route table before anything is emitted. That second step is what makes this
 * safe to extend: adding a private route name to the config by mistake does not leak it,
 * because this controller reads that route's middleware and refuses anything that is not
 * genuinely public. The config is a wish; the route table is the authority.
 *
 * Deliberately absent: <changefreq> and <priority>. Google has publicly ignored both for
 * years, and inventing values that do not reflect a real publishing cadence would only
 * make the file look busier than it is. <lastmod> is emitted only where a real
 * last-modified value exists; a made-up timestamp for a static page is worse than none.
 */
class SitemapController extends Controller
{
    /**
     * Middleware ALIASES that mean "this route is not for a search engine".
     *
     * `guest` is here for a different reason than the rest: it marks the login and
     * registration screens, which are reachable by anyone but have no business in an
     * index.
     */
    private const EXCLUDED_ALIASES = [
        'auth',
        'guest',
        'verified',
        'tenant',
        'platform.admin',
    ];

    /** The same guard written as class names, for routes that reference it directly. */
    private const EXCLUDED_CLASSES = [
        'Illuminate\Auth\Middleware\Authenticate',
        'Illuminate\Auth\Middleware\EnsureEmailIsVerified',
        'App\Http\Middleware\EnsureTenantContext',
        'App\Http\Middleware\EnsurePlatformAdmin',
        'App\Http\Middleware\EnsureModuleActive',
        'App\Http\Middleware\RequirePlanFeature',
        'Illuminate\Auth\Middleware\RedirectIfAuthenticated',
    ];

    public function __invoke(): Response
    {
        $urls = [];

        foreach ((array) config('seo.sitemap.routes', []) as $name) {
            $url = $this->publicUrlFor((string) $name);

            if ($url !== null) {
                $urls[$url] = true; // keyed, so a repeated route cannot appear twice
            }
        }

        ksort($urls);

        return response($this->toXml(array_keys($urls)), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    /**
     * The absolute URL for a route name, or null when it must not be advertised.
     */
    private function publicUrlFor(string $name): ?string
    {
        $route = Route::getRoutes()->getByName($name);

        if ($route === null || ! in_array('GET', $route->methods(), true)) {
            return null;
        }

        foreach ($this->resolveMiddleware($route) as $middleware) {
            if (in_array($middleware, self::EXCLUDED_ALIASES, true)) {
                return null;
            }

            foreach (self::EXCLUDED_CLASSES as $class) {
                if ($middleware === $class || str_starts_with($middleware, $class)) {
                    return null;
                }
            }
        }

        $base = rtrim((string) config('seo.url'), '/');
        $path = '/'.ltrim($route->uri(), '/');

        return $base.($path === '/' ? '/' : rtrim($path, '/'));
    }

    /**
     * A route's own middleware, with each name resolved to its class.
     *
     * Two things this deliberately does NOT do:
     *
     * 1. It does not expand middleware GROUPS. The `web` group in this application
     *    contains App\Http\Middleware\EnsureTenantContext, so the homepage runs it too
     *    — it simply passes when there is no tenant session. Expanding `web` therefore
     *    marks `/` private and empties the sitemap, which is wrong: the group describes
     *    what every route needs, not what makes a route private.
     *
     * 2. It does not compare against class names alone. gatherMiddleware() returns the
     *    short alias the route file wrote — "auth", not the fully qualified class — so
     *    class-only matching silently matched nothing and every private route shipped
     *    in the sitemap. Both spellings are checked, with ":parameter" suffixes
     *    stripped so Authenticate:sanctum still counts.
     *
     * @return array<int, string>
     */
    private function resolveMiddleware(\Illuminate\Routing\Route $route): array
    {
        $aliases = app('router')->getMiddleware();
        $resolved = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            $name = explode(':', (string) $middleware, 2)[0];

            $resolved[] = $name;
            $resolved[] = $aliases[$name] ?? $name;
        }

        return array_values(array_unique(array_filter($resolved)));
    }

    /**
     * Serialised by hand rather than through a template so the output is exactly the
     * sitemap schema and nothing else — no stray whitespace, no HTML wrapper.
     *
     * @param  array<int, string>  $urls
     */
    private function toXml(array $urls): string
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

        foreach ($urls as $url) {
            // & and < cannot appear raw inside an element body.
            $loc = htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8');

            $xml .= "    <url>\n";
            $xml .= "        <loc>{$loc}</loc>\n";
            $xml .= "    </url>\n";
        }

        return $xml."</urlset>\n";
    }
}
