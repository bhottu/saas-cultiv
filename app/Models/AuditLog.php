<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = ['metadata' => 'array'];

    public static function record(string $action, ?Model $resource = null, array $metadata = []): self
    {
        $ctx = app('tenant.context');

        return static::create([
            'tenant_id' => $resource?->tenant_id ?? $ctx->tenant()?->id,
            'user_id' => auth()->id(),
            'action' => $action,
            'resource_type' => $resource ? $resource->getMorphClass() : null,
            'resource_id' => $resource?->getKey(),
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 500),
            'metadata' => $metadata,
        ]);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
