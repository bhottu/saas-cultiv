<?php

namespace App\Services\Ai;

use RuntimeException;

class TelegramApiException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly bool $parseError,
    ) {
        parent::__construct("Telegram request failed with HTTP {$httpStatus}.");
    }
}
