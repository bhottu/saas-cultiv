<?php

namespace App\Services\Ai;

use App\Exceptions\SubscriptionLimitException;
use App\Models\AiChannelLink;
use App\Services\ModuleManager;
use App\Services\UsageService;
use Throwable;

class AiChatService
{
    public function __construct(
        private readonly AiAgent $agent,
        private readonly UsageService $usage,
        private readonly ModuleManager $modules,
        private readonly AiAuditService $audit,
    ) {}

    public function respond(AiChannelLink $link, string $message): AiToolResult
    {
        if (! $this->modules->active('ai_agent', $link->tenant)) {
            $this->audit->channel($link, 'ai.request.blocked', 'module_inactive');
            throw new \RuntimeException('AI Agent is not active for this workspace.');
        }

        try {
            $this->usage->consume($link->tenant, 'ai_messages');
        } catch (SubscriptionLimitException $error) {
            $this->audit->channel($link, 'ai.request.blocked', 'quota_exceeded');
            throw $error;
        }

        $this->audit->channel($link, 'ai.request.received', 'processing');

        try {
            $reply = $this->agent->respond($message, $link);
        } catch (Throwable $error) {
            $this->audit->channel($link, 'ai.request.failed', 'provider_or_tool_error', [
                'exception' => $error::class,
            ]);
            throw $error;
        }

        $this->audit->channel($link, 'ai.response.prepared', 'success', [
            'action' => $reply->pendingAction?->action,
        ]);

        return $reply;
    }
}
