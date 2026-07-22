<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Recovery;

/**
 * Immutable result of {@see AtlasLoopRestoreVerifier::verify()}.
 */
final class AtlasLoopRestoreVerificationResult
{
    /**
     * @param  list<string>  $mismatchedKeys
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $actualStateHash,
        public readonly string $expectedStateHash,
        public readonly array $mismatchedKeys,
        public readonly int $replayedEventCount,
        public readonly string $reason = '',
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'actual_state_hash' => $this->actualStateHash,
            'expected_state_hash' => $this->expectedStateHash,
            'mismatched_keys' => $this->mismatchedKeys,
            'replayed_event_count' => $this->replayedEventCount,
            'reason' => $this->reason,
        ];
    }
}
