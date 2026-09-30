<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BusinessInvoice extends Model
{
    use BelongsToTenant, SoftDeletes;
    protected $guarded = [];
    protected $casts = [
        'subtotal' => 'integer', 'discount' => 'integer', 'tax' => 'integer',
        'shipping' => 'integer', 'total' => 'integer', 'amount_paid' => 'integer',
        'outstanding' => 'integer', 'issued_at' => 'datetime', 'due_at' => 'date',
        'paid_at' => 'datetime',
    ];
    public function customer() { return $this->belongsTo(Customer::class); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function sale() { return $this->belongsTo(Sale::class); }
    public function purchase() { return $this->belongsTo(Purchase::class); }
    public function payments() { return $this->hasMany(BusinessInvoicePayment::class); }
}
