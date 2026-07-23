<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\Codex;

use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentDispatchProviderSection;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerActualProcessStartRehearsalExecutor;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerFinalProcessStartAuthorizationGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerGuardedProcessStartExecutor;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerProcessStartEnvelopeBuilder;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerStartExecutionGate;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;
use Illuminate\Support\Facades\Schema;

/**
 * AGENT CODEX projection sub-section 05 of 11, sub-split from the god
 * {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling agentCodex* pipeline calls route through the
 * injected Section facade (`$this->section->agentCodex*`), which re-dispatches
 * to whichever sub-section owns the target stage. The dispatch-provider
 * collaborator is injected verbatim for the one stage that consumes it.
 *
 * Stage range: agentCodexRealInvokerFinalProcessStartAuthorizationGateContractTemplate
 *           .. agentCodexRealInvokerStartExecutionGateImplementationPacket
 */
final class CodexPart05SubSection
{
    public function __construct(
        private readonly ReadinessProjectionAgentCodexSection $section,
        private readonly ReadinessProjectionAgentDispatchProviderSection $agentDispatchProviderSection,
    ) {}

public function agentCodexRealInvokerFinalProcessStartAuthorizationGateContractTemplate(array $options = []): array
    {
        $guardedPayload = $this->section->agentCodexRealInvokerGuardedProcessStartExecutorPreflight($options);

        $template = [
            'status' => 'codex_real_invoker_final_process_start_authorization_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-FINAL-PROCESS-START-AUTH-'.strtoupper(substr(ReadinessHash::stable([
                'codex_real_invoker_guarded_process_start_executor_preflight_hash' => data_get($guardedPayload, 'codex_real_invoker_guarded_process_start_executor_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_guarded_process_start_executor_status' => data_get($guardedPayload, 'status'),
            'source_codex_real_invoker_guarded_process_start_executor_preflight_hash' => data_get($guardedPayload, 'codex_real_invoker_guarded_process_start_executor_preflight_hash'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerFinalProcessStartAuthorizationGate',
                'method' => 'authorizeFinalStart',
                'input_contract' => [
                    'run_key',
                    'codex_execution_id',
                    'real_invoker_executor_plan_id',
                    'real_invoker_executor_fresh_release_id',
                    'real_invoker_executor_enablement_id',
                    'real_invoker_supervised_start_activation_id',
                    'real_invoker_guarded_process_start_id',
                    'real_invoker_final_process_start_authorization_id',
                    'operator_final_start_receipt_hash',
                    'final_start_signature_hash',
                    'final_start_policy_hash',
                    'final_start_window_hash',
                    'final_start_replay_guard_hash',
                    'final_start_kill_switch_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'real_invoker_final_process_start_authorization_id',
                    'real_invoker_guarded_process_start_id',
                    'agent_run_id',
                    'run_key',
                    'provider',
                    'adapter',
                    'real_invoker_final_process_start_authorization_prepared',
                    'final_process_start_authorized',
                    'actual_process_start_allowed',
                    'external_process_started',
                    'token_spend_allowed',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'real_invoker_final_process_start_authorization_must' => [
                'require_codex_real_invoker_guarded_process_start',
                'require_operator_final_start_receipt_hash',
                'require_final_start_signature_hash',
                'require_final_start_policy_hash',
                'require_final_start_window_hash',
                'require_final_start_replay_guard_hash',
                'record_append_only_final_start_authorization_event_before_any_actual_start',
            ],
            'real_invoker_final_process_start_authorization_must_not' => [
                'start_codex_process_from_contract_template',
                'spawn_shell_or_subprocess',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'dispatch_work_to_provider',
                'complete_or_merge_packet',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerFinalProcessStartAuthorizationGate.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerFinalProcessStartAuthorizationGateTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'final_process_start_authorization_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-final-process-start-authorization-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_final_process_start_authorization_gate_contract_template.v1',
            'status' => 'codex_real_invoker_final_process_start_authorization_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_final_process_start_authorization_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_final_process_start_authorization_gate_contract_template' => $template,
            'codex_real_invoker_final_process_start_authorization_gate_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_final_process_start_authorization_gate_contract_template_does_not_start_codex',
                'agent_codex_real_invoker_final_process_start_authorization_gate_contract_template_does_not_call_codex',
                'agent_codex_real_invoker_final_process_start_authorization_gate_contract_template_does_not_spend_tokens',
                'agent_codex_real_invoker_final_process_start_authorization_gate_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker final process start authorization gate contract template records the final signed authorization but still separates authorization from actual process start.',
        ];
    }


public function agentCodexRealInvokerFinalProcessStartAuthorizationGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerFinalProcessStartAuthorizationGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_final_process_start_authorization_gate_contract_template', []);
        $authorizationClass = AgentCodexRealInvokerFinalProcessStartAuthorizationGate::class;
        $guardedStartClass = AgentCodexRealInvokerGuardedProcessStartExecutor::class;
        $authorizationReady = class_exists($authorizationClass);
        $guardedStartReady = class_exists($guardedStartClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $authorizationReady ? null : 'codex_real_invoker_final_process_start_authorization_gate_missing',
            $guardedStartReady ? null : 'codex_real_invoker_guarded_process_start_executor_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_final_process_start_authorization_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_final_process_start_authorization_gate_contract_template_hash'),
            'source_codex_real_invoker_guarded_process_start_executor_status' => data_get($contract, 'source_codex_real_invoker_guarded_process_start_executor_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_final_process_start_authorization_gate_ready' => $authorizationReady,
                'codex_real_invoker_guarded_process_start_executor_ready' => $guardedStartReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'final_process_start_authorization_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_real_invoker_final_process_start_authorization_gate',
                'require_codex_real_invoker_guarded_process_start_metadata',
                'require_operator_final_start_receipt_hash',
                'require_final_start_signature_policy_window_and_replay_guard_hashes',
                'record_final_start_authorization_event_without_starting_codex',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerFinalProcessStartAuthorizationGate.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerFinalProcessStartAuthorizationGateTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_final_process_start_authorization_gate',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'final_process_start_authorization_file_creation_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-final-process-start-authorization-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_final_process_start_authorization_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_final_process_start_authorization_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_final_process_start_authorization_gate_preflight' => $preflight,
            'codex_real_invoker_final_process_start_authorization_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_final_process_start_authorization_gate_preflight_does_not_start_codex',
                'agent_codex_real_invoker_final_process_start_authorization_gate_preflight_does_not_call_codex',
                'agent_codex_real_invoker_final_process_start_authorization_gate_preflight_does_not_spend_tokens',
                'agent_codex_real_invoker_final_process_start_authorization_gate_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker final process start authorization gate is ready; actual process start still requires a separate start executor.'
                : 'Codex real invoker final process start authorization gate is blocked until the authorization gate and guarded start prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerFinalProcessStartAuthorizationGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerFinalProcessStartAuthorizationGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_final_process_start_authorization_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_final_process_start_authorization_gate_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create Codex real invoker final process start authorization gate',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerFinalProcessStartAuthorizationGate.php'],
                'acceptance' => 'Gate records final signed start authorization and never starts Codex or permits token spend.',
            ],
            [
                'id' => 'T2',
                'title' => 'Enforce guarded start and final authorization contracts',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerFinalProcessStartAuthorizationGate.php'],
                'acceptance' => 'Gate requires guarded start metadata, final receipt, final signature, final policy, final window, replay guard and kill switch hashes.',
            ],
            [
                'id' => 'T3',
                'title' => 'Add final process start authorization tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerFinalProcessStartAuthorizationGateTest.php'],
                'acceptance' => 'Tests prove missing guarded start rejection, duplicate authorization rejection, rollback and no process/token/dispatch side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose final start authorization readiness commands',
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
            'status' => 'ready_for_scoped_codex_real_invoker_final_process_start_authorization_gate_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-FINAL-PROCESS-START-AUTHORIZATION-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker final process start authorization gate that records explicit final authorization while still refusing to start Codex, spend tokens or dispatch work.',
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
                'final_start_authorization_requires_guarded_process_start_metadata',
                'final_start_authorization_requires_operator_final_start_receipt_hash',
                'final_start_authorization_requires_final_start_signature_hash',
                'final_start_authorization_is_idempotent_for_same_authorization_id',
                'final_start_authorization_records_event_without_starting_codex',
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
                'final_process_start_authorization_allowed_by_packet' => false,
                'actual_process_start_allowed_by_packet' => false,
                'provider_process_start_allowed_by_packet' => false,
                'codex_invocation_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
            ],
            'next_required_action' => 'Implement only the allowed files, then run the required gates and report evidence.',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_final_process_start_authorization_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_final_process_start_authorization_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_final_process_start_authorization_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_final_process_start_authorization_gate_implementation_packet' => $packet,
            'codex_real_invoker_final_process_start_authorization_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_final_process_start_authorization_gate_implementation_packet_does_not_start_codex',
                'agent_codex_real_invoker_final_process_start_authorization_gate_implementation_packet_does_not_call_codex',
                'agent_codex_real_invoker_final_process_start_authorization_gate_implementation_packet_does_not_spend_tokens',
                'agent_codex_real_invoker_final_process_start_authorization_gate_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker final process start authorization gate implementation packet is ready; it records final authorization only and does not start Codex.',
        ];
    }


public function agentCodexRealInvokerActualProcessStartRehearsalExecutorContractTemplate(array $options = []): array
    {
        $authorizationPayload = $this->section->agentCodexRealInvokerFinalProcessStartAuthorizationGatePreflight($options);

        $template = [
            'status' => 'codex_real_invoker_actual_process_start_rehearsal_executor_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-ACTUAL-PROCESS-START-REHEARSAL-'.strtoupper(substr(ReadinessHash::stable([
                'codex_real_invoker_final_process_start_authorization_gate_preflight_hash' => data_get($authorizationPayload, 'codex_real_invoker_final_process_start_authorization_gate_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_final_process_start_authorization_gate_status' => data_get($authorizationPayload, 'status'),
            'source_codex_real_invoker_final_process_start_authorization_gate_preflight_hash' => data_get($authorizationPayload, 'codex_real_invoker_final_process_start_authorization_gate_preflight_hash'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerActualProcessStartRehearsalExecutor',
                'method' => 'rehearseActualStart',
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
                    'process_start_rehearsal_hash',
                    'command_resolution_hash',
                    'environment_resolution_hash',
                    'cwd_verification_hash',
                    'supervisor_dry_run_hash',
                    'liveness_probe_rehearsal_hash',
                    'operator_final_start_receipt_hash',
                    'final_start_signature_hash',
                    'final_start_policy_hash',
                    'final_start_window_hash',
                    'final_start_replay_guard_hash',
                    'final_start_kill_switch_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'real_invoker_actual_process_start_rehearsal_id',
                    'real_invoker_final_process_start_authorization_id',
                    'agent_run_id',
                    'run_key',
                    'provider',
                    'adapter',
                    'real_invoker_actual_process_start_rehearsal_prepared',
                    'process_start_rehearsed',
                    'actual_process_start_allowed',
                    'external_process_started',
                    'token_spend_allowed',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'real_invoker_actual_process_start_rehearsal_must' => [
                'require_codex_real_invoker_final_process_start_authorization',
                'require_process_start_rehearsal_hash',
                'require_command_resolution_hash',
                'require_environment_resolution_hash',
                'require_cwd_verification_hash',
                'require_supervisor_dry_run_hash',
                'require_liveness_probe_rehearsal_hash',
                'record_append_only_actual_process_start_rehearsal_event_before_any_actual_start',
            ],
            'real_invoker_actual_process_start_rehearsal_must_not' => [
                'start_codex_process_from_contract_template',
                'spawn_shell_or_subprocess',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'dispatch_work_to_provider',
                'complete_or_merge_packet',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerActualProcessStartRehearsalExecutor.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerActualProcessStartRehearsalExecutorTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'actual_process_start_rehearsal_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-actual-process-start-rehearsal-executor-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_actual_process_start_rehearsal_executor_contract_template.v1',
            'status' => 'codex_real_invoker_actual_process_start_rehearsal_executor_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_actual_process_start_rehearsal_executor_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_actual_process_start_rehearsal_executor_contract_template' => $template,
            'codex_real_invoker_actual_process_start_rehearsal_executor_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_actual_process_start_rehearsal_executor_contract_template_does_not_start_codex',
                'agent_codex_real_invoker_actual_process_start_rehearsal_executor_contract_template_does_not_call_codex',
                'agent_codex_real_invoker_actual_process_start_rehearsal_executor_contract_template_does_not_spend_tokens',
                'agent_codex_real_invoker_actual_process_start_rehearsal_executor_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker actual process start rehearsal executor contract template rehearses the final start path but does not start Codex.',
        ];
    }


public function agentCodexRealInvokerActualProcessStartRehearsalExecutorPreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerActualProcessStartRehearsalExecutorContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_actual_process_start_rehearsal_executor_contract_template', []);
        $rehearsalClass = AgentCodexRealInvokerActualProcessStartRehearsalExecutor::class;
        $authorizationClass = AgentCodexRealInvokerFinalProcessStartAuthorizationGate::class;
        $rehearsalReady = class_exists($rehearsalClass);
        $authorizationReady = class_exists($authorizationClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $rehearsalReady ? null : 'codex_real_invoker_actual_process_start_rehearsal_executor_missing',
            $authorizationReady ? null : 'codex_real_invoker_final_process_start_authorization_gate_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_actual_process_start_rehearsal_executor_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_actual_process_start_rehearsal_executor_contract_template_hash'),
            'source_codex_real_invoker_final_process_start_authorization_gate_status' => data_get($contract, 'source_codex_real_invoker_final_process_start_authorization_gate_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_actual_process_start_rehearsal_executor_ready' => $rehearsalReady,
                'codex_real_invoker_final_process_start_authorization_gate_ready' => $authorizationReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'actual_process_start_rehearsal_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_real_invoker_actual_process_start_rehearsal_executor',
                'require_codex_real_invoker_final_process_start_authorization_metadata',
                'require_process_start_rehearsal_hash',
                'require_command_environment_cwd_supervisor_and_liveness_rehearsal_hashes',
                'record_actual_process_start_rehearsal_event_without_starting_codex',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerActualProcessStartRehearsalExecutor.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerActualProcessStartRehearsalExecutorTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_actual_process_start_rehearsal_executor',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'actual_process_start_rehearsal_file_creation_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-actual-process-start-rehearsal-executor-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_actual_process_start_rehearsal_executor_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_actual_process_start_rehearsal_executor_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_actual_process_start_rehearsal_executor_preflight' => $preflight,
            'codex_real_invoker_actual_process_start_rehearsal_executor_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_actual_process_start_rehearsal_executor_preflight_does_not_start_codex',
                'agent_codex_real_invoker_actual_process_start_rehearsal_executor_preflight_does_not_call_codex',
                'agent_codex_real_invoker_actual_process_start_rehearsal_executor_preflight_does_not_spend_tokens',
                'agent_codex_real_invoker_actual_process_start_rehearsal_executor_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker actual process start rehearsal executor is ready; actual process start still requires a separate executor.'
                : 'Codex real invoker actual process start rehearsal executor is blocked until the rehearsal executor and final authorization prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerActualProcessStartRehearsalExecutorImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerActualProcessStartRehearsalExecutorPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_actual_process_start_rehearsal_executor_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_actual_process_start_rehearsal_executor_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create Codex real invoker actual process start rehearsal executor',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerActualProcessStartRehearsalExecutor.php'],
                'acceptance' => 'Executor records start rehearsal metadata and never starts Codex or permits token spend.',
            ],
            [
                'id' => 'T2',
                'title' => 'Enforce final authorization and start rehearsal contracts',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerActualProcessStartRehearsalExecutor.php'],
                'acceptance' => 'Executor requires final authorization metadata, process start rehearsal, command, environment, cwd, supervisor and liveness rehearsal hashes.',
            ],
            [
                'id' => 'T3',
                'title' => 'Add actual process start rehearsal tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerActualProcessStartRehearsalExecutorTest.php'],
                'acceptance' => 'Tests prove missing final authorization rejection, duplicate rehearsal rejection, rollback and no process/token/dispatch side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose actual process start rehearsal readiness commands',
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
            'status' => 'ready_for_scoped_codex_real_invoker_actual_process_start_rehearsal_executor_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-ACTUAL-PROCESS-START-REHEARSAL-EXECUTOR-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker actual process start rehearsal executor that rehearses the final process start path while still refusing to start Codex, spend tokens or dispatch work.',
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
                'actual_start_rehearsal_requires_final_authorization_metadata',
                'actual_start_rehearsal_requires_final_process_start_authorization_metadata',
                'actual_start_rehearsal_requires_command_resolution_hash',
                'actual_start_rehearsal_requires_liveness_probe_rehearsal_hash',
                'actual_start_rehearsal_is_idempotent_for_same_rehearsal_id',
                'actual_start_rehearsal_records_event_without_starting_codex',
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
                'actual_process_start_rehearsal_allowed_by_packet' => false,
                'actual_process_start_allowed_by_packet' => false,
                'provider_process_start_allowed_by_packet' => false,
                'codex_invocation_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
            ],
            'next_required_action' => 'Implement only the allowed files, then run the required gates and report evidence.',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_actual_process_start_rehearsal_executor_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_actual_process_start_rehearsal_executor_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_actual_process_start_rehearsal_executor_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_actual_process_start_rehearsal_executor_implementation_packet' => $packet,
            'codex_real_invoker_actual_process_start_rehearsal_executor_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_actual_process_start_rehearsal_executor_implementation_packet_does_not_start_codex',
                'agent_codex_real_invoker_actual_process_start_rehearsal_executor_implementation_packet_does_not_call_codex',
                'agent_codex_real_invoker_actual_process_start_rehearsal_executor_implementation_packet_does_not_spend_tokens',
                'agent_codex_real_invoker_actual_process_start_rehearsal_executor_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker actual process start rehearsal executor implementation packet is ready; it rehearses final start only and does not start Codex.',
        ];
    }


public function agentCodexRealInvokerProcessStartEnvelopeBuilderContractTemplate(array $options = []): array
    {
        $rehearsalPayload = $this->section->agentCodexRealInvokerActualProcessStartRehearsalExecutorPreflight($options);

        $template = [
            'status' => 'codex_real_invoker_process_start_envelope_builder_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-PROCESS-START-ENVELOPE-'.strtoupper(substr(ReadinessHash::stable([
                'codex_real_invoker_actual_process_start_rehearsal_executor_preflight_hash' => data_get($rehearsalPayload, 'codex_real_invoker_actual_process_start_rehearsal_executor_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_actual_process_start_rehearsal_executor_status' => data_get($rehearsalPayload, 'status'),
            'source_codex_real_invoker_actual_process_start_rehearsal_executor_preflight_hash' => data_get($rehearsalPayload, 'codex_real_invoker_actual_process_start_rehearsal_executor_preflight_hash'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerProcessStartEnvelopeBuilder',
                'method' => 'buildStartEnvelope',
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
                    'process_start_rehearsal_hash',
                    'command_resolution_hash',
                    'environment_resolution_hash',
                    'cwd_verification_hash',
                    'supervisor_dry_run_hash',
                    'liveness_probe_rehearsal_hash',
                    'operator_final_start_receipt_hash',
                    'final_start_signature_hash',
                    'final_start_policy_hash',
                    'final_start_window_hash',
                    'final_start_replay_guard_hash',
                    'final_start_kill_switch_hash',
                    'process_start_envelope_hash',
                    'start_command_hash',
                    'start_environment_hash',
                    'start_cwd_hash',
                    'start_supervisor_hash',
                    'start_liveness_contract_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'real_invoker_process_start_envelope_id',
                    'real_invoker_actual_process_start_rehearsal_id',
                    'agent_run_id',
                    'run_key',
                    'provider',
                    'adapter',
                    'real_invoker_process_start_envelope_built',
                    'start_envelope_ready',
                    'actual_process_start_allowed',
                    'external_process_started',
                    'token_spend_allowed',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'real_invoker_process_start_envelope_must' => [
                'require_codex_real_invoker_actual_process_start_rehearsal',
                'require_process_start_envelope_hash',
                'require_start_command_hash',
                'require_start_environment_hash',
                'require_start_cwd_hash',
                'require_start_supervisor_hash',
                'require_start_liveness_contract_hash',
                'record_append_only_process_start_envelope_event_before_any_actual_start',
            ],
            'real_invoker_process_start_envelope_must_not' => [
                'start_codex_process_from_contract_template',
                'spawn_shell_or_subprocess',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'dispatch_work_to_provider',
                'complete_or_merge_packet',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerProcessStartEnvelopeBuilder.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerProcessStartEnvelopeBuilderTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'process_start_envelope_build_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-process-start-envelope-builder-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_process_start_envelope_builder_contract_template.v1',
            'status' => 'codex_real_invoker_process_start_envelope_builder_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_process_start_envelope_builder_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_process_start_envelope_builder_contract_template' => $template,
            'codex_real_invoker_process_start_envelope_builder_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_process_start_envelope_builder_contract_template_does_not_start_codex',
                'agent_codex_real_invoker_process_start_envelope_builder_contract_template_does_not_call_codex',
                'agent_codex_real_invoker_process_start_envelope_builder_contract_template_does_not_spend_tokens',
                'agent_codex_real_invoker_process_start_envelope_builder_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker process start envelope builder contract template defines the future start envelope but does not start Codex.',
        ];
    }


public function agentCodexRealInvokerProcessStartEnvelopeBuilderPreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerProcessStartEnvelopeBuilderContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_process_start_envelope_builder_contract_template', []);
        $envelopeClass = AgentCodexRealInvokerProcessStartEnvelopeBuilder::class;
        $rehearsalClass = AgentCodexRealInvokerActualProcessStartRehearsalExecutor::class;
        $envelopeReady = class_exists($envelopeClass);
        $rehearsalReady = class_exists($rehearsalClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $envelopeReady ? null : 'codex_real_invoker_process_start_envelope_builder_missing',
            $rehearsalReady ? null : 'codex_real_invoker_actual_process_start_rehearsal_executor_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_process_start_envelope_builder_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_process_start_envelope_builder_contract_template_hash'),
            'source_codex_real_invoker_actual_process_start_rehearsal_executor_status' => data_get($contract, 'source_codex_real_invoker_actual_process_start_rehearsal_executor_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_process_start_envelope_builder_ready' => $envelopeReady,
                'codex_real_invoker_actual_process_start_rehearsal_executor_ready' => $rehearsalReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'process_start_envelope_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_real_invoker_process_start_envelope_builder',
                'require_codex_real_invoker_actual_process_start_rehearsal_metadata',
                'require_process_start_envelope_hash',
                'require_start_command_environment_cwd_supervisor_and_liveness_hashes',
                'record_process_start_envelope_event_without_starting_codex',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerProcessStartEnvelopeBuilder.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerProcessStartEnvelopeBuilderTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_process_start_envelope_builder',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'process_start_envelope_file_creation_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-process-start-envelope-builder-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_process_start_envelope_builder_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_process_start_envelope_builder_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_process_start_envelope_builder_preflight' => $preflight,
            'codex_real_invoker_process_start_envelope_builder_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_process_start_envelope_builder_preflight_does_not_start_codex',
                'agent_codex_real_invoker_process_start_envelope_builder_preflight_does_not_call_codex',
                'agent_codex_real_invoker_process_start_envelope_builder_preflight_does_not_spend_tokens',
                'agent_codex_real_invoker_process_start_envelope_builder_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker process start envelope builder is ready; actual process start still requires a separate execution gate.'
                : 'Codex real invoker process start envelope builder is blocked until the builder and rehearsal prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerProcessStartEnvelopeBuilderImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerProcessStartEnvelopeBuilderPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_process_start_envelope_builder_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_process_start_envelope_builder_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create Codex real invoker process start envelope builder',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerProcessStartEnvelopeBuilder.php'],
                'acceptance' => 'Builder records the future start envelope and never starts Codex or permits token spend.',
            ],
            [
                'id' => 'T2',
                'title' => 'Enforce rehearsal and start envelope contracts',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerProcessStartEnvelopeBuilder.php'],
                'acceptance' => 'Builder requires rehearsal metadata plus process start envelope, command, environment, cwd, supervisor and liveness hashes.',
            ],
            [
                'id' => 'T3',
                'title' => 'Add process start envelope builder tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerProcessStartEnvelopeBuilderTest.php'],
                'acceptance' => 'Tests prove missing rehearsal rejection, duplicate envelope rejection, rollback and no process/token/dispatch side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose process start envelope readiness commands',
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
            'status' => 'ready_for_scoped_codex_real_invoker_process_start_envelope_builder_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-PROCESS-START-ENVELOPE-BUILDER-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker process start envelope builder that materializes the future start envelope while still refusing to start Codex, spend tokens or dispatch work.',
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
                'process_start_envelope_requires_actual_process_start_rehearsal_metadata',
                'process_start_envelope_requires_process_start_envelope_hash',
                'process_start_envelope_requires_start_command_hash',
                'process_start_envelope_is_idempotent_for_same_envelope_id',
                'process_start_envelope_records_event_without_starting_codex',
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
                'process_start_envelope_build_allowed_by_packet' => false,
                'actual_process_start_allowed_by_packet' => false,
                'provider_process_start_allowed_by_packet' => false,
                'codex_invocation_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
            ],
            'next_required_action' => 'Implement only the allowed files, then run the required gates and report evidence.',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_process_start_envelope_builder_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_process_start_envelope_builder_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_process_start_envelope_builder_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_process_start_envelope_builder_implementation_packet' => $packet,
            'codex_real_invoker_process_start_envelope_builder_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_process_start_envelope_builder_implementation_packet_does_not_start_codex',
                'agent_codex_real_invoker_process_start_envelope_builder_implementation_packet_does_not_call_codex',
                'agent_codex_real_invoker_process_start_envelope_builder_implementation_packet_does_not_spend_tokens',
                'agent_codex_real_invoker_process_start_envelope_builder_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker process start envelope builder implementation packet is ready; it builds the future start envelope only and does not start Codex.',
        ];
    }


public function agentCodexRealInvokerStartExecutionGateContractTemplate(array $options = []): array
    {
        $envelopePayload = $this->section->agentCodexRealInvokerProcessStartEnvelopeBuilderPreflight($options);

        $template = [
            'status' => 'codex_real_invoker_start_execution_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-START-EXECUTION-GATE-'.strtoupper(substr(ReadinessHash::stable([
                'codex_real_invoker_process_start_envelope_builder_preflight_hash' => data_get($envelopePayload, 'codex_real_invoker_process_start_envelope_builder_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_process_start_envelope_builder_status' => data_get($envelopePayload, 'status'),
            'source_codex_real_invoker_process_start_envelope_builder_preflight_hash' => data_get($envelopePayload, 'codex_real_invoker_process_start_envelope_builder_preflight_hash'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerStartExecutionGate',
                'method' => 'authorizeStartExecution',
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
                    'process_start_envelope_hash',
                    'start_command_hash',
                    'start_environment_hash',
                    'start_cwd_hash',
                    'start_supervisor_hash',
                    'start_liveness_contract_hash',
                    'operator_execution_gate_receipt_hash',
                    'execution_gate_policy_hash',
                    'execution_window_hash',
                    'preflight_snapshot_hash',
                    'rollback_readiness_hash',
                    'human_start_signature_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'real_invoker_start_execution_gate_id',
                    'real_invoker_process_start_envelope_id',
                    'agent_run_id',
                    'run_key',
                    'provider',
                    'adapter',
                    'real_invoker_start_execution_gate_authorized',
                    'start_execution_authorized',
                    'actual_process_start_allowed',
                    'external_process_started',
                    'token_spend_allowed',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'real_invoker_start_execution_gate_must' => [
                'require_codex_real_invoker_process_start_envelope',
                'require_operator_execution_gate_receipt_hash',
                'require_execution_gate_policy_hash',
                'require_execution_window_hash',
                'require_preflight_snapshot_hash',
                'require_rollback_readiness_hash',
                'require_human_start_signature_hash',
                'record_append_only_start_execution_gate_event_before_any_actual_start',
            ],
            'real_invoker_start_execution_gate_must_not' => [
                'start_codex_process_from_contract_template',
                'spawn_shell_or_subprocess',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'dispatch_work_to_provider',
                'complete_or_merge_packet',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerStartExecutionGate.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerStartExecutionGateTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'start_execution_gate_authorization_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-start-execution-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_start_execution_gate_contract_template.v1',
            'status' => 'codex_real_invoker_start_execution_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_start_execution_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_start_execution_gate_contract_template' => $template,
            'codex_real_invoker_start_execution_gate_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_start_execution_gate_contract_template_does_not_start_codex',
                'agent_codex_real_invoker_start_execution_gate_contract_template_does_not_call_codex',
                'agent_codex_real_invoker_start_execution_gate_contract_template_does_not_spend_tokens',
                'agent_codex_real_invoker_start_execution_gate_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker start execution gate contract template authorizes the next guarded layer only; it does not start Codex.',
        ];
    }


public function agentCodexRealInvokerStartExecutionGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerStartExecutionGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_start_execution_gate_contract_template', []);
        $gateClass = AgentCodexRealInvokerStartExecutionGate::class;
        $envelopeClass = AgentCodexRealInvokerProcessStartEnvelopeBuilder::class;
        $gateReady = class_exists($gateClass);
        $envelopeReady = class_exists($envelopeClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $gateReady ? null : 'codex_real_invoker_start_execution_gate_missing',
            $envelopeReady ? null : 'codex_real_invoker_process_start_envelope_builder_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_start_execution_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_start_execution_gate_contract_template_hash'),
            'source_codex_real_invoker_process_start_envelope_builder_status' => data_get($contract, 'source_codex_real_invoker_process_start_envelope_builder_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_start_execution_gate_ready' => $gateReady,
                'codex_real_invoker_process_start_envelope_builder_ready' => $envelopeReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'start_execution_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_real_invoker_start_execution_gate',
                'require_codex_real_invoker_process_start_envelope_metadata',
                'require_operator_execution_gate_receipt_hash',
                'require_human_start_signature_hash',
                'record_start_execution_gate_event_without_starting_codex',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerStartExecutionGate.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerStartExecutionGateTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_start_execution_gate',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'start_execution_gate_file_creation_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-start-execution-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_start_execution_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_start_execution_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_start_execution_gate_preflight' => $preflight,
            'codex_real_invoker_start_execution_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_start_execution_gate_preflight_does_not_start_codex',
                'agent_codex_real_invoker_start_execution_gate_preflight_does_not_call_codex',
                'agent_codex_real_invoker_start_execution_gate_preflight_does_not_spend_tokens',
                'agent_codex_real_invoker_start_execution_gate_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker start execution gate is ready; actual process start still requires the separate process starter layer.'
                : 'Codex real invoker start execution gate is blocked until the gate and envelope prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerStartExecutionGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerStartExecutionGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_start_execution_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_start_execution_gate_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create Codex real invoker start execution gate',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerStartExecutionGate.php'],
                'acceptance' => 'Gate authorizes start execution metadata and never starts Codex, spends tokens or dispatches work.',
            ],
            [
                'id' => 'T2',
                'title' => 'Enforce envelope and execution gate contracts',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerStartExecutionGate.php'],
                'acceptance' => 'Gate requires process start envelope metadata plus operator, policy, window, preflight, rollback and human signature hashes.',
            ],
            [
                'id' => 'T3',
                'title' => 'Add start execution gate tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerStartExecutionGateTest.php'],
                'acceptance' => 'Tests prove missing envelope rejection, missing human signature rejection, duplicate gate rejection, rollback and no process/token/dispatch side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose start execution gate readiness commands',
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
            'status' => 'ready_for_scoped_codex_real_invoker_start_execution_gate_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-START-EXECUTION-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker start execution gate that records a final governed execution authorization while still refusing to start Codex, spend tokens or dispatch work.',
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
                'start_execution_gate_requires_process_start_envelope_metadata',
                'start_execution_gate_requires_human_start_signature_hash',
                'start_execution_gate_requires_execution_gate_policy_hash',
                'start_execution_gate_is_idempotent_for_same_gate_id',
                'start_execution_gate_records_event_without_starting_codex',
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
                'start_execution_gate_authorization_allowed_by_packet' => false,
                'actual_process_start_allowed_by_packet' => false,
                'provider_process_start_allowed_by_packet' => false,
                'codex_invocation_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
            ],
            'next_required_action' => 'Implement only the allowed files, then run the required gates and report evidence.',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_start_execution_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_start_execution_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_start_execution_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_start_execution_gate_implementation_packet' => $packet,
            'codex_real_invoker_start_execution_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_start_execution_gate_implementation_packet_does_not_start_codex',
                'agent_codex_real_invoker_start_execution_gate_implementation_packet_does_not_call_codex',
                'agent_codex_real_invoker_start_execution_gate_implementation_packet_does_not_spend_tokens',
                'agent_codex_real_invoker_start_execution_gate_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker start execution gate implementation packet is ready; it authorizes the next guarded layer only and does not start Codex.',
        ];
    }


}
