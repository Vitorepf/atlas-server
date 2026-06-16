<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Models\AiCodebaseWorldModel;
use App\Models\AiCodebaseWorldModelEdge;
use App\Models\AiCodebaseWorldModelNode;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Throwable;

/**
 * ACDE lever B4a — DE-ORPHAN the blast-radius brain onto the live loop window.
 *
 * The weak engine edits a file blind to WHO depends on it. {@see AtlasLoopBlastRadiusAnalyzer} computes that
 * impact set (reverse-dependency BFS + risk band) but was a pure keystone with no real edge source. This reader
 * supplies the missing source: it resolves the target file to its code-graph node(s) in the latest world model
 * (AP-815), feeds the analyzer a `consumersOf` callable backed by {@see CodeGraphAdjacencyIndex}'s `incoming`
 * semantics (here: a bounded reverse-edge query), and TRANSLATES the impacted node ids back to repo-relative
 * file PATHS so the driver can inject "these consume you — keep them green".
 *
 * PROVIDER-SAFE: returns only repo-relative PATHS + a coarse risk band — never raw code, never a self-report,
 * never engine-vs-engine. A read over an index that already exists (no new table, no write, no merge-path
 * touch — the same shape as B3). Flag-gated default-OFF => empty => byte-identical; every DB touch is guarded
 * (a DB-less / un-indexed caller degrades to empty, never throws).
 *
 * World-model selection is the most-recently-updated model (the loop runs against one primary indexed
 * workspace locally). Default-OFF means the operator only arms this once the index is fresh for their
 * workspace; a stale or foreign model simply yields no path match => empty (it can never inject a wrong file).
 */
final class AtlasLoopBlastRadiusReader
{
    /**
     * Impact summary for the file being edited, or [] when OFF / no DB / un-indexed / no consumers.
     *
     * @return array{consumer_count:int, risk:string, consumers:list<string>, truncated:bool}|array{}
     */
    public function read(string $targetPath, int $maxDepth = 1, int $maxNodes = 60): array
    {
        if (! (bool) config('atlas.loop.blast_radius_brain_enabled', false)) {
            return [];
        }
        if (! DatabaseTableAvailability::all([
            'ai_codebase_world_models',
            'ai_codebase_world_model_nodes',
            'ai_codebase_world_model_edges',
        ])) {
            return [];
        }
        $targetPath = trim($targetPath);
        if ($targetPath === '') {
            return [];
        }
        $maxDepth = max(1, $maxDepth);
        $maxNodes = max(1, $maxNodes);

        try {
            $modelId = AiCodebaseWorldModel::query()->orderByDesc('updated_at')->value('id');
            if ($modelId === null) {
                return [];
            }

            $targetNodeIds = AiCodebaseWorldModelNode::query()
                ->where('world_model_id', $modelId)
                ->where('path', $targetPath)
                ->limit(50)
                ->pluck('node_id')
                ->map(static fn ($id): string => trim((string) $id))
                ->filter(static fn (string $id): bool => $id !== '')
                ->values()
                ->all();
            if ($targetNodeIds === []) {
                return [];
            }

            // Reverse-dependency edge query, bounded per node — the analyzer caps total nodes walked, so the
            // number of these queries is bounded by $maxNodes. Mirrors CodeGraphAdjacencyIndex::incoming().
            $consumersOf = function (string $nodeId) use ($modelId): array {
                return AiCodebaseWorldModelEdge::query()
                    ->where('world_model_id', $modelId)
                    ->where('to_node_id', $nodeId)
                    ->limit(200)
                    ->pluck('from_node_id')
                    ->map(static fn ($id): array => ['to' => (string) $id])
                    ->all();
            };

            $analyzer = new AtlasLoopBlastRadiusAnalyzer;
            $blastNodeIds = [];
            $worstScore = 0.0;
            $truncated = false;
            foreach ($targetNodeIds as $seed) {
                $result = $analyzer->analyze($seed, $consumersOf, $maxDepth, $maxNodes);
                foreach ($result['blast_radius'] as $consumerNodeId) {
                    $blastNodeIds[(string) $consumerNodeId] = true;
                }
                $worstScore = max($worstScore, (float) $result['risk_score']);
                $truncated = $truncated || (bool) $result['truncated'];
            }
            // The target's own symbol nodes are never their own consumers.
            foreach ($targetNodeIds as $seed) {
                unset($blastNodeIds[$seed]);
            }
            if ($blastNodeIds === []) {
                return [];
            }

            // Translate impacted node ids -> distinct repo paths (provider-safe), excluding the edited file.
            $paths = AiCodebaseWorldModelNode::query()
                ->where('world_model_id', $modelId)
                ->whereIn('node_id', array_keys($blastNodeIds))
                ->pluck('path')
                ->map(static fn ($p): string => trim((string) $p))
                ->filter(static fn (string $p): bool => $p !== '' && $p !== $targetPath)
                ->unique()
                ->values()
                ->all();
            if ($paths === []) {
                return [];
            }

            sort($paths, SORT_NATURAL | SORT_FLAG_CASE);
            $risk = match (true) {
                $truncated || $worstScore >= 0.75 => 'critical',
                $worstScore >= 0.4 => 'high',
                $worstScore >= 0.15 => 'medium',
                default => 'low',
            };

            return [
                'consumer_count' => count($paths),
                'risk' => $risk,
                'consumers' => array_slice($paths, 0, 12),
                'truncated' => $truncated,
            ];
        } catch (Throwable) {
            return [];
        }
    }
}
