<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Custom workspace branding: logo, name and tagline shown in the application shell.
 *
 * Branding is workspace data, so everything here runs inside the `tenant` group and
 * is authorised with the existing permission registry — `manage_settings`, which the
 * role table grants to Owner and Admin only. No new role, gate or permission is
 * introduced for this.
 *
 * The logo is stored on the configured uploads disk and served back through
 * `logo()`, so it is never a publicly guessable URL.
 */
class WorkspaceBrandingController extends Controller
{
    /** Logos are chrome, not documents: a small cap keeps a header-sized asset cheap. */
    private const MAX_LOGO_KB = 2048;

    /** Only the raster formats a browser can paint in an <img> without scripting. */
    private const LOGO_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function update(Request $request)
    {
        $ctx = app('tenant.context');
        $ctx->check();
        $ctx->authorize('manage_settings');

        $tenant = $ctx->tenant();

        $data = $request->validate([
            // Caps come from the migration and are enforced again here, so the
            // contract holds even if a form is posted directly.
            'brand_name' => ['nullable', 'string', 'max:30'],
            'brand_tagline' => ['nullable', 'string', 'max:60'],
            'brand_logo' => ['nullable', 'file', 'max:'.self::MAX_LOGO_KB],
            // Checkbox: an unticked box is simply absent from the payload.
            'remove_logo' => ['nullable', 'boolean'],
        ], [
            'brand_name.max' => 'Keep the brand name to 30 characters so the header stays on one line.',
            'brand_tagline.max' => 'Keep the brand description to 60 characters.',
            'brand_logo.max' => 'The logo may not be larger than 2 MB.',
        ]);

        $attributes = [
            // Blank is stored as NULL: the model reads that as "use the default".
            'brand_name' => filled($data['brand_name'] ?? null) ? $data['brand_name'] : null,
            'brand_tagline' => filled($data['brand_tagline'] ?? null) ? $data['brand_tagline'] : null,
        ];

        $previousLogo = $tenant->brand_logo_path;

        if ($request->boolean('remove_logo')) {
            $attributes['brand_logo_path'] = null;
        } elseif ($request->hasFile('brand_logo')) {
            $attributes['brand_logo_path'] = $this->storeLogo($request, $tenant);
        }

        $tenant->update($attributes);

        // Replacing or clearing a logo drops the file it superseded, so the disk does
        // not accumulate every logo a workspace ever uploaded.
        if ($previousLogo && $previousLogo !== ($attributes['brand_logo_path'] ?? null)) {
            Storage::disk(config('saas.uploads.disk'))->delete($previousLogo);
        }

        AuditLogger::log('tenant.branding_updated', $tenant, [
            'name' => $attributes['brand_name'],
            'logo_changed' => ($attributes['brand_logo_path'] ?? null) !== $previousLogo,
        ]);

        return redirect()->route('profile.edit')->with('success', 'Brand identity saved.');
    }

    /**
     * Stream the workspace logo for the shell's <img>.
     *
     * Resolved through the tenant route model binding, so a member of another
     * workspace gets a 404 rather than somebody else's brand mark.
     */
    public function logo(Request $request, Tenant $tenant)
    {
        $ctx = app('tenant.context');
        $ctx->check();

        abort_if($tenant->id !== $ctx->tenant()->id, 404);

        abort_unless($tenant->brand_logo_path, 404);

        $disk = Storage::disk(config('saas.uploads.disk'));

        abort_unless($disk->exists($tenant->brand_logo_path), 404, 'Logo missing from storage.');

        return $disk->response($tenant->brand_logo_path);
    }

    /**
     * Validate the upload by CONTENT, not by extension, then park it on the uploads
     * disk under a tenant-prefixed, randomly named path.
     */
    private function storeLogo(Request $request, Tenant $tenant): string
    {
        $upload = $request->file('brand_logo');

        if (! in_array($upload->getMimeType(), self::LOGO_MIMES, true)) {
            $validator = Validator::make([], []);
            $validator->errors()->add('brand_logo', 'The logo must be a PNG, JPG or WEBP image.');

            throw new ValidationException($validator);
        }

        $path = $upload->store("tenants/{$tenant->id}/branding", config('saas.uploads.disk'));

        if (! $path) {
            abort(500, 'The logo could not be stored.');
        }

        return $path;
    }
}
