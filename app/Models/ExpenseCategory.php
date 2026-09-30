<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    use BelongsToTenant;
    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];
    public function expenses() { return $this->hasMany(Expense::class); }
}
