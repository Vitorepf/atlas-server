<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Orders simplification candidates into small, reversible waves: one wave per
 * canonical layer, candidates within a wave sorted by dependency risk then
 * consumer count so low-risk high-proof work is scheduled first. Candidates
 * without proof readiness are held back as blocked_candidates rather than
 * folded into a single dangerous refactor.
 *
 * WAVE SIZE SAFETY (options): a layer's ready candidates are further split into
 * multiple sequential sub-waves (wave_{layer}_1, wave_{layer}_2, ...) whenever
 * max_wave_size (candidate count) or risk_budget (cumulative dependency_risk)
 * would otherwise be exceeded — a heavy refactor becomes several small,
 * independently reversible batches instead of one broad, all-or-nothing merge.
 * A single candidate whose own risk already exceeds risk_budget is still
 * admitted alone (never dropped) but starts a fresh wave immediately after.
 * blocked_candidates and next_required_proof always ride on the LAST sub-wave
 * of their layer. With no options supplied, output is byte-identical to the
 * unsplit one-wave-per-layer behavior.
 */
final class AtlasSelfConstructionLayerConsolidationWavePlanner
{
    private const CANONICAL_LAYER_ORDER = ['domain', 'application', 'infrastructure', 'presentation'];

    /**
     * @param  array<int,array<string,mixed>>  $candidates
     * @param  array{max_wave_size?:int, risk_budget?:int}  $options
     * @return array<string,mixed>
     */
    public function plan(array $candidates, array $options = []): array
    {
        $maxWaveSize = max(1, (int) ($options['max_wave_size'] ?? PHP_INT_MAX));
        $riskBudget = max(1, (int) ($options['risk_budget'] ?? PHP_INT_MAX));

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

            $readyIds = [];
            $blockedCandidates = [];
            foreach ($group as $candidate) {
                $id = (string) ($candidate['id'] ?? '');
                if ((bool) ($candidate['proof_ready'] ?? false)) {
                    $readyIds[] = ['id' => $id, 'risk' => (int) ($candidate['dependency_risk'] ?? 0)];

                    continue;
                }
                $blockedCandidates[] = [
                    'id' => $id,
                    'reason' => (string) ($candidate['missing_proof'] ?? 'proof_not_ready'),
                ];
            }

            $subWaves = $this->splitByBudget($readyIds, $maxWaveSize, $riskBudget);
            if ($subWaves === []) {
                $subWaves = [[]]; // still emit one wave to carry blocked_candidates
            }

            $lastIndex = count($subWaves) - 1;
            foreach ($subWaves as $index => $candidateIds) {
                $isLast = $index === $lastIndex;
                $wave = [
                    'wave_id' => count($subWaves) > 1 ? 'wave_'.$layer.'_'.($index + 1) : 'wave_'.$layer,
                    'layer' => $layer,
                    'candidate_ids' => $candidateIds,
                    'blocked_candidates' => $isLast ? $blockedCandidates : [],
                    'next_required_proof' => $isLast && $blockedCandidates !== [] ? $blockedCandidates[0]['reason'] : null,
                ];
                $waves[] = $wave;
            }
        }

        return ['waves' => $waves];
    }

    /**
     * Greedy bin-packing: a fresh sub-wave always accepts its first candidate (never drops one for
     * being too risky alone); every candidate after that only joins if it stays within both bounds.
     *
     * @param  list<array{id:string, risk:int}>  $readyIds
     * @return list<list<string>>
     */
    private function splitByBudget(array $readyIds, int $maxWaveSize, int $riskBudget): array
    {
        $subWaves = [];
        $current = [];
        $currentRisk = 0;

        foreach ($readyIds as $entry) {
            $wouldExceedSize = count($current) >= $maxWaveSize;
            $wouldExceedBudget = $current !== [] && $currentRisk + $entry['risk'] > $riskBudget;
            if ($current !== [] && ($wouldExceedSize || $wouldExceedBudget)) {
                $subWaves[] = $current;
                $current = [];
                $currentRisk = 0;
            }
            $current[] = $entry['id'];
            $currentRisk += $entry['risk'];
        }

        if ($current !== []) {
            $subWaves[] = $current;
        }

        return $subWaves;
    }

    private function layerRank(string $layer): int
    {
        $index = array_search($layer, self::CANONICAL_LAYER_ORDER, true);

        return $index === false ? PHP_INT_MAX : $index;
    }
}
