<?php

namespace App\Services\Ai;

use App\Models\AiChannelLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TelegramLinkService
{
    public function createCode(int $tenantId, int $userId): string
    {
        AiChannelLink::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->where('channel', 'telegram')->whereNull('external_id')->delete();

        $code = Str::upper(Str::random(8));
        AiChannelLink::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'channel' => 'telegram',
            'link_token_hash' => hash('sha256', $code),
            'link_expires_at' => now()->addMinutes(10),
        ]);

        return $code;
    }

    public function redeem(string $code, string $telegramUserId): bool
    {
        if (! preg_match('/^[A-Z0-9]{8}$/', $code)) {
            return false;
        }

        return DB::transaction(function () use ($code, $telegramUserId): bool {
            $pending = AiChannelLink::query()
                ->where('channel', 'telegram')
                ->whereNull('external_id')
                ->where('link_token_hash', hash('sha256', $code))
                ->where('link_expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $pending || AiChannelLink::query()
                ->where('channel', 'telegram')
                ->where('external_id', $telegramUserId)
                ->exists()) {
                return false;
            }

            $pending->forceFill([
                'external_id' => $telegramUserId,
                'link_token_hash' => null,
                'link_expires_at' => null,
                'linked_at' => now(),
            ])->save();

            return true;
        });
    }
}
