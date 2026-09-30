<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Purchase extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    public const STATUSES = ['draft', 'ordered', 'received', 'cancelled'];

    protected $guarded = [];
    protected $casts = [
        'ordered_at' => 'datetime', 'expected_at' => 'date',
        'received_at' => 'datetime', 'cancelled_at' => 'datetime',
        'subtotal' => 'integer', 'discount' => 'integer', 'tax' => 'integer',
        'shipping' => 'integer', 'total' => 'integer',
    ];

    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function items() { return $this->hasMany(PurchaseItem::class); }
    public function invoice() { return $this->hasOne(BusinessInvoice::class); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
}
