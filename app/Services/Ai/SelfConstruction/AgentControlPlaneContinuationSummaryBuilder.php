<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Services\Ai\SelfConstruction\Support\HashesKsortedPayloadCanonically;
use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;

/**
 * Builds a deterministic continuation summary for a task packet: objective
 * summary, current state snapshot, next actions, blockers, evidence refs
 * and a context compaction plan that a resumed agent would use. Persistence
 * is forbidden; runtime resume is disabled.
 *
 * Read-only: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming, never writes the ledger.
 */
final class AgentControlPlaneContinuationSummaryBuilder
{
    use RecursivelyKsortsArrays;
    use HashesKsortedPayloadCanonically;
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_continuation_summary.v1';

    public const MODE = 'read_only_agent_control_plane_continuation_summary_builder';

    /**
     * @param  array<string, mixed>  $taskPacket
     * @param  array<string, mixed>  $evidencePlan
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(array $taskPacket, array $evidencePlan = [], array $options = []): array
    {
        $packetId = (string) ($taskPacket['task_packet_id'] ?? 'task-packet-unknown');
        $objective = (string) ($taskPacket['objective'] ?? '');
        $allowed = (array) data_get($taskPacket, 'normalized_scope.allowed_files', []);
        $acceptance = (array) ($taskPacket['acceptance_criteria'] ?? []);
        $blockingReasonsPacket = (array) ($taskPacket['blocking_reasons'] ?? []);
        $additionalBlockers = (array) ($options['blockers'] ?? []);
        $nextActions = (array) ($options['next_actions'] ?? []);
        if ($nextActions === []) {
            $nextActions = [
                'reload_task_packet',
                'verify_scope_lock_plan',
                'replay_evidence_dry_run',
                'rebuild_continuation_context',
            ];
        }

        $currentState = [
            'task_packet_status' => (string) ($taskPacket['status'] ?? 'unknown'),
            'allowed_file_count' => count($allowed),
            'acceptance_count' => count($acceptance),
            'evidence_planned_count' => (int) ($evidencePlan['receipt_count'] ?? 0),
        ];

        $blockers = array_values(array_unique(array_merge($blockingReasonsPacket, $additionalBlockers)));

        $contextCompactionPlan = [
            'strategy' => 'pinned_packet_hash_plus_compacted_log',
            'pinned' => [
                'task_packet_hash' => (string) ($taskPacket['task_packet_hash'] ?? ''),
                'scope_hash' => (string) ($taskPacket['scope_hash'] ?? ''),
                'acceptance_hash' => (string) ($taskPacket['acceptance_hash'] ?? ''),
                'evidence_hash' => (string) ($evidencePlan['evidence_hash'] ?? ''),
            ],
            'max_history_lines' => (int) ($options['max_history_lines'] ?? 200),
            'runtime_enabled' => false,
        ];

        $evidenceRefs = [
            'evidence_plan_hash' => (string) ($evidencePlan['evidence_plan_hash'] ?? ''),
            'evidence_hash' => (string) ($evidencePlan['evidence_hash'] ?? ''),
            'planned_receipt_count' => (int) ($evidencePlan['receipt_count'] ?? 0),
        ];

        $resumeInstructions = [
            'verify_task_packet_hash_matches' => true,
            'reload_acceptance_criteria' => true,
            'reload_scope_lock_plan' => true,
            'reload_evidence_dry_run' => true,
            'do_not_dispatch_provider' => true,
            'do_not_write_ledger' => true,
            'continuation_runtime_enabled' => false,
        ];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'continuation_summary_id' => (string) Str::uuid(),
            'task_packet_id' => $packetId,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'status' => $blockers === [] ? 'planned' : 'planned_blocked',
            'objective_summary' => Str::limit($objective, 160),
            'scope_summary' => [
                'allowed_file_count' => count($allowed),
                'allowed_first_files' => array_slice($allowed, 0, 5),
            ],
            'current_state' => $currentState,
            'next_actions' => $nextActions,
            'blockers' => $blockers,
            'evidence_refs' => $evidenceRefs,
            'context_compaction_plan' => $contextCompactionPlan,
            'resume_instructions' => $resumeInstructions,
            'read_only' => true,
            'runtime_disabled' => true,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'persistence_allowed' => false,
            'non_execution_guarantees' => [
                'continuation_summary_builder_does_not_start_codex',
                'continuation_summary_builder_does_not_call_codex_cli_or_app',
                'continuation_summary_builder_does_not_spawn_subprocess',
                'continuation_summary_builder_does_not_invoke_adapter',
                'continuation_summary_builder_does_not_call_provider',
                'continuation_summary_builder_does_not_dispatch_work',
                'continuation_summary_builder_does_not_spend_tokens',
                'continuation_summary_builder_does_not_enable_self_programming',
                'continuation_summary_builder_does_not_write_ledger',
                'continuation_summary_builder_does_not_persist_context',
                'continuation_summary_builder_does_not_mutate_pointer',
            ],
            'human_summary' => sprintf(
                'Continuation summary planned for packet %s (%d next actions, %d blockers).',
                $packetId,
                count($nextActions),
                count($blockers),
            ),
        ];

        $payload['continuation_hash'] = $this->stableHash($this->normalizeForHash($payload));

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForHash(array $payload): array
    {
        $clone = $payload;
        unset($clone['continuation_summary_id'], $clone['generated_at'], $clone['continuation_hash'], $clone['human_summary'], $clone['task_packet_id']);

        return $this->recursivelyKsort($clone);
    }


}
