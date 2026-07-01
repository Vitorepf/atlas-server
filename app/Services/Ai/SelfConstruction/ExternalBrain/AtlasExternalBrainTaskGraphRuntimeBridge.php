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
