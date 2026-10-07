<?php

namespace App\Services\Ai;

final class AiProviderResponse
{
    /**
     * @param array<int, array{id:string,name:string,arguments:array<string,mixed>}> $toolCalls
     */
    public function __construct(
        public readonly ?string $text,
        public readonly array $toolCalls = [],
    ) {}
}
