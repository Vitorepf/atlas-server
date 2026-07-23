<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Semantic dedupe gate for the simplification originator: candidates that overlap in target
 * files, proof scope, or capability impact compete for the same shared-main real estate and
 * proof work — admitting all of them wastes muscle time and risks conflicting edits. This gate
 * clusters candidates by STRUCTURED overlap (never a bare name/string dedupe) and admits only
 * the highest-leverage candidate per cluster.
 *
 * Overlap signals (any shared value merges two candidates into one cluster, same union pattern
 * as {@see AtlasExternalBrainDeepModuleFinder}):
 *   - target_files:       candidate['target_files']
 *   - proof_scope:        candidate['proof_scope']
 *   - capability_impact:  candidate['capability_impact']
 *
 * Within a cluster, the candidate with the highest leverage_score is kept; every other candidate
 * in that cluster is rejected as a lower-value duplicate, with the kept candidate named. A
 * candidate that shares no signal with anyone (a true singleton) is always admitted regardless
 * of its leverage score — distinctness alone is not a reason to reject.
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainConsolidationCandidateDeduper
{
    public const SCHEMA = 'atlas.external_brain.consolidation_candidate_deduper.v1';

    /**
     * @param  array{candidates?: list<array<string,mixed>>}  $facts
     * @return array{schema:string, admitted:list<string>, rejected:list<array<string,mixed>>}
     */
    public function dedupe(array $facts): array
    {
        $candidates = (array) ($facts['candidates'] ?? []);

        $keyToIds = [];
        $leverageById = [];
        $order = [];

        foreach ($candidates as $candidate) {
            $id = (string) ($candidate['candidate_id'] ?? 'unknown');
            $order[] = $id;
            $leverageById[$id] = max(0.0, min(1.0, (float) ($candidate['leverage_score'] ?? 0.0)));

            foreach ((array) ($candidate['target_files'] ?? []) as $file) {
                $keyToIds['target_file:'.(string) $file][] = $id;
            }
            foreach ((array) ($candidate['proof_scope'] ?? []) as $scope) {
                $keyToIds['proof_scope:'.(string) $scope][] = $id;
            }
            foreach ((array) ($candidate['capability_impact'] ?? []) as $capability) {
                $keyToIds['capability_impact:'.(string) $capability][] = $id;
            }
        }

        // Union candidates sharing at least one overlap key into clusters.
        $clusters = []; // clusterKey => list<id>
        $assigned = []; // id => clusterKey

        foreach ($keyToIds as $ids) {
            $ids = array_values(array_unique($ids));
            if (count($ids) < 2) {
                continue;
            }

            $clusterKey = null;
            foreach ($ids as $id) {
                if (isset($assigned[$id])) {
                    $clusterKey = $assigned[$id];
                    break;
                }
            }
            if ($clusterKey === null) {
                $clusterKey = implode('|', $ids);
                $clusters[$clusterKey] = [];
            }

            foreach ($ids as $id) {
                if (! in_array($id, $clusters[$clusterKey], true)) {
                    $clusters[$clusterKey][] = $id;
                    $assigned[$id] = $clusterKey;
                }
            }
        }

        $admitted = [];
        $rejected = [];

        foreach ($clusters as $clusterIds) {
            $keptId = $this->highestLeverageId($clusterIds, $leverageById);
            $admitted[] = $keptId;

            foreach ($clusterIds as $id) {
                if ($id === $keptId) {
                    continue;
                }
                $rejected[] = [
                    'candidate_id' => $id,
                    'reason' => 'lower_leverage_duplicate_in_overlap_cluster',
                    'kept_candidate_id' => $keptId,
                    'overlaps_with' => array_values(array_diff($clusterIds, [$id])),
                ];
            }
        }

        foreach ($order as $id) {
            if (! isset($assigned[$id])) {
                $admitted[] = $id;
            }
        }

        $admitted = array_values(array_unique($admitted));
        sort($admitted, SORT_STRING);
        usort($rejected, static fn (array $a, array $b): int => $a['candidate_id'] <=> $b['candidate_id']);

        return [
            'schema' => self::SCHEMA,
            'admitted' => $admitted,
            'rejected' => $rejected,
        ];
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string,float>  $leverageById
     */
    private function highestLeverageId(array $ids, array $leverageById): string
    {
        $sorted = $ids;
        usort($sorted, static function (string $a, string $b) use ($leverageById): int {
            $scoreA = $leverageById[$a] ?? 0.0;
            $scoreB = $leverageById[$b] ?? 0.0;

            return $scoreB <=> $scoreA ?: $a <=> $b; // tie-break: lower candidate_id wins, deterministic.
        });

        return $sorted[0];
    }
}
