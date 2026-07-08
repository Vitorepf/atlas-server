<?php

declare(strict_types=1);

namespace App\Services\Ai\Context\ValueObjects;

/**
 * Binary gate verdict from AUCRI policies (internal to ContextRuntime).
 */
final readonly class GateVerdict
{
    public function __construct(
        public string $status,
        public array $blockers,
        public array $audit,
    ) {}

    public function passed(): bool
    {
        return $this->status === 'passed';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromEnforcement(array $payload): self
    {
        return new self(
            status: (string) ($payload['status'] ?? 'blocked'),
            blockers: is_array($payload['blockers'] ?? null) ? $payload['blockers'] : [],
            audit: $payload,
        );
    }
}
