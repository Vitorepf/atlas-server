<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Retry;

/**
 * Immutable FACT row about a reshape pattern. NO score, NO rank — just counts and the structural
 * delta that defined the pattern fingerprint.
 */
final class ReshapePatternFact
{
    /**
     * @param  array{forbidden_removed:list<string>, anchors_added:list<string>, scope_widened_bool:bool}  $structuralDelta
     */
    public function __construct(
        public readonly string $reshapePatternFingerprint,
        public readonly array $structuralDelta,
        public readonly int $observedAttempts,
        public readonly int $observedSuccesses,
        public readonly int $observedFailures,
        public readonly int $lastSeenSeq,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'last_seen_seq' => $this->lastSeenSeq,
            'observed_attempts' => $this->observedAttempts,
            'observed_failures' => $this->observedFailures,
            'observed_successes' => $this->observedSuccesses,
            'reshape_pattern_fingerprint' => $this->reshapePatternFingerprint,
            'structural_delta' => $this->structuralDelta,
        ];
    }
}
