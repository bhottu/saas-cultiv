<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Product category (tenant-owned, flat list).
 *
 * Schema: tenant_id, name, slug, description, is_active (unique tenant_id+slug).
 */
class Category extends Model
{
    use HasFactory, BelongsToTenant;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Category $category) {
            if (empty($category->slug)) {
                $category->slug = self::uniqueSlug($category);
            }
        });
    }

    /** Tenant-scoped unique slug (base slug plus a counter when taken). */
    private static function uniqueSlug(Category $category): string
    {
        $base = Str::slug((string) $category->name) ?: 'category';
        $slug = $base;
        $suffix = 1;

        while (
            $category->newQueryWithoutScopes()
                ->where('tenant_id', $category->tenant_id)
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}