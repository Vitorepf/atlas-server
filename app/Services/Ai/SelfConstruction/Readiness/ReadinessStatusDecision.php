<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

/**
 * Immutable result of ReadinessFailClosedPolicy::decideOuterStatus.
 *
 * ARCH BLUEPRINT SelfConstructionReadiness §2.1 — every outer status carries its
 * typed violations so a `blocked` verdict is always explainable and auditable.
 */
final class ReadinessStatusDecision
{
    /**
     * @param  list<array{type: string, key: string}>  $violations
     */
    public function __construct(
        public readonly string $status,
        public readonly array $violations = [],
    ) {}

    public function isBlocked(): bool
    {
        return $this->status === ReadinessFailClosedPolicy::STATUS_BLOCKED;
    }

    /**
     * @return array{status: string, violations: list<array{type: string, key: string}>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'violations' => $this->violations,
        ];
    }
}
