<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The single row of platform-wide SEO overrides (seo_settings).
 *
 * Everything here is OPTIONAL. A null column means "the administrator has not set this",
 * and the rendered metadata falls back to config/seo.php — which stays the default and
 * is never overwritten. That is what lets the platform boot with no row at all and still
 * produce complete, valid metadata.
 *
 * Assets are stored as paths relative to the configured uploads disk, the same
 * convention tenants.brand_logo_path uses, so the public URL is built in one place.
 */
class SeoSetting extends Model
{
    protected $guarded = [];

    protected $casts = [
        'allow_indexing' => 'boolean',
    ];

    /** Columns an administrator may set. The id/timestamps are never part of this. */
    public const EDITABLE = [
        'site_name', 'default_title', 'default_description', 'canonical_url', 'theme_color', 'allow_indexing',
        'favicon_path', 'og_image_path',
        'og_title', 'og_description', 'og_type', 'og_site_name',
        'twitter_card_type', 'twitter_title', 'twitter_description', 'twitter_image_path',
        'twitter_handle',
    ];

    /**
     * The one settings row, created lazily.
     *
     * A singleton means there is nothing to look up and nothing to select by id, so no
     * read path can accidentally read a second row.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], ['allow_indexing' => true]);
    }

    /**
     * The site-wide master indexing switch.
     *
     * Read through config() so there is ONE definition of it, defaulting to TRUE to match
     * both the column default and the behaviour the site had before this table existed.
     * An absent row must never silently de-index a live site.
     */
    public const DEFAULT_ALLOW_INDEXING = true;

    /**
     * Read a stored setting WITHOUT writing one.
     *
     * Layouts run on every anonymous page view, and rendering the <head> must never be
     * the thing that creates a database row — nor fail when the migration has not been
     * run yet. Returns the config default when nothing is stored, so callers can treat
     * the result as final and never need to know whether an override exists.
     */
    public static function config(string $key): mixed
    {
        try {
            $settings = static::query()->first();
        } catch (\Throwable) {
            return config("seo.{$key}");
        }

        $value = $settings?->value($key);

        return $value ?? config("seo.{$key}");
    }

    /** Whether the site is currently allowed to be indexed at all. */
    public static function allowsIndexing(): bool
    {
        try {
            $settings = static::query()->first();
        } catch (\Throwable) {
            return self::DEFAULT_ALLOW_INDEXING;
        }

        // A present row that has never been touched carries the column default, which
        // the boolean cast turns into a real value; only a NULL means "not configured".
        return (bool) ($settings?->allow_indexing ?? self::DEFAULT_ALLOW_INDEXING);
    }

    /** Public URL of a stored asset path, or null when nothing is configured. */
    public static function configuredAsset(string $key): ?string
    {
        return self::assetUrl(self::config($key));
    }

    /**
     * Public URL of a stored asset path, or null when not configured.
     *
     * Deliberately routed rather than Storage::url(). The uploads disk defaults to
     * `local` (storage/app/private), so Storage::url() would hand back a /storage/... URL
     * that resolves to nothing — a favicon and an og:image that 404 are worse than no
     * custom asset at all. The public `seo.asset` route streams the file instead, and
     * that is also what lets a crawler or a chat-app scraper fetch it without a session.
     */
    public static function assetUrl(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        return route('seo.asset', ['file' => $path]);
    }

    /**
     * A configured value, or null so the caller can fall back to config.
     *
     * Blank strings are treated as "not set" on purpose: an admin who clears a field
     * means "go back to the default", not "render an empty title tag".
     */
    public function value(string $key): mixed
    {
        $value = $this->{$key};

        return is_string($value) && trim($value) === '' ? null : $value;
    }
}