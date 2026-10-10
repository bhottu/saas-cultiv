<?php

namespace App\Services\Ai;

use App\Models\AiChannelLink;
use App\Models\AiSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AiAgent
{
    private const MAX_TOOL_TURNS = 4;

    public function __construct(
        private readonly AiProviderManager $providers,
        private readonly BusinessAiTools $tools,
    ) {}

    /**
     * @param array<int, array{role: 'user'|'assistant', content: string}> $history
     */
    public function respond(string $text, AiChannelLink $link, array $history = []): AiToolResult
    {
        $requestId = request()->attributes->get('ai_request_id') ?: (string) Str::uuid();
        $settings = AiSetting::current();
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are Cultiv AI, an assistant for the user’s active and server-verified workspace. Reply naturally in the same language as the latest user message, understanding common typos and incomplete Indonesian phrasing. Use recent conversation context when resolving short follow-ups, but treat the latest message as authoritative and do not carry old sale details into a new unrelated request. Use only available tools for workspace/business data; never invent records, prices, stock, customer details, or figures. For questions about the workspace name use get_workspace_info. For stock requests use get_stock; if no product is specified, ask which product. When given a product name, SKU, or barcode, pass it as query to get_stock. If the tool reports multiple matches, ask the user to choose one. For customer lookup use search_customers and only report existing customers. Customer creation is not supported in chat: explain that an authorized user must create the customer in Cultiv One first. If the user chooses walk-in after a customer lookup, omit customer fields from draft_sale. A sale is only a draft until the user explicitly confirms it using the confirmation button. If information is missing, ask one concise clarification and continue from the conversation context; a customer is optional. Use actual existing tools for draft_sale and never claim a draft or sale exists unless confirmed by a tool. Do not ask for or expose credentials.',
            ],
            ...array_slice(array_values(array_filter($history, fn (array $message) => in_array($message['role'] ?? null, ['user', 'assistant'], true)
                && is_string($message['content'] ?? null))), -8),
            ['role' => 'user', 'content' => mb_substr($text, 0, 4000)],
        ];
        $definitions = $this->tools->definitions();
        $requiresFollowUp = false;

        for ($turn = 0; $turn < self::MAX_TOOL_TURNS; $turn++) {
            $providerStartedAt = hrtime(true);
            try {
                $response = $this->providers->complete($messages, $definitions);
            } catch (Throwable $error) {
                Log::warning('ai.assistant.provider_failed', [
                    'request_id' => $requestId,
                    'stage' => 'provider_request',
                    'conversation_stage' => collect($messages)->contains(fn (array $message) => ($message['role'] ?? null) === 'tool')
                        ? 'tool_continuation'
                        : 'initial_request',
                    'provider' => $error instanceof AiProviderException ? $error->provider : $settings->provider,
                    'model' => $error instanceof AiProviderException ? $error->model : $settings->model,
                    'reason' => $error instanceof AiProviderException ? $error->reason : 'provider_or_transport_error',
                    'provider_status' => $error instanceof AiProviderException ? $error->status : null,
                    'provider_api_status' => $error instanceof AiProviderException ? $error->apiStatus : null,
                    'provider_message' => $error instanceof AiProviderException ? $error->apiMessage : null,
                    'duration_ms' => (int) ((hrtime(true) - $providerStartedAt) / 1_000_000),
                    'exception' => $error::class,
                ]);

                throw $error;
            }
            Log::info('ai.assistant.provider_completed', [
                'request_id' => $requestId,
                'provider' => $settings->provider,
                'model' => $settings->model,
                'duration_ms' => (int) ((hrtime(true) - $providerStartedAt) / 1_000_000),
                'tool_call_count' => count($response->toolCalls),
                'status' => 'success',
            ]);

            if ($response->toolCalls === []) {
                $answer = trim($response->text ?? '');
                if ($answer === '') {
                    Log::warning('ai.assistant.empty_response', [
                        'request_id' => $requestId,
                        'provider' => $settings->provider,
                        'model' => $settings->model,
                        'turn' => $turn + 1,
                    ]);

                    return new AiToolResult(__('I could not prepare a response. Please try again.'));
                }

                return new AiToolResult($answer, requiresFollowUp: $requiresFollowUp);
            }

            $assistantMessage = [
                'role' => 'assistant',
                'content' => $response->text ?? '',
                'tool_calls' => array_map(fn (array $call) => [
                    'id' => $call['id'],
                    'type' => 'function',
                    'function' => [
                        'name' => $call['name'],
                        'arguments' => json_encode($call['arguments'], JSON_THROW_ON_ERROR),
                    ],
                ], $response->toolCalls),
            ];
            if ($response->metadata !== []) {
                $assistantMessage['provider_metadata'] = $response->metadata;
            }
            $messages[] = $assistantMessage;

            foreach ($response->toolCalls as $call) {
                $startedAt = hrtime(true);
                try {
                    $result = $this->tools->execute($call['name'], $call['arguments'], $link);
                } catch (Throwable $error) {
                    Log::warning('ai.assistant.tool_failed', [
                        'request_id' => $requestId,
                        'stage' => 'tool_execution',
                        'tool' => $call['name'],
                        'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
                        'exception' => $error::class,
                        'tenant_id' => $link->tenant_id,
                    ]);

                    throw $error;
                }
                Log::info('ai.assistant.tool_completed', [
                    'request_id' => $requestId,
                    'tool' => $call['name'],
                    'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
                    'tenant_id' => $link->tenant_id,
                ]);
                $requiresFollowUp = $requiresFollowUp || $result->requiresFollowUp;
                if ($result->pendingAction) {
                    return $result;
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'],
                    'name' => $call['name'],
                    'content' => $result->content,
                ];
            }
        }

        Log::warning('ai.assistant.tool_turn_limit_reached', [
            'request_id' => $requestId,
            'provider' => $settings->provider,
            'model' => $settings->model,
            'max_tool_turns' => self::MAX_TOOL_TURNS,
        ]);

        return new AiToolResult(__('I could not complete that request. Please try a simpler question.'));
    }
}
