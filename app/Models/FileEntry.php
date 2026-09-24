<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class FileEntry extends Model
{
    use HasFactory, BelongsToTenant, SoftDeletes;

    protected $table = 'files';

    protected $guarded = [];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function deleteFromStorage(): void
    {
        Storage::disk($this->disk)->delete($this->path);
    }
}
