<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Pure, deterministic live-shadow equivalence plan for simplification candidates.
 *
 * A simplification candidate (deletion, merge, replacement) must prove behavioral
 * equivalence through a live shadow run before it can be promoted. This plan
 * evaluates the shadow run evidence and returns promote_ready=true only when:
 *   - minimum sample count is met
 *   - equivalence is proven (zero divergences or within tolerance)
 *   - runtime evidence is present
 *   - rollback observations are documented
 *
 * Pure: no I/O, no provider calls, no side effects.
 */
final class AtlasSelfConstructionSimplificationLiveShadowPlan
{
    public const SCHEMA = 'atlas.self_construction.simplification.live_shadow_plan.v1';

    public const DECISION_PROMOTE = 'promote';
    public const DECISION_SHADOW_MORE = 'shadow_more';
    public const DECISION_BLOCKED = 'blocked';

    public const DEFAULT_MIN_SAMPLES = 100;
    public const DEFAULT_MAX_DIVERGENCES = 0;

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    public function evaluate(array $candidate): array
    {
        $candidateId = (string) ($candidate['candidate_id'] ?? '');
        $kind = (string) ($candidate['kind'] ?? 'deletion');
        $samplesCompared = (int) ($candidate['samples_compared'] ?? 0);
        $divergences = (int) ($candidate['divergences'] ?? 0);
        $minSamples = (int) ($candidate['min_samples'] ?? self::DEFAULT_MIN_SAMPLES);
        $maxDivergences = (int) ($candidate['max_divergences'] ?? self::DEFAULT_MAX_DIVERGENCES);
        $runtimeEvidence = $candidate['runtime_evidence'] ?? null;
        $rollbackObservations = $candidate['rollback_observations'] ?? null;

        $evidenceGaps = [];
        $blockers = [];

        // Check minimum sample count
        if ($samplesCompared < $minSamples) {
            $evidenceGaps[] = 'below_minimum_samples:'.$samplesCompared.'/'.$minSamples;
        }

        // Check equivalence
        if ($divergences > $maxDivergences) {
            $evidenceGaps[] = 'divergences_exceed_tolerance:'.$divergences.'/'.$maxDivergences;
            $blockers[] = 'equivalence_not_proven';
        }

        // Check runtime evidence
        $hasRuntimeEvidence = is_array($runtimeEvidence)
            ? $runtimeEvidence !== []
            : (bool) $runtimeEvidence;
        if (! $hasRuntimeEvidence) {
            $evidenceGaps[] = 'missing_runtime_evidence';
            $blockers[] = 'runtime_evidence_required';
        }

        // Check rollback observations
        $hasRollbackObservations = is_array($rollbackObservations)
            ? $rollbackObservations !== []
            : (bool) $rollbackObservations;
        if (! $hasRollbackObservations) {
            $evidenceGaps[] = 'missing_rollback_observations';
            $blockers[] = 'rollback_observations_required';
        }

        // Decision: blocked if any hard blocker, shadow_more if below samples, promote if all pass
        $decision = match (true) {
            $blockers !== [] => self::DECISION_BLOCKED,
            $samplesCompared < $minSamples => self::DECISION_SHADOW_MORE,
            default => self::DECISION_PROMOTE,
        };

        $promoteReady = $decision === self::DECISION_PROMOTE;

        return [
            'schema' => self::SCHEMA,
            'candidate_id' => $candidateId,
            'kind' => $kind,
            'decision' => $decision,
            'promote_ready' => $promoteReady,
            'samples_compared' => $samplesCompared,
            'min_samples' => $minSamples,
            'divergences' => $divergences,
            'max_divergences' => $maxDivergences,
            'evidence_gaps' => $evidenceGaps,
            'blockers' => $blockers,
            'runtime_evidence_present' => $hasRuntimeEvidence,
            'rollback_observations_present' => $hasRollbackObservations,
            'rollback_observations' => $hasRollbackObservations ? $rollbackObservations : null,
        ];
    }
}
