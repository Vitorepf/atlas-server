<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Orders simplification candidates into small, reversible waves: one wave per
 * canonical layer, candidates within a wave sorted by dependency risk then
 * consumer count so low-risk high-proof work is scheduled first. Candidates
 * without proof readiness are held back as blocked_candidates rather than
 * folded into a single dangerous refactor.
 */
final class AtlasSelfConstructionLayerConsolidationWavePlanner
{
    private const CANONICAL_LAYER_ORDER = ['domain', 'application', 'infrastructure', 'presentation'];

    /**
     * @param  array<int,array<string,mixed>>  $candidates
     * @return array<string,mixed>
     */
    public function plan(array $candidates): array
    {
        $byLayer = [];
        foreach ($candidates as $candidate) {
            $layer = (string) ($candidate['layer'] ?? 'unknown');
            $byLayer[$layer][] = $candidate;
        }

        $layers = array_keys($byLayer);
        usort($layers, fn (string $a, string $b): int => $this->layerRank($a) <=> $this->layerRank($b) ?: $a <=> $b);

        $waves = [];
        foreach ($layers as $layer) {
            $group = $byLayer[$layer];
            usort(
                $group,
                fn (array $a, array $b): int => ((int) ($a['dependency_risk'] ?? PHP_INT_MAX)) <=> ((int) ($b['dependency_risk'] ?? PHP_INT_MAX))
                    ?: ((int) ($a['consumer_count'] ?? PHP_INT_MAX)) <=> ((int) ($b['consumer_count'] ?? PHP_INT_MAX)),
            );

            $candidateIds = [];
            $blockedCandidates = [];
            foreach ($group as $candidate) {
                $id = (string) ($candidate['id'] ?? '');
                if ((bool) ($candidate['proof_ready'] ?? false)) {
                    $candidateIds[] = $id;

                    continue;
                }
                $blockedCandidates[] = [
                    'id' => $id,
                    'reason' => (string) ($candidate['missing_proof'] ?? 'proof_not_ready'),
                ];
            }

            $waves[] = [
                'wave_id' => 'wave_'.$layer,
                'layer' => $layer,
                'candidate_ids' => $candidateIds,
                'blocked_candidates' => $blockedCandidates,
                'next_required_proof' => $blockedCandidates === [] ? null : $blockedCandidates[0]['reason'],
            ];
        }

        return ['waves' => $waves];
    }

    private function layerRank(string $layer): int
    {
        $index = array_search($layer, self::CANONICAL_LAYER_ORDER, true);

        return $index === false ? PHP_INT_MAX : $index;
    }
}
