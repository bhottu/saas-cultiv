<?php

namespace App\Services\Ai;

use App\Exceptions\SubscriptionLimitException;
use App\Models\AiChannelLink;
use App\Services\ModuleManager;
use App\Services\UsageService;
use Illuminate\Support\Facades\Cache;
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
            $key = $this->conversationKey($link);
            $history = Cache::get($key, []);
            $reply = $this->agent->respond($message, $link, is_array($history) ? $history : []);
        } catch (Throwable $error) {
            $this->audit->channel($link, 'ai.request.failed', 'provider_or_tool_error', [
                'exception' => $error::class,
            ]);
            throw $error;
        }

        if ($reply->pendingAction) {
            Cache::forget($key);
        } else {
            $history = array_merge(is_array($history) ? $history : [], [
                ['role' => 'user', 'content' => mb_substr($message, 0, 4000)],
                ['role' => 'assistant', 'content' => mb_substr($reply->content, 0, 4000)],
            ]);
            Cache::put($key, array_slice($history, -8), now()->addMinutes(10));
        }

        $this->audit->channel($link, 'ai.response.prepared', 'success', [
            'action' => $reply->pendingAction?->action,
            'requires_follow_up' => $reply->requiresFollowUp,
        ]);

        return $reply;
    }

    private function conversationKey(AiChannelLink $link): string
    {
        return sprintf(
            'ai.telegram.conversation.%d.%d.%d',
            $link->id,
            $link->tenant_id,
            $link->user_id,
        );
    }
}
