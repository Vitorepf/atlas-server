<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\StrategyCouncil;

/**
 * Selects the next structural evolution candidate by combining
 * capability gap, implementation surface, proof path and queue safety.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasStrategyCouncilNextEvolutionSelector
{
    public const SCHEMA = 'atlas.self_construction.strategy_council_next_evolution_selector.v1';

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    public function select(array $candidates): array
    {
        $ranked = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $id = (string) ($candidate['id'] ?? '');
            $capabilityGap = (float) ($candidate['capability_gap'] ?? 0.0);
            $implementationSurface = (float) ($candidate['implementation_surface'] ?? 0.0);
            $proofPath = (float) ($candidate['proof_path_score'] ?? 0.0);
            $queueSafety = (float) ($candidate['queue_safety'] ?? 0.0);

            $score = ($capabilityGap * 0.4) + ($implementationSurface * 0.2) + ($proofPath * 0.2) + ($queueSafety * 0.2);

            $ranked[] = [
                'id' => $id,
                'score' => round($score, 4),
                'capability_gap' => $capabilityGap,
                'implementation_surface' => $implementationSurface,
                'proof_path_score' => $proofPath,
                'queue_safety' => $queueSafety,
                'capability_reason' => 'capability_gap:'.$capabilityGap,
                'target_surface_reason' => 'implementation_surface:'.$implementationSurface,
                'acceptance_proof_reason' => 'proof_path:'.$proofPath,
                'collision_safety_reason' => 'queue_safety:'.$queueSafety,
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $selected = $ranked[0] ?? null;

        return [
            'schema' => self::SCHEMA,
            'selected' => $selected,
            'ranked_candidates' => $ranked,
            'candidate_count' => count($ranked),
        ];
    }
}
