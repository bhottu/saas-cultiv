<?php

namespace App\Support;

use App\Models\SeoSetting;
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
        // Administrator overrides from /admin/seo, falling back to config/seo.php for
        // anything left blank. config/seo.php therefore stays the default and is never
        // overwritten, so a fresh install with no row renders exactly as it did before.
        $settings = $this->settings();

        $siteName = (string) $this->setting($settings, 'site_name', config('seo.site_name'));
        $home = $overrides['home'] ?? false;

        // The homepage already spells out the brand in its own title; appending the
        // site name to it would read "Cultiv — ... — Cultiv".
        $title = $overrides['title'] ?? $this->titleForRoute();
        $title = $home
            ? (string) $this->setting($settings, 'default_title', config('seo.default_title'))
            : $this->assemble($title, $siteName);

        $description = $this->setting($settings, 'default_description', config('seo.default_description'));
        $canonical = $this->canonical($request, $overrides['canonical'] ?? null);

        $ogImage = SeoSetting::assetUrl($this->setting($settings, 'og_image_path'));
        $twitterImage = SeoSetting::assetUrl($this->setting($settings, 'twitter_image_path'));
        $defaultImage = (string) config('seo.default_image');

        return [
            'title' => $title,
            'description' => (string) $description,
            // An explicit page image still wins, then the administrator's social image,
            // then the config default.
            'image' => $this->absolute($overrides['image'] ?? $ogImage ?? $defaultImage),
            'canonical' => $canonical,
            'url' => $canonical,
            'type' => $overrides['type'] ?? $this->setting($settings, 'og_type', 'website'),
            'site_name' => $siteName,
            'og_title' => $this->setting($settings, 'og_title') ?? $title,
            'og_description' => $this->setting($settings, 'og_description') ?? (string) $description,
            'og_site_name' => $this->setting($settings, 'og_site_name', $siteName),
            'locale' => (string) config('seo.locale'),
            'robots' => $this->robots($overrides['robots'] ?? null),
            'twitter_handle' => $this->setting($settings, 'twitter_handle', config('seo.twitter_handle')),
            'twitter_card' => $this->setting($settings, 'twitter_card_type', 'summary_large_image'),
            'twitter_title' => $this->setting($settings, 'twitter_title') ?? $ogTitle ?? $title,
            'twitter_description' => $this->setting($settings, 'twitter_description') ?? $ogDescription ?? (string) $description,
            // Falls back to the administrator's OG image before the config default, so a
            // site that configured only one social image gets a card on both platforms.
            'twitter_image' => $this->absolute($overrides['image'] ?? $twitterImage ?? $ogImage ?? $defaultImage),
            'json_ld' => $overrides['json_ld'] ?? ($home ? $this->jsonLd($canonical) : []),
        ];
    }

    /**
     * The override row, or null when the table has never been written to.
     *
     * Read through the model rather than by creating a row, so simply VIEWING a page
     * never creates one.
     */
    private function settings(): ?SeoSetting
    {
        try {
            return SeoSetting::query()->first();
        } catch (\Throwable) {
            // No table yet (mid-deploy, or a config cache warmed before the migration ran).
            // Metadata still renders from config instead of fataling on every page.
            return null;
        }
    }

    private function setting(?SeoSetting $settings, string $key, mixed $default = null): mixed
    {
        if ($settings === null) {
            return $default;
        }

        $value = $settings->value($key);

        return $value === null ? $default : $value;
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
        // An administrator-configured canonical host becomes the BASE for every page, so
        // staging or a moved domain is corrected in one place instead of per page. It
        // still never comes from the incoming Host header: a visitor cannot dictate what
        // the site claims its own address to be.
        $base = rtrim((string) ($this->setting($this->settings(), 'canonical_url') ?? config('seo.url')), '/');

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

        // The master switch in /admin/seo can only ever REMOVE indexing. It is checked
        // before the allow list, so a locked-down site cannot leak through a route name
        // that somebody later adds to indexable_routes.
        //
        // SeoSetting owns the default so there is one definition of it, and that default
        // is TRUE: with no settings row the site behaves exactly as it did before this
        // feature existed. Defaulting to false would quietly de-index every production
        // site the moment the migration was applied.
        if (! SeoSetting::allowsIndexing()) {
            return 'noindex, nofollow';
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
        $settings = $this->settings();
        $siteName = (string) $this->setting($settings, 'site_name', config('seo.site_name'));
        $title = (string) $this->setting($settings, 'default_title', config('seo.default_title'));
        $description = (string) $this->setting($settings, 'default_description', config('seo.default_description'));

        $orgId = $canonical.'#organization';
        $siteId = $canonical.'#website';
        $home = rtrim($canonical, '/').'/';

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
                'name' => $siteName,
                'inLanguage' => config('seo.locale'),
                'publisher' => ['@id' => $orgId],
            ],
            [
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                '@id' => $canonical.'#webpage',
                'url' => $canonical,
                'name' => $title,
                'description' => $description,
                'isPartOf' => ['@id' => $siteId],
                'about' => ['@id' => $orgId],
                'inLanguage' => config('seo.locale'),
            ],
            [
                '@context' => 'https://schema.org',
                '@type' => 'SoftwareApplication',
                '@id' => $canonical.'#software',
                'name' => $siteName,
                'url' => $home,
                'applicationCategory' => config('seo.software_application.application_category'),
                'operatingSystem' => config('seo.software_application.operating_system'),
                'description' => $description,
                'inLanguage' => config('seo.locale'),
                'publisher' => ['@id' => $orgId],
            ],
        ];
    }
}
