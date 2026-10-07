<?php

namespace App\Services\Ai;

use App\Models\AiChannelLink;

class AiAgent
{
    public function __construct(
        private readonly AiProviderManager $providers,
        private readonly BusinessAiTools $tools,
    ) {}

    public function respond(string $text, AiChannelLink $link): AiToolResult
    {
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are Cultiv AI, an assistant for the user’s active workspace. Reply in the same language as the user. Use only available tools for business data, never invent records or figures, and never claim an action is complete unless a tool confirms it. A sale is only a draft until the user explicitly confirms it. Do not ask for or expose credentials.',
            ],
            ['role' => 'user', 'content' => mb_substr($text, 0, 4000)],
        ];
        $definitions = $this->tools->definitions();

        for ($turn = 0; $turn < 4; $turn++) {
            $response = $this->providers->complete($messages, $definitions);

            if ($response->toolCalls === []) {
                return new AiToolResult(trim($response->text ?? '') ?: __('I could not prepare a response. Please try again.'));
            }

            $messages[] = [
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

            foreach ($response->toolCalls as $call) {
                $result = $this->tools->execute($call['name'], $call['arguments'], $link);
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

        return new AiToolResult(__('I could not complete that request. Please try a simpler question.'));
    }
}
