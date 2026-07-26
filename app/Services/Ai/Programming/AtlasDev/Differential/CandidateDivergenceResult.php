<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Differential;

/**
 * E4 -- Pure comparison result produced by
 * {@see DifferentialTestingService::compare()}.
 *
 * A pure value object: it carries what the comparison decided (agreement,
 * divergence, or a skip), never how to apply the verdict. The gate
 * ({@see CandidateDivergenceGate}) reads this result and routes through the
 * sanctioned channels.
 *
 * Fields:
 *   - $agreed:         true iff ALL candidates produced identical diffs
 *                       (high confidence). Mutually exclusive with $diverged.
 *   - $diverged:       true iff at least one candidate's diff differs from
 *                       the others. Mutually exclusive with $agreed.
 *   - $isSkipped:      true iff fewer than 2 candidates were compared
 *                       (nothing to diff). Mutually exclusive with both.
 *   - $skipReason:     non-empty when $isSkipped is true.
 *   - $divergentDiffs: list of {index, diff} entries carrying every
 *                       candidate's diff text as evidence when $diverged.
 *                       Empty on agreement or skip.
 *   - $candidateCount: the number of candidates compared.
 *
 * Canonical: mission architecture.md (Atlas Dev Elevation v2, M5 / E4,
 * e4-differential-candidates feature).
 */
final class CandidateDivergenceResult
{
    /**
     * @param  list<array{index: int, diff: string}>  $divergentDiffs
     */
    public function __construct(
        public readonly bool $agreed,
        public readonly bool $diverged,
        public readonly bool $isSkipped,
        public readonly string $skipReason,
        public readonly array $divergentDiffs,
        public readonly int $candidateCount,
    ) {}

    /**
     * All candidates produced identical diffs (high confidence).
     */
    public static function agreement(int $candidateCount): self
    {
        return new self(
            agreed: true,
            diverged: false,
            isSkipped: false,
            skipReason: '',
            divergentDiffs: [],
            candidateCount: $candidateCount,
        );
    }

    /**
     * At least one candidate's diff differs. Carries every candidate's diff
     * as evidence.
     *
     * @param  list<array{index: int, diff: string}>  $divergentDiffs
     */
    public static function divergence(array $divergentDiffs): self
    {
        return new self(
            agreed: false,
            diverged: true,
            isSkipped: false,
            skipReason: '',
            divergentDiffs: $divergentDiffs,
            candidateCount: count($divergentDiffs),
        );
    }

    /**
     * Fewer than 2 candidates: nothing to compare. Never trips.
     */
    public static function skipped(string $reason): self
    {
        return new self(
            agreed: false,
            diverged: false,
            isSkipped: true,
            skipReason: $reason,
            divergentDiffs: [],
            candidateCount: 0,
        );
    }
}
