<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    /**
     * The foreign key on `expenses` is `category_id`, not the `expense_category_id`
     * Laravel would infer from this method name.
     *
     * The column has always been `category_id` (business-finance migration), so the
     * inferred name was wrong from day one. Nothing noticed because no code path ever
     * traversed this relation — it only surfaced once the category list started counting
     * its expenses, which failed on `column expenses.expense_category_id does not exist`.
     * Named explicitly so the inverse of Expense::category() actually resolves.
     */
    public function expenses()
    {
        return $this->hasMany(Expense::class, 'category_id');
    }
}
