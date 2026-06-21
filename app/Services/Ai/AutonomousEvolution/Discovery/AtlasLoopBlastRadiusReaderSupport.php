<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Models\AiCodebaseWorldModel;
use App\Models\AiCodebaseWorldModelEdge;
use App\Models\AiCodebaseWorldModelNode;

final class AtlasLoopBlastRadiusReaderSupport
{
    /**
     * @return array{consumer_count:int, risk:string, consumers:list<string>, truncated:bool}|array{}
     */
    public function readFromIndexedGraph(string $targetPath, int $maxDepth, int $maxNodes): array
    {
        $modelId = $this->latestModelId();
        if ($modelId === null) {
            return [];
        }
        $modelId = (string) $modelId;

        $targetNodeIds = $this->targetNodeIds($modelId, $targetPath);
        if ($targetNodeIds === []) {
            return [];
        }

        // Reverse-dependency edge query, bounded per node — the analyzer caps total nodes walked, so the
        // number of these queries is bounded by $maxNodes. Mirrors CodeGraphAdjacencyIndex::incoming().
        $consumersOf = fn (string $nodeId): array => $this->consumersOf($modelId, $nodeId);

        $analyzer = new AtlasLoopBlastRadiusAnalyzer;
        $blastSummary = $this->summarizeBlastRadius(
            $targetNodeIds,
            fn (string $seed): array => $analyzer->analyze($seed, $consumersOf, $maxDepth, $maxNodes),
        );
        $blastNodeIds = $blastSummary['blast_node_ids'];
        if ($blastNodeIds === []) {
            return [];
        }

        // Translate impacted node ids -> distinct repo paths (provider-safe), excluding the edited file.
        $paths = $this->distinctPaths($modelId, $blastNodeIds, $targetPath);
        if ($paths === []) {
            return [];
        }

        sort($paths, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'consumer_count' => count($paths),
            'risk' => $this->risk($blastSummary['truncated'], $blastSummary['worst_score']),
            'consumers' => array_slice($paths, 0, 12),
            'truncated' => $blastSummary['truncated'],
        ];
    }

    public function latestModelId(): mixed
    {
        return AiCodebaseWorldModel::query()->orderByDesc('updated_at')->value('id');
    }

    /**
     * @return list<string>
     */
    public function targetNodeIds(string $modelId, string $targetPath): array
    {
        return AiCodebaseWorldModelNode::query()
            ->where('world_model_id', $modelId)
            ->where('path', $targetPath)
            ->limit(50)
            ->pluck('node_id')
            ->map(static fn ($id): string => trim((string) $id))
            ->filter(static fn (string $id): bool => $id !== '')
            ->values()
            ->all();
    }

    /**
     * @return list<array{to:string}>
     */
    public function consumersOf(string $modelId, string $nodeId): array
    {
        return AiCodebaseWorldModelEdge::query()
            ->where('world_model_id', $modelId)
            ->where('to_node_id', $nodeId)
            ->limit(200)
            ->pluck('from_node_id')
            ->map(static fn ($id): array => ['to' => (string) $id])
            ->all();
    }

    /**
     * @param  list<string>  $targetNodeIds
     * @param  callable(string):array{blast_radius:list<string>, risk_score:float|int, truncated:bool}  $analyze
     * @return array{blast_node_ids:array<string, bool>, worst_score:float, truncated:bool}
     */
    public function summarizeBlastRadius(array $targetNodeIds, callable $analyze): array
    {
        $blastNodeIds = [];
        $worstScore = 0.0;
        $truncated = false;

        foreach ($targetNodeIds as $seed) {
            $result = $analyze($seed);
            foreach ($result['blast_radius'] as $consumerNodeId) {
                $blastNodeIds[(string) $consumerNodeId] = true;
            }
            $worstScore = max($worstScore, (float) $result['risk_score']);
            $truncated = $truncated || (bool) $result['truncated'];
        }

        foreach ($targetNodeIds as $seed) {
            unset($blastNodeIds[$seed]);
        }

        return [
            'blast_node_ids' => $blastNodeIds,
            'worst_score' => $worstScore,
            'truncated' => $truncated,
        ];
    }

    /**
     * @param  array<string, bool>  $blastNodeIds
     * @return list<string>
     */
    public function distinctPaths(string $modelId, array $blastNodeIds, string $targetPath): array
    {
        return AiCodebaseWorldModelNode::query()
            ->where('world_model_id', $modelId)
            ->whereIn('node_id', array_keys($blastNodeIds))
            ->pluck('path')
            ->map(static fn ($p): string => trim((string) $p))
            ->filter(static fn (string $p): bool => $p !== '' && $p !== $targetPath)
            ->unique()
            ->values()
            ->all();
    }

    public function risk(bool $truncated, float $worstScore): string
    {
        return match (true) {
            $truncated || $worstScore >= 0.75 => 'critical',
            $worstScore >= 0.4 => 'high',
            $worstScore >= 0.15 => 'medium',
            default => 'low',
        };
    }
}
