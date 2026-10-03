<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Builds the metadata for one page.
 *
 * Every page answers the same questions — what is it called, what is it about, which
 * image represents it, and is it allowed in an index — so they are answered once, here,
 * instead of being re-derived in each Blade template. A page states only what is
 * DIFFERENT about itself; everything else comes from config/seo.php, which is why two
 * pages cannot end up with the same title or description by omission.
 */
class Seo
{
    /**
     * @param  array<string, mixed>  $overrides  Page-declared values, e.g.
     *                                          ['title' => 'Pricing', 'image' => 'images/og/cultiv-pricing.jpg'].
     * @return array<string, mixed>
     */
    public function build(Request $request, array $overrides = []): array
    {
        $siteName = (string) config('seo.site_name');
        $home = $overrides['home'] ?? false;

        // The homepage already spells out the brand in its own title; appending the
        // site name to it would read "Cultiv — ... — Cultiv".
        $title = $overrides['title'] ?? $this->titleForRoute();
        $title = $home
            ? (string) config('seo.default_title')
            : $this->assemble($title, $siteName);

        $description = $overrides['description'] ?? config('seo.default_description');
        $canonical = $this->canonical($request, $overrides['canonical'] ?? null);

        return [
            'title' => $title,
            'description' => (string) $description,
            'image' => $this->absolute($overrides['image'] ?? config('seo.default_image')),
            'canonical' => $canonical,
            'url' => $canonical,
            'type' => $overrides['type'] ?? 'website',
            'site_name' => $siteName,
            'locale' => (string) config('seo.locale'),
            'robots' => $this->robots($overrides['robots'] ?? null),
            'twitter_handle' => config('seo.twitter_handle'),
            'json_ld' => $overrides['json_ld'] ?? ($home ? $this->jsonLd($canonical) : []),
        ];
    }

    /**
     * The page's own name, from config('seo.route_titles').
     *
     * Returns null when the route has no entry, which lets assemble() fall through to
     * the site default.
     */
    private function titleForRoute(): ?string
    {
        $name = Route::currentRouteName();

        if ($name === null) {
            return null;
        }

        $titles = (array) config('seo.route_titles', []);

        return array_key_exists($name, $titles) ? $titles[$name] : null;
    }

    /**
     * "[Page Title] — Cultiv", or the site default when the page declares none.
     *
     * A page that already names the brand is left alone rather than being given a
     * second, identical copy of it.
     */
    private function assemble(?string $title, string $siteName): string
    {
        if ($title === null || trim($title) === '') {
            return (string) config('seo.default_title');
        }

        $title = trim($title);

        if ($title === $siteName || str_ends_with($title, $siteName)) {
            return $title;
        }

        return $title.config('seo.title_separator').$siteName;
    }

    /**
     * Absolute, HTTPS, and free of query strings.
     *
     * Built from the configured origin rather than the incoming host, and from the
     * path alone: /products?page=2 and /products are the same page to a crawler, so
     * the paginated variants must all point back at the one canonical.
     */
    private function canonical(Request $request, ?string $override): string
    {
        $base = (string) config('seo.url');

        if ($override) {
            return str_starts_with($override, 'http')
                ? $override
                : $base.'/'.ltrim($override, '/');
        }

        $path = '/'.ltrim($request->path(), '/');

        return $base.($path === '/' ? '/' : rtrim($path, '/'));
    }

    private function absolute(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim((string) config('seo.url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * Index only for the routes config/seo.php has explicitly listed.
     *
     * Denying by omission matters here. A signed-in page, a tenant-scoped page or an
     * admin screen is protected because nobody listed it — not because somebody
     * remembered to block it. robots.txt is a crawler courtesy, not a control, so the
     * meta tag is the one doing the real work of keeping business data out of results.
     */
    private function robots(?string $override): string
    {
        if ($override !== null) {
            return $override;
        }

        $name = Route::currentRouteName();

        return $name !== null && in_array($name, (array) config('seo.indexable_routes', []), true)
            ? 'index, follow'
            : 'noindex, nofollow';
    }

    /**
     * Organization + WebSite + WebPage + SoftwareApplication for the homepage.
     *
     * The organization-shaped nodes are emitted once with stable @id values and
     * cross-referenced, which is how schema.org expects them linked — repeating the
     * name inline in four places is what makes structured data read as four unrelated
     * things instead of one company and its site.
     *
     * Only facts this project can actually back up are included: no ratings, no review
     * counts, no invented user numbers and no pricing, because none of those are
     * verifiable here and a fabricated figure is worse than an absent one.
     *
     * @return array<int, array<string, mixed>>
     */
    private function jsonLd(string $canonical): array
    {
        $orgId = $canonical.'#organization';
        $siteId = $canonical.'#website';
        $home = rtrim((string) config('seo.url'), '/').'/';

        return [
            [
                '@context' => 'https://schema.org',
                '@type' => 'Organization',
                '@id' => $orgId,
                'name' => config('seo.organization.name'),
                'url' => config('seo.organization.url'),
                'logo' => $this->absolute((string) config('seo.organization.logo')),
                'description' => config('seo.organization.description'),
            ],
            [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                '@id' => $siteId,
                'url' => $home,
                'name' => config('seo.site_name'),
                'inLanguage' => config('seo.locale'),
                'publisher' => ['@id' => $orgId],
            ],
            [
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                '@id' => $canonical.'#webpage',
                'url' => $canonical,
                'name' => config('seo.default_title'),
                'description' => config('seo.default_description'),
                'isPartOf' => ['@id' => $siteId],
                'about' => ['@id' => $orgId],
                'inLanguage' => config('seo.locale'),
            ],
            [
                '@context' => 'https://schema.org',
                '@type' => 'SoftwareApplication',
                '@id' => $canonical.'#software',
                'name' => config('seo.site_name'),
                'url' => $home,
                'applicationCategory' => config('seo.software_application.application_category'),
                'operatingSystem' => config('seo.software_application.operating_system'),
                'description' => config('seo.default_description'),
                'inLanguage' => config('seo.locale'),
                'publisher' => ['@id' => $orgId],
            ],
        ];
    }
}
