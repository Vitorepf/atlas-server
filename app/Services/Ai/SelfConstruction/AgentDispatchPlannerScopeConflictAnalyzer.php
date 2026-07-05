<?php

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Support\HashesPayloadCanonically;

/**
 * Detect scope conflicts between planned dispatches and active leases
 * recorded in the Claim/Lease ledger.
 *
 * Pure projection: never claims, never dispatches, never modifies the
 * lease registry. Conflict detection is read-only and the output is
 * advisory.
 */
final class AgentDispatchPlannerScopeConflictAnalyzer
{
    use RuntimeFlagsShared;

    use HashesPayloadCanonically;
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_dispatch_planner_scope_conflict.v1';

    public const MODE = 'read_only_agent_dispatch_planner_scope_conflict';

    public function __construct(
        private readonly AgentControlPlaneClaimLeaseRepository $leases = new AgentControlPlaneClaimLeaseRepository,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $tasks
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function analyze(array $tasks, array $options = []): array
    {
        $useLiveLedger = (bool) ($options['use_live_ledger'] ?? true);
        $providedLeases = (array) ($options['active_leases'] ?? []);

        $activeLeases = $useLiveLedger ? $this->leases->activeLeases() : $providedLeases;
        $leaseWriteSets = $this->extractLeaseWriteSets($activeLeases);

        $analyses = [];
        $conflictingTasks = 0;
        $clearTasks = 0;
        $blockedTaskIds = [];

        foreach ($tasks as $task) {
            $taskId = (string) ($task['task_packet_id'] ?? '');
            if ($taskId === '') {
                continue;
            }
            $scopeLock = (array) ($task['scope_lock'] ?? []);
            $writeSet = $this->normalizeSet((array) ($scopeLock['write_set'] ?? []));
            $readSet = $this->normalizeSet((array) ($scopeLock['read_set'] ?? []));
            $taskCapabilities = $this->normalizeSet((array) ($task['capabilities'] ?? []));
            $dependsOn = $this->normalizeSet((array) ($task['depends_on'] ?? []));

            if ($useLiveLedger) {
                $live = $this->leases->conflictCheck($scopeLock, ['task_packet_id' => $taskId]);
                $conflicts = (array) ($live['conflicts'] ?? []);
                $liveStatus = (string) ($live['status'] ?? '');
            } else {
                $conflicts = $this->detectConflicts($writeSet, $leaseWriteSets, $taskId);
                $liveStatus = $conflicts === [] ? 'clear' : 'conflict';
            }

            // Detect capability conflicts: same capability required by multiple tasks.
            $capabilityConflicts = $this->detectCapabilityConflicts($taskId, $taskCapabilities, $tasks);

            // Detect dependency-order conflicts.
            $dependencyConflicts = $this->detectDependencyConflicts($taskId, $dependsOn, $tasks);

            $hotScopeDirs = $this->hotScopeDirectories($writeSet, $leaseWriteSets);
            [$recommendation, $recommendationReasons] = $this->recommend(
                $conflicts,
                $capabilityConflicts,
                $dependencyConflicts,
                $hotScopeDirs,
            );

            $allConflicts = array_merge(
                $conflicts,
                $capabilityConflicts,
                $dependencyConflicts,
            );

            if ($recommendation === self::RECOMMENDATION_REJECT_CONFLICT) {
                $blockedTaskIds[] = $taskId;
            }

            $analyses[] = [
                'task_packet_id' => $taskId,
                'write_set' => $writeSet,
                'read_set' => $readSet,
                'required_capabilities' => $taskCapabilities,
                'depends_on' => $dependsOn,
                'conflict_status' => $liveStatus,
                'conflict_count' => count($allConflicts),
                'conflicts' => $conflicts,
                'capability_conflicts' => $capabilityConflicts,
                'dependency_conflicts' => $dependencyConflicts,
                'has_scope_lock' => $writeSet !== [] || $readSet !== [],
                'hot_scope_directories' => $hotScopeDirs,
                'recommendation' => $recommendation,
                'recommendation_reasons' => $recommendationReasons,
            ];

            if ($allConflicts === [] && $capabilityConflicts === [] && $dependencyConflicts === []) {
                $clearTasks++;
            } else {
                $conflictingTasks++;
            }
        }

        // Build safe_parallel_groups and conflict_groups.
        $safeParallelGroups = $this->buildSafeParallelGroups($analyses, $tasks);
        $conflictGroups = $this->buildConflictGroups($analyses);

        // Build recommended sequencing (topological sort over dependencies).
        $recommendedSequencing = $this->buildRecommendedSequencing($tasks);

        $hashPayload = [
            'analyses' => array_map(static fn (array $a): array => [
                'task_packet_id' => (string) $a['task_packet_id'],
                'write_set' => (array) $a['write_set'],
                'conflict_count' => (int) $a['conflict_count'],
            ], $analyses),
            'use_live_ledger' => $useLiveLedger,
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'use_live_ledger' => $useLiveLedger,
            'analyses' => $analyses,
            'clear_task_count' => $clearTasks,
            'conflicting_task_count' => $conflictingTasks,
            'active_lease_count' => count($activeLeases),
            'safe_parallel_groups' => $safeParallelGroups,
            'conflict_groups' => $conflictGroups,
            'blocked_task_ids' => $blockedTaskIds,
            'recommended_sequencing' => $recommendedSequencing,
            'analysis_hash' => $this->stableHash($hashPayload),
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'claim_real_allowed' => false,
        ];
    }

    /**
     * Detect capability conflicts: two tasks requiring the same finite capability.
     *
     * @return list<array{task_packet_id:string, capability:string, conflict_type:string}>
     */
    private function detectCapabilityConflicts(string $taskId, array $taskCaps, array $tasks): array
    {
        $conflicts = [];
        foreach ($tasks as $other) {
            $otherId = (string) ($other['task_packet_id'] ?? '');
            if ($otherId === '' || $otherId === $taskId) {
                continue;
            }
            $otherCaps = $this->normalizeSet((array) ($other['capabilities'] ?? []));
            $shared = array_intersect($taskCaps, $otherCaps);
            foreach ($shared as $cap) {
                $conflicts[] = [
                    'task_packet_id' => $otherId,
                    'capability' => $cap,
                    'conflict_type' => 'shared_capability',
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Detect dependency-order conflicts: task depends on another in the same batch
     * but that other has not yet been scheduled or is itself blocked.
     *
     * @return list<array{task_packet_id:string, dependency:string, conflict_type:string}>
     */
    private function detectDependencyConflicts(string $taskId, array $dependsOn, array $tasks): array
    {
        $conflicts = [];
        foreach ($dependsOn as $dep) {
            $found = false;
            foreach ($tasks as $other) {
                if ((string) ($other['task_packet_id'] ?? '') === $dep) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $conflicts[] = [
                    'task_packet_id' => $dep,
                    'dependency' => $dep,
                    'conflict_type' => 'missing_dependency',
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Group tasks that can run in parallel with no conflicts (disjoint write sets, no shared capabilities, no dependency chains).
     *
     * @param  list<array<string, mixed>>  $analyses
     * @param  list<array<string, mixed>>  $tasks
     * @return list<list<string>>
     */
    private function buildSafeParallelGroups(array $analyses, array $tasks): array
    {
        $parallelSafe = [];
        $remaining = [];
        foreach ($analyses as $a) {
            if ($a['recommendation'] === self::RECOMMENDATION_PARALLEL_SAFE) {
                $parallelSafe[] = $a['task_packet_id'];
            } else {
                $remaining[] = $a['task_packet_id'];
            }
        }

        $groups = [];
        if ($parallelSafe !== []) {
            sort($parallelSafe);
            $groups[] = $parallelSafe;
        }
        foreach ($remaining as $id) {
            $groups[] = [$id];
        }

        return $groups;
    }

    /**
     * Group conflicting tasks by their conflict type.
     *
     * @param  list<array<string, mixed>>  $analyses
     * @return list<array{conflict_type:string, task_ids:list<string>}>
     */
    private function buildConflictGroups(array $analyses): array
    {
        $groups = [];
        $index = [];

        foreach ($analyses as $a) {
            $id = $a['task_packet_id'];
            foreach ((array) ($a['conflicts'] ?? []) as $c) {
                $type = 'file_overlap';
                $index[$type][] = $id;
            }
            foreach ((array) ($a['capability_conflicts'] ?? []) as $c) {
                $type = 'capability:'.$c['capability'];
                $index[$type][] = $id;
            }
            foreach ((array) ($a['dependency_conflicts'] ?? []) as $c) {
                $type = 'dependency:'.$c['dependency'];
                $index[$type][] = $id;
            }
        }

        foreach ($index as $type => $ids) {
            $uniqueIds = array_values(array_unique($ids));
            sort($uniqueIds);
            $groups[] = [
                'conflict_type' => $type,
                'task_ids' => $uniqueIds,
            ];
        }

        // Sort by first task_id for determinism.
        usort($groups, static fn (array $a, array $b): int => strcmp($a['task_ids'][0] ?? '', $b['task_ids'][0] ?? ''));

        return $groups;
    }

    /**
     * Build a recommended execution order respecting dependency chains.
     *
     * @return list<string>
     */
    private function buildRecommendedSequencing(array $tasks): array
    {
        $sequenced = [];
        $taskMap = [];
        $depMap = [];

        foreach ($tasks as $task) {
            $id = (string) ($task['task_packet_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $taskMap[$id] = true;
            $deps = $this->normalizeSet((array) ($task['depends_on'] ?? []));
            $depMap[$id] = array_values(array_intersect($deps, array_keys($taskMap)));
        }

        // Simple topological sort: tasks with no deps first, then tasks whose
        // deps have all been sequenced.
        $sequencedIds = [];
        $remainingIds = array_keys($taskMap);
        while ($remainingIds !== []) {
            $next = [];
            foreach ($remainingIds as $id) {
                $unmet = array_diff($depMap[$id], $sequencedIds);
                if ($unmet === []) {
                    $next[] = $id;
                }
            }
            if ($next === []) {
                // Cycle or dependency on missing task — append remaining as-is.
                $sequencedIds = array_merge($sequencedIds, $remainingIds);
                break;
            }
            sort($next);
            $sequencedIds = array_merge($sequencedIds, $next);
            $remainingIds = array_values(array_diff($remainingIds, $next));
        }

        return $sequencedIds;
    }

    /**
     * @param  array<int, array<string, mixed>>  $leases
     * @return array<string, list<string>>
     */
    private function extractLeaseWriteSets(array $leases): array
    {
        $writeSets = [];
        foreach ($leases as $lease) {
            $leaseId = (string) ($lease['lease_id'] ?? '');
            if ($leaseId === '') {
                continue;
            }
            $scopeLock = (array) ($lease['scope_lock'] ?? []);
            $writeSets[$leaseId] = $this->normalizeSet((array) ($scopeLock['write_set'] ?? []));
        }

        return $writeSets;
    }

    /**
     * @param  list<string>  $writeSet
     * @param  array<string, list<string>>  $leaseWriteSets
     * @return list<array<string, mixed>>
     */
    private function detectConflicts(array $writeSet, array $leaseWriteSets, string $taskId): array
    {
        $conflicts = [];
        foreach ($leaseWriteSets as $leaseId => $leaseWriteSet) {
            $overlap = WriteSetOverlap::collidingPaths($writeSet, $leaseWriteSet); // A5/MF-12: prefix-aware dir-vs-file
            if ($overlap !== []) {
                $conflicts[] = [
                    'lease_id' => $leaseId,
                    'task_packet_id' => $taskId,
                    'overlap' => $overlap,
                    'overlap_count' => count($overlap),
                ];
            }
        }

        return $conflicts;
    }

    public const RECOMMENDATION_PARALLEL_SAFE   = 'parallel_safe';
    public const RECOMMENDATION_SERIALIZE       = 'serialize';
    public const RECOMMENDATION_REJECT_CONFLICT = 'reject_conflict';

    /**
     * Same-directory hot-scope risk: a task touches the same directory as an
     * active lease's write set without an exact file overlap — safe to serialize,
     * not safe to run blindly in parallel. Read-only comparison, no worktree/sandbox.
     *
     * @param  list<string>  $writeSet
     * @param  array<string, list<string>>  $leaseWriteSets
     * @return list<string>
     */
    private function hotScopeDirectories(array $writeSet, array $leaseWriteSets): array
    {
        $taskDirs = array_unique(array_map(static fn (string $f): string => dirname($f), $writeSet));
        $hot = [];

        foreach ($leaseWriteSets as $leaseWriteSet) {
            foreach ($leaseWriteSet as $leaseFile) {
                $leaseDir = dirname($leaseFile);
                if (in_array($leaseDir, $taskDirs, true) && ! in_array($leaseFile, $writeSet, true)) {
                    $hot[$leaseDir] = true;
                }
            }
        }

        $dirs = array_keys($hot);
        sort($dirs);

        return $dirs;
    }

    /**
     * @param  list<array<string,mixed>>  $conflicts
     * @param  list<array<string,mixed>>  $capabilityConflicts
     * @param  list<array<string,mixed>>  $dependencyConflicts
     * @param  list<string>  $hotScopeDirs
     * @return array{0:string,1:list<string>}
     */
    private function recommend(array $conflicts, array $capabilityConflicts, array $dependencyConflicts, array $hotScopeDirs): array
    {
        if ($conflicts !== []) {
            return [self::RECOMMENDATION_REJECT_CONFLICT, ['exact_allowed_files_overlap_or_active_lease_conflict']];
        }
        if ($dependencyConflicts !== []) {
            return [self::RECOMMENDATION_REJECT_CONFLICT, ['missing_dependency_or_dependency_order_conflict']];
        }
        if ($capabilityConflicts !== []) {
            return [self::RECOMMENDATION_SERIALIZE, ['shared_capability_conflict']];
        }
        if ($hotScopeDirs !== []) {
            return [self::RECOMMENDATION_SERIALIZE, ['same_directory_hot_scope']];
        }

        return [self::RECOMMENDATION_PARALLEL_SAFE, []];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function normalizeSet(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $clean = trim((string) $value);
            if ($clean === '') {
                continue;
            }
            $normalized[$clean] = true;
        }
        $keys = array_keys($normalized);
        sort($keys);

        return array_values($keys);
    }

}
