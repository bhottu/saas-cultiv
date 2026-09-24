<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UsageRecord extends Model
{
    use HasFactory, BelongsToTenant;

    protected $guarded = [];

    public function scopeCurrentPeriod($query, string $metric, string $period = 'month')
    {
        $start = $period === 'forever' ? '1970-01-01' : now()->startOfMonth()->toDateString();
        $end = $period === 'forever' ? '9999-12-31' : now()->endOfMonth()->toDateString();

        return $query->where('metric', $metric)
            ->where('period_start', $start)
            ->where('period_end', $end);
    }
}
