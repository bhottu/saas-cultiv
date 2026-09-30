<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use BelongsToTenant, SoftDeletes;
    protected $guarded = [];
    protected $casts = ['amount' => 'integer', 'expense_date' => 'date'];
    public function category() { return $this->belongsTo(ExpenseCategory::class); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
}
