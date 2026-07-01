<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Proof-backed indirection invariant gate: AI-generated code often grows tiny indirection layers
 * that add no real value. This advisor recommends collapsing one ONLY when it is simultaneously
 * non-public, has exactly one consumer, is covered by tests, and proven behavior-equivalent to
 * what would remain after collapse. Any public API, multi-consumer (or zero-consumer) indirection,
 * missing test coverage, or unproven behavior equivalence holds the recommendation and names the
 * exact proof still required — collapse is never approved by a generic "looks like bloat" heuristic.
 *
 * Input shape:
 *   { candidate: {
 *       is_public?:            bool,
 *       consumer_count?:       int,
 *       covered_by_tests?:     bool,
 *       behavior_equivalent?:  bool,
 *   } }
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainIndirectionCollapseAdvisor
{
    public const SCHEMA = 'atlas.self_construction.external_brain.indirection_collapse_advisor.v1';

    public const RECOMMENDATION_COLLAPSE = 'collapse';

    public const RECOMMENDATION_HOLD = 'hold';

    /**
     * @param  array{candidate?: array<string,mixed>}  $facts
     * @return array{schema:string, recommendation:string, collapse_approved:bool, required_proof:list<string>}
     */
    public function advise(array $facts): array
    {
        $candidate = is_array($facts['candidate'] ?? null) ? $facts['candidate'] : [];

        $isPublic = (bool) ($candidate['is_public'] ?? false);
        $consumerCount = (int) ($candidate['consumer_count'] ?? 0);
        $covered = (bool) ($candidate['covered_by_tests'] ?? false);
        $behaviorEquivalent = (bool) ($candidate['behavior_equivalent'] ?? false);

        $requiredProof = [];
        if ($isPublic) {
            $requiredProof[] = 'public_api_requires_explicit_deprecation_proof';
        }
        if ($consumerCount > 1) {
            $requiredProof[] = 'multi_consumer_indirection_requires_all_consumer_migration_proof';
        }
        if ($consumerCount === 0) {
            $requiredProof[] = 'zero_consumer_indirection_requires_dead_code_proof_not_collapse_advice';
        }
        if (! $covered) {
            $requiredProof[] = 'missing_test_coverage_proof';
        }
        if (! $behaviorEquivalent) {
            $requiredProof[] = 'missing_behavior_equivalence_proof';
        }

        $recommendation = $requiredProof === [] ? self::RECOMMENDATION_COLLAPSE : self::RECOMMENDATION_HOLD;

        return [
            'schema' => self::SCHEMA,
            'recommendation' => $recommendation,
            'collapse_approved' => $recommendation === self::RECOMMENDATION_COLLAPSE,
            'required_proof' => $requiredProof,
        ];
    }
}
