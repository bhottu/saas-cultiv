<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    use HasFactory, BelongsToTenant;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Brand $brand) {
            if (empty($brand->slug)) {
                $brand->slug = \Illuminate\Support\Str::slug($brand->name).'-'.static::generateSlugSuffix($brand);
            }
        });
    }

    private static function generateSlugSuffix(Brand $brand): string
    {
        $like = $brand->newQueryWithoutScopes()
            ->where('tenant_id', $brand->tenant_id)
            ->where('slug', \Illuminate\Support\Str::slug($brand->name).'%')
            ->count();

        return ($like + 1);
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