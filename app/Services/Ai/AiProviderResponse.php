<?php

namespace App\Services\Ai;

final class AiProviderResponse
{
    /**
     * @param array<int, array{id:string,name:string,arguments:array<string,mixed>}> $toolCalls
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly ?string $text,
        public readonly array $toolCalls = [],
        public readonly array $metadata = [],
    ) {}
}
