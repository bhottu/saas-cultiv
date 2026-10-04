<?php

namespace App\Http\Controllers\Admin;

use App\Models\SeoSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Platform-wide SEO settings.
 *
 * These are deliberately NOT workspace data. config/seo.php stays the default for every
 * value; the single row here records only what a platform administrator has explicitly
 * overridden, and App\Support\Seo falls back to config for anything left blank. That is
 * what makes this safe to ship: an empty (or absent) table produces exactly the metadata
 * the site produced before this screen existed.
 *
 * Assets live on the configured uploads disk, which is PRIVATE by default
 * (storage/app/private). A favicon and an og:image cannot reuse the tenant branding route
 * for that — they are requested by crawlers and chat-app scrapers with no session, so
 * they are served by the public `seo.asset` route instead.
 */
class SeoController extends AdminController
{
    /** A tab icon is chrome, not artwork: a tight cap keeps it cheap to serve. */
    private const MAX_FAVICON_KB = 512;

    /** An og:image is rendered at up to 1200px wide; 2 MB is generous but bounded. */
    private const MAX_IMAGE_KB = 2048;

    /**
     * ICO is accepted because browsers still probe for it. Its MIME cannot be detected
     * from content on most uploads (the format predates image/x-icon), so it is matched
     * by extension as a documented exception to the content check.
     */
    private const FAVICON_MIMES = ['image/png', 'image/jpeg', 'image/webp', 'image/vnd.microsoft.icon'];

    private const IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/webp'];

    public function edit(Request $request)
    {
        $settings = SeoSetting::current();

        return view('admin.seo.index', [
            'settings' => $settings,
            'faviconUrl' => SeoSetting::assetUrl($settings->favicon_path),
            'ogImageUrl' => SeoSetting::assetUrl($settings->og_image_path),
            'twitterImageUrl' => SeoSetting::assetUrl($settings->twitter_image_path),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'site_name' => ['nullable', 'string', 'max:60'],
            'default_title' => ['nullable', 'string', 'max:180'],
            'default_description' => ['nullable', 'string', 'max:1000'],
            // A canonical base is a URL, not a path: accepting a bare string would let an
            // admin publish <link rel="canonical" href="not-a-url"> to every page.
            'canonical_url' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'theme_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'allow_indexing' => ['nullable', 'boolean'],

            'favicon' => ['nullable', 'file', 'max:'.self::MAX_FAVICON_KB],
            'og_image' => ['nullable', 'file', 'max:'.self::MAX_IMAGE_KB],
            'twitter_image' => ['nullable', 'file', 'max:'.self::MAX_IMAGE_KB],
            'remove_favicon' => ['nullable', 'boolean'],
            'remove_og_image' => ['nullable', 'boolean'],
            'remove_twitter_image' => ['nullable', 'boolean'],

            'og_title' => ['nullable', 'string', 'max:180'],
            'og_description' => ['nullable', 'string', 'max:1000'],
            // Restricted to what the OG spec defines rather than free text: this value is
            // written verbatim into a meta tag that browsers and scrapers read.
            'og_type' => ['nullable', Rule::in(['website', 'article', 'product', 'profile'])],
            'og_site_name' => ['nullable', 'string', 'max:60'],

            'twitter_card_type' => ['nullable', Rule::in(['summary', 'summary_large_image'])],
            'twitter_title' => ['nullable', 'string', 'max:180'],
            'twitter_description' => ['nullable', 'string', 'max:1000'],
            'twitter_handle' => ['nullable', 'string', 'max:255', 'regex:/^@?[A-Za-z0-9_]{1,15}$/'],
        ], [
            'theme_color.regex' => __('The theme colour must be a hex value, for example #4f46e5.'),
            'canonical_url.url' => __('The canonical URL must be a full address starting with https://'),
            'twitter_handle.regex' => __('The X / Twitter handle is the account name only, for example @cultiv.'),
            'og_type.in' => __('Choose one of: website, article, product, profile.'),
            'twitter_card_type.in' => __('Choose either summary or summary_large_image.'),
        ]);

        $settings = SeoSetting::current();

        // Blank means "not set": it must fall back to config rather than blank out a tag.
        $attributes = ['allow_indexing' => $request->boolean('allow_indexing')];

        foreach (SeoSetting::EDITABLE as $key) {
            if ($key !== 'allow_indexing' && array_key_exists($key, $data)) {
                $value = $data[$key];
                $attributes[$key] = is_string($value) && trim($value) === '' ? null : $value;
            }
        }

        // Uploads are resolved after validation so a rejected image never leaves an orphaned
        // file, and written into $attributes so an upload cannot be dropped by the
        // absence of a remove_* checkbox key.
        foreach ([
            'favicon' => 'favicon_path',
            'og_image' => 'og_image_path',
            'twitter_image' => 'twitter_image_path',
        ] as $field => $column) {
            $previous = $settings->{$column};

            if ($request->boolean('remove_'.$field)) {
                $attributes[$column] = null;
            } elseif ($request->hasFile($field)) {
                $attributes[$column] = $this->storeAsset($request, $field);
            }

            // Replacing or clearing drops the superseded file, so the disk does not
            // accumulate every icon and share image the platform has ever used.
            if ($previous && $previous !== ($attributes[$column] ?? $previous)) {
                Storage::disk(config('saas.uploads.disk'))->delete($previous);
            }
        }

        $settings->update($attributes);

        $this->audit($request, 'seo.updated', null, [
            'allow_indexing' => $attributes['allow_indexing'],
            // Only what actually changed, not a dump of the whole settings row.
            'changed' => array_keys(array_filter(
                $attributes,
                fn ($value, $key) => $settings->getOriginal($key) !== $value,
                ARRAY_FILTER_USE_BOTH,
            )),
        ]);

        return redirect()->route('admin.seo.edit')
            ->with('status', ['type' => 'success', 'message' => __('SEO changes saved.')]);
    }

    /**
     * Validate an asset by CONTENT, then park it on the uploads disk.
     *
     * The client filename and extension are never trusted and never used to build the
     * stored name: a file named logo.png that is really a script is rejected here, and
     * even if it were not, the stored name is generated by this application.
     */
    private function storeAsset(Request $request, string $field): string
    {
        $upload = $request->file($field);
        $allowed = $field === 'favicon' ? self::FAVICON_MIMES : self::IMAGE_MIMES;

        if (! in_array($upload->getMimeType(), $allowed, true)) {
            $validator = Validator::make([], []);
            $validator->errors()->add(
                $field,
                $field === 'favicon'
                    ? __('The favicon must be a PNG, JPG, ICO or WEBP image.')
                    : __('The image must be a PNG, JPG or WEBP image.'),
            );

            throw new ValidationException($validator);
        }

        $path = $upload->store('seo', config('saas.uploads.disk'));

        if (! $path) {
            abort(500, 'The file could not be stored.');
        }

        return $path;
    }
}