<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Loads and normalizes the OpenRouter model catalog from GET /api/v1/models.
 *
 * The list is deliberately NOT hard-coded: it always reflects what OpenRouter currently
 * serves. Each model is reduced to the handful of attributes the admin needs to choose a
 * good model for Cultiv AI, then sorted so the best options surface first:
 *
 *   1. free  + tool calling
 *   2. free
 *   3. paid + tool calling
 *   4. everything else
 *
 * "free" comes from the model id suffix `:free` OR pricing OpenRouter reports as genuinely
 * zero for both prompt and completion (missing pricing never counts as free). "tool
 * calling" is only asserted when OpenRouter's own `supported_parameters` says the model
 * accepts `tools`/`tool_choice`/`functions` — we never guess.
 *
 * The catalog itself is global (not credential-specific), so it is cached under a single
 * key; the API key is used only to authenticate the fetch and is never part of the cache
 * key. Only successful fetches are cached, so a transient failure never poisons the list.
 */
class OpenRouterModelCatalog
{
    private const CACHE_KEY = 'openrouter.ai.models.v1';

    /** Fresh enough for a model list, short enough to pick up new models the same day. */
    private const CACHE_TTL_HOURS = 6;

    /**
     * @return array<int, array{id:string,name:string,free:bool,tool_calling:bool,context_length:int|null}>
     */
    public function models(bool $refresh = false): array
    {
        if (! $refresh) {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached) && $cached !== []) {
                return $cached;
            }
            // A cached empty list means a previous attempt observed zero models — treat it
            // as a miss and try the network again instead of serving an empty dropdown.
            if (is_array($cached)) {
                Cache::forget(self::CACHE_KEY);
            }
        }

        $models = $this->fetchAndNormalize();

        // Only successful, non-empty fetches are cached: a transient failure or an empty
        // parse must never poison the list served to the admin UI.
        if ($models !== []) {
            Cache::put(self::CACHE_KEY, $models, now()->addHours(self::CACHE_TTL_HOURS));
        }

        return $models;
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchAndNormalize(): array
    {
        $key = AiSetting::current()->apiKeyFor('openrouter');

        try {
            $request = Http::acceptJson()->connectTimeout(5)->timeout(20)->withoutRedirecting();
            // The /models endpoint is public, so a missing key still yields a usable list;
            // the key (when present) only authenticates the request.
            if ($key) {
                $request = $request->withToken($key);
            }
            $response = $request->get(OpenRouterProvider::baseUrl().'/models');
        } catch (ConnectionException $error) {
            throw new OpenRouterModelException('transport_failure', previous: $error);
        } catch (Throwable $error) {
            throw new OpenRouterModelException('request_failure', previous: $error);
        }

        if (! $response->successful()) {
            throw new OpenRouterModelException(match (true) {
                $response->status() === 401, $response->status() === 403 => 'authentication_or_access_denied',
                $response->status() === 429 => 'rate_limited_or_quota_exceeded',
                $response->status() >= 500 => 'provider_unavailable',
                default => 'http_failure',
            }, $response->status());
        }

        $data = $response->json('data');
        if (! is_array($data)) {
            throw new OpenRouterModelException('invalid_response');
        }

        $models = [];
        foreach ($data as $item) {
            if (is_array($item) && is_string($item['id'] ?? null) && $item['id'] !== '') {
                $models[] = $this->normalize($item);
            }
        }

        usort($models, fn (array $a, array $b): int => [
            $this->rank($a), $a['name'], $a['id'],
        ] <=> [
            $this->rank($b), $b['name'], $b['id'],
        ]);

        return $models;
    }

    /** @return array{id:string,name:string,free:bool,tool_calling:bool,context_length:int|null} */
    private function normalize(array $item): array
    {
        $id = (string) $item['id'];
        $name = is_string($item['name'] ?? null) && $item['name'] !== '' ? $item['name'] : $id;
        $contextLength = isset($item['context_length']) && is_numeric($item['context_length'])
            ? (int) $item['context_length']
            : null;

        return [
            'id' => $id,
            'name' => $name,
            'free' => $this->isFree($id, is_array($item['pricing'] ?? null) ? $item['pricing'] : []),
            'tool_calling' => $this->supportsToolCalling(is_array($item['supported_parameters'] ?? null) ? $item['supported_parameters'] : []),
            'context_length' => $contextLength,
        ];
    }

    /** @param array<string, mixed> $pricing */
    private function isFree(string $id, array $pricing): bool
    {
        // The `:free` suffix is OpenRouter's explicit free-tier marker and takes priority.
        if (str_ends_with($id, ':free')) {
            return true;
        }

        // Otherwise only trust pricing OpenRouter actually returned as zero for BOTH sides.
        // Absent or non-numeric pricing is never treated as free.
        $prompt = $pricing['prompt'] ?? null;
        $completion = $pricing['completion'] ?? null;

        return $prompt !== null && $completion !== null
            && is_numeric($prompt) && is_numeric($completion)
            && (float) $prompt === 0.0 && (float) $completion === 0.0;
    }

    /** @param array<int, mixed> $parameters */
    private function supportsToolCalling(array $parameters): bool
    {
        foreach (['tools', 'tool_choice', 'functions'] as $capability) {
            if (in_array($capability, $parameters, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array{id:string,name:string,free:bool,tool_calling:bool,context_length:int|null} $model */
    private function rank(array $model): int
    {
        return match (true) {
            $model['free'] && $model['tool_calling'] => 0,
            $model['free'] => 1,
            $model['tool_calling'] => 2,
            default => 3,
        };
    }
}
