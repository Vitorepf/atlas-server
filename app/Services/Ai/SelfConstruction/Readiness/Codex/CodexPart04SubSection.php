<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\Codex;

use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentDispatchProviderSection;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerExecutorEnablementGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerExecutorFreshReleaseGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerExecutorPlan;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerGuardedProcessStartExecutor;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerSupervisedStartActivationGate;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;
use Illuminate\Support\Facades\Schema;

/**
 * AGENT CODEX projection sub-section 04 of 11, sub-split from the god
 * {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling agentCodex* pipeline calls route through the
 * injected Section facade (`$this->section->agentCodex*`), which re-dispatches
 * to whichever sub-section owns the target stage. The dispatch-provider
 * collaborator is injected verbatim for the one stage that consumes it.
 *
 * Stage range: agentCodexRealInvokerExecutorFreshReleaseGateContractTemplate
 *           .. agentCodexRealInvokerGuardedProcessStartExecutorImplementationPacket
 */
final class CodexPart04SubSection
{
    public function __construct(
        private readonly ReadinessProjectionAgentCodexSection $section,
        private readonly ReadinessProjectionAgentDispatchProviderSection $agentDispatchProviderSection,
    ) {}

public function agentCodexRealInvokerExecutorFreshReleaseGateContractTemplate(array $options = []): array
    {
        $executorPlanPayload = $this->section->agentCodexRealInvokerExecutorPlanPreflight($options);

        $template = [
            'status' => 'codex_real_invoker_executor_fresh_release_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-EXECUTOR-FRESH-RELEASE-'.strtoupper(substr(ReadinessHash::stable([
                'codex_real_invoker_executor_plan_preflight_hash' => data_get($executorPlanPayload, 'codex_real_invoker_executor_plan_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_executor_plan_status' => data_get($executorPlanPayload, 'status'),
            'source_codex_real_invoker_executor_plan_preflight_hash' => data_get($executorPlanPayload, 'codex_real_invoker_executor_plan_preflight_hash'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerExecutorFreshReleaseGate',
                'method' => 'authorizeFreshRelease',
                'input_contract' => [
                    'run_key',
                    'codex_execution_id',
                    'process_start_release_id',
                    'supervised_start_id',
                    'spawn_enablement_id',
                    'spawn_executor_id',
                    'runtime_driver_id',
                    'invocation_authorization_id',
                    'dry_run_id',
                    'real_invoker_release_preflight_id',
                    'signed_real_invoker_release_id',
                    'real_invoker_implementation_boundary_id',
                    'real_invoker_executor_plan_id',
                    'real_invoker_executor_fresh_release_id',
                    'operator_fresh_release_receipt_hash',
                    'plan_revalidation_report_hash',
                    'freshness_window_hash',
                    'final_human_signature_hash',
                    'real_invoker_contract_hash',
                    'release_policy_hash',
                    'implementation_plan_hash',
                    'executor_binary_contract_hash',
                    'executor_observability_contract_hash',
                    'process_command_hash',
                    'environment_contract_hash',
                    'termination_policy_hash',
                    'stdout_stderr_sink_hash',
                    'liveness_probe_hash',
                    'rollback_plan_hash',
                    'max_runtime_policy_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'real_invoker_executor_fresh_release_id',
                    'real_invoker_executor_plan_id',
                    'real_invoker_implementation_boundary_id',
                    'signed_real_invoker_release_id',
                    'codex_execution_id',
                    'agent_run_id',
                    'run_key',
                    'provider',
                    'adapter',
                    'real_invoker_executor_fresh_release_authorized',
                    'executor_enabled',
                    'external_process_started',
                    'token_spend_allowed',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'real_invoker_executor_fresh_release_must' => [
                'require_codex_real_invoker_executor_plan_prepared',
                'require_operator_fresh_release_receipt_hash',
                'require_plan_revalidation_report_hash',
                'require_freshness_window_hash',
                'require_final_human_signature_hash',
                'record_append_only_real_invoker_executor_fresh_release_event_before_any_enablement',
            ],
            'real_invoker_executor_fresh_release_must_not' => [
                'enable_executor_from_contract_template',
                'start_codex_process_from_contract_template',
                'spawn_shell_or_subprocess',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'complete_or_merge_packet',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerExecutorFreshReleaseGate.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerExecutorFreshReleaseGateTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'fresh_release_authorization_allowed_here' => false,
                'executor_enablement_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-executor-fresh-release-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_executor_fresh_release_gate_contract_template.v1',
            'status' => 'codex_real_invoker_executor_fresh_release_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_executor_fresh_release_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_fresh_release_gate_contract_template' => $template,
            'codex_real_invoker_executor_fresh_release_gate_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_executor_fresh_release_gate_contract_template_does_not_start_codex',
                'agent_codex_real_invoker_executor_fresh_release_gate_contract_template_does_not_call_codex',
                'agent_codex_real_invoker_executor_fresh_release_gate_contract_template_does_not_spend_tokens',
                'agent_codex_real_invoker_executor_fresh_release_gate_contract_template_does_not_enable_executor',
                'agent_codex_real_invoker_executor_fresh_release_gate_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker executor fresh release gate contract template defines a final freshness authorization without enabling the executor.',
        ];
    }


public function agentCodexRealInvokerExecutorFreshReleaseGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerExecutorFreshReleaseGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_executor_fresh_release_gate_contract_template', []);
        $freshReleaseClass = AgentCodexRealInvokerExecutorFreshReleaseGate::class;
        $executorPlanClass = AgentCodexRealInvokerExecutorPlan::class;
        $freshReleaseReady = class_exists($freshReleaseClass);
        $executorPlanReady = class_exists($executorPlanClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $freshReleaseReady ? null : 'codex_real_invoker_executor_fresh_release_gate_missing',
            $executorPlanReady ? null : 'codex_real_invoker_executor_plan_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_executor_fresh_release_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_executor_fresh_release_gate_contract_template_hash'),
            'source_codex_real_invoker_executor_plan_status' => data_get($contract, 'source_codex_real_invoker_executor_plan_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_executor_fresh_release_gate_ready' => $freshReleaseReady,
                'codex_real_invoker_executor_plan_ready' => $executorPlanReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'fresh_release_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_real_invoker_executor_fresh_release_gate',
                'require_codex_real_invoker_executor_plan_metadata',
                'require_operator_fresh_release_receipt_hash',
                'require_plan_revalidation_and_freshness_hashes',
                'record_fresh_release_event_without_enabling_executor',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerExecutorFreshReleaseGate.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerExecutorFreshReleaseGateTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_executor_fresh_release_gate',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'fresh_release_gate_file_creation_allowed_here' => false,
                'executor_enablement_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-executor-fresh-release-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_executor_fresh_release_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_executor_fresh_release_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_fresh_release_gate_preflight' => $preflight,
            'codex_real_invoker_executor_fresh_release_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_executor_fresh_release_gate_preflight_does_not_start_codex',
                'agent_codex_real_invoker_executor_fresh_release_gate_preflight_does_not_call_codex',
                'agent_codex_real_invoker_executor_fresh_release_gate_preflight_does_not_spend_tokens',
                'agent_codex_real_invoker_executor_fresh_release_gate_preflight_does_not_enable_executor',
                'agent_codex_real_invoker_executor_fresh_release_gate_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker executor fresh release gate is ready; executor remains disabled until a later enablement stage.'
                : 'Codex real invoker executor fresh release gate is blocked until the gate service and executor plan prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerExecutorFreshReleaseGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerExecutorFreshReleaseGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_executor_fresh_release_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_executor_fresh_release_gate_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create Codex real invoker executor fresh release gate',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerExecutorFreshReleaseGate.php'],
                'acceptance' => 'Fresh release records authorization metadata and never enables executor or starts Codex.',
            ],
            [
                'id' => 'T2',
                'title' => 'Enforce freshness and human signature contracts',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerExecutorFreshReleaseGate.php'],
                'acceptance' => 'Fresh release requires executor plan metadata, revalidation report hash, freshness window hash and final human signature hash.',
            ],
            [
                'id' => 'T3',
                'title' => 'Add real invoker executor fresh release tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerExecutorFreshReleaseGateTest.php'],
                'acceptance' => 'Tests prove missing executor plan rejection, duplicate release rejection, rollback and no executor/process/token side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose fresh release readiness commands',
                'type' => 'command_surface',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                    'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare executor disabled.',
            ],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_executor_fresh_release_gate_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-EXECUTOR-FRESH-RELEASE-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker executor fresh release gate that authorizes a fresh future enablement while still refusing to enable or start Codex.',
            'non_goals' => [
                'do_not_call_codex_cli_or_codex_app',
                'do_not_spawn_processes_or_shell_commands',
                'do_not_spend_provider_tokens',
                'do_not_enable_executor',
                'do_not_mark_runs_running_or_terminal',
                'do_not_change_packet_claim_completion_or_merge_state',
            ],
            'allowed_files' => $allowedFiles,
            'forbidden_scopes' => [
                'actual_codex_process_invocation',
                'provider_token_spend',
                'executor_enablement',
                'merge_runtime',
                'packet_claim_or_completion_state',
                'hot_kernel_runtime',
                'voice_runtime',
                'policy_mutation',
            ],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => [
                'fresh_release_gate_requires_executor_plan_metadata',
                'fresh_release_gate_requires_operator_fresh_release_receipt_hash',
                'fresh_release_gate_requires_plan_revalidation_report_hash',
                'fresh_release_gate_is_idempotent_for_same_release_id',
                'fresh_release_gate_records_authorized_event_without_enabling_executor',
            ],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'stop_conditions' => [
                'need_to_start_codex_or_spawn_process',
                'need_to_enable_executor',
                'need_to_modify_file_outside_allowed_files',
                'need_to_allow_provider_token_spend',
                'need_to_change_packet_claim_completion_or_merge_state',
                'test_requires_hot_runtime_or_voice_scope_change',
            ],
            'implementation_policy' => [
                'packet_is_read_only' => true,
                'implementation_allowed_by_packet' => true,
                'fresh_release_authorization_allowed_by_packet' => false,
                'executor_enablement_allowed_by_packet' => false,
                'provider_process_start_allowed_by_packet' => false,
                'codex_invocation_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
            ],
            'next_required_action' => 'Implement only the allowed files, then run the required gates and report evidence.',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_executor_fresh_release_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_executor_fresh_release_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_executor_fresh_release_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_fresh_release_gate_implementation_packet' => $packet,
            'codex_real_invoker_executor_fresh_release_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_executor_fresh_release_gate_implementation_packet_does_not_start_codex',
                'agent_codex_real_invoker_executor_fresh_release_gate_implementation_packet_does_not_call_codex',
                'agent_codex_real_invoker_executor_fresh_release_gate_implementation_packet_does_not_spend_tokens',
                'agent_codex_real_invoker_executor_fresh_release_gate_implementation_packet_does_not_enable_executor',
                'agent_codex_real_invoker_executor_fresh_release_gate_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker executor fresh release gate implementation packet is ready; it defines scoped fresh-release work but does not enable or start Codex.',
        ];
    }


public function agentCodexRealInvokerExecutorEnablementGateContractTemplate(array $options = []): array
    {
        $freshReleasePayload = $this->section->agentCodexRealInvokerExecutorFreshReleaseGatePreflight($options);

        $template = [
            'status' => 'codex_real_invoker_executor_enablement_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-EXECUTOR-ENABLEMENT-'.strtoupper(substr(ReadinessHash::stable([
                'codex_real_invoker_executor_fresh_release_gate_preflight_hash' => data_get($freshReleasePayload, 'codex_real_invoker_executor_fresh_release_gate_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_executor_fresh_release_gate_status' => data_get($freshReleasePayload, 'status'),
            'source_codex_real_invoker_executor_fresh_release_gate_preflight_hash' => data_get($freshReleasePayload, 'codex_real_invoker_executor_fresh_release_gate_preflight_hash'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerExecutorEnablementGate',
                'method' => 'enableExecutor',
                'input_contract' => [
                    'run_key',
                    'codex_execution_id',
                    'process_start_release_id',
                    'supervised_start_id',
                    'spawn_enablement_id',
                    'spawn_executor_id',
                    'runtime_driver_id',
                    'invocation_authorization_id',
                    'dry_run_id',
                    'real_invoker_release_preflight_id',
                    'signed_real_invoker_release_id',
                    'real_invoker_implementation_boundary_id',
                    'real_invoker_executor_plan_id',
                    'real_invoker_executor_fresh_release_id',
                    'real_invoker_executor_enablement_id',
                    'operator_enablement_receipt_hash',
                    'enablement_policy_hash',
                    'pre_start_checklist_hash',
                    'disable_switch_hash',
                    'operator_fresh_release_receipt_hash',
                    'plan_revalidation_report_hash',
                    'freshness_window_hash',
                    'final_human_signature_hash',
                    'real_invoker_contract_hash',
                    'release_policy_hash',
                    'implementation_plan_hash',
                    'executor_binary_contract_hash',
                    'executor_observability_contract_hash',
                    'process_command_hash',
                    'environment_contract_hash',
                    'termination_policy_hash',
                    'stdout_stderr_sink_hash',
                    'liveness_probe_hash',
                    'rollback_plan_hash',
                    'max_runtime_policy_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'real_invoker_executor_enablement_id',
                    'real_invoker_executor_fresh_release_id',
                    'real_invoker_executor_plan_id',
                    'real_invoker_implementation_boundary_id',
                    'signed_real_invoker_release_id',
                    'codex_execution_id',
                    'agent_run_id',
                    'run_key',
                    'provider',
                    'adapter',
                    'real_invoker_executor_enabled',
                    'executor_enabled',
                    'external_process_started',
                    'token_spend_allowed',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'real_invoker_executor_enablement_must' => [
                'require_codex_real_invoker_executor_fresh_release_authorized',
                'require_operator_enablement_receipt_hash',
                'require_enablement_policy_hash',
                'require_pre_start_checklist_hash',
                'require_disable_switch_hash',
                'record_append_only_real_invoker_executor_enablement_event_before_any_process_start',
            ],
            'real_invoker_executor_enablement_must_not' => [
                'start_codex_process_from_contract_template',
                'spawn_shell_or_subprocess',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'dispatch_work_to_provider',
                'complete_or_merge_packet',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerExecutorEnablementGate.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerExecutorEnablementGateTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'executor_enablement_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-executor-enablement-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_executor_enablement_gate_contract_template.v1',
            'status' => 'codex_real_invoker_executor_enablement_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_executor_enablement_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_enablement_gate_contract_template' => $template,
            'codex_real_invoker_executor_enablement_gate_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_executor_enablement_gate_contract_template_does_not_start_codex',
                'agent_codex_real_invoker_executor_enablement_gate_contract_template_does_not_call_codex',
                'agent_codex_real_invoker_executor_enablement_gate_contract_template_does_not_spend_tokens',
                'agent_codex_real_invoker_executor_enablement_gate_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker executor enablement gate contract template defines executor enablement while keeping process start disabled.',
        ];
    }


public function agentCodexRealInvokerExecutorEnablementGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerExecutorEnablementGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_executor_enablement_gate_contract_template', []);
        $enablementClass = AgentCodexRealInvokerExecutorEnablementGate::class;
        $freshReleaseClass = AgentCodexRealInvokerExecutorFreshReleaseGate::class;
        $enablementReady = class_exists($enablementClass);
        $freshReleaseReady = class_exists($freshReleaseClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $enablementReady ? null : 'codex_real_invoker_executor_enablement_gate_missing',
            $freshReleaseReady ? null : 'codex_real_invoker_executor_fresh_release_gate_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_executor_enablement_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_executor_enablement_gate_contract_template_hash'),
            'source_codex_real_invoker_executor_fresh_release_gate_status' => data_get($contract, 'source_codex_real_invoker_executor_fresh_release_gate_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_executor_enablement_gate_ready' => $enablementReady,
                'codex_real_invoker_executor_fresh_release_gate_ready' => $freshReleaseReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'enablement_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_real_invoker_executor_enablement_gate',
                'require_codex_real_invoker_executor_fresh_release_metadata',
                'require_operator_enablement_receipt_hash',
                'require_enablement_policy_and_pre_start_checklist_hashes',
                'record_enablement_event_without_starting_codex',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerExecutorEnablementGate.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerExecutorEnablementGateTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_executor_enablement_gate',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'enablement_gate_file_creation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-executor-enablement-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_executor_enablement_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_executor_enablement_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_enablement_gate_preflight' => $preflight,
            'codex_real_invoker_executor_enablement_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_executor_enablement_gate_preflight_does_not_start_codex',
                'agent_codex_real_invoker_executor_enablement_gate_preflight_does_not_call_codex',
                'agent_codex_real_invoker_executor_enablement_gate_preflight_does_not_spend_tokens',
                'agent_codex_real_invoker_executor_enablement_gate_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker executor enablement gate is ready; process start remains disabled until a later supervised-start activation stage.'
                : 'Codex real invoker executor enablement gate is blocked until the gate service and fresh release prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerExecutorEnablementGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerExecutorEnablementGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_executor_enablement_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_executor_enablement_gate_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create Codex real invoker executor enablement gate',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerExecutorEnablementGate.php'],
                'acceptance' => 'Enablement records executor-enabled metadata and never starts Codex or permits token spend.',
            ],
            [
                'id' => 'T2',
                'title' => 'Enforce fresh-release and pre-start contracts',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerExecutorEnablementGate.php'],
                'acceptance' => 'Enablement requires fresh release metadata, operator enablement receipt, enablement policy, pre-start checklist and disable switch hashes.',
            ],
            [
                'id' => 'T3',
                'title' => 'Add real invoker executor enablement tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerExecutorEnablementGateTest.php'],
                'acceptance' => 'Tests prove missing fresh release rejection, duplicate enablement rejection, rollback and no process/token/dispatch side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose enablement readiness commands',
                'type' => 'command_surface',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                    'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare process start disabled.',
            ],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_executor_enablement_gate_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-EXECUTOR-ENABLEMENT-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker executor enablement gate that enables the executor contract while still refusing to start Codex, spend tokens or dispatch work.',
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
                'enablement_gate_requires_fresh_release_metadata',
                'enablement_gate_requires_operator_enablement_receipt_hash',
                'enablement_gate_requires_pre_start_checklist_hash',
                'enablement_gate_is_idempotent_for_same_enablement_id',
                'enablement_gate_records_enabled_event_without_starting_codex',
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
                'executor_enablement_allowed_by_packet' => false,
                'provider_process_start_allowed_by_packet' => false,
                'codex_invocation_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
            ],
            'next_required_action' => 'Implement only the allowed files, then run the required gates and report evidence.',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_executor_enablement_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_executor_enablement_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_executor_enablement_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_enablement_gate_implementation_packet' => $packet,
            'codex_real_invoker_executor_enablement_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_executor_enablement_gate_implementation_packet_does_not_start_codex',
                'agent_codex_real_invoker_executor_enablement_gate_implementation_packet_does_not_call_codex',
                'agent_codex_real_invoker_executor_enablement_gate_implementation_packet_does_not_spend_tokens',
                'agent_codex_real_invoker_executor_enablement_gate_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker executor enablement gate implementation packet is ready; it enables the executor contract only and does not start Codex.',
        ];
    }


public function agentCodexRealInvokerSupervisedStartActivationGateContractTemplate(array $options = []): array
    {
        $enablementPayload = $this->section->agentCodexRealInvokerExecutorEnablementGatePreflight($options);

        $template = [
            'status' => 'codex_real_invoker_supervised_start_activation_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-SUPERVISED-START-ACTIVATION-'.strtoupper(substr(ReadinessHash::stable([
                'codex_real_invoker_executor_enablement_gate_preflight_hash' => data_get($enablementPayload, 'codex_real_invoker_executor_enablement_gate_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_executor_enablement_gate_status' => data_get($enablementPayload, 'status'),
            'source_codex_real_invoker_executor_enablement_gate_preflight_hash' => data_get($enablementPayload, 'codex_real_invoker_executor_enablement_gate_preflight_hash'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerSupervisedStartActivationGate',
                'method' => 'prepareActivation',
                'input_contract' => [
                    'run_key',
                    'codex_execution_id',
                    'process_start_release_id',
                    'supervised_start_id',
                    'spawn_enablement_id',
                    'spawn_executor_id',
                    'runtime_driver_id',
                    'invocation_authorization_id',
                    'dry_run_id',
                    'real_invoker_release_preflight_id',
                    'signed_real_invoker_release_id',
                    'real_invoker_implementation_boundary_id',
                    'real_invoker_executor_plan_id',
                    'real_invoker_executor_fresh_release_id',
                    'real_invoker_executor_enablement_id',
                    'real_invoker_supervised_start_activation_id',
                    'operator_start_activation_receipt_hash',
                    'start_window_hash',
                    'process_start_guard_hash',
                    'supervisor_observer_hash',
                    'pid_guard_hash',
                    'cwd_integrity_hash',
                    'operator_enablement_receipt_hash',
                    'enablement_policy_hash',
                    'pre_start_checklist_hash',
                    'disable_switch_hash',
                    'operator_fresh_release_receipt_hash',
                    'plan_revalidation_report_hash',
                    'freshness_window_hash',
                    'final_human_signature_hash',
                    'real_invoker_contract_hash',
                    'release_policy_hash',
                    'implementation_plan_hash',
                    'executor_binary_contract_hash',
                    'executor_observability_contract_hash',
                    'process_command_hash',
                    'environment_contract_hash',
                    'termination_policy_hash',
                    'stdout_stderr_sink_hash',
                    'liveness_probe_hash',
                    'rollback_plan_hash',
                    'max_runtime_policy_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'real_invoker_supervised_start_activation_id',
                    'real_invoker_executor_enablement_id',
                    'real_invoker_executor_fresh_release_id',
                    'real_invoker_executor_plan_id',
                    'codex_execution_id',
                    'agent_run_id',
                    'run_key',
                    'provider',
                    'adapter',
                    'real_invoker_supervised_start_activation_prepared',
                    'executor_enabled',
                    'process_start_armed',
                    'external_process_started',
                    'token_spend_allowed',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'real_invoker_supervised_start_activation_must' => [
                'require_codex_real_invoker_executor_enabled',
                'require_operator_start_activation_receipt_hash',
                'require_start_window_hash',
                'require_process_start_guard_hash',
                'require_supervisor_observer_hash',
                'record_append_only_supervised_start_activation_event_before_any_process_start',
            ],
            'real_invoker_supervised_start_activation_must_not' => [
                'start_codex_process_from_contract_template',
                'spawn_shell_or_subprocess',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'dispatch_work_to_provider',
                'complete_or_merge_packet',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerSupervisedStartActivationGate.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerSupervisedStartActivationGateTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'supervised_start_activation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-supervised-start-activation-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_supervised_start_activation_gate_contract_template.v1',
            'status' => 'codex_real_invoker_supervised_start_activation_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_supervised_start_activation_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_supervised_start_activation_gate_contract_template' => $template,
            'codex_real_invoker_supervised_start_activation_gate_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_supervised_start_activation_gate_contract_template_does_not_start_codex',
                'agent_codex_real_invoker_supervised_start_activation_gate_contract_template_does_not_call_codex',
                'agent_codex_real_invoker_supervised_start_activation_gate_contract_template_does_not_spend_tokens',
                'agent_codex_real_invoker_supervised_start_activation_gate_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker supervised start activation gate contract template arms supervised start metadata while keeping process start disabled.',
        ];
    }


public function agentCodexRealInvokerSupervisedStartActivationGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerSupervisedStartActivationGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_supervised_start_activation_gate_contract_template', []);
        $activationClass = AgentCodexRealInvokerSupervisedStartActivationGate::class;
        $enablementClass = AgentCodexRealInvokerExecutorEnablementGate::class;
        $activationReady = class_exists($activationClass);
        $enablementReady = class_exists($enablementClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $activationReady ? null : 'codex_real_invoker_supervised_start_activation_gate_missing',
            $enablementReady ? null : 'codex_real_invoker_executor_enablement_gate_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_supervised_start_activation_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_supervised_start_activation_gate_contract_template_hash'),
            'source_codex_real_invoker_executor_enablement_gate_status' => data_get($contract, 'source_codex_real_invoker_executor_enablement_gate_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_supervised_start_activation_gate_ready' => $activationReady,
                'codex_real_invoker_executor_enablement_gate_ready' => $enablementReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'activation_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_real_invoker_supervised_start_activation_gate',
                'require_codex_real_invoker_executor_enablement_metadata',
                'require_operator_start_activation_receipt_hash',
                'require_process_start_guard_and_supervisor_observer_hashes',
                'record_activation_event_without_starting_codex',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerSupervisedStartActivationGate.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerSupervisedStartActivationGateTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_supervised_start_activation_gate',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'activation_gate_file_creation_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-supervised-start-activation-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_supervised_start_activation_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_supervised_start_activation_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_supervised_start_activation_gate_preflight' => $preflight,
            'codex_real_invoker_supervised_start_activation_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_supervised_start_activation_gate_preflight_does_not_start_codex',
                'agent_codex_real_invoker_supervised_start_activation_gate_preflight_does_not_call_codex',
                'agent_codex_real_invoker_supervised_start_activation_gate_preflight_does_not_spend_tokens',
                'agent_codex_real_invoker_supervised_start_activation_gate_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker supervised start activation gate is ready; the actual process start still requires a later guarded process-start executor.'
                : 'Codex real invoker supervised start activation gate is blocked until the activation service and enablement prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerSupervisedStartActivationGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerSupervisedStartActivationGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_supervised_start_activation_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_supervised_start_activation_gate_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create Codex real invoker supervised start activation gate',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerSupervisedStartActivationGate.php'],
                'acceptance' => 'Activation records process-start-armed metadata and never starts Codex or permits token spend.',
            ],
            [
                'id' => 'T2',
                'title' => 'Enforce executor enablement and start guard contracts',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerSupervisedStartActivationGate.php'],
                'acceptance' => 'Activation requires executor enablement metadata, operator start receipt, start window, process guard, supervisor observer, PID guard and cwd integrity hashes.',
            ],
            [
                'id' => 'T3',
                'title' => 'Add supervised start activation tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerSupervisedStartActivationGateTest.php'],
                'acceptance' => 'Tests prove missing enablement rejection, duplicate activation rejection, rollback and no process/token/dispatch side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose activation readiness commands',
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
            'status' => 'ready_for_scoped_codex_real_invoker_supervised_start_activation_gate_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-SUPERVISED-START-ACTIVATION-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker supervised start activation gate that arms a future guarded process start while still refusing to start Codex, spend tokens or dispatch work.',
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
                'activation_gate_requires_executor_enablement_metadata',
                'activation_gate_requires_operator_start_activation_receipt_hash',
                'activation_gate_requires_process_start_guard_hash',
                'activation_gate_is_idempotent_for_same_activation_id',
                'activation_gate_records_armed_event_without_starting_codex',
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
                'supervised_start_activation_allowed_by_packet' => false,
                'provider_process_start_allowed_by_packet' => false,
                'codex_invocation_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
            ],
            'next_required_action' => 'Implement only the allowed files, then run the required gates and report evidence.',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_supervised_start_activation_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_supervised_start_activation_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_supervised_start_activation_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_supervised_start_activation_gate_implementation_packet' => $packet,
            'codex_real_invoker_supervised_start_activation_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_supervised_start_activation_gate_implementation_packet_does_not_start_codex',
                'agent_codex_real_invoker_supervised_start_activation_gate_implementation_packet_does_not_call_codex',
                'agent_codex_real_invoker_supervised_start_activation_gate_implementation_packet_does_not_spend_tokens',
                'agent_codex_real_invoker_supervised_start_activation_gate_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker supervised start activation gate implementation packet is ready; it arms a future start contract only and does not start Codex.',
        ];
    }


public function agentCodexRealInvokerGuardedProcessStartExecutorContractTemplate(array $options = []): array
    {
        $activationPayload = $this->section->agentCodexRealInvokerSupervisedStartActivationGatePreflight($options);

        $template = [
            'status' => 'codex_real_invoker_guarded_process_start_executor_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-GUARDED-PROCESS-START-'.strtoupper(substr(ReadinessHash::stable([
                'codex_real_invoker_supervised_start_activation_gate_preflight_hash' => data_get($activationPayload, 'codex_real_invoker_supervised_start_activation_gate_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_supervised_start_activation_gate_status' => data_get($activationPayload, 'status'),
            'source_codex_real_invoker_supervised_start_activation_gate_preflight_hash' => data_get($activationPayload, 'codex_real_invoker_supervised_start_activation_gate_preflight_hash'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerGuardedProcessStartExecutor',
                'method' => 'prepareGuardedStart',
                'input_contract' => [
                    'run_key',
                    'codex_execution_id',
                    'process_start_release_id',
                    'supervised_start_id',
                    'spawn_enablement_id',
                    'spawn_executor_id',
                    'runtime_driver_id',
                    'invocation_authorization_id',
                    'dry_run_id',
                    'real_invoker_release_preflight_id',
                    'signed_real_invoker_release_id',
                    'real_invoker_implementation_boundary_id',
                    'real_invoker_executor_plan_id',
                    'real_invoker_executor_fresh_release_id',
                    'real_invoker_executor_enablement_id',
                    'real_invoker_supervised_start_activation_id',
                    'real_invoker_guarded_process_start_id',
                    'operator_guarded_start_receipt_hash',
                    'process_runner_contract_hash',
                    'dry_run_rehearsal_hash',
                    'launch_invocation_contract_hash',
                    'post_start_observability_hash',
                    'revoke_guard_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'real_invoker_guarded_process_start_id',
                    'real_invoker_supervised_start_activation_id',
                    'agent_run_id',
                    'run_key',
                    'provider',
                    'adapter',
                    'real_invoker_guarded_process_start_prepared',
                    'executor_enabled',
                    'process_start_armed',
                    'actual_process_start_allowed',
                    'external_process_started',
                    'token_spend_allowed',
                    'provider_started',
                    'dispatch_allowed',
                ],
            ],
            'real_invoker_guarded_process_start_must' => [
                'require_codex_real_invoker_supervised_start_activation',
                'require_operator_guarded_start_receipt_hash',
                'require_process_runner_contract_hash',
                'require_dry_run_rehearsal_hash',
                'require_launch_invocation_contract_hash',
                'record_append_only_guarded_process_start_event_before_any_actual_start',
            ],
            'real_invoker_guarded_process_start_must_not' => [
                'start_codex_process_from_contract_template',
                'spawn_shell_or_subprocess',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'dispatch_work_to_provider',
                'complete_or_merge_packet',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerGuardedProcessStartExecutor.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerGuardedProcessStartExecutorTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'guarded_process_start_preparation_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-guarded-process-start-executor-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_guarded_process_start_executor_contract_template.v1',
            'status' => 'codex_real_invoker_guarded_process_start_executor_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_guarded_process_start_executor_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_guarded_process_start_executor_contract_template' => $template,
            'codex_real_invoker_guarded_process_start_executor_contract_template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_guarded_process_start_executor_contract_template_does_not_start_codex',
                'agent_codex_real_invoker_guarded_process_start_executor_contract_template_does_not_call_codex',
                'agent_codex_real_invoker_guarded_process_start_executor_contract_template_does_not_spend_tokens',
                'agent_codex_real_invoker_guarded_process_start_executor_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker guarded process start executor contract template prepares the last guarded start envelope while keeping actual start disabled.',
        ];
    }


public function agentCodexRealInvokerGuardedProcessStartExecutorPreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerGuardedProcessStartExecutorContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_guarded_process_start_executor_contract_template', []);
        $guardedStartClass = AgentCodexRealInvokerGuardedProcessStartExecutor::class;
        $activationClass = AgentCodexRealInvokerSupervisedStartActivationGate::class;
        $guardedStartReady = class_exists($guardedStartClass);
        $activationReady = class_exists($activationClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $guardedStartReady ? null : 'codex_real_invoker_guarded_process_start_executor_missing',
            $activationReady ? null : 'codex_real_invoker_supervised_start_activation_gate_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_guarded_process_start_executor_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_guarded_process_start_executor_contract_template_hash'),
            'source_codex_real_invoker_supervised_start_activation_gate_status' => data_get($contract, 'source_codex_real_invoker_supervised_start_activation_gate_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_guarded_process_start_executor_ready' => $guardedStartReady,
                'codex_real_invoker_supervised_start_activation_gate_ready' => $activationReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'guarded_process_start_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_real_invoker_guarded_process_start_executor',
                'require_codex_real_invoker_supervised_start_activation_metadata',
                'require_operator_guarded_start_receipt_hash',
                'require_dry_run_rehearsal_and_process_runner_contract_hashes',
                'record_guarded_process_start_event_without_starting_codex',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerGuardedProcessStartExecutor.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerGuardedProcessStartExecutorTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_guarded_process_start_executor',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'guarded_process_start_executor_file_creation_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-guarded-process-start-executor-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_guarded_process_start_executor_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_guarded_process_start_executor_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_guarded_process_start_executor_preflight' => $preflight,
            'codex_real_invoker_guarded_process_start_executor_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_guarded_process_start_executor_preflight_does_not_start_codex',
                'agent_codex_real_invoker_guarded_process_start_executor_preflight_does_not_call_codex',
                'agent_codex_real_invoker_guarded_process_start_executor_preflight_does_not_spend_tokens',
                'agent_codex_real_invoker_guarded_process_start_executor_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker guarded process start executor is ready; actual process start still requires a later explicit final start authorization.'
                : 'Codex real invoker guarded process start executor is blocked until the executor and activation prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerGuardedProcessStartExecutorImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerGuardedProcessStartExecutorPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_guarded_process_start_executor_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_guarded_process_start_executor_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_future_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create Codex real invoker guarded process start executor',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerGuardedProcessStartExecutor.php'],
                'acceptance' => 'Executor records guarded process-start metadata and never starts Codex or permits token spend.',
            ],
            [
                'id' => 'T2',
                'title' => 'Enforce supervised activation and guarded start contracts',
                'type' => 'service_logic',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerGuardedProcessStartExecutor.php'],
                'acceptance' => 'Executor requires supervised activation metadata, guarded receipt, process runner contract, dry-run rehearsal, launch contract and revoke guard hashes.',
            ],
            [
                'id' => 'T3',
                'title' => 'Add guarded process start tests',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerGuardedProcessStartExecutorTest.php'],
                'acceptance' => 'Tests prove missing activation rejection, duplicate guarded start rejection, rollback and no process/token/dispatch side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose guarded process start readiness commands',
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
            'status' => 'ready_for_scoped_codex_real_invoker_guarded_process_start_executor_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-GUARDED-PROCESS-START-EXECUTOR-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker guarded process start executor that prepares a future final process start while still refusing to start Codex, spend tokens or dispatch work.',
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
                'guarded_start_executor_requires_supervised_activation_metadata',
                'guarded_start_executor_requires_operator_guarded_start_receipt_hash',
                'guarded_start_executor_requires_dry_run_rehearsal_hash',
                'guarded_start_executor_is_idempotent_for_same_guarded_start_id',
                'guarded_start_executor_records_guarded_event_without_starting_codex',
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
                'guarded_process_start_preparation_allowed_by_packet' => false,
                'actual_process_start_allowed_by_packet' => false,
                'provider_process_start_allowed_by_packet' => false,
                'codex_invocation_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
            ],
            'next_required_action' => 'Implement only the allowed files, then run the required gates and report evidence.',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_guarded_process_start_executor_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_guarded_process_start_executor_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_guarded_process_start_executor_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_guarded_process_start_executor_implementation_packet' => $packet,
            'codex_real_invoker_guarded_process_start_executor_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_guarded_process_start_executor_implementation_packet_does_not_start_codex',
                'agent_codex_real_invoker_guarded_process_start_executor_implementation_packet_does_not_call_codex',
                'agent_codex_real_invoker_guarded_process_start_executor_implementation_packet_does_not_spend_tokens',
                'agent_codex_real_invoker_guarded_process_start_executor_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker guarded process start executor implementation packet is ready; it prepares a future final start only and does not start Codex.',
        ];
    }


}
