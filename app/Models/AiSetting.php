<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiSetting extends Model
{
    public const PROVIDERS = ['openai', 'gemini', 'ollama'];

    protected $guarded = [];

    protected $casts = [
        'openai_api_key' => 'encrypted',
        'gemini_api_key' => 'encrypted',
        'telegram_bot_token' => 'encrypted',
        'telegram_webhook_secret' => 'encrypted',
    ];

    public static function current(): self
    {
        $settings = static::query()->firstOrCreate(['id' => 1]);

        return $settings->wasRecentlyCreated ? $settings->refresh() : $settings;
    }

    public function apiKeyFor(string $provider): ?string
    {
        return match ($provider) {
            'openai' => $this->openai_api_key,
            'gemini' => $this->gemini_api_key,
            default => null,
        };
    }
}
