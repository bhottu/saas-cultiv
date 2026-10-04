<?php

namespace App\Http\Controllers;

use App\Models\SeoSetting;
use Illuminate\Support\Facades\Storage;

/**
 * Public delivery of the SEO assets referenced by the rendered metadata.
 *
 * Crawlers and chat-app scrapers request a favicon and an og:image with no session and no
 * cookies, so this route is deliberately outside every middleware group — exactly like
 * the sitemap route.
 *
 * It is NOT a general file endpoint. The path is matched against the asset columns of the
 * settings row, so it can only ever serve the images an administrator has actually
 * chosen to publish; an arbitrary storage path resolves to nothing. That matters because
 * the uploads disk holds tenant documents.
 */
class SeoAssetController extends Controller
{
    public function __invoke(string $file)
    {
        $settings = SeoSetting::query()->first();

        abort_if($settings === null, 404);

        // Only paths that are genuinely in use are servable, and only from the columns
        // that hold public-by-design imagery.
        $published = array_filter([
            $settings->favicon_path,
            $settings->og_image_path,
            $settings->twitter_image_path,
        ]);

        abort_unless(in_array($file, $published, true), 404);

        $disk = Storage::disk(config('saas.uploads.disk'));

        abort_unless($disk->exists($file), 404);

        return $disk->response($file, null, [
            // Long-lived but revalidated: an administrator replacing the favicon should
            // be seen on the next visit, not pinned in a tab strip for a year.
            //
            // Note the argument position. FilesystemAdapter::response() is
            // ($path, $name, $headers, $disposition) — headers are the THIRD argument.
            // Passing them second is silently typed as a file name and throws
            // "Array to string conversion" the moment the file is actually served.
            'Cache-Control' => 'public, max-age=604800, must-revalidate',
        ]);
    }
}