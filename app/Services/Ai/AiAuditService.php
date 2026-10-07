<?php

namespace App\Services\Ai;

use App\Models\AiChannelLink;
use App\Models\AiPendingAction;
use App\Models\AuditLog;

class AiAuditService
{
    public function tenantEvent(int $tenantId, int $userId, string $action, string $status, array $metadata = []): void
    {
        $this->write($tenantId, $userId, $action, 'ai_channel_link', '', [
            'channel' => 'telegram',
            'status' => $status,
            ...$metadata,
        ]);
    }

    public function channel(AiChannelLink $link, string $action, string $status, array $metadata = []): void
    {
        $this->write($link->tenant_id, $link->user_id, $action, AiChannelLink::class, $link->id, [
            'channel' => $link->channel,
            'status' => $status,
            ...$metadata,
        ]);
    }

    public function pendingAction(AiPendingAction $pending, string $action, string $status, array $metadata = []): void
    {
        $this->write($pending->tenant_id, $pending->user_id, $action, AiPendingAction::class, $pending->id, [
            'channel' => 'telegram',
            'status' => $status,
            ...$metadata,
        ]);
    }

    private function write(int $tenantId, int $userId, string $action, string $resourceType, string|int $resourceId, array $metadata): void
    {
        AuditLog::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => (string) $resourceId,
            'ip_address' => null,
            'user_agent' => null,
            'metadata' => $metadata,
        ]);
    }
}
