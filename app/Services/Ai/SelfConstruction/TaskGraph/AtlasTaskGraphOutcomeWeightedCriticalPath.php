<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskGraph;

/**
 * Ranks task graph critical paths by real outcome learning and
 * autonomy unblock value instead of path length.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasTaskGraphOutcomeWeightedCriticalPath
{
    public const SCHEMA = 'atlas.self_construction.task_graph_outcome_weighted_critical_path.v1';

    /**
     * @param  array<int, array<string, mixed>>  $chains
     * @return array<string, mixed>
     */
    public function rank(array $chains): array
    {
        $ranked = [];

        foreach ($chains as $chain) {
            if (! is_array($chain)) {
                continue;
            }
            $id = (string) ($chain['chain_id'] ?? '');
            $outcomeLearning = (float) ($chain['outcome_learning_value'] ?? 0.0);
            $autonomyUnblock = (float) ($chain['autonomy_unblock_value'] ?? 0.0);
            $pathLength = (int) ($chain['path_length'] ?? 0);
            $hasOutcomeEvidence = (bool) ($chain['has_outcome_evidence'] ?? false);

            // Weight: outcome learning + autonomy unblock, not path length
            $weight = $outcomeLearning + $autonomyUnblock;
            if (! $hasOutcomeEvidence) {
                $weight *= 0.5; // discount chains without outcome evidence
            }

            $ranked[] = [
                'chain_id' => $id,
                'weight' => round($weight, 4),
                'outcome_learning_value' => $outcomeLearning,
                'autonomy_unblock_value' => $autonomyUnblock,
                'path_length' => $pathLength,
                'has_outcome_evidence' => $hasOutcomeEvidence,
            ];
        }

        // Sort by weight descending
        usort($ranked, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);

        // Suppress duplicate chains (same chain_id)
        $seen = [];
        $deduped = [];
        foreach ($ranked as $r) {
            if (isset($seen[$r['chain_id']])) {
                continue;
            }
            $seen[$r['chain_id']] = true;
            $deduped[] = $r;
        }

        return [
            'schema' => self::SCHEMA,
            'ranked_chains' => $deduped,
            'chain_count' => count($deduped),
            'top_chain' => $deduped[0] ?? null,
        ];
    }
}
