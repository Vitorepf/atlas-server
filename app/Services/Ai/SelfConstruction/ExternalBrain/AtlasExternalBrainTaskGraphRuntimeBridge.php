<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Promotes task-graph orchestration from read-only audit to live replenishment
 * guidance. Converts critical-path findings, broken (stale) dependencies,
 * prerequisite unlocks, and release-gate findings into a single deterministic
 * guidance envelope the replenisher can act on directly: while unresolved
 * critical-path leverage or stale dependencies still dominate, off-path
 * low-novelty replenishment must be blocked in favour of the unlocked/blocked
 * high-leverage work.
 *
 * Pure: no I/O, no side effects — a read-only bridge that only computes guidance.
 */
final class AtlasExternalBrainTaskGraphRuntimeBridge
{
    public const SCHEMA = 'atlas.external_brain.task_graph_runtime_bridge.v1';

    public const STATUS_CLAIMABLE = 'claimable';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_STALE = 'stale';

    /**
     * Bridges task-graph nodes to their live runtime status and a worker-safe
     * release decision, so a caller never has to re-derive dependency readiness
     * from raw graph facts on its own.
     *
     * Classification per node (first match wins):
     *   stale     — dependency_stale=true
     *   claimed   — queue_status='claimed'
     *   completed — queue_status='completed'
     *   claimable — every prerequisite is itself live-complete
     *   blocked   — otherwise
     *
     * release_ready=true only for claimable nodes; every other node carries a
     * hold_reason explaining exactly why it must not be released to a worker yet.
     *
     * @param  array{nodes?: list<array{
     *   task_packet_id?: string,
     *   prerequisites?: list<string>,
     *   queue_status?: string,
     *   dependency_stale?: bool,
     * }>}  $facts
     * @return array{schema:string, nodes:list<array<string,mixed>>}
     */
    public function bridgeNodes(array $facts): array
    {
        $rawNodes = is_array($facts['nodes'] ?? null) ? $facts['nodes'] : [];

        $ownStatus = [];
        $prerequisitesById = [];
        foreach ($rawNodes as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (string) ($row['task_packet_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $prerequisitesById[$id] = array_values(array_map('strval', (array) ($row['prerequisites'] ?? [])));

            $queueStatus = (string) ($row['queue_status'] ?? '');
            $ownStatus[$id] = match (true) {
                (bool) ($row['dependency_stale'] ?? false) => self::STATUS_STALE,
                $queueStatus === self::STATUS_CLAIMED => self::STATUS_CLAIMED,
                $queueStatus === self::STATUS_COMPLETED => self::STATUS_COMPLETED,
                default => null, // resolved in the second pass, once all own-statuses are known
            };
        }

        $nodes = [];
        foreach ($prerequisitesById as $id => $prerequisites) {
            if ($ownStatus[$id] !== null) {
                $liveStatus = $ownStatus[$id];
                $unmetPrerequisites = [];
            } else {
                $unmetPrerequisites = array_values(array_filter(
                    $prerequisites,
                    static fn (string $prereqId): bool => ($ownStatus[$prereqId] ?? null) !== self::STATUS_COMPLETED,
                ));
                $liveStatus = $unmetPrerequisites === [] ? self::STATUS_CLAIMABLE : self::STATUS_BLOCKED;
            }

            $releaseReady = $liveStatus === self::STATUS_CLAIMABLE;
            $holdReason = match (true) {
                $releaseReady => null,
                $liveStatus === self::STATUS_STALE => 'dependency_stale',
                $liveStatus === self::STATUS_CLAIMED => 'already_claimed',
                $liveStatus === self::STATUS_COMPLETED => 'already_completed',
                default => 'prerequisite_not_live_complete:'.implode(',', $unmetPrerequisites),
            };

            $nodes[] = [
                'task_packet_id' => $id,
                'live_status' => $liveStatus,
                'release_ready' => $releaseReady,
                'hold_reason' => $holdReason,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'nodes' => $nodes,
        ];
    }

    /**
     * @param  array{
     *   critical_path?: list<array{task_packet_id?:string, blocked?:bool, leverage?:int}>,
     *   broken_dependencies?: list<array{task_packet_id?:string, stale?:bool}>,
     *   prerequisite_unlocks?: list<array{task_packet_id?:string, unlocked?:bool}>,
     *   release_gate_findings?: list<array{task_packet_id?:string, gate_status?:string}>,
     * }  $input
     * @return array<string,mixed>
     */
    public function bridge(array $input): array
    {
        $criticalPath = is_array($input['critical_path'] ?? null) ? $input['critical_path'] : [];
        $brokenDependencies = is_array($input['broken_dependencies'] ?? null) ? $input['broken_dependencies'] : [];
        $prerequisiteUnlocks = is_array($input['prerequisite_unlocks'] ?? null) ? $input['prerequisite_unlocks'] : [];
        $releaseGateFindings = is_array($input['release_gate_findings'] ?? null) ? $input['release_gate_findings'] : [];

        $blockers = [];
        $prioritized = []; // task_packet_id => leverage

        foreach ($criticalPath as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (string) ($row['task_packet_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $leverage = (int) ($row['leverage'] ?? 0);
            if ((bool) ($row['blocked'] ?? false)) {
                $blockers[] = 'critical_path_blocked:'.$id;
                $prioritized[$id] = max($prioritized[$id] ?? 0, $leverage);
            }
        }

        foreach ($brokenDependencies as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (string) ($row['task_packet_id'] ?? '');
            if ($id === '') {
                continue;
            }
            if ((bool) ($row['stale'] ?? false)) {
                $blockers[] = 'stale_dependency:'.$id;
                $prioritized[$id] = max($prioritized[$id] ?? 0, 1);
            }
        }

        foreach ($prerequisiteUnlocks as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (string) ($row['task_packet_id'] ?? '');
            if ($id === '' || ! (bool) ($row['unlocked'] ?? false)) {
                continue;
            }
            $prioritized[$id] = max($prioritized[$id] ?? 0, 1);
        }

        foreach ($releaseGateFindings as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (string) ($row['task_packet_id'] ?? '');
            if ($id === '') {
                continue;
            }
            if ((string) ($row['gate_status'] ?? '') === 'blocked') {
                $blockers[] = 'release_gate_blocked:'.$id;
            }
        }

        $blockers = array_values(array_unique($blockers));
        sort($blockers, SORT_STRING);

        $unresolvedLeveragePresent = $blockers !== [];

        $prioritizedIds = array_keys($prioritized);
        usort($prioritizedIds, static fn (string $a, string $b): int => ($prioritized[$b] <=> $prioritized[$a]) ?: strcmp($a, $b));

        return [
            'schema' => self::SCHEMA,
            'unresolved_leverage_present' => $unresolvedLeveragePresent,
            'block_off_path_low_novelty' => $unresolvedLeveragePresent,
            'prioritized_task_packet_ids' => array_values($prioritizedIds),
            'blockers' => $blockers,
        ];
    }
}
