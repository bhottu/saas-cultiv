<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class BusinessInvoicePayment extends Model
{
    use BelongsToTenant;
    protected $guarded = [];
    protected $casts = ['amount' => 'integer', 'paid_at' => 'datetime'];
    public function invoice() { return $this->belongsTo(BusinessInvoice::class, 'business_invoice_id'); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
}
