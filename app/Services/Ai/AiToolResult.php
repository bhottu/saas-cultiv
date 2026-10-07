<?php

namespace App\Services\Ai;

use App\Models\AiPendingAction;

final class AiToolResult
{
    public function __construct(
        public readonly string $content,
        public readonly ?AiPendingAction $pendingAction = null,
    ) {}
}
