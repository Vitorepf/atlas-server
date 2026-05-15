<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Curated corpus of synthetic regression scenarios for the Agent
 * Control Plane certification observatory. Each scenario carries the
 * canonical category, injected fault, expected detector, expected
 * status, expected violation, severity, whether it should block
 * promotion and whether the synthetic mutation is runtime-safe.
 *
 * The corpus is read-only and never starts processes, never calls
 * Codex CLI/app, never spawns subprocesses, never invokes adapters,
 * never dispatches work, never spends tokens, never advances the
 * pointer, never enables self-programming and never writes the
 * ledger.
 */
final class AgentControlPlaneCertificationScenarioCorpusService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_certification_scenario_corpus.v1';

    public const MODE = 'read_only_agent_control_plane_certification_scenario_corpus';

    public const CATEGORIES = [
        'capability',
        'cli',
        'readiness',
        'invoker',
        'doc',
        'edge',
        'pointer',
        'runtime_safety',
        'cycle_reentry',
        'replay_hash',
        'snapshot_diff',
        'promotion_gate',
        'mutation_guard',
    ];

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    public function __construct(
        private readonly AgentControlPlaneCertificationScenarioSimulator $simulator,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function corpus(array $options = []): array
    {
        $scenarios = $this->definitions();
        $byCategory = [];
        foreach ($scenarios as $scenario) {
            $byCategory[$scenario['category']] = ($byCategory[$scenario['category']] ?? 0) + 1;
        }
        ksort($byCategory);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => 'available',
            'corpus_id' => (string) Str::uuid(),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'read_only' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'external_provider_call' => false,
            'token_spend' => false,
            'process_started' => false,
            'self_programming_allowed' => false,
            'completion_claim_allowed' => false,
            'scenario_count' => count($scenarios),
            'categories' => self::CATEGORIES,
            'severities' => self::SEVERITIES,
            'by_category' => $byCategory,
            'scenarios' => $scenarios,
            'non_execution_guarantees' => [
                'corpus_does_not_start_codex',
                'corpus_does_not_call_codex_cli_or_app',
                'corpus_does_not_spawn_subprocess',
                'corpus_does_not_invoke_adapter',
                'corpus_does_not_execute_adapter',
                'corpus_does_not_call_provider',
                'corpus_does_not_dispatch_work',
                'corpus_does_not_spend_tokens',
                'corpus_does_not_enable_self_programming',
                'corpus_does_not_write_ledger',
                'corpus_does_not_mutate_pointer',
                'corpus_does_not_promote_completion_claim',
            ],
            'human_summary' => sprintf('Scenario corpus exposes %d canonical scenarios across %d categories.', count($scenarios), count(self::CATEGORIES)),
        ];

        $payload['corpus_hash'] = $this->stableHash($this->normalizeForCorpusHash($payload));

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function scenario(string $id): ?array
    {
        foreach ($this->definitions() as $scenario) {
            if ((string) $scenario['scenario_id'] === $id) {
                return $scenario;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @return array<string, mixed>
     */
    public function validate(array $scenario): array
    {
        $required = ['scenario_id', 'category', 'injected_fault', 'expected_detector', 'expected_status', 'expected_violation', 'severity', 'should_block_promotion', 'runtime_safe'];
        $missing = [];
        foreach ($required as $key) {
            if (! array_key_exists($key, $scenario)) {
                $missing[] = $key;
            }
        }

        $reasons = [];
        if ($missing !== []) {
            $reasons[] = 'missing_keys:'.implode(',', $missing);
        }
        if (isset($scenario['category']) && ! in_array((string) $scenario['category'], self::CATEGORIES, true)) {
            $reasons[] = 'unknown_category:'.$scenario['category'];
        }
        if (isset($scenario['severity']) && ! in_array((string) $scenario['severity'], self::SEVERITIES, true)) {
            $reasons[] = 'unknown_severity:'.$scenario['severity'];
        }
        if (isset($scenario['runtime_safe']) && ! is_bool($scenario['runtime_safe'])) {
            $reasons[] = 'runtime_safe_must_be_boolean';
        }
        if (isset($scenario['should_block_promotion']) && ! is_bool($scenario['should_block_promotion'])) {
            $reasons[] = 'should_block_promotion_must_be_boolean';
        }

        return [
            'valid' => $reasons === [],
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function runCorpus(array $options = []): array
    {
        $simulation = $this->simulator->simulate($options);
        $scenarios = $this->definitions();
        $byScenarioId = [];
        foreach ((array) data_get($simulation, 'scenarios', []) as $entry) {
            $byScenarioId[(string) data_get($entry, 'scenario_id')] = $entry;
        }

        $alignedDetected = 0;
        $alignedMissed = 0;
        $unmatched = 0;
        $report = [];
        foreach ($scenarios as $scenario) {
            $simEntry = $byScenarioId[$scenario['scenario_id']] ?? null;
            $detected = $simEntry !== null && (bool) data_get($simEntry, 'detected', false);
            $expectedDetected = $scenario['expected_status'] !== 'not_applicable';
            $report[] = [
                'scenario_id' => $scenario['scenario_id'],
                'category' => $scenario['category'],
                'expected_detected' => $expectedDetected,
                'actual_detected' => $detected,
                'aligned' => $expectedDetected === $detected,
                'simulator_status' => (string) data_get($simEntry, 'status', $simEntry === null ? 'no_simulator_entry' : 'unknown'),
                'severity' => $scenario['severity'],
                'should_block_promotion' => $scenario['should_block_promotion'],
                'runtime_safe' => $scenario['runtime_safe'],
            ];

            if ($simEntry === null) {
                $unmatched++;

                continue;
            }
            if ($expectedDetected && $detected) {
                $alignedDetected++;
            } elseif (! $expectedDetected && ! $detected) {
                $alignedDetected++;
            } else {
                $alignedMissed++;
            }
        }

        $alignmentRate = count($scenarios) > 0
            ? round($alignedDetected / count($scenarios), 4)
            : 0.0;

        $status = $alignedMissed === 0 && $unmatched === 0 ? 'passed' : ($alignedMissed > 0 ? 'failed' : 'warning');

        $payload = [
            'schema_version' => 'atlas.self_construction.agent_control_plane_certification_scenario_corpus_run.v1',
            'mode' => 'read_only_agent_control_plane_certification_scenario_corpus_run',
            'status' => $status,
            'run_id' => (string) Str::uuid(),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'read_only' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'corpus_count' => count($scenarios),
            'simulator_scenario_count' => (int) data_get($simulation, 'scenario_count'),
            'simulator_detection_rate' => (float) data_get($simulation, 'detection_rate'),
            'simulator_status' => (string) data_get($simulation, 'status'),
            'simulator_matrix_hash' => (string) data_get($simulation, 'scenario_matrix_hash'),
            'aligned_count' => $alignedDetected,
            'misaligned_count' => $alignedMissed,
            'unmatched_count' => $unmatched,
            'alignment_rate' => $alignmentRate,
            'all_expected_detected' => $alignedMissed === 0 && $unmatched === 0,
            'report' => $report,
            'human_summary' => sprintf('Scenario corpus run: %d aligned, %d misaligned, %d unmatched.', $alignedDetected, $alignedMissed, $unmatched),
        ];

        $payload['run_hash'] = $this->stableHash($this->normalizeForCorpusHash($payload));

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function definitions(): array
    {
        $catalog = [];
        $add = function (string $id, string $category, string $injectedFault, string $expectedDetector, string $expectedStatus, string $expectedViolation, string $severity, bool $shouldBlockPromotion, bool $runtimeSafe) use (&$catalog) {
            $catalog[] = [
                'scenario_id' => $id,
                'category' => $category,
                'injected_fault' => $injectedFault,
                'expected_detector' => $expectedDetector,
                'expected_status' => $expectedStatus,
                'expected_violation' => $expectedViolation,
                'severity' => $severity,
                'should_block_promotion' => $shouldBlockPromotion,
                'runtime_safe' => $runtimeSafe,
            ];
        };

        // Capability category (5)
        $add('missing_capability', 'capability', 'capability_removed_from_current_capability', 'chain_integrity_audit', 'degraded', 'slice_missing_artifact', 'high', true, true);
        $add('duplicate_capability', 'capability', 'capability_listed_twice_in_current_capability', 'chain_integrity_audit', 'degraded', 'duplicate_capabilities_registered', 'medium', true, true);
        $add('capability_renamed', 'capability', 'capability_replaced_by_different_key', 'chain_integrity_audit', 'degraded', 'slice_missing_artifact', 'high', true, true);
        $add('capability_orphaned', 'capability', 'unrelated_capability_added', 'chain_integrity_audit', 'available', 'no_violation_expected', 'low', false, true);
        $add('capability_capitalisation_drift', 'capability', 'capability_case_mismatch', 'chain_integrity_audit', 'degraded', 'slice_missing_artifact', 'medium', true, true);

        // CLI category (4)
        $add('missing_cli_option', 'cli', 'cli_option_missing_from_command_surface', 'chain_integrity_audit', 'degraded', 'cli_option_missing', 'high', true, true);
        $add('cli_option_typo', 'cli', 'cli_option_misspelled', 'chain_integrity_audit', 'degraded', 'cli_option_missing', 'high', true, true);
        $add('cli_option_extra', 'cli', 'extra_cli_option_added', 'chain_integrity_audit', 'available', 'no_violation_expected', 'low', false, true);
        $add('cli_option_renamed', 'cli', 'cli_option_renamed_without_doc_update', 'chain_integrity_audit', 'degraded', 'cli_option_missing', 'medium', true, true);

        // Readiness category (3)
        $add('missing_readiness_method', 'readiness', 'readiness_method_missing_for_slice', 'chain_integrity_audit', 'degraded', 'readiness_quartet_missing_method', 'critical', true, true);
        $add('readiness_method_signature_drift', 'readiness', 'readiness_method_signature_changed', 'chain_integrity_audit', 'degraded', 'readiness_quartet_missing_method', 'high', true, true);
        $add('readiness_method_returns_blocked', 'readiness', 'readiness_method_returns_blocked', 'chain_integrity_audit', 'degraded', 'readiness_quartet_missing_method', 'medium', true, true);

        // Invoker category (3)
        $add('missing_invoker', 'invoker', 'invoker_class_or_prepare_method_missing', 'chain_integrity_audit', 'degraded', 'invoker_missing', 'high', true, true);
        $add('invoker_prepare_method_renamed', 'invoker', 'invoker_prepare_method_renamed', 'chain_integrity_audit', 'degraded', 'invoker_missing', 'high', true, true);
        $add('invoker_class_relocated', 'invoker', 'invoker_class_namespace_changed', 'chain_integrity_audit', 'degraded', 'invoker_missing', 'medium', true, true);

        // Doc category (3)
        $add('missing_doc_bullet', 'doc', 'doc_bullet_missing_for_slice', 'chain_integrity_audit', 'degraded', 'doc_bullet_missing', 'medium', true, true);
        $add('duplicate_doc_bullet', 'doc', 'doc_bullet_duplicated', 'chain_integrity_audit', 'degraded', 'duplicate_doc_bullet', 'medium', true, true);
        $add('doc_anchor_drift', 'doc', 'doc_bullet_anchor_renamed', 'chain_integrity_audit', 'degraded', 'doc_bullet_missing', 'low', true, true);

        // Edge category (3)
        $add('broken_edge', 'edge', 'slice_edge_points_to_wrong_next_slice', 'chain_integrity_audit', 'degraded', 'broken_edge', 'high', true, true);
        $add('reversed_edge', 'edge', 'edge_points_back_to_predecessor', 'chain_integrity_audit', 'degraded', 'broken_edge', 'high', true, true);
        $add('orphan_terminal_edge', 'edge', 'edge_points_to_unknown_slice', 'chain_integrity_audit', 'degraded', 'broken_edge', 'critical', true, true);

        // Pointer category (3)
        $add('pointer_regression', 'pointer', 'pointer_regresses_to_previously_certified_slice', 'chain_integrity_audit', 'degraded', 'pointer_regressed_into_previously_certified_slice_without_reentry', 'critical', true, true);
        $add('pointer_unexpected_reentry', 'pointer', 'pointer_jumps_back_without_intentional_reentry_justification', 'chain_integrity_audit', 'degraded', 'pointer_regressed_into_previously_certified_slice_without_reentry', 'critical', true, true);
        $add('next_build_slices_misalignment', 'pointer', 'next_build_slices_misaligned_with_pointer', 'chain_integrity_audit', 'degraded', 'next_build_slices_does_not_contain_next_required_slice', 'high', true, true);

        // Runtime safety category (7)
        $add('runtime_flag_actual_process_start_true', 'runtime_safety', 'runtime_flag_process_started_set_to_true', 'chain_integrity_audit', 'degraded', 'runtime_safety_violation', 'critical', true, true);
        $add('runtime_flag_provider_call_true', 'runtime_safety', 'runtime_flag_external_provider_call_set_to_true', 'chain_integrity_audit', 'degraded', 'runtime_safety_violation', 'critical', true, true);
        $add('runtime_flag_adapter_invocation_true', 'runtime_safety', 'runtime_flag_adapter_invocation_set_to_true', 'chain_integrity_audit', 'degraded', 'runtime_safety_violation', 'critical', true, true);
        $add('runtime_flag_adapter_execution_true', 'runtime_safety', 'runtime_flag_adapter_execution_set_to_true', 'chain_integrity_audit', 'degraded', 'runtime_safety_violation', 'critical', true, true);
        $add('runtime_flag_dispatch_true', 'runtime_safety', 'runtime_flag_dispatch_allowed_set_to_true', 'chain_integrity_audit', 'degraded', 'runtime_safety_violation', 'critical', true, true);
        $add('runtime_flag_token_spend_true', 'runtime_safety', 'runtime_flag_token_spend_set_to_true', 'chain_integrity_audit', 'degraded', 'runtime_safety_violation', 'critical', true, true);
        $add('runtime_flag_self_programming_true', 'runtime_safety', 'runtime_flag_self_programming_set_to_true', 'chain_integrity_audit', 'degraded', 'runtime_safety_violation', 'critical', true, true);

        // Cycle/reentry category (3)
        $add('cycle_unintentional', 'cycle_reentry', 'unintentional_cycle_back_to_certified_slice', 'chain_integrity_audit', 'degraded', 'pointer_regressed_into_previously_certified_slice_without_reentry', 'high', true, true);
        $add('intentional_reentry_wrong_target', 'cycle_reentry', 'pointer_set_to_unjustified_reentry_target', 'chain_integrity_audit', 'degraded', 'pointer_regressed_into_previously_certified_slice_without_reentry', 'high', true, true);
        $add('terminal_horizon_missing', 'cycle_reentry', 'terminal_horizon_analysis_block_missing', 'replay', 'available', 'terminal_horizon_block_missing', 'high', true, true);

        // Replay hash category (3)
        $add('deterministic_replay_hash_drift', 'replay_hash', 'deterministic_replay_hash_changes_without_chain_growth', 'replay_diff', 'changed', 'replay_hash_changed_without_structural_reason', 'high', true, true);
        $add('proof_bundle_hash_drift', 'replay_hash', 'proof_bundle_hash_changes_between_two_runs', 'replay_diff', 'changed', 'proof_bundle_hash_changed', 'high', true, true);
        $add('replay_hash_zero', 'replay_hash', 'replay_hash_returned_empty', 'replay_diff', 'changed', 'replay_hash_missing', 'medium', true, true);

        // Snapshot/diff category (3)
        $add('snapshot_missing_baseline', 'snapshot_diff', 'no_baseline_snapshot_recorded_for_diff', 'replay_diff', 'no_baseline', 'no_baseline_detected_by_diff', 'medium', false, true);
        $add('diff_regression', 'snapshot_diff', 'replay_diff_classifies_regressed', 'replay_diff', 'regressed', 'violation_increase', 'high', true, true);
        $add('snapshot_corrupt_registry', 'snapshot_diff', 'snapshot_registry_json_corrupt', 'replay_diff', 'no_baseline', 'snapshot_registry_corrupt', 'medium', false, true);

        // Promotion gate category (3)
        $add('promotion_gate_regression', 'promotion_gate', 'promotion_gate_blocks_due_to_regression', 'promotion_gate', 'blocked', 'after_replay_has_violations', 'critical', true, true);
        $add('promotion_gate_runtime_safety_drop', 'promotion_gate', 'promotion_gate_blocks_due_to_runtime_safety_drop', 'promotion_gate', 'blocked', 'after_runtime_safety_is_not_all_false', 'critical', true, true);
        $add('promotion_gate_warning_only', 'promotion_gate', 'promotion_gate_emits_warning_only', 'promotion_gate', 'warning', 'warning_count_increased', 'medium', false, true);

        // Mutation guard category (4)
        $add('mutation_guard_pointer_mutation', 'mutation_guard', 'pointer_mutated_during_certification', 'mutation_guard', 'failed', 'pointer_mutated', 'critical', true, true);
        $add('mutation_guard_runtime_safety_mutation', 'mutation_guard', 'runtime_safety_mutated_during_certification', 'mutation_guard', 'failed', 'runtime_safety_mutated', 'critical', true, true);
        $add('mutation_guard_ledger_mutation', 'mutation_guard', 'ledger_count_changed_during_certification', 'mutation_guard', 'failed', 'ledger_mutated', 'critical', true, true);
        $add('mutation_guard_storage_mutation', 'mutation_guard', 'storage_outside_allowed_prefix_changed', 'mutation_guard', 'failed', 'storage_outside_allowed_prefix_changed', 'critical', true, true);

        // Provider/corridor category extras (3)
        $add('provider_runtime_matrix_gap', 'edge', 'provider_runtime_matrix_row_missing', 'chain_integrity_audit', 'degraded', 'broken_edge', 'high', true, true);
        $add('evidence_corridor_gap', 'edge', 'post_start_evidence_corridor_member_missing_or_misordered', 'chain_integrity_audit', 'degraded', 'broken_edge', 'high', true, true);
        $add('implementation_corridor_gap', 'edge', 'implementation_to_operator_handoff_corridor_member_missing', 'chain_integrity_audit', 'degraded', 'broken_edge', 'high', true, true);

        return $catalog;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForCorpusHash(array $payload): array
    {
        $clone = $payload;
        unset($clone['corpus_id'], $clone['run_id'], $clone['generated_at'], $clone['corpus_hash'], $clone['run_hash']);

        return $this->recursivelyKsort($clone);
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<mixed, mixed>
     */
    private function recursivelyKsort(array $value): array
    {
        $isAssoc = $value !== [] && array_keys($value) !== range(0, count($value) - 1);
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->recursivelyKsort($entry);
            }
        }
        if ($isAssoc) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param  array<mixed, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        $payload = $this->recursivelyKsort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
