<?php

namespace App\Services\Ai\SelfConstruction;

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

        foreach ($tasks as $task) {
            $taskId = (string) ($task['task_packet_id'] ?? '');
            if ($taskId === '') {
                continue;
            }
            $scopeLock = (array) ($task['scope_lock'] ?? []);
            $writeSet = $this->normalizeSet((array) ($scopeLock['write_set'] ?? []));
            $readSet = $this->normalizeSet((array) ($scopeLock['read_set'] ?? []));

            if ($useLiveLedger) {
                $live = $this->leases->conflictCheck($scopeLock, ['task_packet_id' => $taskId]);
                $conflicts = (array) ($live['conflicts'] ?? []);
                $liveStatus = (string) ($live['status'] ?? '');
            } else {
                $conflicts = $this->detectConflicts($writeSet, $leaseWriteSets, $taskId);
                $liveStatus = $conflicts === [] ? 'clear' : 'conflict';
            }

            $analyses[] = [
                'task_packet_id' => $taskId,
                'write_set' => $writeSet,
                'read_set' => $readSet,
                'conflict_status' => $liveStatus,
                'conflict_count' => count($conflicts),
                'conflicts' => $conflicts,
                'has_scope_lock' => $writeSet !== [] || $readSet !== [],
            ];

            if ($conflicts === []) {
                $clearTasks++;
            } else {
                $conflictingTasks++;
            }
        }

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
     * @return array<string, bool>
     */
    public function runtimeFlags(): array
    {
        return [
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
