<?php

namespace App\Exceptions;

class SubscriptionLimitException extends \Symfony\Component\HttpKernel\Exception\HttpException
{
    public function __construct(
        public readonly string $resource,
        public readonly ?int $current,
        public readonly ?int $limit,
        public readonly string $planName,
        public readonly string $field = 'general',
        public readonly ?string $requiredPlans = null,
    ) {
        parent::__construct($this->requiredPlans !== null ? 403 : 429, $this->buildMessage());
    }

    private function buildMessage(): string
    {
        if ($this->requiredPlans !== null) {
            return "{$this->resource} are available on {$this->requiredPlans} plans. Upgrade your plan to continue.";
        }

        $limit = number_format((int) $this->limit);

        return match ($this->resource) {
            'Workspace' => "Workspace limit reached.\n\nYour {$this->planName} plan includes {$limit} workspace. Upgrade your plan to create additional workspaces.",
            'User' => "User limit reached.\n\nYour {$this->planName} plan includes {$limit} user. Upgrade your plan to invite additional users.",
            'Product' => "Product limit reached.\n\nYour {$this->planName} plan allows up to {$limit} products. Upgrade your plan to add unlimited products.",
            default => "{$this->resource} limit reached for the {$this->planName} plan ({$limit}). Upgrade your plan to continue.",
        };
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return [
            'resource' => $this->resource,
            'current' => $this->current,
            'limit' => $this->limit,
            'plan' => $this->planName,
            'required_plans' => $this->requiredPlans,
            'message' => $this->getMessage(),
        ];
    }
}