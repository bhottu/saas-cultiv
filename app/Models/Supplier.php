<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Case-insensitive substring search across the supplier's contact fields.
     *
     * The operator is the important part. A plain `where('name', 'like', …)` is
     * case-SENSITIVE on PostgreSQL — the production database — so searching "kaya"
     * silently found nothing for "PT Kaya Raya". `whereLike()` with the default
     * `$caseSensitive = false` compiles to ILIKE on PostgreSQL and LIKE on SQLite,
     * which is what the test suite runs on. One call, correct on both.
     *
     * A blank or whitespace-only term is treated as "no filter" rather than as a
     * search for spaces, which would match nothing and look like a bug.
     *
     * @param  string|null  $term
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.$term.'%';

        return $query->where(function (Builder $query) use ($like) {
            $query->whereLike('name', $like)
                ->orWhereLike('phone', $like)
                ->orWhereLike('email', $like);
        });
    }

    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }

    public function invoices()
    {
        return $this->hasMany(BusinessInvoice::class);
    }
}
