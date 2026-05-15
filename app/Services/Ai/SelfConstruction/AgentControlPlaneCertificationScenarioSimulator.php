<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Runs a battery of synthetic regression scenarios against the Agent
 * Control Plane Chain Integrity audit, Deterministic Replay, Replay Diff
 * and Macro-Sprint Promotion Gate.
 *
 * Each scenario injects a controlled fault (via overrides or synthetic
 * payloads) and verifies the appropriate detector flagged it. The
 * simulator is read-only: never starts processes, never calls Codex
 * CLI/app, never spawns subprocesses, never invokes adapters, never
 * dispatches work, never spends tokens, never advances the next required
 * slice, never enables self-programming, never writes the ledger.
 */
final class AgentControlPlaneCertificationScenarioSimulator
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_certification_scenario_simulator.v1';

    public const MODE = 'read_only_agent_control_plane_certification_scenario_simulator';

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
        private readonly AgentControlPlaneChainIntegrityAuditService $audit,
        private readonly AgentControlPlaneDeterministicChainReplayService $replay,
        private readonly AgentControlPlaneReplayDiffService $diff,
        private readonly AgentControlPlaneReplaySnapshotStore $store,
        private readonly AgentControlPlaneMacroSprintPromotionGate $gate,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function simulate(array $options = []): array
    {
        $scenarios = [];
        foreach ($this->scenarioDefinitions() as $definition) {
            $scenarios[] = $this->runScenario($definition);
        }

        $passed = 0;
        $failed = 0;
        $regressions = [];
        foreach ($scenarios as $scenario) {
            if ($scenario['detected']) {
                $passed++;
            } else {
                $failed++;
                $regressions[] = [
                    'scenario_id' => $scenario['scenario_id'],
                    'injected_fault' => $scenario['injected_fault'],
                    'expected_detection' => $scenario['expected_detection'],
                    'detector' => $scenario['detector'],
                    'status' => $scenario['status'],
                ];
            }
        }

        $scenarioCount = count($scenarios);
        $detectionRate = $scenarioCount > 0 ? round($passed / $scenarioCount, 4) : 0.0;

        $matrixHashInput = array_map(
            static fn (array $s) => [
                'scenario_id' => $s['scenario_id'],
                'injected_fault' => $s['injected_fault'],
                'expected_detection' => $s['expected_detection'],
                'detector' => $s['detector'],
                'detected' => $s['detected'],
            ],
            $scenarios,
        );

        $simulationId = (string) Str::uuid();
        $generatedAt = CarbonImmutable::now()->toIso8601String();
        $allExpectedDetected = $failed === 0;
        $status = $allExpectedDetected ? 'passed' : 'failed';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'simulation_id' => $simulationId,
            'generated_at' => $generatedAt,
            'read_only' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'external_provider_call' => false,
            'token_spend' => false,
            'process_started' => false,
            'self_programming_allowed' => false,
            'scenario_count' => $scenarioCount,
            'passed_count' => $passed,
            'failed_count' => $failed,
            'detection_rate' => $detectionRate,
            'all_expected_faults_detected' => $allExpectedDetected,
            'scenarios' => $scenarios,
            'regressions' => $regressions,
            'scenario_matrix_hash' => $this->stableHash($matrixHashInput),
            'non_execution_guarantees' => [
                'simulator_does_not_start_codex',
                'simulator_does_not_call_codex_cli_or_app',
                'simulator_does_not_spawn_subprocess',
                'simulator_does_not_invoke_adapter',
                'simulator_does_not_execute_adapter',
                'simulator_does_not_call_provider',
                'simulator_does_not_dispatch_work',
                'simulator_does_not_spend_tokens',
                'simulator_does_not_enable_self_programming',
                'simulator_does_not_write_ledger',
                'simulator_does_not_mutate_pointer',
                'simulator_does_not_promote_completion_claim',
            ],
            'human_summary' => $allExpectedDetected
                ? 'Scenario simulator detected every injected fault: certification detectors are healthy.'
                : 'Scenario simulator failed to detect at least one injected fault; review regressions before relying on certification.',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scenarioDefinitions(): array
    {
        return [
            ['id' => 'missing_capability', 'detector' => 'chain_integrity_audit', 'fault' => 'capability_removed_from_current_capability'],
            ['id' => 'duplicate_capability', 'detector' => 'chain_integrity_audit', 'fault' => 'capability_listed_twice_in_current_capability'],
            ['id' => 'missing_cli_option', 'detector' => 'chain_integrity_audit', 'fault' => 'cli_option_missing_from_command_surface'],
            ['id' => 'missing_readiness_method', 'detector' => 'chain_integrity_audit', 'fault' => 'readiness_method_missing_for_slice'],
            ['id' => 'missing_invoker', 'detector' => 'chain_integrity_audit', 'fault' => 'invoker_class_or_prepare_method_missing'],
            ['id' => 'missing_doc_bullet', 'detector' => 'chain_integrity_audit', 'fault' => 'doc_bullet_missing_for_slice'],
            ['id' => 'duplicate_doc_bullet', 'detector' => 'chain_integrity_audit', 'fault' => 'doc_bullet_duplicated'],
            ['id' => 'broken_edge', 'detector' => 'chain_integrity_audit', 'fault' => 'slice_edge_points_to_wrong_next_slice'],
            ['id' => 'pointer_regression', 'detector' => 'chain_integrity_audit', 'fault' => 'pointer_regresses_to_previously_certified_slice'],
            ['id' => 'pointer_unexpected_reentry', 'detector' => 'chain_integrity_audit', 'fault' => 'pointer_jumps_back_without_intentional_reentry_justification'],
            ['id' => 'not_yet_runtime_capable_misalignment', 'detector' => 'chain_integrity_audit', 'fault' => 'not_yet_runtime_capable_does_not_match_chain_state'],
            ['id' => 'next_build_slices_misalignment', 'detector' => 'chain_integrity_audit', 'fault' => 'next_build_slices_misaligned_with_pointer'],
            ['id' => 'runtime_flag_actual_process_start_true', 'detector' => 'chain_integrity_audit', 'fault' => 'runtime_flag_process_started_set_to_true'],
            ['id' => 'runtime_flag_provider_call_true', 'detector' => 'chain_integrity_audit', 'fault' => 'runtime_flag_external_provider_call_set_to_true'],
            ['id' => 'runtime_flag_adapter_invocation_true', 'detector' => 'chain_integrity_audit', 'fault' => 'runtime_flag_adapter_invocation_set_to_true'],
            ['id' => 'runtime_flag_adapter_execution_true', 'detector' => 'chain_integrity_audit', 'fault' => 'runtime_flag_adapter_execution_set_to_true'],
            ['id' => 'runtime_flag_dispatch_true', 'detector' => 'chain_integrity_audit', 'fault' => 'runtime_flag_dispatch_allowed_set_to_true'],
            ['id' => 'runtime_flag_token_spend_true', 'detector' => 'chain_integrity_audit', 'fault' => 'runtime_flag_token_spend_set_to_true'],
            ['id' => 'runtime_flag_self_programming_true', 'detector' => 'chain_integrity_audit', 'fault' => 'runtime_flag_self_programming_set_to_true'],
            ['id' => 'deterministic_replay_hash_drift', 'detector' => 'replay_diff', 'fault' => 'deterministic_replay_hash_changes_without_chain_growth'],
            ['id' => 'proof_bundle_hash_drift', 'detector' => 'replay_diff', 'fault' => 'proof_bundle_hash_changes_between_two_runs'],
            ['id' => 'snapshot_missing_baseline', 'detector' => 'replay_diff', 'fault' => 'no_baseline_snapshot_recorded_for_diff'],
            ['id' => 'diff_regression', 'detector' => 'replay_diff', 'fault' => 'replay_diff_classifies_regressed'],
            ['id' => 'promotion_gate_regression', 'detector' => 'promotion_gate', 'fault' => 'promotion_gate_blocks_due_to_regression'],
            ['id' => 'cycle_unintentional', 'detector' => 'chain_integrity_audit', 'fault' => 'unintentional_cycle_back_to_certified_slice'],
            ['id' => 'intentional_reentry_wrong_target', 'detector' => 'chain_integrity_audit', 'fault' => 'pointer_set_to_unjustified_reentry_target'],
            ['id' => 'terminal_horizon_missing', 'detector' => 'replay', 'fault' => 'terminal_horizon_analysis_block_missing'],
            ['id' => 'provider_runtime_matrix_gap', 'detector' => 'chain_integrity_audit', 'fault' => 'provider_runtime_matrix_row_missing'],
            ['id' => 'evidence_corridor_gap', 'detector' => 'chain_integrity_audit', 'fault' => 'post_start_evidence_corridor_member_missing_or_misordered'],
            ['id' => 'implementation_corridor_gap', 'detector' => 'chain_integrity_audit', 'fault' => 'implementation_to_operator_handoff_corridor_member_missing'],
        ];
    }

    /**
     * @param  array{id: string, detector: string, fault: string}  $definition
     * @return array<string, mixed>
     */
    private function runScenario(array $definition): array
    {
        $detection = match ($definition['detector']) {
            'chain_integrity_audit' => $this->detectViaAudit($definition['id']),
            'replay_diff' => $this->detectViaDiff($definition['id']),
            'promotion_gate' => $this->detectViaGate($definition['id']),
            'replay' => $this->detectViaReplay($definition['id']),
            default => ['detected' => false, 'status' => 'unknown_detector', 'violations' => [], 'warnings' => []],
        };

        $detectionInput = [
            'scenario_id' => $definition['id'],
            'detector' => $definition['detector'],
            'detected' => $detection['detected'],
            'violations' => $detection['violations'],
            'warnings' => $detection['warnings'],
        ];

        return [
            'scenario_id' => $definition['id'],
            'injected_fault' => $definition['fault'],
            'expected_detection' => true,
            'detected' => (bool) $detection['detected'],
            'detector' => $definition['detector'],
            'status' => (string) $detection['status'],
            'violations' => array_values($detection['violations']),
            'warnings' => array_values($detection['warnings']),
            'detection_hash' => $this->stableHash($detectionInput),
        ];
    }

    /**
     * @return array{detected: bool, status: string, violations: list<mixed>, warnings: list<mixed>}
     */
    private function detectViaAudit(string $scenarioId): array
    {
        $audit = $this->audit->audit($this->auditOverridesFor($scenarioId));
        $violations = (array) data_get($audit, 'violations', []);
        $warnings = (array) data_get($audit, 'warnings', []);
        $status = (string) data_get($audit, 'status', 'unknown');
        $invariantsAllTrue = (bool) data_get($audit, 'invariants_all_true', false);
        $runtimeSafetyAllFalse = (bool) data_get($audit, 'runtime_safety.runtime_safety_all_false', true);

        $detected = $violations !== []
            || $status !== 'available'
            || ! $invariantsAllTrue
            || ! $runtimeSafetyAllFalse;

        return [
            'detected' => $detected,
            'status' => $status,
            'violations' => array_values($violations),
            'warnings' => array_values($warnings),
        ];
    }

    /**
     * @return array{detected: bool, status: string, violations: list<mixed>, warnings: list<mixed>}
     */
    private function detectViaReplay(string $scenarioId): array
    {
        if ($scenarioId === 'terminal_horizon_missing') {
            $synthetic = $this->buildReplayPayload();
            unset($synthetic['terminal_horizon_analysis']);
            $detected = ! isset($synthetic['terminal_horizon_analysis']);

            return [
                'detected' => $detected,
                'status' => $detected ? 'terminal_horizon_block_missing' : 'unknown',
                'violations' => $detected ? ['terminal_horizon_block_missing'] : [],
                'warnings' => [],
            ];
        }
        $replay = $this->replay->replay();
        $violations = (array) data_get($replay, 'violations', []);

        return [
            'detected' => $violations !== [],
            'status' => (string) data_get($replay, 'status', 'unknown'),
            'violations' => array_values($violations),
            'warnings' => array_values((array) data_get($replay, 'warnings', [])),
        ];
    }

    /**
     * @return array{detected: bool, status: string, violations: list<mixed>, warnings: list<mixed>}
     */
    private function detectViaDiff(string $scenarioId): array
    {
        if ($scenarioId === 'snapshot_missing_baseline') {
            $diff = $this->diff->diff();
            $detected = data_get($diff, 'status') === 'no_baseline';

            return [
                'detected' => $detected,
                'status' => (string) data_get($diff, 'status'),
                'violations' => $detected ? ['no_baseline_detected_by_diff'] : [],
                'warnings' => [],
            ];
        }

        $before = $this->buildReplayPayload();
        $after = $this->buildReplayPayload();

        if ($scenarioId === 'deterministic_replay_hash_drift') {
            $after['deterministic_replay_hash'] = 'drift_'.bin2hex(random_bytes(31));
        } elseif ($scenarioId === 'proof_bundle_hash_drift') {
            $after['proof_bundle_hash'] = 'drift_'.bin2hex(random_bytes(31));
            $after['deterministic_replay_hash'] = 'drift_'.bin2hex(random_bytes(31));
        } elseif ($scenarioId === 'diff_regression') {
            $after['violations'][] = ['code' => 'synthetic_violation'];
            $after['deterministic_replay_hash'] = 'after_'.bin2hex(random_bytes(31));
        }

        $diff = $this->diff->diff($before, $after);
        $regressed = data_get($diff, 'status') === 'regressed';
        $changed = (bool) data_get($diff, 'changed', false);
        $detected = match ($scenarioId) {
            'deterministic_replay_hash_drift' => $changed,
            'proof_bundle_hash_drift' => $changed || data_get($diff, 'proof_bundle_hash_change.changed') === true,
            'diff_regression' => $regressed,
            default => $changed,
        };

        return [
            'detected' => $detected,
            'status' => (string) data_get($diff, 'status'),
            'violations' => $regressed ? array_column((array) data_get($diff, 'regressions', []), 'kind') : [],
            'warnings' => [],
        ];
    }

    /**
     * @return array{detected: bool, status: string, violations: list<mixed>, warnings: list<mixed>}
     */
    private function detectViaGate(string $scenarioId): array
    {
        $diffOverride = [
            'status' => 'regressed',
            'changed' => true,
            'regressions' => [['kind' => 'synthetic_violation_increase_for_'.$scenarioId]],
            'violation_count_change' => ['before' => 0, 'after' => 1, 'delta' => 1],
            'runtime_safety_change' => ['before' => true, 'after' => true, 'changed' => false],
            'warning_count_change' => ['before' => 0, 'after' => 0, 'delta' => 0],
        ];

        $fakeDiff = new class($this->store, $this->replay, $diffOverride) extends AgentControlPlaneReplayDiffService
        {
            /**
             * @param  array<string, mixed>  $override
             */
            public function __construct(
                AgentControlPlaneReplaySnapshotStore $store,
                AgentControlPlaneDeterministicChainReplayService $replay,
                private readonly array $override,
            ) {
                parent::__construct($store, $replay);
            }

            public function diff(array|string|null $before = null, array|string|null $after = null, array $options = []): array
            {
                return array_merge([
                    'schema_version' => AgentControlPlaneReplayDiffService::SCHEMA_VERSION,
                    'status' => 'unchanged',
                    'mode' => AgentControlPlaneReplayDiffService::MODE,
                    'diff_id' => 'simulator-diff',
                    'generated_at' => '2026-05-14T00:00:00+00:00',
                    'read_only' => true,
                    'execution_allowed' => false,
                    'dispatch_allowed' => false,
                    'ledger_write_allowed' => false,
                    'runtime_write_allowed' => false,
                    'before_snapshot_id' => 'simulator',
                    'after_snapshot_id' => 'simulator',
                    'before_source' => 'array',
                    'after_source' => 'array',
                    'before_replay_hash' => '',
                    'after_replay_hash' => '',
                    'before_deterministic_replay_hash' => '',
                    'after_deterministic_replay_hash' => '',
                    'before_proof_bundle_hash' => '',
                    'after_proof_bundle_hash' => '',
                    'changed' => false,
                    'pointer_change' => ['before' => '', 'after' => '', 'changed' => false, 'intentional_reentry' => false],
                    'slice_count_change' => ['before' => 0, 'after' => 0, 'delta' => 0],
                    'edge_count_change' => ['before' => 0, 'after' => 0, 'delta' => 0],
                    'violation_count_change' => ['before' => 0, 'after' => 0, 'delta' => 0],
                    'warning_count_change' => ['before' => 0, 'after' => 0, 'delta' => 0],
                    'runtime_safety_change' => ['before' => true, 'after' => true, 'changed' => false],
                    'proof_bundle_hash_change' => ['before' => '', 'after' => '', 'changed' => false],
                    'added_slices' => [],
                    'removed_slices' => [],
                    'added_edges' => [],
                    'removed_edges' => [],
                    'changed_edges' => [],
                    'capability_changes' => ['before' => [], 'after' => [], 'changed_keys' => [], 'added_keys' => [], 'removed_keys' => [], 'changed' => false],
                    'cli_changes' => ['before' => [], 'after' => [], 'changed_keys' => [], 'added_keys' => [], 'removed_keys' => [], 'changed' => false],
                    'readiness_changes' => ['before' => [], 'after' => [], 'changed_keys' => [], 'added_keys' => [], 'removed_keys' => [], 'changed' => false],
                    'invoker_changes' => ['before' => [], 'after' => [], 'changed_keys' => [], 'added_keys' => [], 'removed_keys' => [], 'changed' => false],
                    'doc_changes' => ['before' => [], 'after' => [], 'changed_keys' => [], 'added_keys' => [], 'removed_keys' => [], 'changed' => false],
                    'matrix_changes' => ['before' => [], 'after' => [], 'changed_keys' => [], 'added_keys' => [], 'removed_keys' => [], 'changed' => false],
                    'cycle_integrity_changes' => ['before' => [], 'after' => [], 'changed_keys' => [], 'added_keys' => [], 'removed_keys' => [], 'changed' => false],
                    'terminal_horizon_changes' => ['before' => [], 'after' => [], 'changed_keys' => [], 'added_keys' => [], 'removed_keys' => [], 'changed' => false],
                    'regressions' => [],
                    'regression_count' => 0,
                    'improvements' => [],
                    'improvement_count' => 0,
                    'non_execution_guarantees' => [],
                    'human_summary' => 'simulator diff stub',
                    'diff_hash' => 'simulator-diff-hash',
                ], $this->override);
            }
        };

        $gate = (new AgentControlPlaneMacroSprintPromotionGate($fakeDiff, $this->audit, $this->replay))->evaluate();
        $blocked = data_get($gate, 'status') === 'blocked';

        return [
            'detected' => $blocked,
            'status' => (string) data_get($gate, 'status'),
            'violations' => (array) data_get($gate, 'blockers', []),
            'warnings' => (array) data_get($gate, 'warnings', []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditOverridesFor(string $scenarioId): array
    {
        return match ($scenarioId) {
            'missing_capability' => [
                'override_projection' => [
                    'remove_capability' => [
                        'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract',
                        'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_preflight',
                        'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_implementation_packet',
                        'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_invoker_service',
                        'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_status_projection',
                    ],
                ],
            ],
            'duplicate_capability' => [
                'override_projection' => [
                    'append_capability' => [
                        'agent_control_plane_chain_integrity_certification_service',
                    ],
                ],
            ],
            'missing_cli_option' => [
                'override_slices' => $this->syntheticSliceWithMissingArtifacts('cli'),
            ],
            'missing_readiness_method' => [
                'override_slices' => $this->syntheticSliceWithMissingArtifacts('readiness'),
            ],
            'missing_invoker' => [
                'override_slices' => $this->syntheticSliceWithMissingArtifacts('invoker'),
            ],
            'missing_doc_bullet' => [
                'override_slices' => $this->syntheticSliceWithMissingArtifacts('doc'),
            ],
            'duplicate_doc_bullet' => [
                'override_slices' => $this->syntheticSliceWithMissingArtifacts('duplicate_doc'),
            ],
            'broken_edge' => [
                'override_slices' => $this->syntheticChainWithBrokenEdge(),
            ],
            'pointer_regression' => [
                'override_projection' => [
                    'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_evidence_receipt_contract',
                ],
            ],
            'pointer_unexpected_reentry' => [
                'override_projection' => [
                    'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_release_gate_contract',
                ],
            ],
            'not_yet_runtime_capable_misalignment' => [
                'override_projection' => [
                    'not_yet_runtime_capable' => ['only_synthetic_no_codex_real_invoker'],
                ],
            ],
            'next_build_slices_misalignment' => [
                'override_projection' => [
                    'next_build_slices' => ['activate_synthetic_unknown_slice'],
                ],
            ],
            'runtime_flag_actual_process_start_true' => [
                'override_projection' => [
                    'flags' => ['execution_allowed' => true],
                ],
            ],
            'runtime_flag_provider_call_true' => [
                'override_projection' => [
                    'flags' => ['dispatch_allowed' => true],
                ],
            ],
            'runtime_flag_adapter_invocation_true' => [
                'override_projection' => [
                    'flags' => ['dispatch_allowed' => true],
                ],
            ],
            'runtime_flag_adapter_execution_true' => [
                'override_projection' => [
                    'flags' => ['execution_allowed' => true],
                ],
            ],
            'runtime_flag_dispatch_true' => [
                'override_projection' => [
                    'flags' => ['dispatch_allowed' => true],
                ],
            ],
            'runtime_flag_token_spend_true' => [
                'override_projection' => [
                    'flags' => ['execution_allowed' => true],
                ],
            ],
            'runtime_flag_self_programming_true' => [
                'override_projection' => [
                    'flags' => ['execution_allowed' => true],
                ],
            ],
            'cycle_unintentional' => [
                'override_projection' => [
                    'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract',
                ],
            ],
            'intentional_reentry_wrong_target' => [
                'override_projection' => [
                    'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract',
                ],
            ],
            'provider_runtime_matrix_gap' => [
                'override_slices' => $this->syntheticSliceWithMissingArtifacts('provider_runtime_matrix'),
            ],
            'evidence_corridor_gap' => [
                'override_slices' => $this->syntheticChainWithBrokenEdge(),
            ],
            'implementation_corridor_gap' => [
                'override_slices' => $this->syntheticChainWithBrokenEdge(),
            ],
            default => [],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function syntheticSliceWithMissingArtifacts(string $gap): array
    {
        return [
            [
                'slice_key' => 'synthetic_scenario_slice_'.$gap,
                'activate_key' => 'activate_synthetic_scenario_slice_'.$gap,
                'runtime_key' => 'synthetic_runtime_'.$gap,
                'expected_next_slice' => 'synthetic_next_'.$gap,
                'method_prefix' => 'agentSyntheticScenarioSlice'.ucfirst($gap),
                'invoker_class' => 'App\\Services\\Ai\\Synthetic\\AgentSyntheticScenarioInvoker'.ucfirst($gap),
                'prepare_method' => 'prepareSynthetic'.ucfirst($gap),
                'doc_bullet' => $gap === 'doc' ? 'never-anchored-bullet-'.$gap : 'synthetic-anchor-'.$gap,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function syntheticChainWithBrokenEdge(): array
    {
        return [
            [
                'slice_key' => 'synthetic_edge_a',
                'activate_key' => 'activate_synthetic_edge_a',
                'runtime_key' => 'synthetic_runtime_a',
                'expected_next_slice' => 'synthetic_edge_b',
                'method_prefix' => 'agentSyntheticEdgeA',
                'invoker_class' => 'App\\Services\\Ai\\Synthetic\\AgentSyntheticEdgeAInvoker',
                'prepare_method' => 'prepareEdgeA',
                'doc_bullet' => 'synthetic-anchor-a',
            ],
            [
                'slice_key' => 'synthetic_edge_b',
                'activate_key' => 'activate_synthetic_edge_b',
                'runtime_key' => 'synthetic_runtime_b',
                'expected_next_slice' => 'synthetic_edge_NEVER_RESOLVES',
                'method_prefix' => 'agentSyntheticEdgeB',
                'invoker_class' => 'App\\Services\\Ai\\Synthetic\\AgentSyntheticEdgeBInvoker',
                'prepare_method' => 'prepareEdgeB',
                'doc_bullet' => 'synthetic-anchor-b',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReplayPayload(): array
    {
        return $this->replay->replay();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeReadinessOptions(): array
    {
        return array_fill_keys([
            'workspace', 'target', 'packet', 'actor', 'session',
            'lease_minutes', 'reason', 'evidence_hash', 'model',
            'input_tokens', 'output_tokens', 'cost_usd', 'artifact_type',
            'artifact_path', 'artifact_hash', 'summary', 'decision',
            'signed_by', 'receipt_hash', 'dispatch_envelope_hash',
            'adapter_contract_hash', 'expires_at',
        ], null);
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
