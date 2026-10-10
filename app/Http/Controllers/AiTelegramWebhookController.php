<?php

namespace App\Http\Controllers;

use App\Exceptions\SubscriptionLimitException;
use App\Models\AiChannelLink;
use App\Models\AiSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\AiChatService;
use App\Services\Ai\AiPendingSaleService;
use App\Services\Ai\TelegramClient;
use App\Services\Ai\TelegramLinkService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AiTelegramWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        TelegramLinkService $linker,
        AiChatService $chat,
        AiPendingSaleService $sales,
        TelegramClient $telegram,
    ): JsonResponse {
        $secret = AiSetting::current()->telegram_webhook_secret;
        $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        abort_unless($secret && $provided !== '' && hash_equals($secret, $provided), 403);

        $updateId = $request->input('update_id');
        if (! is_int($updateId) && ! (is_string($updateId) && ctype_digit($updateId))) {
            return response()->json(['ok' => true]);
        }

        $inserted = DB::table('ai_channel_updates')->insertOrIgnore([
            'channel' => 'telegram',
            'external_update_id' => (string) $updateId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if (! $inserted) {
            return response()->json(['ok' => true]);
        }

        try {
            if ($request->filled('callback_query')) {
                $this->handleCallback($request->input('callback_query'), $context, $sales, $telegram);
            } elseif ($request->filled('message')) {
                $this->handleMessage($request->input('message'), $context, $linker, $chat, $telegram);
            }
        } catch (Throwable $error) {
            Log::warning('ai.telegram.update_failed', [
                'exception' => $error::class,
                'update_id' => (string) $updateId,
            ]);
            $this->reportUnexpectedFailure($request, $telegram);
        }

        return response()->json(['ok' => true]);
    }

    private function handleMessage(
        array $message,
        TenantContext $context,
        TelegramLinkService $linker,
        AiChatService $chat,
        TelegramClient $telegram,
    ): void {
        $chatData = $message['chat'] ?? [];
        $from = $message['from'] ?? [];
        $text = trim((string) ($message['text'] ?? ''));
        if (($chatData['type'] ?? null) !== 'private' || ! isset($from['id']) || ($from['is_bot'] ?? false) || $text === '') {
            return;
        }

        $telegramUserId = (string) $from['id'];
        if (preg_match('/^\/link(?:@\w+)?\s+([A-Z0-9]{8})$/i', $text, $matches)) {
            $code = strtoupper($matches[1]);
            if ($linker->redeem($code, $telegramUserId)) {
                $link = AiChannelLink::query()->where('channel', 'telegram')->where('external_id', $telegramUserId)->firstOrFail();
                app(\App\Services\Ai\AiAuditService::class)->channel($link, 'ai.telegram.linked', 'success');
                $telegram->sendText($link, __('Telegram is linked to workspace :workspace. You can now send business questions here.', [
                    'workspace' => $link->tenant->name,
                ]));
            } else {
                $this->sendUnlinked($telegramUserId, __('That link code is invalid or expired. Create a new code in workspace settings.'));
            }

            return;
        }

        $link = AiChannelLink::query()->where('channel', 'telegram')
            ->where('external_id', $telegramUserId)->first();
        if (! $link) {
            $this->sendUnlinked($telegramUserId, __('Link your Telegram account from Cultiv One workspace settings first.'));

            return;
        }

        $tenant = Tenant::query()->find($link->tenant_id);
        $user = User::query()->find($link->user_id);
        if (! $tenant || ! $user || ! $user->membershipIn($tenant)) {
            $this->sendUnlinked($telegramUserId, __('This workspace link is no longer active. Link your account again from workspace settings.'));

            return;
        }

        $requestId = (string) \Illuminate\Support\Str::uuid();
        request()->attributes->set('ai_request_id', $requestId);
        $context->set($tenant, $user);
        try {
            $reply = $chat->respond($link, $text);
            $telegram->sendText($link, $reply->content, $reply->pendingAction);
        } catch (SubscriptionLimitException) {
            $telegram->sendText($link, __('This workspace has reached its monthly AI message limit.'));
        } catch (\App\Services\Ai\AiProviderException $error) {
            Log::warning('ai.telegram.message_processing_failed', [
                'request_id' => $requestId,
                'tenant_id' => $tenant->id,
                'exception' => $error::class,
                'failure_stage' => 'provider',
            ]);
            $telegram->sendText($link, __('The AI service is temporarily unavailable. Please try again later.'));
        } catch (HttpExceptionInterface $error) {
            if ($error->getStatusCode() === 403) {
                Log::warning('ai.telegram.access_denied', [
                    'request_id' => $requestId,
                    'tenant_id' => $tenant->id,
                    'exception' => $error::class,
                ]);
                $telegram->sendText($link, __('You do not have permission to access that workspace information.'));

                return;
            }

            Log::warning('ai.telegram.message_processing_failed', [
                'request_id' => $requestId,
                'tenant_id' => $tenant->id,
                'exception' => $error::class,
                'failure_stage' => 'tool_or_message_processing',
                'http_status' => $error->getStatusCode(),
            ]);
            $telegram->sendText($link, __('I could not process that request. Please try again later.'));
        } catch (Throwable $error) {
            Log::warning('ai.telegram.message_processing_failed', [
                'request_id' => $requestId,
                'tenant_id' => $tenant->id,
                'exception' => $error::class,
                'failure_stage' => 'tool_or_message_processing',
            ]);
            $telegram->sendText($link, __('I could not process that request. Please try again later.'));
        }
    }

    private function handleCallback(
        array $callback,
        TenantContext $context,
        AiPendingSaleService $sales,
        TelegramClient $telegram,
    ): void {
        $from = $callback['from'] ?? [];
        $data = (string) ($callback['data'] ?? '');
        $chat = $callback['message']['chat'] ?? [];
        $callbackId = (string) ($callback['id'] ?? '');
        if (($chat['type'] ?? null) !== 'private'
            || ! isset($from['id'])
            || (string) ($chat['id'] ?? '') !== (string) $from['id']
            || ! preg_match('/^(confirm|cancel)_sale:([0-9a-f-]{36})$/i', $data, $matches)) {
            $this->respondToCallback(
                $telegram,
                $callbackId,
                isset($from['id']) ? new AiChannelLink(['channel' => 'telegram', 'external_id' => (string) $from['id']]) : null,
                __('That action is not available.'),
                __('That action is not available.'),
            );

            return;
        }

        $userId = (string) $from['id'];
        $link = AiChannelLink::query()->where('channel', 'telegram')->where('external_id', $userId)->first();
        if (! $link) {
            $this->respondToCallback(
                $telegram,
                $callbackId,
                new AiChannelLink(['channel' => 'telegram', 'external_id' => $userId]),
                __('Workspace access is no longer active.'),
                __('Workspace access is no longer active.'),
            );

            return;
        }

        $tenant = Tenant::query()->find($link->tenant_id);
        $user = User::query()->find($link->user_id);
        if (! $tenant || ! $user || ! $user->membershipIn($tenant)) {
            $this->respondToCallback(
                $telegram,
                $callbackId,
                $link,
                __('Workspace access is no longer active.'),
                __('Workspace access is no longer active.'),
            );

            return;
        }

        $context->set($tenant, $user);
        $confirmed = strtolower($matches[1]) === 'confirm';
        $failed = false;
        try {
            $result = $sales->decide($matches[2], $link, $confirmed);
        } catch (Throwable $error) {
            Log::warning('ai.telegram.confirmation_failed', ['exception' => $error::class]);
            $result = __('I could not process that request. Please try again later.');
            $failed = true;
        }
        $this->respondToCallback(
            $telegram,
            $callbackId,
            $link,
            $failed
                ? __('I could not process that request. Please try again later.')
                : ($confirmed ? __('Sale confirmation processed.') : __('Sale cancelled.')),
            $result,
        );
    }

    private function respondToCallback(
        TelegramClient $telegram,
        string $callbackId,
        ?AiChannelLink $link,
        string $callbackText,
        string $message,
    ): void {
        if ($callbackId !== '') {
            try {
                $telegram->answerCallback($callbackId, $callbackText);
            } catch (Throwable $error) {
                Log::warning('ai.telegram.callback_response_failed', ['exception' => $error::class]);
            }
        }

        if ($link) {
            try {
                $telegram->sendText($link, $message);
            } catch (Throwable $error) {
                Log::warning('ai.telegram.callback_message_failed', ['exception' => $error::class]);
            }
        }
    }

    private function reportUnexpectedFailure(Request $request, TelegramClient $telegram): void
    {
        $callback = $request->input('callback_query');
        if (is_array($callback)) {
            $from = $callback['from'] ?? [];
            $chat = $callback['message']['chat'] ?? [];
            $link = ($chat['type'] ?? null) === 'private'
                && isset($from['id'])
                && (string) ($chat['id'] ?? '') === (string) $from['id']
                    ? new AiChannelLink(['channel' => 'telegram', 'external_id' => (string) $from['id']])
                    : null;
            $this->respondToCallback(
                $telegram,
                (string) ($callback['id'] ?? ''),
                $link,
                __('I could not process that request. Please try again later.'),
                __('I could not process that request. Please try again later.'),
            );

            return;
        }

        $message = $request->input('message');
        if (! is_array($message)) {
            return;
        }

        $chat = $message['chat'] ?? [];
        $from = $message['from'] ?? [];
        if (($chat['type'] ?? null) !== 'private'
            || ! isset($from['id'])
            || (string) ($chat['id'] ?? '') !== (string) $from['id']) {
            return;
        }

        $this->respondToCallback(
            $telegram,
            '',
            new AiChannelLink(['channel' => 'telegram', 'external_id' => (string) $from['id']]),
            '',
            __('I could not process that request. Please try again later.'),
        );
    }

    private function sendUnlinked(string $telegramUserId, string $message): void
    {
        $link = new AiChannelLink(['channel' => 'telegram', 'external_id' => $telegramUserId]);
        app(TelegramClient::class)->sendText($link, $message);
    }
}
