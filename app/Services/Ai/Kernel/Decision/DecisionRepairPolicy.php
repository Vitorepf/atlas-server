<?php

namespace App\Services\Ai\Kernel\Decision;

final readonly class DecisionRepairPolicy
{
    /**
     * @param  array<string,mixed>  $values
     */
    public function __construct(
        public bool $enabled,
        public int $maxAttempts,
        public array $values = [],
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            enabled: (bool) ($payload['enabled'] ?? false),
            maxAttempts: max(0, (int) ($payload['max_attempts'] ?? 0)),
            values: $payload,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array_merge($this->values, [
            'enabled' => $this->enabled,
            'max_attempts' => $this->maxAttempts,
        ]);
    }
}
