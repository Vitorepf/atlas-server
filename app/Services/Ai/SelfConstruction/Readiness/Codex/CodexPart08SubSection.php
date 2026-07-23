<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\Codex;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorAdapterInvocationBoundary;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorProviderStartDriver;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorReceiptUseWriter;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentProviderAdapterExecutionGuard;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentProviderAdapterRegistry;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentDispatchProviderSection;
use App\Services\Ai\SelfConstruction\Support\AgentCodexProviderExecutionDriver;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartAdapterExecutionGuardGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartDispatchExecutorHandoff;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartDispatchReceiptUseExecutor;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartProviderExecutionContractGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartProviderStartDriverGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;
use Illuminate\Support\Facades\Schema;

/**
 * AGENT CODEX projection sub-section 08 of 11, sub-split from the god
 * {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling agentCodex* pipeline calls route through the
 * injected Section facade (`$this->section->agentCodex*`), which re-dispatches
 * to whichever sub-section owns the target stage. The dispatch-provider
 * collaborator is injected verbatim for the one stage that consumes it.
 *
 * Stage range: agentCodexRealInvokerPostStartDispatchExecutorHandoffContractTemplate
 *           .. agentCodexRealInvokerPostStartProviderExecutionContractGateImplementationPacket
 */
final class CodexPart08SubSection
{
    public function __construct(
        private readonly ReadinessProjectionAgentCodexSection $section,
        private readonly ReadinessProjectionAgentDispatchProviderSection $agentDispatchProviderSection,
    ) {}

public function agentCodexRealInvokerPostStartDispatchExecutorHandoffContractTemplate(array $options = []): array
    {
        $authorizationPayload = $this->section->agentCodexRealInvokerPostStartSignedDispatchAuthorizationGatePreflight($options);
        $authorization = (array) data_get($authorizationPayload, 'codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_dispatch_executor_handoff_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-DISPATCH-EXECUTOR-HANDOFF-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_signed_dispatch_authorization_gate_preflight_hash' => data_get($authorizationPayload, 'codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_signed_dispatch_authorization_gate_status' => data_get($authorizationPayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartDispatchExecutorHandoff',
                'method' => 'preparePostStartDispatchExecutorHandoff',
                'input_contract' => [
                    'run_key',
                    'codex_execution_id',
                    'manual_start_executor_receipt_id',
                    'operator_start_handoff_id',
                    'post_start_receipt_contract_id',
                    'post_start_evidence_receipt_id',
                    'post_start_evidence_acceptance_bridge_id',
                    'post_start_liveness_monitor_id',
                    'dispatch_release_gate_id',
                    'signed_dispatch_authorization_id',
                    'dispatch_executor_handoff_id',
                    'signed_dispatch_receipt_hash',
                    'human_dispatch_signature_hash',
                    'signed_dispatch_policy_hash',
                    'dispatch_window_hash',
                    'dispatch_scope_hash',
                    'continuation_summary_hash',
                    'context_pack_hash',
                    'dispatch_replay_guard_hash',
                    'dispatch_kill_switch_hash',
                    'executor_handoff_packet_hash',
                    'executor_workspace_hash',
                    'executor_scope_lock_hash',
                    'no_direct_provider_call_attestation_hash',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'dispatch_executor_handoff_id',
                    'signed_dispatch_authorization_id',
                    'post_start_evidence_acceptance_bridge_id',
                    'agent_run_id',
                    'run_key',
                    'run_status',
                    'dispatch_executor_handoff_prepared',
                    'future_dispatch_authorized',
                    'dispatch_allowed',
                ],
            ],
            'real_invoker_post_start_dispatch_executor_handoff_must' => [
                'require_codex_real_invoker_post_start_evidence_acceptance_bridge',
                'require_codex_real_invoker_post_start_signed_dispatch_authorization',
                'require_liveness_state_alive',
                'require_signed_dispatch_authorization_recorded',
                'require_future_dispatch_authorized',
                'require_executor_handoff_packet_hash',
                'require_executor_workspace_hash',
                'require_executor_scope_lock_hash',
                'record_append_only_dispatch_executor_handoff_event',
            ],
            'real_invoker_post_start_dispatch_executor_handoff_must_not' => [
                'call_codex_cli_or_codex_app',
                'spawn_process_or_shell_command',
                'spend_provider_tokens',
                'dispatch_work_to_provider',
                'call_provider_process',
                'mark_run_running_or_terminal',
                'mark_signed_dispatch_receipt_used',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartDispatchExecutorHandoff.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartDispatchExecutorHandoffTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'post_start_dispatch_executor_handoff_write_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_dispatch_use_receipt_executor' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-dispatch-executor-handoff-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_dispatch_executor_handoff_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_dispatch_executor_handoff_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_dispatch_executor_handoff_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_dispatch_executor_handoff_contract_template' => $template,
            'codex_real_invoker_post_start_dispatch_executor_handoff_contract_template_hash' => ReadinessHash::stable($template),
            'source_authorization_preflight' => $authorization,
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_post_start_dispatch_executor_handoff_contract_template_does_not_start_codex',
                'agent_codex_real_invoker_post_start_dispatch_executor_handoff_contract_template_does_not_call_codex',
                'agent_codex_real_invoker_post_start_dispatch_executor_handoff_contract_template_does_not_spend_tokens',
                'agent_codex_real_invoker_post_start_dispatch_executor_handoff_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker post-start dispatch executor handoff contract template prepares the future executor handoff only; it does not dispatch work.',
        ];
    }


public function agentCodexRealInvokerPostStartDispatchExecutorHandoffPreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartDispatchExecutorHandoffContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_dispatch_executor_handoff_contract_template', []);
        $handoffClass = AgentCodexRealInvokerPostStartDispatchExecutorHandoff::class;
        $bridgeClass = AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge::class;
        $authorizationClass = AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate::class;
        $handoffReady = class_exists($handoffClass);
        $bridgeReady = class_exists($bridgeClass);
        $authorizationReady = class_exists($authorizationClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $handoffReady ? null : 'codex_real_invoker_post_start_dispatch_executor_handoff_missing',
            $bridgeReady ? null : 'codex_real_invoker_post_start_evidence_acceptance_bridge_missing',
            $authorizationReady ? null : 'codex_real_invoker_post_start_signed_dispatch_authorization_gate_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_dispatch_executor_handoff_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_dispatch_executor_handoff_contract_template_hash'),
            'source_codex_real_invoker_post_start_signed_dispatch_authorization_gate_status' => data_get($contract, 'source_codex_real_invoker_post_start_signed_dispatch_authorization_gate_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_post_start_dispatch_executor_handoff_ready' => $handoffReady,
                'codex_real_invoker_post_start_evidence_acceptance_bridge_ready' => $bridgeReady,
                'codex_real_invoker_post_start_signed_dispatch_authorization_gate_ready' => $authorizationReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_dispatch_executor_handoff_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_real_invoker_post_start_dispatch_executor_handoff',
                'require_codex_real_invoker_post_start_evidence_acceptance_bridge_metadata',
                'require_codex_real_invoker_post_start_signed_dispatch_authorization_metadata',
                'require_executor_handoff_packet_workspace_and_scope_hashes',
                'record_dispatch_executor_handoff_without_dispatching_codex',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartDispatchExecutorHandoff.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartDispatchExecutorHandoffTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_dispatch_executor_handoff',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'post_start_dispatch_executor_handoff_file_creation_allowed_here' => false,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_dispatch_use_receipt_executor' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-dispatch-executor-handoff-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_dispatch_executor_handoff_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_dispatch_executor_handoff_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_dispatch_executor_handoff_preflight' => $preflight,
            'codex_real_invoker_post_start_dispatch_executor_handoff_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_post_start_dispatch_executor_handoff_preflight_does_not_start_codex',
                'agent_codex_real_invoker_post_start_dispatch_executor_handoff_preflight_does_not_call_codex',
                'agent_codex_real_invoker_post_start_dispatch_executor_handoff_preflight_does_not_spend_tokens',
                'agent_codex_real_invoker_post_start_dispatch_executor_handoff_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker post-start dispatch executor handoff is ready; it prepares executor handoff but still does not dispatch work.'
                : 'Codex real invoker post-start dispatch executor handoff is blocked until signed authorization and handoff prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartDispatchExecutorHandoffImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartDispatchExecutorHandoffPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_dispatch_executor_handoff_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_dispatch_executor_handoff_preflight_hash');

        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start dispatch executor handoff', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartDispatchExecutorHandoff.php'], 'acceptance' => 'Handoff records executor-ready metadata after signed dispatch authorization and never dispatches work.'],
            ['id' => 'T2', 'title' => 'Enforce executor handoff contract', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartDispatchExecutorHandoff.php'], 'acceptance' => 'Handoff requires signed authorization metadata, accepted evidence bridge id, alive liveness, executor packet, workspace, scope lock, receipt hashes and no-provider-call attestation.'],
            ['id' => 'T3', 'title' => 'Add post-start dispatch executor handoff tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartDispatchExecutorHandoffTest.php'], 'acceptance' => 'Tests prove missing authorization rejection, non-alive rejection, missing executor workspace rejection, duplicate rejection, rollback and no process/token/dispatch side effects.'],
            ['id' => 'T4', 'title' => 'Expose post-start dispatch executor handoff readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare dispatch disabled.'],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_dispatch_executor_handoff_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-DISPATCH-EXECUTOR-HANDOFF-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker post-start dispatch executor handoff that prepares a future receipt-use executor handoff while forbidding Atlas from dispatching work.',
            'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_mark_signed_dispatch_receipt_used', 'do_not_mark_runs_running_or_terminal'],
            'allowed_files' => data_get($preflight, 'allowed_future_files', []),
            'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'process_start', 'provider_process_call', 'dispatch_runtime', 'receipt_use_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => ['post_start_dispatch_executor_handoff_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_dispatch_executor_handoff_requires_signed_dispatch_authorization_metadata', 'post_start_dispatch_executor_handoff_requires_liveness_alive', 'post_start_dispatch_executor_handoff_requires_executor_packet_workspace_and_scope_hashes', 'post_start_dispatch_executor_handoff_does_not_dispatch_codex'],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'post_start_dispatch_executor_handoff_write_allowed_by_packet' => false, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_dispatch_executor_handoff_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_dispatch_executor_handoff_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_dispatch_executor_handoff_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_dispatch_executor_handoff_implementation_packet' => $packet,
            'codex_real_invoker_post_start_dispatch_executor_handoff_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_dispatch_executor_handoff_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_dispatch_executor_handoff_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_dispatch_executor_handoff_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_dispatch_executor_handoff_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start dispatch executor handoff implementation packet is ready; it prepares executor handoff and does not dispatch work.',
        ];
    }


public function agentCodexRealInvokerPostStartDispatchReceiptUseExecutorContractTemplate(array $options = []): array
    {
        $handoffPayload = $this->section->agentCodexRealInvokerPostStartDispatchExecutorHandoffPreflight($options);
        $handoff = (array) data_get($handoffPayload, 'codex_real_invoker_post_start_dispatch_executor_handoff_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-DISPATCH-RECEIPT-USE-EXECUTOR-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_dispatch_executor_handoff_preflight_hash' => data_get($handoffPayload, 'codex_real_invoker_post_start_dispatch_executor_handoff_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_dispatch_executor_handoff_status' => data_get($handoffPayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartDispatchReceiptUseExecutor',
                'method' => 'executePostStartDispatchReceiptUse',
                'input_contract' => [
                    'run_key',
                    'dispatch_executor_handoff_id',
                    'signed_dispatch_authorization_id',
                    'post_start_evidence_acceptance_bridge_id',
                    'signed_dispatch_receipt_hash',
                    'executor_contract_hash',
                    'executor_release_authorization_hash',
                    'executor_handoff_packet_hash',
                    'executor_workspace_hash',
                    'executor_scope_lock_hash',
                    'provider_start_attempt_id',
                    'actor',
                    'session',
                    'reason',
                ],
                'result_contract' => [
                    'provider_start_attempt_id',
                    'dispatch_executor_handoff_id',
                    'post_start_evidence_acceptance_bridge_id',
                    'signed_dispatch_receipt_hash',
                    'dispatch_receipt_used',
                    'provider_start_allowed_after_mark',
                    'dispatch_allowed',
                ],
            ],
            'real_invoker_post_start_dispatch_receipt_use_must' => [
                'require_codex_real_invoker_post_start_dispatch_executor_handoff',
                'require_post_start_evidence_acceptance_bridge_metadata',
                'require_signed_dispatch_receipt_hash',
                'require_executor_contract_hash',
                'require_executor_release_authorization_hash',
                'call_atomic_receipt_use_writer',
                'keep_provider_start_disabled_after_mark',
            ],
            'real_invoker_post_start_dispatch_receipt_use_must_not' => [
                'call_codex_cli_or_codex_app',
                'spawn_process_or_shell_command',
                'spend_provider_tokens',
                'dispatch_work_to_provider',
                'call_provider_process',
                'mark_run_running_or_terminal',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartDispatchReceiptUseExecutor.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartDispatchReceiptUseExecutorTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'receipt_use_execution_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_provider_start_driver' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-dispatch-receipt-use-executor-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_template' => $template,
            'codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_template_hash' => ReadinessHash::stable($template),
            'source_handoff_preflight' => $handoff,
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_template_does_not_start_codex',
                'agent_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_template_does_not_call_codex',
                'agent_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_template_does_not_spend_tokens',
                'agent_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex real invoker post-start dispatch receipt-use executor contract template permits only atomic receipt-use marking; it does not start Codex.',
        ];
    }


public function agentCodexRealInvokerPostStartDispatchReceiptUseExecutorPreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartDispatchReceiptUseExecutorContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_template', []);
        $executorClass = AgentCodexRealInvokerPostStartDispatchReceiptUseExecutor::class;
        $handoffClass = AgentCodexRealInvokerPostStartDispatchExecutorHandoff::class;
        $receiptUseWriterClass = AgentDispatchExecutorReceiptUseWriter::class;
        $executorReady = class_exists($executorClass);
        $handoffReady = class_exists($handoffClass);
        $receiptUseWriterReady = class_exists($receiptUseWriterClass);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $dispatchReceiptsTableReady = Schema::hasTable('atlas_self_construction_agent_dispatch_receipts');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $executorReady ? null : 'codex_real_invoker_post_start_dispatch_receipt_use_executor_missing',
            $handoffReady ? null : 'codex_real_invoker_post_start_dispatch_executor_handoff_missing',
            $receiptUseWriterReady ? null : 'dispatch_executor_receipt_use_writer_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $dispatchReceiptsTableReady ? null : 'dispatch_receipts_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_dispatch_receipt_use_executor_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_template_hash'),
            'source_codex_real_invoker_post_start_dispatch_executor_handoff_status' => data_get($contract, 'source_codex_real_invoker_post_start_dispatch_executor_handoff_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_post_start_dispatch_receipt_use_executor_ready' => $executorReady,
                'codex_real_invoker_post_start_dispatch_executor_handoff_ready' => $handoffReady,
                'dispatch_executor_receipt_use_writer_ready' => $receiptUseWriterReady,
                'agent_runs_table_ready' => $runsTableReady,
                'dispatch_receipts_table_ready' => $dispatchReceiptsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_dispatch_receipt_use_executor_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => [
                'create_codex_real_invoker_post_start_dispatch_receipt_use_executor',
                'require_codex_real_invoker_post_start_dispatch_executor_handoff_metadata',
                'require_post_start_evidence_acceptance_bridge_metadata',
                'call_atomic_receipt_use_writer_without_starting_codex',
                'record_run_metadata_after_receipt_use',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartDispatchReceiptUseExecutor.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartDispatchReceiptUseExecutorTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_dispatch_receipt_use_executor',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'post_start_dispatch_receipt_use_executor_file_creation_allowed_here' => false,
                'receipt_use_mark_allowed_by_executor' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_provider_start_driver' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-dispatch-receipt-use-executor-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight' => $preflight,
            'codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight_does_not_start_codex',
                'agent_codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight_does_not_call_codex',
                'agent_codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight_does_not_spend_tokens',
                'agent_codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker post-start dispatch receipt-use executor is ready; it marks receipt use but still does not start Codex.'
                : 'Codex real invoker post-start dispatch receipt-use executor is blocked until handoff, writer and storage prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartDispatchReceiptUseExecutorImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartDispatchReceiptUseExecutorPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight_hash');

        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start dispatch receipt-use executor', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartDispatchReceiptUseExecutor.php'], 'acceptance' => 'Executor consumes post-start handoff and marks one signed dispatch receipt used via the atomic writer.'],
            ['id' => 'T2', 'title' => 'Enforce receipt-use executor contract', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartDispatchReceiptUseExecutor.php'], 'acceptance' => 'Executor requires handoff metadata, evidence acceptance bridge id, receipt hash, executor contract hash and release authorization hash while keeping provider start disabled.'],
            ['id' => 'T3', 'title' => 'Add post-start dispatch receipt-use executor tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartDispatchReceiptUseExecutorTest.php'], 'acceptance' => 'Tests prove receipt use, idempotency, duplicate attempt rejection, missing handoff/evidence bridge rejection, packet mismatch rejection and rollback.'],
            ['id' => 'T4', 'title' => 'Expose post-start dispatch receipt-use executor readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare provider start and dispatch disabled.'],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_dispatch_receipt_use_executor_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-DISPATCH-RECEIPT-USE-EXECUTOR-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker post-start dispatch receipt-use executor that marks a signed dispatch receipt used after handoff while forbidding provider start.',
            'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_mark_runs_running_or_terminal'],
            'allowed_files' => data_get($preflight, 'allowed_future_files', []),
            'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'process_start', 'provider_process_call', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => ['post_start_dispatch_receipt_use_executor_requires_handoff_metadata', 'post_start_dispatch_receipt_use_executor_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_dispatch_receipt_use_executor_marks_signed_receipt_used_once', 'post_start_dispatch_receipt_use_executor_keeps_provider_start_disabled', 'post_start_dispatch_receipt_use_executor_does_not_dispatch_codex'],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'receipt_use_mark_allowed_by_executor' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_dispatch_receipt_use_executor_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_dispatch_receipt_use_executor_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_dispatch_receipt_use_executor_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_dispatch_receipt_use_executor_implementation_packet' => $packet,
            'codex_real_invoker_post_start_dispatch_receipt_use_executor_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_dispatch_receipt_use_executor_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_dispatch_receipt_use_executor_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_dispatch_receipt_use_executor_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_dispatch_receipt_use_executor_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start dispatch receipt-use executor implementation packet is ready; it marks receipt use but does not start Codex.',
        ];
    }


public function agentCodexRealInvokerPostStartProviderStartDriverGateContractTemplate(array $options = []): array
    {
        $receiptUsePayload = $this->section->agentCodexRealInvokerPostStartDispatchReceiptUseExecutorPreflight($options);
        $receiptUse = (array) data_get($receiptUsePayload, 'codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_provider_start_driver_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-PROVIDER-START-DRIVER-GATE-'.strtoupper(substr(ReadinessHash::stable([
                'receipt_use_preflight_hash' => data_get($receiptUsePayload, 'codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_dispatch_receipt_use_status' => data_get($receiptUsePayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartProviderStartDriverGate',
                'method' => 'preparePostStartProviderStartDriver',
                'input_contract' => ['run_key', 'provider_start_driver_gate_id', 'provider_start_attempt_id', 'dispatch_executor_handoff_id', 'signed_dispatch_authorization_id', 'post_start_evidence_acceptance_bridge_id', 'signed_dispatch_receipt_hash', 'executor_contract_hash', 'executor_release_authorization_hash', 'executor_handoff_packet_hash', 'executor_workspace_hash', 'executor_scope_lock_hash', 'sandbox_binding_key', 'command', 'cwd', 'actor', 'session', 'max_runtime_minutes', 'max_cost_usd', 'reason'],
                'result_contract' => ['provider_start_driver_gate_id', 'provider_start_attempt_id', 'post_start_evidence_acceptance_bridge_id', 'provider_start_result', 'adapter_invocation_allowed', 'dispatch_allowed'],
            ],
            'real_invoker_post_start_provider_start_driver_gate_must' => [
                'require_codex_real_invoker_post_start_dispatch_receipt_use_metadata',
                'require_post_start_evidence_acceptance_bridge_metadata',
                'require_dispatch_receipt_used_pending_provider_start',
                'require_active_sandbox_binding',
                'delegate_to_agent_dispatch_executor_provider_start_driver',
                'record_bridge_metadata_on_observed_post_start_run',
            ],
            'real_invoker_post_start_provider_start_driver_gate_must_not' => [
                'call_codex_cli_or_codex_app',
                'spawn_process_or_shell_command',
                'spend_provider_tokens',
                'dispatch_work_to_provider',
                'enable_adapter_invocation',
                'mark_observed_run_running_or_terminal',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProviderStartDriverGate.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartProviderStartDriverGateTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'provider_start_driver_gate_execution_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_adapter_invocation_boundary' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-provider-start-driver-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_provider_start_driver_gate_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_provider_start_driver_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_provider_start_driver_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_provider_start_driver_gate_contract_template' => $template,
            'codex_real_invoker_post_start_provider_start_driver_gate_contract_template_hash' => ReadinessHash::stable($template),
            'source_receipt_use_preflight' => $receiptUse,
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_provider_start_driver_gate_contract_template_does_not_start_codex', 'agent_codex_real_invoker_post_start_provider_start_driver_gate_contract_template_does_not_call_codex', 'agent_codex_real_invoker_post_start_provider_start_driver_gate_contract_template_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_provider_start_driver_gate_contract_template_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start provider start driver gate contract template bridges receipt-use to the generic provider start driver without calling Codex.',
        ];
    }


public function agentCodexRealInvokerPostStartProviderStartDriverGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartProviderStartDriverGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_provider_start_driver_gate_contract_template', []);
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProviderStartDriverGate::class);
        $receiptUseReady = class_exists(AgentCodexRealInvokerPostStartDispatchReceiptUseExecutor::class);
        $driverReady = class_exists(AgentDispatchExecutorProviderStartDriver::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $dispatchReceiptsTableReady = Schema::hasTable('atlas_self_construction_agent_dispatch_receipts');
        $releaseAuthorizationsTableReady = Schema::hasTable('atlas_self_construction_agent_dispatch_executor_release_authorizations');
        $sandboxBindingsTableReady = Schema::hasTable('atlas_self_construction_agent_sandbox_bindings');
        $heartbeatsTableReady = Schema::hasTable('atlas_self_construction_agent_heartbeats');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $gateReady ? null : 'codex_real_invoker_post_start_provider_start_driver_gate_missing',
            $receiptUseReady ? null : 'codex_real_invoker_post_start_dispatch_receipt_use_executor_missing',
            $driverReady ? null : 'dispatch_executor_provider_start_driver_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $dispatchReceiptsTableReady ? null : 'dispatch_receipts_table_missing',
            $releaseAuthorizationsTableReady ? null : 'dispatch_executor_release_authorizations_table_missing',
            $sandboxBindingsTableReady ? null : 'sandbox_bindings_table_missing',
            $heartbeatsTableReady ? null : 'agent_heartbeats_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_provider_start_driver_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_provider_start_driver_gate_contract_template_hash'),
            'source_codex_real_invoker_post_start_dispatch_receipt_use_status' => data_get($contract, 'source_codex_real_invoker_post_start_dispatch_receipt_use_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_post_start_provider_start_driver_gate_ready' => $gateReady,
                'codex_real_invoker_post_start_dispatch_receipt_use_executor_ready' => $receiptUseReady,
                'dispatch_executor_provider_start_driver_ready' => $driverReady,
                'agent_runs_table_ready' => $runsTableReady,
                'dispatch_receipts_table_ready' => $dispatchReceiptsTableReady,
                'dispatch_executor_release_authorizations_table_ready' => $releaseAuthorizationsTableReady,
                'sandbox_bindings_table_ready' => $sandboxBindingsTableReady,
                'agent_heartbeats_table_ready' => $heartbeatsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_provider_start_driver_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => ['create_codex_real_invoker_post_start_provider_start_driver_gate', 'require_post_start_dispatch_receipt_use_metadata', 'require_post_start_evidence_acceptance_bridge_metadata', 'delegate_to_generic_provider_start_driver_without_calling_codex', 'record_provider_start_driver_bridge_on_observed_run'],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProviderStartDriverGate.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartProviderStartDriverGateTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_provider_start_driver_gate',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'post_start_provider_start_driver_gate_file_creation_allowed_here' => false,
                'provider_start_driver_bridge_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_adapter_invocation_boundary' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-provider-start-driver-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_provider_start_driver_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_provider_start_driver_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_provider_start_driver_gate_preflight' => $preflight,
            'codex_real_invoker_post_start_provider_start_driver_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_provider_start_driver_gate_preflight_does_not_start_codex', 'agent_codex_real_invoker_post_start_provider_start_driver_gate_preflight_does_not_call_codex', 'agent_codex_real_invoker_post_start_provider_start_driver_gate_preflight_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_provider_start_driver_gate_preflight_does_not_dispatch_work'],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker post-start provider start driver gate is ready; it prepares the driver bridge but still does not call Codex.'
                : 'Codex real invoker post-start provider start driver gate is blocked until receipt-use, driver, sandbox and storage prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartProviderStartDriverGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartProviderStartDriverGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_provider_start_driver_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_provider_start_driver_gate_preflight_hash');

        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start provider start driver gate', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProviderStartDriverGate.php'], 'acceptance' => 'Gate consumes post-start receipt-use metadata and delegates to the generic provider start driver without calling Codex.'],
            ['id' => 'T2', 'title' => 'Enforce provider start bridge contract', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProviderStartDriverGate.php'], 'acceptance' => 'Gate requires used receipt, evidence acceptance bridge id, active sandbox binding, release authorization hashes and keeps adapter invocation disabled.'],
            ['id' => 'T3', 'title' => 'Add post-start provider start driver gate tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartProviderStartDriverGateTest.php'], 'acceptance' => 'Tests prove bridge preparation, idempotency, duplicate attempt rejection, missing receipt-use/evidence bridge rejection, sandbox rejection and rollback.'],
            ['id' => 'T4', 'title' => 'Expose post-start provider start driver gate readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare adapter invocation and dispatch disabled.'],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_provider_start_driver_gate_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-PROVIDER-START-DRIVER-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker post-start provider start driver gate that bridges receipt-use to the generic provider start driver while forbidding Codex invocation.',
            'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_enable_adapter_invocation'],
            'allowed_files' => data_get($preflight, 'allowed_future_files', []),
            'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'process_start', 'provider_process_call', 'adapter_invocation_runtime', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => ['post_start_provider_start_driver_gate_requires_receipt_use_metadata', 'post_start_provider_start_driver_gate_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_provider_start_driver_gate_requires_active_sandbox_binding', 'post_start_provider_start_driver_gate_delegates_to_generic_provider_start_driver', 'post_start_provider_start_driver_gate_does_not_call_codex'],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'provider_start_driver_bridge_allowed_by_service' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'adapter_invocation_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_provider_start_driver_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_provider_start_driver_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_provider_start_driver_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_provider_start_driver_gate_implementation_packet' => $packet,
            'codex_real_invoker_post_start_provider_start_driver_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_provider_start_driver_gate_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_provider_start_driver_gate_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_provider_start_driver_gate_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_provider_start_driver_gate_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start provider start driver gate implementation packet is ready; it prepares the driver bridge but does not call Codex.',
        ];
    }


public function agentCodexRealInvokerPostStartAdapterInvocationBoundaryGateContractTemplate(array $options = []): array
    {
        $providerStartPayload = $this->section->agentCodexRealInvokerPostStartProviderStartDriverGatePreflight($options);
        $providerStart = (array) data_get($providerStartPayload, 'codex_real_invoker_post_start_provider_start_driver_gate_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-ADAPTER-INVOCATION-BOUNDARY-GATE-'.strtoupper(substr(ReadinessHash::stable([
                'provider_start_driver_preflight_hash' => data_get($providerStartPayload, 'codex_real_invoker_post_start_provider_start_driver_gate_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_provider_start_driver_status' => data_get($providerStartPayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate',
                'method' => 'preparePostStartAdapterInvocationBoundary',
                'input_contract' => ['run_key', 'adapter_invocation_boundary_gate_id', 'adapter_invocation_id', 'provider_start_driver_gate_id', 'provider_start_attempt_id', 'dispatch_executor_handoff_id', 'signed_dispatch_authorization_id', 'post_start_evidence_acceptance_bridge_id', 'signed_dispatch_receipt_hash', 'context_pack_hash', 'continuation_summary_hash', 'command', 'cwd', 'actor', 'session', 'max_runtime_minutes', 'max_cost_usd', 'reason'],
                'result_contract' => ['adapter_invocation_boundary_gate_id', 'adapter_invocation_id', 'post_start_evidence_acceptance_bridge_id', 'adapter_invocation_boundary_result', 'adapter_invocation_allowed', 'dispatch_allowed'],
            ],
            'real_invoker_post_start_adapter_invocation_boundary_gate_must' => [
                'require_codex_real_invoker_post_start_provider_start_driver_metadata',
                'require_post_start_evidence_acceptance_bridge_metadata',
                'require_provider_start_projection_run',
                'require_pre_start_heartbeat',
                'delegate_to_agent_dispatch_executor_adapter_invocation_boundary',
                'record_bridge_metadata_on_observed_post_start_run',
            ],
            'real_invoker_post_start_adapter_invocation_boundary_gate_must_not' => [
                'call_codex_cli_or_codex_app',
                'spawn_process_or_shell_command',
                'spend_provider_tokens',
                'dispatch_work_to_provider',
                'enable_adapter_execution',
                'mark_observed_run_running_or_terminal',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartAdapterInvocationBoundaryGateTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'adapter_invocation_boundary_bridge_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_adapter_execution_guard' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-adapter-invocation-boundary-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_template' => $template,
            'codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_template_hash' => ReadinessHash::stable($template),
            'source_provider_start_driver_preflight' => $providerStart,
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_template_does_not_start_codex', 'agent_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_template_does_not_call_codex', 'agent_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_template_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_template_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start adapter invocation boundary gate contract template bridges provider start driver metadata to the generic adapter invocation boundary without calling Codex.',
        ];
    }


public function agentCodexRealInvokerPostStartAdapterInvocationBoundaryGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartAdapterInvocationBoundaryGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_template', []);
        $gateReady = class_exists(AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate::class);
        $providerStartGateReady = class_exists(AgentCodexRealInvokerPostStartProviderStartDriverGate::class);
        $boundaryReady = class_exists(AgentDispatchExecutorAdapterInvocationBoundary::class);
        $registryReady = class_exists(AgentProviderAdapterRegistry::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $heartbeatsTableReady = Schema::hasTable('atlas_self_construction_agent_heartbeats');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $gateReady ? null : 'codex_real_invoker_post_start_adapter_invocation_boundary_gate_missing',
            $providerStartGateReady ? null : 'codex_real_invoker_post_start_provider_start_driver_gate_missing',
            $boundaryReady ? null : 'dispatch_executor_adapter_invocation_boundary_missing',
            $registryReady ? null : 'provider_adapter_registry_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $heartbeatsTableReady ? null : 'agent_heartbeats_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_adapter_invocation_boundary_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_template_hash'),
            'source_codex_real_invoker_post_start_provider_start_driver_status' => data_get($contract, 'source_codex_real_invoker_post_start_provider_start_driver_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_post_start_adapter_invocation_boundary_gate_ready' => $gateReady,
                'codex_real_invoker_post_start_provider_start_driver_gate_ready' => $providerStartGateReady,
                'dispatch_executor_adapter_invocation_boundary_ready' => $boundaryReady,
                'provider_adapter_registry_ready' => $registryReady,
                'agent_runs_table_ready' => $runsTableReady,
                'agent_heartbeats_table_ready' => $heartbeatsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_adapter_invocation_boundary_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => ['create_codex_real_invoker_post_start_adapter_invocation_boundary_gate', 'require_post_start_provider_start_driver_metadata', 'require_post_start_evidence_acceptance_bridge_metadata', 'delegate_to_generic_adapter_invocation_boundary_without_calling_codex', 'record_adapter_invocation_boundary_bridge_on_observed_run'],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartAdapterInvocationBoundaryGateTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_adapter_invocation_boundary_gate',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'post_start_adapter_invocation_boundary_gate_file_creation_allowed_here' => false,
                'adapter_invocation_boundary_bridge_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_adapter_execution_guard' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-adapter-invocation-boundary-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight' => $preflight,
            'codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight_does_not_start_codex', 'agent_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight_does_not_call_codex', 'agent_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight_does_not_dispatch_work'],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker post-start adapter invocation boundary gate is ready; it prepares the boundary bridge but still does not call Codex.'
                : 'Codex real invoker post-start adapter invocation boundary gate is blocked until provider start metadata, boundary, registry and storage prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartAdapterInvocationBoundaryGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartAdapterInvocationBoundaryGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight_hash');

        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start adapter invocation boundary gate', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate.php'], 'acceptance' => 'Gate consumes post-start provider start driver metadata and delegates to the generic adapter invocation boundary without calling Codex.'],
            ['id' => 'T2', 'title' => 'Enforce adapter invocation boundary bridge contract', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate.php'], 'acceptance' => 'Gate requires evidence acceptance bridge id, provider start projection run, pre-start heartbeat, context hashes and keeps adapter execution disabled.'],
            ['id' => 'T3', 'title' => 'Add post-start adapter invocation boundary gate tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartAdapterInvocationBoundaryGateTest.php'], 'acceptance' => 'Tests prove boundary preparation, idempotency, duplicate attempt rejection, missing provider start/evidence bridge metadata rejection, forbidden adapter flag rejection and rollback.'],
            ['id' => 'T4', 'title' => 'Expose post-start adapter invocation boundary gate readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare adapter execution and dispatch disabled.'],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_adapter_invocation_boundary_gate_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-ADAPTER-INVOCATION-BOUNDARY-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker post-start adapter invocation boundary gate that bridges provider start driver metadata to the generic adapter invocation boundary while forbidding Codex invocation.',
            'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_enable_adapter_execution'],
            'allowed_files' => data_get($preflight, 'allowed_future_files', []),
            'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'process_start', 'provider_process_call', 'adapter_execution_runtime', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => ['post_start_adapter_invocation_boundary_gate_requires_provider_start_driver_metadata', 'post_start_adapter_invocation_boundary_gate_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_adapter_invocation_boundary_gate_requires_provider_start_projection_run', 'post_start_adapter_invocation_boundary_gate_delegates_to_generic_adapter_invocation_boundary', 'post_start_adapter_invocation_boundary_gate_does_not_call_codex'],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'adapter_invocation_boundary_bridge_allowed_by_service' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'adapter_invocation_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_adapter_invocation_boundary_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_adapter_invocation_boundary_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_adapter_invocation_boundary_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_adapter_invocation_boundary_gate_implementation_packet' => $packet,
            'codex_real_invoker_post_start_adapter_invocation_boundary_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_adapter_invocation_boundary_gate_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_adapter_invocation_boundary_gate_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_adapter_invocation_boundary_gate_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_adapter_invocation_boundary_gate_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start adapter invocation boundary gate implementation packet is ready; it prepares the boundary bridge but does not call Codex.',
        ];
    }


public function agentCodexRealInvokerPostStartAdapterExecutionGuardGateContractTemplate(array $options = []): array
    {
        $boundaryPayload = $this->section->agentCodexRealInvokerPostStartAdapterInvocationBoundaryGatePreflight($options);
        $boundary = (array) data_get($boundaryPayload, 'codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_adapter_execution_guard_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-ADAPTER-EXECUTION-GUARD-GATE-'.strtoupper(substr(ReadinessHash::stable([
                'adapter_invocation_boundary_preflight_hash' => data_get($boundaryPayload, 'codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_adapter_invocation_boundary_status' => data_get($boundaryPayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartAdapterExecutionGuardGate',
                'method' => 'blockPostStartAdapterExecution',
                'input_contract' => ['run_key', 'adapter_execution_guard_gate_id', 'execution_guard_id', 'adapter_invocation_boundary_gate_id', 'adapter_invocation_id', 'provider_start_driver_gate_id', 'provider_start_attempt_id', 'dispatch_executor_handoff_id', 'signed_dispatch_authorization_id', 'post_start_evidence_acceptance_bridge_id', 'signed_dispatch_receipt_hash', 'actor', 'session', 'reason'],
                'result_contract' => ['adapter_execution_guard_gate_id', 'execution_guard_id', 'adapter_invocation_id', 'post_start_evidence_acceptance_bridge_id', 'provider_adapter_execution_guard_result', 'adapter_execution_allowed', 'dispatch_allowed'],
            ],
            'real_invoker_post_start_adapter_execution_guard_gate_must' => [
                'require_codex_real_invoker_post_start_adapter_invocation_boundary_metadata',
                'require_post_start_evidence_acceptance_bridge_metadata',
                'require_provider_start_projection_run_with_adapter_invocation',
                'delegate_to_agent_provider_adapter_execution_guard',
                'record_bridge_metadata_on_observed_post_start_run',
                'require_provider_specific_execution_contract_before_any_later_execution',
            ],
            'real_invoker_post_start_adapter_execution_guard_gate_must_not' => [
                'call_codex_cli_or_codex_app',
                'spawn_process_or_shell_command',
                'spend_provider_tokens',
                'dispatch_work_to_provider',
                'enable_adapter_execution',
                'create_provider_specific_execution_contract',
                'mark_observed_run_running_or_terminal',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartAdapterExecutionGuardGate.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartAdapterExecutionGuardGateTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'adapter_execution_guard_bridge_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_codex_provider_execution_contract' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-adapter-execution-guard-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_adapter_execution_guard_gate_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_adapter_execution_guard_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_adapter_execution_guard_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_adapter_execution_guard_gate_contract_template' => $template,
            'codex_real_invoker_post_start_adapter_execution_guard_gate_contract_template_hash' => ReadinessHash::stable($template),
            'source_adapter_invocation_boundary_preflight' => $boundary,
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_adapter_execution_guard_gate_contract_template_does_not_start_codex', 'agent_codex_real_invoker_post_start_adapter_execution_guard_gate_contract_template_does_not_call_codex', 'agent_codex_real_invoker_post_start_adapter_execution_guard_gate_contract_template_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_adapter_execution_guard_gate_contract_template_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start adapter execution guard gate contract template bridges the adapter boundary to the provider adapter execution guard without calling Codex.',
        ];
    }


public function agentCodexRealInvokerPostStartAdapterExecutionGuardGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartAdapterExecutionGuardGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_adapter_execution_guard_gate_contract_template', []);
        $gateReady = class_exists(AgentCodexRealInvokerPostStartAdapterExecutionGuardGate::class);
        $boundaryGateReady = class_exists(AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate::class);
        $guardReady = class_exists(AgentProviderAdapterExecutionGuard::class);
        $registryReady = class_exists(AgentProviderAdapterRegistry::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $gateReady ? null : 'codex_real_invoker_post_start_adapter_execution_guard_gate_missing',
            $boundaryGateReady ? null : 'codex_real_invoker_post_start_adapter_invocation_boundary_gate_missing',
            $guardReady ? null : 'provider_adapter_execution_guard_missing',
            $registryReady ? null : 'provider_adapter_registry_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_adapter_execution_guard_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_adapter_execution_guard_gate_contract_template_hash'),
            'source_codex_real_invoker_post_start_adapter_invocation_boundary_status' => data_get($contract, 'source_codex_real_invoker_post_start_adapter_invocation_boundary_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_post_start_adapter_execution_guard_gate_ready' => $gateReady,
                'codex_real_invoker_post_start_adapter_invocation_boundary_gate_ready' => $boundaryGateReady,
                'provider_adapter_execution_guard_ready' => $guardReady,
                'provider_adapter_registry_ready' => $registryReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_adapter_execution_guard_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => ['create_codex_real_invoker_post_start_adapter_execution_guard_gate', 'require_post_start_adapter_invocation_boundary_metadata', 'require_post_start_evidence_acceptance_bridge_metadata', 'delegate_to_provider_adapter_execution_guard_without_calling_codex', 'record_adapter_execution_guard_bridge_on_observed_run'],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartAdapterExecutionGuardGate.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartAdapterExecutionGuardGateTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_adapter_execution_guard_gate',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'post_start_adapter_execution_guard_gate_file_creation_allowed_here' => false,
                'adapter_execution_guard_bridge_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_codex_provider_execution_contract' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-adapter-execution-guard-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_adapter_execution_guard_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_adapter_execution_guard_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_adapter_execution_guard_gate_preflight' => $preflight,
            'codex_real_invoker_post_start_adapter_execution_guard_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_adapter_execution_guard_gate_preflight_does_not_start_codex', 'agent_codex_real_invoker_post_start_adapter_execution_guard_gate_preflight_does_not_call_codex', 'agent_codex_real_invoker_post_start_adapter_execution_guard_gate_preflight_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_adapter_execution_guard_gate_preflight_does_not_dispatch_work'],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker post-start adapter execution guard gate is ready; it records the execution block but still does not call Codex.'
                : 'Codex real invoker post-start adapter execution guard gate is blocked until boundary, guard, registry and storage prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartAdapterExecutionGuardGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartAdapterExecutionGuardGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_adapter_execution_guard_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_adapter_execution_guard_gate_preflight_hash');

        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start adapter execution guard gate', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartAdapterExecutionGuardGate.php'], 'acceptance' => 'Gate consumes post-start adapter invocation boundary metadata and delegates to the generic provider adapter execution guard without calling Codex.'],
            ['id' => 'T2', 'title' => 'Enforce adapter execution guard bridge contract', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartAdapterExecutionGuardGate.php'], 'acceptance' => 'Gate requires evidence acceptance bridge id plus provider start projection adapter invocation metadata and keeps provider-specific execution blocked.'],
            ['id' => 'T3', 'title' => 'Add post-start adapter execution guard gate tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartAdapterExecutionGuardGateTest.php'], 'acceptance' => 'Tests prove blocking, idempotency, duplicate guard rejection, missing boundary/evidence bridge rejection, forbidden dispatch rejection and rollback.'],
            ['id' => 'T4', 'title' => 'Expose post-start adapter execution guard gate readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare adapter execution and dispatch disabled.'],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_adapter_execution_guard_gate_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-ADAPTER-EXECUTION-GUARD-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker post-start adapter execution guard gate that bridges adapter invocation boundary metadata to the generic provider adapter execution guard while forbidding Codex invocation.',
            'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_enable_adapter_execution', 'do_not_create_codex_provider_execution_contract'],
            'allowed_files' => data_get($preflight, 'allowed_future_files', []),
            'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'process_start', 'provider_process_call', 'adapter_execution_runtime', 'provider_specific_execution_contract', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => ['post_start_adapter_execution_guard_gate_requires_adapter_invocation_boundary_metadata', 'post_start_adapter_execution_guard_gate_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_adapter_execution_guard_gate_requires_provider_start_projection_run_with_adapter_invocation', 'post_start_adapter_execution_guard_gate_delegates_to_provider_adapter_execution_guard', 'post_start_adapter_execution_guard_gate_does_not_call_codex'],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'adapter_execution_guard_bridge_allowed_by_service' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'adapter_invocation_allowed_by_packet' => false, 'adapter_execution_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_adapter_execution_guard_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_adapter_execution_guard_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_adapter_execution_guard_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_adapter_execution_guard_gate_implementation_packet' => $packet,
            'codex_real_invoker_post_start_adapter_execution_guard_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_adapter_execution_guard_gate_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_adapter_execution_guard_gate_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_adapter_execution_guard_gate_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_adapter_execution_guard_gate_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start adapter execution guard gate implementation packet is ready; it records the guard bridge but does not call Codex.',
        ];
    }


public function agentCodexRealInvokerPostStartProviderExecutionContractGateContractTemplate(array $options = []): array
    {
        $guardPayload = $this->section->agentCodexRealInvokerPostStartAdapterExecutionGuardGatePreflight($options);
        $guard = (array) data_get($guardPayload, 'codex_real_invoker_post_start_adapter_execution_guard_gate_preflight', []);
        $codexPayload = $this->section->agentCodexProviderExecutionPreflight($options);

        $template = [
            'status' => 'codex_real_invoker_post_start_provider_execution_contract_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-PROVIDER-EXECUTION-CONTRACT-GATE-'.strtoupper(substr(ReadinessHash::stable([
                'adapter_execution_guard_preflight_hash' => data_get($guardPayload, 'codex_real_invoker_post_start_adapter_execution_guard_gate_preflight_hash'),
                'codex_provider_execution_preflight_hash' => data_get($codexPayload, 'codex_provider_execution_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_adapter_execution_guard_status' => data_get($guardPayload, 'status'),
            'source_codex_provider_execution_status' => data_get($codexPayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartProviderExecutionContractGate',
                'method' => 'preparePostStartProviderExecutionContract',
                'input_contract' => ['run_key', 'provider_execution_contract_gate_id', 'codex_execution_id', 'adapter_execution_guard_gate_id', 'execution_guard_id', 'adapter_invocation_boundary_gate_id', 'adapter_invocation_id', 'provider_start_driver_gate_id', 'provider_start_attempt_id', 'dispatch_executor_handoff_id', 'signed_dispatch_authorization_id', 'post_start_evidence_acceptance_bridge_id', 'signed_dispatch_receipt_hash', 'command', 'cwd', 'context_pack_hash', 'continuation_summary_hash', 'actor', 'session', 'max_runtime_minutes', 'max_cost_usd', 'reason'],
                'result_contract' => ['provider_execution_contract_gate_id', 'codex_execution_id', 'post_start_evidence_acceptance_bridge_id', 'codex_provider_execution_result', 'provider_specific_execution_contract_ready', 'actual_process_start_allowed', 'dispatch_allowed'],
            ],
            'real_invoker_post_start_provider_execution_contract_gate_must' => [
                'require_codex_real_invoker_post_start_adapter_execution_guard_metadata',
                'require_post_start_evidence_acceptance_bridge_from_adapter_execution_guard',
                'require_provider_adapter_execution_guard_result_blocking',
                'require_context_pack_hash_and_continuation_summary_hash_from_boundary',
                'delegate_to_codex_provider_execution_driver',
                'record_bridge_metadata_on_observed_post_start_run',
                'keep_process_start_release_as_separate_later_contract',
            ],
            'real_invoker_post_start_provider_execution_contract_gate_must_not' => [
                'call_codex_cli_or_codex_app',
                'spawn_process_or_shell_command',
                'spend_provider_tokens',
                'dispatch_work_to_provider',
                'enable_adapter_execution',
                'authorize_process_start',
                'mark_observed_run_running_or_terminal',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProviderExecutionContractGate.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartProviderExecutionContractGateTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'provider_execution_contract_bridge_allowed_by_service' => true,
                'codex_provider_execution_driver_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_codex_process_start_release_contract' => true,
            ],
            'source_adapter_execution_guard_preflight' => $guard,
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-provider-execution-contract-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_provider_execution_contract_gate_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_provider_execution_contract_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_provider_execution_contract_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_provider_execution_contract_gate_contract_template' => $template,
            'codex_real_invoker_post_start_provider_execution_contract_gate_contract_template_hash' => ReadinessHash::stable($template),
            'source_adapter_execution_guard_preflight' => $guard,
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_provider_execution_contract_gate_contract_template_does_not_start_codex', 'agent_codex_real_invoker_post_start_provider_execution_contract_gate_contract_template_does_not_call_codex', 'agent_codex_real_invoker_post_start_provider_execution_contract_gate_contract_template_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_provider_execution_contract_gate_contract_template_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start provider execution contract gate template bridges the post-start adapter execution guard to the Codex provider execution driver without starting Codex.',
        ];
    }


public function agentCodexRealInvokerPostStartProviderExecutionContractGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartProviderExecutionContractGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_provider_execution_contract_gate_contract_template', []);
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProviderExecutionContractGate::class);
        $guardGateReady = class_exists(AgentCodexRealInvokerPostStartAdapterExecutionGuardGate::class);
        $codexDriverReady = class_exists(AgentCodexProviderExecutionDriver::class);
        $registryReady = class_exists(AgentProviderAdapterRegistry::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $sandboxBindingTableReady = Schema::hasTable('atlas_self_construction_agent_sandbox_bindings');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $gateReady ? null : 'codex_real_invoker_post_start_provider_execution_contract_gate_missing',
            $guardGateReady ? null : 'codex_real_invoker_post_start_adapter_execution_guard_gate_missing',
            $codexDriverReady ? null : 'codex_provider_execution_driver_missing',
            $registryReady ? null : 'provider_adapter_registry_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $sandboxBindingTableReady ? null : 'agent_sandbox_bindings_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_provider_execution_contract_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_provider_execution_contract_gate_contract_template_hash'),
            'source_codex_real_invoker_post_start_adapter_execution_guard_status' => data_get($contract, 'source_codex_real_invoker_post_start_adapter_execution_guard_status'),
            'source_codex_provider_execution_status' => data_get($contract, 'source_codex_provider_execution_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_post_start_provider_execution_contract_gate_ready' => $gateReady,
                'codex_real_invoker_post_start_adapter_execution_guard_gate_ready' => $guardGateReady,
                'codex_provider_execution_driver_ready' => $codexDriverReady,
                'provider_adapter_registry_ready' => $registryReady,
                'agent_runs_table_ready' => $runsTableReady,
                'agent_sandbox_bindings_table_ready' => $sandboxBindingTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_provider_execution_contract_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => ['create_codex_real_invoker_post_start_provider_execution_contract_gate', 'require_post_start_adapter_execution_guard_metadata', 'require_post_start_evidence_acceptance_bridge_from_adapter_execution_guard', 'delegate_to_codex_provider_execution_driver_without_starting_codex', 'record_provider_execution_contract_bridge_on_observed_run'],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProviderExecutionContractGate.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartProviderExecutionContractGateTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_provider_execution_contract_gate',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'post_start_provider_execution_contract_gate_file_creation_allowed_here' => false,
                'provider_execution_contract_bridge_allowed_by_service' => true,
                'codex_provider_execution_driver_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_codex_process_start_release_contract' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-provider-execution-contract-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_provider_execution_contract_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_provider_execution_contract_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_provider_execution_contract_gate_preflight' => $preflight,
            'codex_real_invoker_post_start_provider_execution_contract_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_provider_execution_contract_gate_preflight_does_not_start_codex', 'agent_codex_real_invoker_post_start_provider_execution_contract_gate_preflight_does_not_call_codex', 'agent_codex_real_invoker_post_start_provider_execution_contract_gate_preflight_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_provider_execution_contract_gate_preflight_does_not_dispatch_work'],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker post-start provider execution contract gate is ready; it prepares the Codex-specific execution contract but still does not start Codex.'
                : 'Codex real invoker post-start provider execution contract gate is blocked until guard, Codex driver, sandbox binding and ledger prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartProviderExecutionContractGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartProviderExecutionContractGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_provider_execution_contract_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_provider_execution_contract_gate_preflight_hash');

        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start provider execution contract gate', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProviderExecutionContractGate.php'], 'acceptance' => 'Gate consumes post-start adapter execution guard metadata and delegates to the Codex provider execution driver without starting Codex.'],
            ['id' => 'T2', 'title' => 'Enforce provider execution contract bridge policy', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProviderExecutionContractGate.php'], 'acceptance' => 'Gate requires accepted post-start evidence bridge, blocking guard result, context hashes, active sandbox binding through the driver and keeps process start release separate.'],
            ['id' => 'T3', 'title' => 'Add post-start provider execution contract gate tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartProviderExecutionContractGateTest.php'], 'acceptance' => 'Tests prove contract preparation, idempotency, duplicate rejection, missing guard rejection, missing evidence bridge rejection, forbidden dispatch rejection, missing sandbox rejection and rollback.'],
            ['id' => 'T4', 'title' => 'Expose post-start provider execution contract gate readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare Codex start, token spend and dispatch disabled.'],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_provider_execution_contract_gate_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-PROVIDER-EXECUTION-CONTRACT-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker post-start provider execution contract gate that bridges the post-start adapter execution guard to the Codex provider execution driver while forbidding process start.',
            'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_enable_adapter_execution', 'do_not_authorize_process_start_release'],
            'allowed_files' => data_get($preflight, 'allowed_future_files', []),
            'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'process_start', 'provider_process_call', 'adapter_execution_runtime', 'process_start_release', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => ['post_start_provider_execution_contract_gate_requires_adapter_execution_guard_metadata', 'post_start_provider_execution_contract_gate_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_provider_execution_contract_gate_delegates_to_codex_provider_execution_driver', 'post_start_provider_execution_contract_gate_records_observed_bridge_metadata', 'post_start_provider_execution_contract_gate_does_not_start_codex'],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'provider_execution_contract_bridge_allowed_by_service' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'adapter_invocation_allowed_by_packet' => false, 'adapter_execution_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_provider_execution_contract_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_provider_execution_contract_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_provider_execution_contract_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_provider_execution_contract_gate_implementation_packet' => $packet,
            'codex_real_invoker_post_start_provider_execution_contract_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_provider_execution_contract_gate_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_provider_execution_contract_gate_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_provider_execution_contract_gate_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_provider_execution_contract_gate_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start provider execution contract gate implementation packet is ready; it prepares the Codex-specific contract bridge but does not start Codex.',
        ];
    }


}
