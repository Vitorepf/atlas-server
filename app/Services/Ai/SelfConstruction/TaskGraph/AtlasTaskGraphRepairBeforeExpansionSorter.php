<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskGraph;

/**
 * Sorts task graph waves so queue repair and malformed prevention run before
 * expansion when health is degraded.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasTaskGraphRepairBeforeExpansionSorter
{
    public const SCHEMA = 'atlas.self_construction.task_graph_repair_before_expansion_sorter.v1';

    public const KIND_REPAIR = 'repair';
    public const KIND_MALFORMED_PREVENTION = 'malformed_prevention';
    public const KIND_EXPANSION = 'expansion';
    public const KIND_LEARNING = 'learning';
    public const KIND_VERIFICATION = 'verification';

    private const REPAIR_KINDS = [
        self::KIND_REPAIR,
        self::KIND_MALFORMED_PREVENTION,
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function sort(array $input): array
    {
        $nodes = is_array($input['nodes'] ?? null) ? $input['nodes'] : [];
        $health = is_array($input['queue_health'] ?? null) ? $input['queue_health'] : [];
        $roundId = (string) ($input['round_id'] ?? '');
        $originatorId = (string) ($input['originator_id'] ?? '');

        $healthy = (bool) ($health['healthy'] ?? false);
        $malformed = (int) ($health['malformed_count'] ?? 0);
        $claimableDepth = (int) ($health['claimable_depth'] ?? 0);
        $activeWorkers = (int) ($health['active_workers'] ?? 0);
        $dryQueue = (bool) ($health['dry_queue'] ?? false);

        $repairFirst = ! $healthy || $malformed > 0 || $dryQueue;
        $allowExpansion = $healthy && $malformed === 0 && ! $dryQueue;

        $sorted = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $kind = (string) ($node['kind'] ?? 'expansion');
            $sorted[] = [
                'node_id' => (string) ($node['node_id'] ?? ''),
                'kind' => $kind,
                'priority_score' => $this->priorityScore($kind, $repairFirst, $allowExpansion, $claimableDepth, $activeWorkers),
            ];
        }

        usort($sorted, static function (array $a, array $b): int {
            return $b['priority_score'] <=> $a['priority_score']
                ?: strcmp($a['node_id'], $b['node_id']);
        });

        return [
            'schema_version' => self::SCHEMA,
            'originator_id' => $originatorId,
            'round_id' => $roundId,
            'sorted_nodes' => $sorted,
            'repair_first' => $repairFirst,
            'allow_expansion' => $allowExpansion,
            'health_healthy' => $healthy,
            'malformed_count' => $malformed,
        ];
    }

    private function priorityScore(string $kind, bool $repairFirst, bool $allowExpansion, int $claimableDepth, int $activeWorkers): int
    {
        $isRepair = in_array($kind, self::REPAIR_KINDS, true);

        if ($repairFirst && $isRepair) {
            return 100;
        }

        if ($repairFirst && $kind === self::KIND_EXPANSION) {
            return 10;
        }

        if ($allowExpansion && $kind === self::KIND_EXPANSION) {
            $workerFloorBreached = $activeWorkers > 0 && ($claimableDepth / $activeWorkers) <= 2.0;

            return $workerFloorBreached ? 90 : 70;
        }

        if ($kind === self::KIND_LEARNING) {
            return 60;
        }

        if ($kind === self::KIND_VERIFICATION) {
            return 50;
        }

        return 40;
    }
}
