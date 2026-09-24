<?php

namespace App\Http\Controllers;

use App\Models\FileEntry;
use App\Services\AuditLogger;
use App\Services\UsageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Tenant-scoped file uploads (§18). Files live on an object-storage-ready disk;
 * metadata in `files` with tenant isolation via BelongsToTenant global scope.
 */
class FileController extends Controller
{
    public function __construct(private readonly UsageService $usage) {}

    public function index(Request $request)
    {
        $ctx = app('tenant.context');
        $ctx->check();

        return view('files.index', [
            'files' => $ctx->tenant()->files()->latest()->take(50)->get(),
            'storageUsedMb' => round($this->usage->usage($ctx->tenant(), 'storage_mb'), 1),
            'storageLimitMb' => $this->usage->limit($ctx->tenant(), 'storage_mb'),
        ]);
    }

    public function store(Request $request)
    {
        $ctx = app('tenant.context');
        $ctx->check();
        $ctx->authorize('create_records');

        $request->validate([
            'file' => ['required', 'file', 'max:'.config('saas.uploads.max_size_kb')],
        ]);

        $upload = $request->file('file');
        $tenant = $ctx->tenant();

        // 1. Verify MIME from actual content — extension/filename are never trusted.
        $detectedMime = $upload->getMimeType();

        if (! in_array($detectedMime, config('saas.uploads.allowed_mimes'), true)) {
            // Render the error on the Files page (in-page), not Laravel's exception page.
            $validator = Validator::make([], []);
            $validator->errors()->add('file', "File type not allowed ({$detectedMime}).");
            return back()->withErrors($validator)->withInput();
        }

        // 2. Enforce the plan's storage quota server-side before storing.
        $sizeMb = $upload->getSize() / 1024 / 1024;
        $this->usage->enforce($tenant, 'storage_mb', (int) ceil($sizeMb));

        // 3. Tenant-prefixed path, random name — original name kept only as metadata.
        $disk = config('saas.uploads.disk');
        $path = $upload->store("tenants/{$tenant->id}/".now()->format('Y/m'), $disk);

        $file = FileEntry::create([
            'tenant_id' => $tenant->id,
            'uploaded_by' => $request->user()->id,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $upload->getClientOriginalName(),
            'mime_type' => $detectedMime,
            'size' => $upload->getSize(),
        ]);

        $this->usage->record($tenant, 'storage_mb', (int) ceil($sizeMb));
        AuditLogger::log('file.uploaded', $file, ['size' => $file->size, 'mime' => $detectedMime]);

        return back()->with('success', 'File uploaded.');
    }

    /** Tenant-scoped download: resolved through the tenant relation — foreign IDs are 404. */
    public function download(Request $request, $fileId)
    {
        $ctx = app('tenant.context');
        $ctx->check();
        $ctx->authorize('view_records');

        // Explicit tenant-scoped lookup (NOT implicit binding — bindings resolve
        // before tenant context exists and would bypass the global scope).
        $file = $ctx->tenant()->files()->findOrFail($fileId);

        abort_unless(Storage::disk($file->disk)->exists($file->path), 404, 'File missing from storage.');

        AuditLogger::log('file.downloaded', $file);

        return Storage::disk($file->disk)->download($file->path, $file->original_name);
    }

    public function destroy(Request $request, $fileId)
    {
        $ctx = app('tenant.context');
        $ctx->check();
        $ctx->authorize('delete_records');

        $file = $ctx->tenant()->files()->findOrFail($fileId);

        $file->deleteFromStorage();
        $file->delete();

        AuditLogger::log('file.deleted', $file);

        return back()->with('success', 'File deleted.');
    }
}
