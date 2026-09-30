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

    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }

    public function invoices()
    {
        return $this->hasMany(BusinessInvoice::class);
    }
}
