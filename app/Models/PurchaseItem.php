<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseItem extends Model
{
    use BelongsToTenant, HasFactory;
    protected $guarded = [];
    protected $casts = ['quantity' => 'integer', 'unit_cost' => 'integer', 'subtotal' => 'integer'];
    public function purchase() { return $this->belongsTo(Purchase::class); }
    public function product() { return $this->belongsTo(Product::class); }
}
