<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\Codex;

use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentDispatchProviderSection;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerManualStartExecutorReceiptWriter;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerOperatorStartHandoffBuilder;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartReceiptContractBuilder;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerProcessStarterReadinessGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerStartExecutionGate;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;
use Illuminate\Support\Facades\Schema;

/**
 * AGENT CODEX projection sub-section 06 of 11, sub-split from the god
 * {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling agentCodex* pipeline calls route through the
 * injected Section facade (`$this->section->agentCodex*`), which re-dispatches
 * to whichever sub-section owns the target stage. The dispatch-provider
 * collaborator is injected verbatim for the one stage that consumes it.
 *
 * Stage range: agentCodexRealInvokerProcessStarterReadinessGateContractTemplate
 *           .. agentCodexRealInvokerPostStartReceiptContractBuilderImplementationPacket
 */
final class CodexPart06SubSection
{
    public function __construct(
        private readonly ReadinessProjectionAgentCodexSection $section,
        private readonly ReadinessProjectionAgentDispatchProviderSection $agentDispatchProviderSection,
    ) {}

public function agentCodexRealInvokerProcessStarterReadinessGateContractTemplate(array $options = []): array
    {
        $startGatePayload = $this->section->agentCodexRealInvokerStartExecutionGatePreflight($options);

        $template = [
            'status' => 'codex_real_invoker_process_starter_readiness_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-PROCESS-STARTER-READINESS-'.strtoupper(substr(ReadinessHash::stable([
                'codex_real_invoker_start_execution_gate_preflight_hash' => data_get($startGatePayload, 'codex_real_invoker_start_execution_gate_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_start_execution_gate_status' => data_get($startGatePayload, 'status'),
            'source_codex_real_invoker_start_execution_gate_preflight_hash' => data_get($startGatePayload, 'codex_real_invoker_start_execution_gate_preflight_hash'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerProcessStarterReadinessGate',
                'method' => 'prepareProcessStarter',
                'input_contract' => [
                    'run_key',
                    'codex_execution_id',
                    'real_invoker_executor_plan_id',
                    'real_invoker_executor_fresh_release_id',
                    'real_invoker_executor_enablement_id',
                    'real_invoker_supervised_start_activation_id',
                    'real_invoker_guarded_process_start_id',
                    'real_invoker_final_process_start_authorization_id',
                    'real_invoker_actual_process_start_rehearsal_id',
                    'real_invoker_process_start_envelope_id',
                    'real_invoker_start_execution_gate_id',
                    'real_invoker_process_starter_readiness_gate_id',
                    'operator_execution_gate_receipt_hash',
                    'execution_gate_policy_hash',
                    'execution_window_hash',
                    'preflight_snapshot_hash',
                    'rollback_readiness_hash',
                    'human_start_signature_hash',
                    'process_starter_manifest_hash',
                    'supervisor_binding_hash',
                    'liveness_monitor_binding_hash',
                    'cancellation_contract_hash',
                    'output_capture_contract_hash',
                    'cost_meter_contract_hash',
                    'start_replay_guard_hash',
                    'operator_process_starter_signature_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'real_invoker_process_starter_readiness_gate_id',
                    'real_invoker_start_execution_gate_id',
                    'agent_run_id',
                    'run_key',
                    'provider',
                    'adapter',
                    'real_invoker_process_starter_readiness_gate_prepared',
                    'process_starter_ready',
                    'actual_process_start_allowed',
                    'external_process_started',
                    'token_spend_allowed',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'real_invoker_process_starter_readiness_gate_must' => [
                'require_codex_real_invoker_start_execution_gate',
                'require_process_starter_manifest_hash',
                'require_supervisor_binding_hash',
                'require_liveness_monitor_binding_hash',
                'require_cancellation_contract_hash',
                'require_output_capture_contract_hash',
                'require_cost_meter_contract_hash',
                'require_operator_process_starter_signature_hash',
                'record_append_only_process_starter_readiness_event_before_any_actual_start',
            ],
            'real_invoker_process_starter_readiness_gate_must_not' => [
                'start_codex_process_from_contract_template',
                'spawn_shell_or_subprocess',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'dispatch_work_to_provider',
                'complete_or_merge_packet',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerProcessStarterReadinessGate.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerProcessStarterReadinessGateTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'process_starter_readiness_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-process-starter-readiness-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_process_starter_readiness_gate_contract_template.v1',
            'status' => 'codex_real_invoker_process_starter_readiness_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_process_starter_readiness_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_process_starter_readiness_gate_contract_template' => $template,
            'codex_real_invoker_process_starter_readiness_gate_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_process_starter_readiness_gate_contract_template_does_not_start_codex',
                'agent_codex_real_invoker_process_starter_readiness_gate_contract_template_does_not_call_codex',
                'agent_codex_real_invoker_process_starter_readiness_gate_contract_template_does_not_spend_tokens',
                'agent_codex_real_invoker_process_starter_readiness_gate_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker process starter readiness contract template prepares the starter boundary only; it does not start Codex.',
        ];
    }


public function agentCodexRealInvokerProcessStarterReadinessGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerProcessStarterReadinessGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_process_starter_readiness_gate_contract_template', []);
        $readinessClass = AgentCodexRealInvokerProcessStarterReadinessGate::class;
        $startGateClass = AgentCodexRealInvokerStartExecutionGate::class;
        $readinessReady = class_exists($readinessClass);
        $startGateReady = class_exists($startGateClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $readinessReady ? null : 'codex_real_invoker_process_starter_readiness_gate_missing',
            $startGateReady ? null : 'codex_real_invoker_start_execution_gate_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_process_starter_readiness_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_process_starter_readiness_gate_contract_template_hash'),
            'source_codex_real_invoker_start_execution_gate_status' => data_get($contract, 'source_codex_real_invoker_start_execution_gate_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_process_starter_readiness_gate_ready' => $readinessReady,
                'codex_real_invoker_start_execution_gate_ready' => $startGateReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'process_starter_readiness_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_real_invoker_process_starter_readiness_gate',
                'require_codex_real_invoker_start_execution_gate_metadata',
                'require_process_starter_manifest_hash',
                'require_supervisor_liveness_cancellation_output_and_cost_hashes',
                'record_process_starter_readiness_event_without_starting_codex',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerProcessStarterReadinessGate.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerProcessStarterReadinessGateTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_process_starter_readiness_gate',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'process_starter_readiness_file_creation_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-process-starter-readiness-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_process_starter_readiness_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_process_starter_readiness_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_process_starter_readiness_gate_preflight' => $preflight,
            'codex_real_invoker_process_starter_readiness_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_process_starter_readiness_gate_preflight_does_not_start_codex',
                'agent_codex_real_invoker_process_starter_readiness_gate_preflight_does_not_call_codex',
                'agent_codex_real_invoker_process_starter_readiness_gate_preflight_does_not_spend_tokens',
                'agent_codex_real_invoker_process_starter_readiness_gate_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker process starter readiness gate is ready; actual process start still requires a separate manually governed executor.'
                : 'Codex real invoker process starter readiness gate is blocked until the readiness and start gate prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerProcessStarterReadinessGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerProcessStarterReadinessGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_process_starter_readiness_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_process_starter_readiness_gate_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create Codex real invoker process starter readiness gate',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerProcessStarterReadinessGate.php'],
                'acceptance' => 'Gate prepares process starter readiness and never starts Codex, spends tokens or dispatches work.',
            ],
            [
                'id' => 'T2',
                'title' => 'Enforce start execution and process starter contracts',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerProcessStarterReadinessGate.php'],
                'acceptance' => 'Gate requires start execution metadata plus starter manifest, supervisor, liveness, cancellation, output, cost and signature hashes.',
            ],
            [
                'id' => 'T3',
                'title' => 'Add process starter readiness tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerProcessStarterReadinessGateTest.php'],
                'acceptance' => 'Tests prove missing start gate rejection, missing starter signature rejection, duplicate readiness rejection, rollback and no process/token/dispatch side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose process starter readiness commands',
                'type' => 'command_surface',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                    'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare actual process start disabled.',
            ],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_process_starter_readiness_gate_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-PROCESS-STARTER-READINESS-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker process starter readiness gate that validates the final starter boundary while still refusing to start Codex, spend tokens or dispatch work.',
            'non_goals' => [
                'do_not_call_codex_cli_or_codex_app',
                'do_not_spawn_processes_or_shell_commands',
                'do_not_spend_provider_tokens',
                'do_not_start_codex_process',
                'do_not_mark_runs_running_or_terminal',
                'do_not_change_packet_claim_completion_or_merge_state',
            ],
            'allowed_files' => $allowedFiles,
            'forbidden_scopes' => [
                'actual_codex_process_invocation',
                'provider_token_spend',
                'process_start',
                'merge_runtime',
                'packet_claim_or_completion_state',
                'hot_kernel_runtime',
                'voice_runtime',
                'policy_mutation',
            ],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => [
                'process_starter_readiness_requires_start_execution_gate_metadata',
                'process_starter_readiness_requires_operator_process_starter_signature_hash',
                'process_starter_readiness_requires_supervisor_binding_hash',
                'process_starter_readiness_is_idempotent_for_same_readiness_id',
                'process_starter_readiness_records_event_without_starting_codex',
            ],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'stop_conditions' => [
                'need_to_start_codex_or_spawn_process',
                'need_to_allow_provider_token_spend',
                'need_to_dispatch_work_to_provider',
                'need_to_modify_file_outside_allowed_files',
                'need_to_change_packet_claim_completion_or_merge_state',
                'test_requires_hot_runtime_or_voice_scope_change',
            ],
            'implementation_policy' => [
                'packet_is_read_only' => true,
                'implementation_allowed_by_packet' => true,
                'process_starter_readiness_allowed_by_packet' => false,
                'actual_process_start_allowed_by_packet' => false,
                'provider_process_start_allowed_by_packet' => false,
                'codex_invocation_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
            ],
            'next_required_action' => 'Implement only the allowed files, then run the required gates and report evidence.',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_process_starter_readiness_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_process_starter_readiness_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_process_starter_readiness_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_process_starter_readiness_gate_implementation_packet' => $packet,
            'codex_real_invoker_process_starter_readiness_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_process_starter_readiness_gate_implementation_packet_does_not_start_codex',
                'agent_codex_real_invoker_process_starter_readiness_gate_implementation_packet_does_not_call_codex',
                'agent_codex_real_invoker_process_starter_readiness_gate_implementation_packet_does_not_spend_tokens',
                'agent_codex_real_invoker_process_starter_readiness_gate_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker process starter readiness implementation packet is ready; it prepares the final starter boundary only and does not start Codex.',
        ];
    }


public function agentCodexRealInvokerManualStartExecutorReceiptWriterContractTemplate(array $options = []): array
    {
        $readinessPayload = $this->section->agentCodexRealInvokerProcessStarterReadinessGatePreflight($options);

        $template = [
            'status' => 'codex_real_invoker_manual_start_executor_receipt_writer_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-MANUAL-START-RECEIPT-'.strtoupper(substr(ReadinessHash::stable([
                'codex_real_invoker_process_starter_readiness_gate_preflight_hash' => data_get($readinessPayload, 'codex_real_invoker_process_starter_readiness_gate_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_process_starter_readiness_gate_status' => data_get($readinessPayload, 'status'),
            'source_codex_real_invoker_process_starter_readiness_gate_preflight_hash' => data_get($readinessPayload, 'codex_real_invoker_process_starter_readiness_gate_preflight_hash'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerManualStartExecutorReceiptWriter',
                'method' => 'writeManualStartExecutorReceipt',
                'input_contract' => [
                    'run_key',
                    'codex_execution_id',
                    'real_invoker_executor_plan_id',
                    'real_invoker_executor_fresh_release_id',
                    'real_invoker_executor_enablement_id',
                    'real_invoker_supervised_start_activation_id',
                    'real_invoker_guarded_process_start_id',
                    'real_invoker_final_process_start_authorization_id',
                    'real_invoker_actual_process_start_rehearsal_id',
                    'real_invoker_process_start_envelope_id',
                    'real_invoker_start_execution_gate_id',
                    'real_invoker_process_starter_readiness_gate_id',
                    'manual_start_executor_receipt_id',
                    'process_starter_manifest_hash',
                    'supervisor_binding_hash',
                    'liveness_monitor_binding_hash',
                    'cancellation_contract_hash',
                    'output_capture_contract_hash',
                    'cost_meter_contract_hash',
                    'start_replay_guard_hash',
                    'operator_process_starter_signature_hash',
                    'manual_start_command_hash',
                    'terminal_session_binding_hash',
                    'operator_presence_hash',
                    'live_supervisor_ack_hash',
                    'initial_liveness_probe_hash',
                    'kill_switch_ack_hash',
                    'output_stream_capture_hash',
                    'cost_meter_initial_hash',
                    'no_autostart_attestation_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'manual_start_executor_receipt_id',
                    'real_invoker_process_starter_readiness_gate_id',
                    'agent_run_id',
                    'run_key',
                    'provider',
                    'adapter',
                    'manual_start_executor_receipt_written',
                    'manual_operator_start_required',
                    'actual_process_start_allowed',
                    'external_process_started',
                    'token_spend_allowed',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'real_invoker_manual_start_executor_receipt_must' => [
                'require_codex_real_invoker_process_starter_readiness_gate',
                'require_manual_start_command_hash',
                'require_terminal_session_binding_hash',
                'require_operator_presence_hash',
                'require_live_supervisor_ack_hash',
                'require_initial_liveness_probe_hash',
                'require_kill_switch_ack_hash',
                'require_no_autostart_attestation_hash',
                'record_append_only_manual_start_executor_receipt_before_any_actual_start',
            ],
            'real_invoker_manual_start_executor_receipt_must_not' => [
                'start_codex_process_from_contract_template',
                'spawn_shell_or_subprocess',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'dispatch_work_to_provider',
                'complete_or_merge_packet',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerManualStartExecutorReceiptWriter.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerManualStartExecutorReceiptWriterTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'manual_start_executor_receipt_write_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-manual-start-executor-receipt-writer-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_manual_start_executor_receipt_writer_contract_template.v1',
            'status' => 'codex_real_invoker_manual_start_executor_receipt_writer_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_manual_start_executor_receipt_writer_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_manual_start_executor_receipt_writer_contract_template' => $template,
            'codex_real_invoker_manual_start_executor_receipt_writer_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_manual_start_executor_receipt_writer_contract_template_does_not_start_codex',
                'agent_codex_real_invoker_manual_start_executor_receipt_writer_contract_template_does_not_call_codex',
                'agent_codex_real_invoker_manual_start_executor_receipt_writer_contract_template_does_not_spend_tokens',
                'agent_codex_real_invoker_manual_start_executor_receipt_writer_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker manual start executor receipt writer contract template records a manual start contract only; it does not start Codex.',
        ];
    }


public function agentCodexRealInvokerManualStartExecutorReceiptWriterPreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerManualStartExecutorReceiptWriterContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_manual_start_executor_receipt_writer_contract_template', []);
        $writerClass = AgentCodexRealInvokerManualStartExecutorReceiptWriter::class;
        $readinessClass = AgentCodexRealInvokerProcessStarterReadinessGate::class;
        $writerReady = class_exists($writerClass);
        $readinessReady = class_exists($readinessClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $writerReady ? null : 'codex_real_invoker_manual_start_executor_receipt_writer_missing',
            $readinessReady ? null : 'codex_real_invoker_process_starter_readiness_gate_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_manual_start_executor_receipt_writer_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_manual_start_executor_receipt_writer_contract_template_hash'),
            'source_codex_real_invoker_process_starter_readiness_gate_status' => data_get($contract, 'source_codex_real_invoker_process_starter_readiness_gate_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_manual_start_executor_receipt_writer_ready' => $writerReady,
                'codex_real_invoker_process_starter_readiness_gate_ready' => $readinessReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'manual_start_executor_receipt_writer_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_real_invoker_manual_start_executor_receipt_writer',
                'require_codex_real_invoker_process_starter_readiness_gate_metadata',
                'require_manual_start_command_hash',
                'require_operator_presence_and_supervisor_ack_hashes',
                'record_manual_start_executor_receipt_without_starting_codex',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerManualStartExecutorReceiptWriter.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerManualStartExecutorReceiptWriterTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_manual_start_executor_receipt_writer',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'manual_start_executor_receipt_writer_file_creation_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-manual-start-executor-receipt-writer-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_manual_start_executor_receipt_writer_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_manual_start_executor_receipt_writer_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_manual_start_executor_receipt_writer_preflight' => $preflight,
            'codex_real_invoker_manual_start_executor_receipt_writer_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_manual_start_executor_receipt_writer_preflight_does_not_start_codex',
                'agent_codex_real_invoker_manual_start_executor_receipt_writer_preflight_does_not_call_codex',
                'agent_codex_real_invoker_manual_start_executor_receipt_writer_preflight_does_not_spend_tokens',
                'agent_codex_real_invoker_manual_start_executor_receipt_writer_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker manual start executor receipt writer is ready; actual Codex start still requires a manual external operator action.'
                : 'Codex real invoker manual start executor receipt writer is blocked until the writer and readiness prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerManualStartExecutorReceiptWriterImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerManualStartExecutorReceiptWriterPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_manual_start_executor_receipt_writer_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_manual_start_executor_receipt_writer_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create Codex real invoker manual start executor receipt writer',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerManualStartExecutorReceiptWriter.php'],
                'acceptance' => 'Writer records a manual start executor receipt and never starts Codex, spends tokens or dispatches work.',
            ],
            [
                'id' => 'T2',
                'title' => 'Enforce readiness and manual start receipt contracts',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerManualStartExecutorReceiptWriter.php'],
                'acceptance' => 'Writer requires process starter readiness metadata plus manual command, terminal, operator, supervisor, liveness, kill switch, output and no-autostart hashes.',
            ],
            [
                'id' => 'T3',
                'title' => 'Add manual start executor receipt tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerManualStartExecutorReceiptWriterTest.php'],
                'acceptance' => 'Tests prove missing readiness rejection, no-autostart attestation rejection, duplicate receipt rejection, rollback and no process/token/dispatch side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose manual start receipt readiness commands',
                'type' => 'command_surface',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                    'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare actual process start disabled.',
            ],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_manual_start_executor_receipt_writer_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-MANUAL-START-EXECUTOR-RECEIPT-WRITER-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker manual start executor receipt writer that records a governed manual external start contract while still refusing to start Codex, spend tokens or dispatch work.',
            'non_goals' => [
                'do_not_call_codex_cli_or_codex_app',
                'do_not_spawn_processes_or_shell_commands',
                'do_not_spend_provider_tokens',
                'do_not_start_codex_process',
                'do_not_mark_runs_running_or_terminal',
                'do_not_change_packet_claim_completion_or_merge_state',
            ],
            'allowed_files' => $allowedFiles,
            'forbidden_scopes' => [
                'actual_codex_process_invocation',
                'provider_token_spend',
                'process_start',
                'merge_runtime',
                'packet_claim_or_completion_state',
                'hot_kernel_runtime',
                'voice_runtime',
                'policy_mutation',
            ],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => [
                'manual_start_executor_receipt_requires_process_starter_readiness_metadata',
                'manual_start_executor_receipt_requires_no_autostart_attestation_hash',
                'manual_start_executor_receipt_requires_operator_presence_hash',
                'manual_start_executor_receipt_is_idempotent_for_same_receipt_id',
                'manual_start_executor_receipt_records_event_without_starting_codex',
            ],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'stop_conditions' => [
                'need_to_start_codex_or_spawn_process',
                'need_to_allow_provider_token_spend',
                'need_to_dispatch_work_to_provider',
                'need_to_modify_file_outside_allowed_files',
                'need_to_change_packet_claim_completion_or_merge_state',
                'test_requires_hot_runtime_or_voice_scope_change',
            ],
            'implementation_policy' => [
                'packet_is_read_only' => true,
                'implementation_allowed_by_packet' => true,
                'manual_start_executor_receipt_write_allowed_by_packet' => false,
                'actual_process_start_allowed_by_packet' => false,
                'provider_process_start_allowed_by_packet' => false,
                'codex_invocation_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
            ],
            'next_required_action' => 'Implement only the allowed files, then run the required gates and report evidence.',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_manual_start_executor_receipt_writer_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_manual_start_executor_receipt_writer_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_manual_start_executor_receipt_writer_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_manual_start_executor_receipt_writer_implementation_packet' => $packet,
            'codex_real_invoker_manual_start_executor_receipt_writer_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_manual_start_executor_receipt_writer_implementation_packet_does_not_start_codex',
                'agent_codex_real_invoker_manual_start_executor_receipt_writer_implementation_packet_does_not_call_codex',
                'agent_codex_real_invoker_manual_start_executor_receipt_writer_implementation_packet_does_not_spend_tokens',
                'agent_codex_real_invoker_manual_start_executor_receipt_writer_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker manual start executor receipt writer implementation packet is ready; it records a manual external start receipt only and does not start Codex.',
        ];
    }


public function agentCodexRealInvokerOperatorStartHandoffBuilderContractTemplate(array $options = []): array
    {
        $receiptPayload = $this->section->agentCodexRealInvokerManualStartExecutorReceiptWriterPreflight($options);

        $template = [
            'status' => 'codex_real_invoker_operator_start_handoff_builder_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-OPERATOR-START-HANDOFF-'.strtoupper(substr(ReadinessHash::stable([
                'codex_real_invoker_manual_start_executor_receipt_writer_preflight_hash' => data_get($receiptPayload, 'codex_real_invoker_manual_start_executor_receipt_writer_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_manual_start_executor_receipt_writer_status' => data_get($receiptPayload, 'status'),
            'source_codex_real_invoker_manual_start_executor_receipt_writer_preflight_hash' => data_get($receiptPayload, 'codex_real_invoker_manual_start_executor_receipt_writer_preflight_hash'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerOperatorStartHandoffBuilder',
                'method' => 'buildOperatorStartHandoff',
                'input_contract' => [
                    'run_key',
                    'codex_execution_id',
                    'real_invoker_executor_plan_id',
                    'real_invoker_executor_fresh_release_id',
                    'real_invoker_executor_enablement_id',
                    'real_invoker_supervised_start_activation_id',
                    'real_invoker_guarded_process_start_id',
                    'real_invoker_final_process_start_authorization_id',
                    'real_invoker_actual_process_start_rehearsal_id',
                    'real_invoker_process_start_envelope_id',
                    'real_invoker_start_execution_gate_id',
                    'real_invoker_process_starter_readiness_gate_id',
                    'manual_start_executor_receipt_id',
                    'operator_start_handoff_id',
                    'manual_start_command_hash',
                    'terminal_session_binding_hash',
                    'operator_presence_hash',
                    'live_supervisor_ack_hash',
                    'initial_liveness_probe_hash',
                    'kill_switch_ack_hash',
                    'output_stream_capture_hash',
                    'cost_meter_initial_hash',
                    'no_autostart_attestation_hash',
                    'handoff_packet_hash',
                    'operator_runbook_hash',
                    'external_terminal_handoff_hash',
                    'post_start_liveness_probe_contract_hash',
                    'post_start_receipt_contract_hash',
                    'failure_escalation_contract_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'operator_start_handoff_id',
                    'manual_start_executor_receipt_id',
                    'agent_run_id',
                    'run_key',
                    'provider',
                    'adapter',
                    'operator_start_handoff_built',
                    'manual_operator_start_required',
                    'actual_process_start_allowed',
                    'external_process_started',
                    'token_spend_allowed',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'real_invoker_operator_start_handoff_must' => [
                'require_codex_real_invoker_manual_start_executor_receipt',
                'require_handoff_packet_hash',
                'require_operator_runbook_hash',
                'require_external_terminal_handoff_hash',
                'require_post_start_liveness_probe_contract_hash',
                'require_post_start_receipt_contract_hash',
                'require_failure_escalation_contract_hash',
                'record_append_only_operator_start_handoff_before_any_actual_start',
            ],
            'real_invoker_operator_start_handoff_must_not' => [
                'start_codex_process_from_contract_template',
                'spawn_shell_or_subprocess',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'dispatch_work_to_provider',
                'complete_or_merge_packet',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerOperatorStartHandoffBuilder.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerOperatorStartHandoffBuilderTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'operator_start_handoff_write_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-operator-start-handoff-builder-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_operator_start_handoff_builder_contract_template.v1',
            'status' => 'codex_real_invoker_operator_start_handoff_builder_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_operator_start_handoff_builder_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_operator_start_handoff_builder_contract_template' => $template,
            'codex_real_invoker_operator_start_handoff_builder_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_operator_start_handoff_builder_contract_template_does_not_start_codex',
                'agent_codex_real_invoker_operator_start_handoff_builder_contract_template_does_not_call_codex',
                'agent_codex_real_invoker_operator_start_handoff_builder_contract_template_does_not_spend_tokens',
                'agent_codex_real_invoker_operator_start_handoff_builder_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker operator start handoff builder contract template prepares a manual operator handoff only; it does not start Codex.',
        ];
    }


public function agentCodexRealInvokerOperatorStartHandoffBuilderPreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerOperatorStartHandoffBuilderContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_operator_start_handoff_builder_contract_template', []);
        $builderClass = AgentCodexRealInvokerOperatorStartHandoffBuilder::class;
        $receiptWriterClass = AgentCodexRealInvokerManualStartExecutorReceiptWriter::class;
        $builderReady = class_exists($builderClass);
        $receiptWriterReady = class_exists($receiptWriterClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $builderReady ? null : 'codex_real_invoker_operator_start_handoff_builder_missing',
            $receiptWriterReady ? null : 'codex_real_invoker_manual_start_executor_receipt_writer_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_operator_start_handoff_builder_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_operator_start_handoff_builder_contract_template_hash'),
            'source_codex_real_invoker_manual_start_executor_receipt_writer_status' => data_get($contract, 'source_codex_real_invoker_manual_start_executor_receipt_writer_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_operator_start_handoff_builder_ready' => $builderReady,
                'codex_real_invoker_manual_start_executor_receipt_writer_ready' => $receiptWriterReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'operator_start_handoff_builder_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_real_invoker_operator_start_handoff_builder',
                'require_codex_real_invoker_manual_start_executor_receipt_metadata',
                'require_operator_runbook_and_handoff_hashes',
                'require_post_start_liveness_and_receipt_contract_hashes',
                'record_operator_start_handoff_without_starting_codex',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerOperatorStartHandoffBuilder.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerOperatorStartHandoffBuilderTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_operator_start_handoff_builder',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'operator_start_handoff_builder_file_creation_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-operator-start-handoff-builder-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_operator_start_handoff_builder_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_operator_start_handoff_builder_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_operator_start_handoff_builder_preflight' => $preflight,
            'codex_real_invoker_operator_start_handoff_builder_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_operator_start_handoff_builder_preflight_does_not_start_codex',
                'agent_codex_real_invoker_operator_start_handoff_builder_preflight_does_not_call_codex',
                'agent_codex_real_invoker_operator_start_handoff_builder_preflight_does_not_spend_tokens',
                'agent_codex_real_invoker_operator_start_handoff_builder_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker operator start handoff builder is ready; the actual Codex start still remains a manual external operator action.'
                : 'Codex real invoker operator start handoff builder is blocked until the builder and receipt writer prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerOperatorStartHandoffBuilderImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerOperatorStartHandoffBuilderPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_operator_start_handoff_builder_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_operator_start_handoff_builder_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create Codex real invoker operator start handoff builder',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerOperatorStartHandoffBuilder.php'],
                'acceptance' => 'Builder records an operator start handoff and never starts Codex, spends tokens or dispatches work.',
            ],
            [
                'id' => 'T2',
                'title' => 'Enforce manual receipt and handoff contracts',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerOperatorStartHandoffBuilder.php'],
                'acceptance' => 'Builder requires manual start executor receipt metadata plus handoff packet, operator runbook, terminal handoff, post-start liveness, post-start receipt and escalation hashes.',
            ],
            [
                'id' => 'T3',
                'title' => 'Add operator start handoff tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerOperatorStartHandoffBuilderTest.php'],
                'acceptance' => 'Tests prove missing manual receipt rejection, handoff hash rejection, duplicate handoff rejection, rollback and no process/token/dispatch side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose operator start handoff readiness commands',
                'type' => 'command_surface',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                    'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare actual process start disabled.',
            ],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_operator_start_handoff_builder_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-OPERATOR-START-HANDOFF-BUILDER-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker operator start handoff builder that prepares the manual external start package while still refusing to start Codex, spend tokens or dispatch work.',
            'non_goals' => [
                'do_not_call_codex_cli_or_codex_app',
                'do_not_spawn_processes_or_shell_commands',
                'do_not_spend_provider_tokens',
                'do_not_start_codex_process',
                'do_not_mark_runs_running_or_terminal',
                'do_not_change_packet_claim_completion_or_merge_state',
            ],
            'allowed_files' => $allowedFiles,
            'forbidden_scopes' => [
                'actual_codex_process_invocation',
                'provider_token_spend',
                'process_start',
                'merge_runtime',
                'packet_claim_or_completion_state',
                'hot_kernel_runtime',
                'voice_runtime',
                'policy_mutation',
            ],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => [
                'operator_start_handoff_requires_manual_start_executor_receipt_metadata',
                'operator_start_handoff_requires_handoff_packet_hash',
                'operator_start_handoff_requires_post_start_liveness_contract_hash',
                'operator_start_handoff_is_idempotent_for_same_handoff_id',
                'operator_start_handoff_records_event_without_starting_codex',
            ],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'stop_conditions' => [
                'need_to_start_codex_or_spawn_process',
                'need_to_allow_provider_token_spend',
                'need_to_dispatch_work_to_provider',
                'need_to_modify_file_outside_allowed_files',
                'need_to_change_packet_claim_completion_or_merge_state',
                'test_requires_hot_runtime_or_voice_scope_change',
            ],
            'implementation_policy' => [
                'packet_is_read_only' => true,
                'implementation_allowed_by_packet' => true,
                'operator_start_handoff_write_allowed_by_packet' => false,
                'actual_process_start_allowed_by_packet' => false,
                'provider_process_start_allowed_by_packet' => false,
                'codex_invocation_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
            ],
            'next_required_action' => 'Implement only the allowed files, then run the required gates and report evidence.',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_operator_start_handoff_builder_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_operator_start_handoff_builder_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_operator_start_handoff_builder_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_operator_start_handoff_builder_implementation_packet' => $packet,
            'codex_real_invoker_operator_start_handoff_builder_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_operator_start_handoff_builder_implementation_packet_does_not_start_codex',
                'agent_codex_real_invoker_operator_start_handoff_builder_implementation_packet_does_not_call_codex',
                'agent_codex_real_invoker_operator_start_handoff_builder_implementation_packet_does_not_spend_tokens',
                'agent_codex_real_invoker_operator_start_handoff_builder_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker operator start handoff builder implementation packet is ready; it prepares a manual external start handoff only and does not start Codex.',
        ];
    }


public function agentCodexRealInvokerPostStartReceiptContractBuilderContractTemplate(array $options = []): array
    {
        $handoffPayload = $this->section->agentCodexRealInvokerOperatorStartHandoffBuilderPreflight($options);

        $template = [
            'status' => 'codex_real_invoker_post_start_receipt_contract_builder_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-RECEIPT-CONTRACT-'.strtoupper(substr(ReadinessHash::stable([
                'codex_real_invoker_operator_start_handoff_builder_preflight_hash' => data_get($handoffPayload, 'codex_real_invoker_operator_start_handoff_builder_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_operator_start_handoff_builder_status' => data_get($handoffPayload, 'status'),
            'source_codex_real_invoker_operator_start_handoff_builder_preflight_hash' => data_get($handoffPayload, 'codex_real_invoker_operator_start_handoff_builder_preflight_hash'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartReceiptContractBuilder',
                'method' => 'buildPostStartReceiptContract',
                'input_contract' => [
                    'run_key',
                    'codex_execution_id',
                    'real_invoker_process_starter_readiness_gate_id',
                    'real_invoker_start_execution_gate_id',
                    'manual_start_executor_receipt_id',
                    'operator_start_handoff_id',
                    'post_start_receipt_contract_id',
                    'handoff_packet_hash',
                    'operator_runbook_hash',
                    'external_terminal_handoff_hash',
                    'post_start_liveness_probe_contract_hash',
                    'post_start_receipt_contract_hash',
                    'failure_escalation_contract_hash',
                    'external_process_identity_contract_hash',
                    'startup_evidence_contract_hash',
                    'terminal_pid_capture_contract_hash',
                    'post_start_cost_meter_contract_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'post_start_receipt_contract_id',
                    'operator_start_handoff_id',
                    'manual_start_executor_receipt_id',
                    'agent_run_id',
                    'run_key',
                    'provider',
                    'adapter',
                    'post_start_receipt_contract_built',
                    'actual_process_start_allowed',
                    'external_process_started',
                    'token_spend_allowed',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'real_invoker_post_start_receipt_contract_must' => [
                'require_codex_real_invoker_operator_start_handoff',
                'require_external_process_identity_contract_hash',
                'require_startup_evidence_contract_hash',
                'require_terminal_pid_capture_contract_hash',
                'require_post_start_cost_meter_contract_hash',
                'record_append_only_post_start_receipt_contract_before_accepting_external_process_evidence',
            ],
            'real_invoker_post_start_receipt_contract_must_not' => [
                'start_codex_process_from_contract_template',
                'spawn_shell_or_subprocess',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'accept_external_process_started_evidence',
                'dispatch_work_to_provider',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartReceiptContractBuilder.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartReceiptContractBuilderTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'post_start_receipt_contract_write_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'external_process_evidence_acceptance_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-receipt-contract-builder-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_receipt_contract_builder_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_receipt_contract_builder_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_receipt_contract_builder_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_receipt_contract_builder_contract_template' => $template,
            'codex_real_invoker_post_start_receipt_contract_builder_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_post_start_receipt_contract_builder_contract_template_does_not_start_codex',
                'agent_codex_real_invoker_post_start_receipt_contract_builder_contract_template_does_not_call_codex',
                'agent_codex_real_invoker_post_start_receipt_contract_builder_contract_template_does_not_spend_tokens',
                'agent_codex_real_invoker_post_start_receipt_contract_builder_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker post-start receipt contract builder contract template prepares future external-start evidence acceptance only; it does not start Codex.',
        ];
    }


public function agentCodexRealInvokerPostStartReceiptContractBuilderPreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartReceiptContractBuilderContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_receipt_contract_builder_contract_template', []);
        $builderClass = AgentCodexRealInvokerPostStartReceiptContractBuilder::class;
        $handoffClass = AgentCodexRealInvokerOperatorStartHandoffBuilder::class;
        $builderReady = class_exists($builderClass);
        $handoffReady = class_exists($handoffClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $builderReady ? null : 'codex_real_invoker_post_start_receipt_contract_builder_missing',
            $handoffReady ? null : 'codex_real_invoker_operator_start_handoff_builder_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_receipt_contract_builder_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_receipt_contract_builder_contract_template_hash'),
            'source_codex_real_invoker_operator_start_handoff_builder_status' => data_get($contract, 'source_codex_real_invoker_operator_start_handoff_builder_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_post_start_receipt_contract_builder_ready' => $builderReady,
                'codex_real_invoker_operator_start_handoff_builder_ready' => $handoffReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_receipt_contract_builder_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_real_invoker_post_start_receipt_contract_builder',
                'require_codex_real_invoker_operator_start_handoff_metadata',
                'require_external_process_identity_contract_hash',
                'require_startup_evidence_and_pid_capture_contract_hashes',
                'record_post_start_receipt_contract_without_accepting_external_process_evidence',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartReceiptContractBuilder.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartReceiptContractBuilderTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_receipt_contract_builder',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'post_start_receipt_contract_builder_file_creation_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'external_process_evidence_acceptance_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-receipt-contract-builder-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_receipt_contract_builder_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_receipt_contract_builder_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_receipt_contract_builder_preflight' => $preflight,
            'codex_real_invoker_post_start_receipt_contract_builder_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_post_start_receipt_contract_builder_preflight_does_not_start_codex',
                'agent_codex_real_invoker_post_start_receipt_contract_builder_preflight_does_not_call_codex',
                'agent_codex_real_invoker_post_start_receipt_contract_builder_preflight_does_not_spend_tokens',
                'agent_codex_real_invoker_post_start_receipt_contract_builder_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker post-start receipt contract builder is ready; it still does not accept external process evidence or start Codex.'
                : 'Codex real invoker post-start receipt contract builder is blocked until the builder and handoff prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartReceiptContractBuilderImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartReceiptContractBuilderPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_receipt_contract_builder_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_receipt_contract_builder_preflight_hash');

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create Codex real invoker post-start receipt contract builder',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartReceiptContractBuilder.php'],
                'acceptance' => 'Builder records a post-start receipt contract from operator handoff metadata and never starts Codex, accepts external process evidence, spends tokens or dispatches work.',
            ],
            [
                'id' => 'T2',
                'title' => 'Enforce operator handoff and external evidence contracts',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartReceiptContractBuilder.php'],
                'acceptance' => 'Builder requires operator handoff metadata plus external process identity, startup evidence, PID capture and cost meter contract hashes.',
            ],
            [
                'id' => 'T3',
                'title' => 'Add post-start receipt contract tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartReceiptContractBuilderTest.php'],
                'acceptance' => 'Tests prove missing handoff rejection, external identity contract rejection, duplicate contract rejection, rollback and no process/token/dispatch side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose post-start receipt contract readiness commands',
                'type' => 'command_surface',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                    'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare actual process start disabled.',
            ],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_receipt_contract_builder_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-RECEIPT-CONTRACT-BUILDER-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker post-start receipt contract builder that prepares future external process evidence acceptance while still refusing to start Codex, accept start evidence, spend tokens or dispatch work.',
            'non_goals' => [
                'do_not_call_codex_cli_or_codex_app',
                'do_not_spawn_processes_or_shell_commands',
                'do_not_accept_external_process_started_evidence',
                'do_not_spend_provider_tokens',
                'do_not_start_codex_process',
                'do_not_mark_runs_running_or_terminal',
            ],
            'allowed_files' => data_get($preflight, 'allowed_future_files', []),
            'forbidden_scopes' => [
                'actual_codex_process_invocation',
                'external_process_evidence_acceptance',
                'provider_token_spend',
                'process_start',
                'merge_runtime',
                'hot_kernel_runtime',
                'policy_mutation',
            ],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => [
                'post_start_receipt_contract_requires_operator_start_handoff_metadata',
                'post_start_receipt_contract_requires_external_process_identity_contract_hash',
                'post_start_receipt_contract_requires_startup_evidence_contract_hash',
                'post_start_receipt_contract_is_idempotent_for_same_contract_id',
                'post_start_receipt_contract_records_event_without_starting_codex',
            ],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'implementation_policy' => [
                'packet_is_read_only' => true,
                'implementation_allowed_by_packet' => true,
                'post_start_receipt_contract_write_allowed_by_packet' => false,
                'actual_process_start_allowed_by_packet' => false,
                'external_process_evidence_acceptance_allowed_by_packet' => false,
                'provider_process_start_allowed_by_packet' => false,
                'codex_invocation_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_receipt_contract_builder_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_receipt_contract_builder_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_receipt_contract_builder_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_receipt_contract_builder_implementation_packet' => $packet,
            'codex_real_invoker_post_start_receipt_contract_builder_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_post_start_receipt_contract_builder_implementation_packet_does_not_start_codex',
                'agent_codex_real_invoker_post_start_receipt_contract_builder_implementation_packet_does_not_call_codex',
                'agent_codex_real_invoker_post_start_receipt_contract_builder_implementation_packet_does_not_spend_tokens',
                'agent_codex_real_invoker_post_start_receipt_contract_builder_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker post-start receipt contract builder implementation packet is ready; it prepares a future evidence contract only and does not start Codex.',
        ];
    }


}
