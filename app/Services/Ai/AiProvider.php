<?php

namespace App\Services\Ai;

interface AiProvider
{
    /**
     * @param array<int, array<string, mixed>> $messages Normalized role/content/tool-call messages.
     * @param array<int, array<string, mixed>> $tools Normalized function definitions.
     */
    public function complete(array $messages, array $tools): AiProviderResponse;
}
