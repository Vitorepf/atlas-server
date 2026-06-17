<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * ARBOR-GRAFT J1 — the J objective as an explicit, READABLE artifact (scalar + gate fingerprint).
 *
 * Arbor's intake crystallizes a goal into a named contract (metric / baseline / direction / scope /
 * hard-constraints). Atlas already FREEZES an acceptance contract (commands / allowed_globs /
 * frozen_globs / metric_kind / revert_recheck) and hashes it — but it is never surfaced as one
 * readable object. AtlasLoopJObjective is a pure, immutable PROJECTION of that already-frozen contract:
 * a saturation probe, the SELECT adjuster (SEL1) and the constraints-block (CB1) can read "what is J"
 * without re-deriving it.
 *
 * FLOOR-SAFE BY CONSTRUCTION: this is read-only. It does NOT author, recompute, or weaken any
 * acceptance field, and it touches NO hash (the existing acceptance_hash already detects a weakened J —
 * dropping a command/glob/metric_kind changes it). It is never a gate; the out-of-process FrozenJudge +
 * SemanticImplementationCertifier remain the sole authority on whether a change is real.
 */
final class AtlasLoopJObjective
{
    /**
     * @param  array{frozen: bool, sealed_holdout: mixed}  $holdout
     */
    private function __construct(
        public readonly string $metricKind,
        public readonly string $metricDirection,
        public readonly ?string $metricPattern,
        public readonly string $baseline,
        public readonly bool $correctnessFloor,
        public readonly array $holdout,
        public readonly mixed $transferSlice,
        public readonly ?string $acceptanceHash,
    ) {
    }

    /**
     * Project an already-frozen acceptance contract into a readable J objective. Pure — no side effects.
     *
     * @param  array<string, mixed>  $acceptance
     */
    public static function fromAcceptance(array $acceptance): self
    {
        $metricKind = (string) ($acceptance['metric_kind'] ?? AtlasEvolutionFrozenJudge::METRIC_GATE);

        // For a pass/fail GATE the "direction" is implicitly "the spec passes"; a numeric metric carries
        // an explicit maximize/minimize. Project whatever the contract declares, defaulting honestly.
        $metricDirection = (string) ($acceptance['metric_direction']
            ?? ($metricKind === AtlasEvolutionFrozenJudge::METRIC_GATE ? 'pass' : 'maximize'));

        $metricPattern = isset($acceptance['metric_pattern']) ? (string) $acceptance['metric_pattern'] : null;

        // revert_recheck (diff_earned: revert must go RED) IS the correctness floor — a vacuous/gamed
        // change cannot earn the metric. correctnessFloor reflects the contract honestly: drop it and
        // this projection reports false, surfacing the weakening.
        $correctnessFloor = (bool) ($acceptance['revert_recheck'] ?? false)
            || ! empty($acceptance['complexity_floor']);

        $frozenGlobs = (array) ($acceptance['frozen_globs'] ?? []);
        $holdout = [
            'frozen' => $frozenGlobs !== [],
            'sealed_holdout' => $acceptance['sealed_holdout'] ?? null,
        ];

        // J2 (not yet implemented) will populate the transfer slice; null until then.
        $transferSlice = $acceptance['transfer_slice'] ?? null;

        $acceptanceHash = isset($acceptance['acceptance_hash']) ? (string) $acceptance['acceptance_hash'] : null;

        return new self(
            metricKind: $metricKind,
            metricDirection: $metricDirection,
            metricPattern: $metricPattern,
            baseline: (string) ($acceptance['baseline'] ?? 'red_on_baseline'),
            correctnessFloor: $correctnessFloor,
            holdout: $holdout,
            transferSlice: $transferSlice,
            acceptanceHash: $acceptanceHash,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'metric_kind' => $this->metricKind,
            'metric_direction' => $this->metricDirection,
            'metric_pattern' => $this->metricPattern,
            'baseline' => $this->baseline,
            'correctness_floor' => $this->correctnessFloor,
            'holdout' => $this->holdout,
            'transfer_slice' => $this->transferSlice,
            'acceptance_hash' => $this->acceptanceHash,
        ];
    }
}
