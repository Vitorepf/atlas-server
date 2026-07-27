<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;

/**
 * Builds a hashable continuity index from local runtime evidence records.
 */
final class AgentRuntimeEvidenceContinuityIndexer
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    use KsortsArraysByReference;


    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_runtime_evidence_continuity_index.v1';

    public const MODE = 'read_only_agent_runtime_evidence_continuity_indexer';

    public const REQUIRED_TYPES = [
        'dispatch_plan',
        'claim_lease',
        'scope_lock',
        'validation_result',
        'continuation_summary',
    ];

    /** Entries older than this are flagged as stale (default 7 days). */
    public const STALE_ENTRY_THRESHOLD_SECONDS = 604800;

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    public function build(array $entries): array
    {
        $types = [];
        $tasks = [];
        $agents = [];
        $typesByTask = [];
        $seenReceiptIds = [];
        $duplicateReceiptCount = 0;
        $staleCutoff = time() - self::STALE_ENTRY_THRESHOLD_SECONDS;
        $staleCount = 0;
        $conflictingOutcomeTasks = [];

        // Chronological sort by created_at if present.
        $sorted = $entries;
        usort($sorted, static function (array $a, array $b): int {
            $tA = (int) ($a['created_at'] ?? $a['timestamp'] ?? 0);
            $tB = (int) ($b['created_at'] ?? $b['timestamp'] ?? 0);

            return $tA <=> $tB;
        });

        foreach ($sorted as $entry) {
            $type = (string) ($entry['evidence_type'] ?? 'unknown');
            $task = (string) ($entry['task_packet_id'] ?? 'unknown');
            $agent = (string) ($entry['agent_id'] ?? 'unknown');
            $types[$type] = ($types[$type] ?? 0) + 1;
            $tasks[$task] = ($tasks[$task] ?? 0) + 1;
            $agents[$agent] = ($agents[$agent] ?? 0) + 1;
            $typesByTask[$task][$type] = true;

            // Duplicate receipt detection.
            $receiptId = (string) ($entry['receipt_id'] ?? '');
            if ($receiptId !== '') {
                if (isset($seenReceiptIds[$receiptId])) {
                    $duplicateReceiptCount++;
                }
                $seenReceiptIds[$receiptId] = true;
            }

            // Stale timestamp detection.
            $ts = (int) ($entry['created_at'] ?? $entry['timestamp'] ?? 0);
            if ($ts > 0 && $ts < $staleCutoff) {
                $staleCount++;
            }

            // Conflicting outcomes: same task with both success and non-success.
            $outcome = (string) ($entry['outcome'] ?? '');
            if ($outcome !== '' && ! isset($conflictingOutcomeTasks[$task])) {
                // First outcome seen for this task.
                $conflictingOutcomeTasks[$task] = ['first_outcome' => $outcome, 'entries_since' => 0, 'conflict' => false];
            } elseif ($outcome !== '' && isset($conflictingOutcomeTasks[$task])) {
                $prev = $conflictingOutcomeTasks[$task]['first_outcome'];
                if ($prev !== $outcome) {
                    $conflictingOutcomeTasks[$task]['conflict'] = true;
                }
                $conflictingOutcomeTasks[$task]['entries_since']++;
            }
        }
        ksort($types);
        ksort($tasks);
        ksort($agents);
        ksort($typesByTask);

        $missing = array_values(array_diff(self::REQUIRED_TYPES, array_keys($types)));

        $perTaskContinuity = [];
        foreach ($typesByTask as $task => $presentTypes) {
            $present = array_values(array_intersect(self::REQUIRED_TYPES, array_keys($presentTypes)));
            $missingForTask = array_values(array_diff(self::REQUIRED_TYPES, array_keys($presentTypes)));
            $perTaskContinuity[] = [
                'task_packet_id' => $task,
                'present_required_types' => $present,
                'missing_required_types' => $missingForTask,
                'complete' => $missingForTask === [],
                'next_evidence_repair_hint' => $missingForTask === [] ? null : 'request_evidence_type:'.$missingForTask[0],
            ];
        }
        $anyTaskComplete = false;
        foreach ($perTaskContinuity as $row) {
            if ($row['complete']) {
                $anyTaskComplete = true;
                break;
            }
        }
        // Global completeness alone can be a stitched illusion: the required types may all be
        // present, but scattered across different tasks rather than proven by any single task.
        $status = match (true) {
            $missing !== [] => 'continuity_index_incomplete',
            $anyTaskComplete => 'continuity_index_complete',
            default => 'continuity_index_stitched_proxy',
        };

        $conflictingTaskList = array_values(array_filter(
            array_keys($conflictingOutcomeTasks),
            static fn (string $t): bool => $conflictingOutcomeTasks[$t]['conflict'] ?? false,
        ));

        $blockers = [];
        if ($missing !== []) {
            $blockers[] = 'missing_required_evidence_types';
        }
        if ($duplicateReceiptCount > 0) {
            $blockers[] = 'duplicate_receipts_detected';
        }
        if ($conflictingTaskList !== []) {
            $blockers[] = 'conflicting_outcomes_detected';
        }

        $index = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'entry_count' => count($entries),
            'stale_entry_count' => $staleCount,
            'duplicate_receipt_count' => $duplicateReceiptCount,
            'conflicting_outcome_tasks' => $conflictingTaskList,
            'task_packet_count' => count($tasks),
            'agent_count' => count($agents),
            'evidence_type_counts' => $types,
            'task_packet_counts' => $tasks,
            'agent_counts' => $agents,
            'required_evidence_types' => self::REQUIRED_TYPES,
            'missing_required_evidence_types' => $missing,
            'per_task_continuity' => $perTaskContinuity,
            'continuation_summary_ready' => in_array('continuation_summary', array_keys($types), true),
            'blockers' => $blockers,
            'continuity_gaps' => $missing !== [] ? ['missing_required_types' => $missing] : [],
            'next_evidence_action' => $missing !== [] ? 'request_missing_types' : (
                $duplicateReceiptCount > 0 ? 'resolve_duplicate_receipts' : (
                    $conflictingTaskList !== [] ? 'resolve_conflicting_outcomes' : 'none_required'
                )
            ),
            'runtime_safety' => [
                'runtime_safety_all_false' => true,
                'runtime_execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
                'ledger_write_allowed' => false,
                'completion_claim_allowed' => false,
            ],
        ];
        $index['continuity_index_hash'] = $this->stableHash($index);

        return $index;
    }

    private function stableHash(array $payload): string
    {
        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

}
