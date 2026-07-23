<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\Codex;

use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentDispatchProviderSection;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerManualStartExecutorReceiptWriter;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerOperatorStartHandoffBuilder;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartActualProcessStartRehearsalGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartOperatorStartHandoffBuilder;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartProcessStartEnvelopeGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartProcessStarterReadinessGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartStartExecutionGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerProcessStartEnvelopeBuilder;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerProcessStarterReadinessGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerStartExecutionGate;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;
use Illuminate\Support\Facades\Schema;

/**
 * AGENT CODEX projection sub-section 11 of 11, sub-split from the god
 * {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling agentCodex* pipeline calls route through the
 * injected Section facade (`$this->section->agentCodex*`), which re-dispatches
 * to whichever sub-section owns the target stage. The dispatch-provider
 * collaborator is injected verbatim for the one stage that consumes it.
 *
 * Stage range: agentCodexRealInvokerPostStartProcessStartEnvelopeGateContractTemplate
 *           .. agentCodexRealInvokerPostStartOperatorStartHandoffBuilderImplementationPacket
 */
final class CodexPart11SubSection
{
    public function __construct(
        private readonly ReadinessProjectionAgentCodexSection $section,
        private readonly ReadinessProjectionAgentDispatchProviderSection $agentDispatchProviderSection,
    ) {}

public function agentCodexRealInvokerPostStartProcessStartEnvelopeGateContractTemplate(array $options = []): array
    {
        $rehearsalPayload = $this->section->agentCodexRealInvokerPostStartActualProcessStartRehearsalGatePreflight($options);
        $rehearsal = (array) data_get($rehearsalPayload, 'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight', []);
        $envelopePayload = $this->section->agentCodexRealInvokerProcessStartEnvelopeBuilderPreflight($options);
        $envelope = (array) data_get($envelopePayload, 'codex_real_invoker_process_start_envelope_builder_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_process_start_envelope_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-PROCESS-START-ENVELOPE-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_actual_process_start_rehearsal_hash' => data_get($rehearsalPayload, 'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight_hash'),
                'process_start_envelope_builder_hash' => data_get($envelopePayload, 'codex_real_invoker_process_start_envelope_builder_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_status' => data_get($rehearsalPayload, 'status'),
            'source_codex_real_invoker_process_start_envelope_builder_status' => data_get($envelopePayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartProcessStartEnvelopeGate',
                'method' => 'buildPostStartProcessStartEnvelope',
                'input_contract' => ['run_key', 'post_start_process_start_envelope_gate_id', 'post_start_actual_process_start_rehearsal_gate_id', 'post_start_evidence_acceptance_bridge_id', 'provider_start_attempt_id', 'codex_execution_id', 'real_invoker_actual_process_start_rehearsal_id', 'real_invoker_process_start_envelope_id', 'process_start_envelope_hash', 'start_command_hash', 'start_environment_hash', 'start_cwd_hash', 'start_supervisor_hash', 'start_liveness_contract_hash', 'actor', 'session', 'reason'],
                'result_contract' => ['post_start_process_start_envelope_gate_id', 'post_start_evidence_acceptance_bridge_id', 'real_invoker_process_start_envelope_id', 'real_invoker_actual_process_start_rehearsal_id', 'start_envelope_ready', 'actual_process_start_allowed', 'dispatch_allowed'],
            ],
            'real_invoker_post_start_process_start_envelope_gate_must' => ['require_codex_real_invoker_post_start_actual_process_start_rehearsal_metadata', 'require_post_start_evidence_acceptance_bridge_from_actual_process_start_rehearsal', 'require_rehearsal_flags_blocking_process_token_dispatch', 'delegate_to_codex_real_invoker_process_start_envelope_builder', 'record_process_start_envelope_bridge_on_observed_post_start_run', 'require_start_execution_gate_as_separate_later_contract'],
            'real_invoker_post_start_process_start_envelope_gate_must_not' => ['call_codex_cli_or_codex_app', 'spawn_process_or_shell_command', 'spend_provider_tokens', 'start_codex_process', 'dispatch_work_to_codex', 'enable_adapter_execution', 'mark_actual_process_start_allowed', 'mark_observed_run_running_or_terminal'],
            'implementation_files_allowed_future' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProcessStartEnvelopeGate.php', 'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartProcessStartEnvelopeGateTest.php'],
            'contract_policy' => ['template_is_read_only' => true, 'post_start_process_start_envelope_bridge_allowed_by_service' => true, 'codex_real_invoker_process_start_envelope_allowed_by_service' => true, 'process_start_envelope_built_here' => true, 'start_envelope_ready_here' => true, 'actual_process_start_allowed_here' => false, 'provider_process_start_allowed_here' => false, 'adapter_invocation_allowed_here' => false, 'adapter_execution_allowed_here' => false, 'codex_invocation_allowed_here' => false, 'token_spend_allowed_here' => false, 'dispatch_allowed_here' => false, 'requires_separate_start_execution_gate_contract' => true],
            'source_post_start_actual_process_start_rehearsal_gate_preflight' => $rehearsal,
            'source_codex_real_invoker_process_start_envelope_builder_preflight' => $envelope,
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-process-start-envelope-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_process_start_envelope_gate_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_process_start_envelope_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_process_start_envelope_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_process_start_envelope_gate_contract_template' => $template,
            'codex_real_invoker_post_start_process_start_envelope_gate_contract_template_hash' => ReadinessHash::stable($template),
            'source_post_start_actual_process_start_rehearsal_gate_preflight' => $rehearsal,
            'source_codex_real_invoker_process_start_envelope_builder_preflight' => $envelope,
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_process_start_envelope_gate_contract_template_does_not_start_codex', 'agent_codex_real_invoker_post_start_process_start_envelope_gate_contract_template_does_not_call_codex', 'agent_codex_real_invoker_post_start_process_start_envelope_gate_contract_template_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_process_start_envelope_gate_contract_template_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start process start envelope gate template builds envelope metadata while actual start remains delegated to a later start execution contract.',
        ];
    }


public function agentCodexRealInvokerPostStartProcessStartEnvelopeGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartProcessStartEnvelopeGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_process_start_envelope_gate_contract_template', []);
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProcessStartEnvelopeGate::class);
        $postStartRehearsalReady = class_exists(AgentCodexRealInvokerPostStartActualProcessStartRehearsalGate::class);
        $envelopeReady = class_exists(AgentCodexRealInvokerProcessStartEnvelopeBuilder::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([$gateReady ? null : 'codex_real_invoker_post_start_process_start_envelope_gate_missing', $postStartRehearsalReady ? null : 'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_missing', $envelopeReady ? null : 'codex_real_invoker_process_start_envelope_builder_missing', $runsTableReady ? null : 'agent_runs_table_missing', $ledgerReady ? null : 'ledger_table_missing']));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_process_start_envelope_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_process_start_envelope_gate_contract_template_hash'),
            'source_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_status' => data_get($contract, 'source_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_status'),
            'source_codex_real_invoker_process_start_envelope_builder_status' => data_get($contract, 'source_codex_real_invoker_process_start_envelope_builder_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => ['codex_real_invoker_post_start_process_start_envelope_gate_ready' => $gateReady, 'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_ready' => $postStartRehearsalReady, 'codex_real_invoker_process_start_envelope_builder_ready' => $envelopeReady, 'agent_runs_table_ready' => $runsTableReady, 'ledger_table_ready' => $ledgerReady],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_process_start_envelope_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => ['create_codex_real_invoker_post_start_process_start_envelope_gate', 'require_post_start_actual_process_start_rehearsal_metadata', 'require_post_start_evidence_acceptance_bridge_from_actual_process_start_rehearsal', 'delegate_to_codex_real_invoker_process_start_envelope_builder_without_invoking_codex', 'record_start_envelope_bridge_on_observed_run'],
            'required_gates' => ['php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProcessStartEnvelopeGate.php', 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartProcessStartEnvelopeGateTest.php', 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_process_start_envelope_gate', 'php artisan atlas:engineering:knowledge docs-health --json', 'php artisan atlas:ai:architecture-validate --json', 'git diff --check'],
            'preflight_policy' => ['preflight_is_read_only' => true, 'post_start_process_start_envelope_gate_file_creation_allowed_here' => false, 'post_start_process_start_envelope_bridge_allowed_by_service' => true, 'codex_real_invoker_process_start_envelope_allowed_by_service' => true, 'process_start_envelope_built_here' => true, 'start_envelope_ready_here' => true, 'actual_process_start_allowed_here' => false, 'provider_process_start_allowed_here' => false, 'adapter_invocation_allowed_here' => false, 'adapter_execution_allowed_here' => false, 'codex_invocation_allowed_here' => false, 'token_spend_allowed_here' => false, 'dispatch_allowed_here' => false, 'requires_separate_start_execution_gate_contract' => true],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-process-start-envelope-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_process_start_envelope_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_process_start_envelope_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_process_start_envelope_gate_preflight' => $preflight,
            'codex_real_invoker_post_start_process_start_envelope_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_process_start_envelope_gate_preflight_does_not_start_codex', 'agent_codex_real_invoker_post_start_process_start_envelope_gate_preflight_does_not_call_codex', 'agent_codex_real_invoker_post_start_process_start_envelope_gate_preflight_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_process_start_envelope_gate_preflight_does_not_dispatch_work'],
            'human_summary' => $blockingReasons === [] ? 'Codex real invoker post-start process start envelope gate is ready; it builds envelope metadata while keeping actual start, token spend and dispatch blocked.' : 'Codex real invoker post-start process start envelope gate is blocked until rehearsal, envelope builder and ledger prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartProcessStartEnvelopeGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartProcessStartEnvelopeGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_process_start_envelope_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_process_start_envelope_gate_preflight_hash');
        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start process start envelope gate', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProcessStartEnvelopeGate.php'], 'acceptance' => 'Gate consumes post-start actual rehearsal metadata, including the accepted evidence bridge id, and delegates to Codex real invoker process start envelope builder without invoking Codex.'],
            ['id' => 'T2', 'title' => 'Enforce post-start envelope policy', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProcessStartEnvelopeGate.php'], 'acceptance' => 'Gate records start envelope metadata while keeping actual process start, token spend, adapter execution and dispatch flags false.'],
            ['id' => 'T3', 'title' => 'Add post-start process start envelope tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartProcessStartEnvelopeGateTest.php'], 'acceptance' => 'Tests prove envelope build, idempotency, duplicate rejection, missing rehearsal bridge rejection, missing evidence bridge rejection, forbidden process-start rejection, missing provider run rejection and rollback.'],
            ['id' => 'T4', 'title' => 'Expose post-start process start envelope readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare start execution gate still separate.'],
        ];
        $packet = ['status' => 'ready_for_scoped_codex_real_invoker_post_start_process_start_envelope_gate_implementation', 'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-PROCESS-START-ENVELOPE-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001', 'source_preflight_hash' => $preflightHash, 'objective' => 'Implement the Codex real invoker post-start process start envelope gate that bridges actual-start rehearsal to process start envelope while forbidding actual process start and dispatch.', 'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_enable_adapter_execution', 'do_not_run_real_external_process_invoker', 'do_not_run_start_execution_gate'], 'allowed_files' => data_get($preflight, 'allowed_future_files', []), 'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'real_external_process_invoker_execution', 'provider_process_call', 'adapter_execution_runtime', 'actual_process_start_runtime', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'], 'tasks' => $tasks, 'task_count' => count($tasks), 'acceptance_criteria' => ['post_start_process_start_envelope_gate_requires_actual_rehearsal_bridge_metadata', 'post_start_process_start_envelope_gate_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_process_start_envelope_gate_delegates_to_codex_real_invoker_process_start_envelope_builder', 'post_start_process_start_envelope_gate_records_observed_bridge_metadata', 'post_start_process_start_envelope_gate_does_not_start_codex_or_dispatch_work'], 'required_gates' => data_get($preflight, 'required_gates', []), 'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'post_start_process_start_envelope_bridge_allowed_by_service' => true, 'process_start_envelope_built_by_packet' => true, 'start_envelope_ready_by_packet' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'adapter_invocation_allowed_by_packet' => false, 'adapter_execution_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false, 'requires_separate_start_execution_gate_contract' => true]];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_process_start_envelope_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_process_start_envelope_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_process_start_envelope_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_process_start_envelope_gate_implementation_packet' => $packet,
            'codex_real_invoker_post_start_process_start_envelope_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_process_start_envelope_gate_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_process_start_envelope_gate_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_process_start_envelope_gate_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_process_start_envelope_gate_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start process start envelope gate implementation packet is ready; it builds envelope metadata but does not start Codex or dispatch work.',
        ];
    }


public function agentCodexRealInvokerPostStartStartExecutionGateContractTemplate(array $options = []): array
    {
        $envelopePayload = $this->section->agentCodexRealInvokerPostStartProcessStartEnvelopeGatePreflight($options);
        $envelope = (array) data_get($envelopePayload, 'codex_real_invoker_post_start_process_start_envelope_gate_preflight', []);
        $startGatePayload = $this->section->agentCodexRealInvokerStartExecutionGatePreflight($options);
        $startGate = (array) data_get($startGatePayload, 'codex_real_invoker_start_execution_gate_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_start_execution_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-START-EXECUTION-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_process_start_envelope_hash' => data_get($envelopePayload, 'codex_real_invoker_post_start_process_start_envelope_gate_preflight_hash'),
                'start_execution_gate_hash' => data_get($startGatePayload, 'codex_real_invoker_start_execution_gate_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_process_start_envelope_gate_status' => data_get($envelopePayload, 'status'),
            'source_codex_real_invoker_start_execution_gate_status' => data_get($startGatePayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartStartExecutionGate',
                'method' => 'authorizePostStartStartExecution',
                'input_contract' => ['run_key', 'post_start_start_execution_gate_id', 'post_start_process_start_envelope_gate_id', 'post_start_evidence_acceptance_bridge_id', 'provider_start_attempt_id', 'codex_execution_id', 'real_invoker_process_start_envelope_id', 'real_invoker_start_execution_gate_id', 'operator_execution_gate_receipt_hash', 'execution_gate_policy_hash', 'execution_window_hash', 'preflight_snapshot_hash', 'rollback_readiness_hash', 'human_start_signature_hash', 'actor', 'session', 'reason'],
                'result_contract' => ['post_start_start_execution_gate_id', 'post_start_evidence_acceptance_bridge_id', 'real_invoker_start_execution_gate_id', 'real_invoker_process_start_envelope_id', 'start_execution_authorized', 'actual_process_start_allowed', 'dispatch_allowed'],
            ],
            'real_invoker_post_start_start_execution_gate_must' => ['require_codex_real_invoker_post_start_process_start_envelope_metadata', 'require_post_start_evidence_acceptance_bridge_from_process_start_envelope', 'require_start_envelope_flags_blocking_process_token_dispatch', 'delegate_to_codex_real_invoker_start_execution_gate', 'record_start_execution_bridge_on_observed_post_start_run', 'require_process_starter_readiness_as_separate_later_contract'],
            'real_invoker_post_start_start_execution_gate_must_not' => ['call_codex_cli_or_codex_app', 'spawn_process_or_shell_command', 'spend_provider_tokens', 'start_codex_process', 'dispatch_work_to_codex', 'enable_adapter_execution', 'mark_actual_process_start_allowed', 'mark_observed_run_running_or_terminal'],
            'implementation_files_allowed_future' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartStartExecutionGate.php', 'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartStartExecutionGateTest.php'],
            'contract_policy' => ['template_is_read_only' => true, 'post_start_start_execution_bridge_allowed_by_service' => true, 'codex_real_invoker_start_execution_allowed_by_service' => true, 'start_execution_authorized_here' => true, 'actual_process_start_allowed_here' => false, 'provider_process_start_allowed_here' => false, 'adapter_invocation_allowed_here' => false, 'adapter_execution_allowed_here' => false, 'codex_invocation_allowed_here' => false, 'token_spend_allowed_here' => false, 'dispatch_allowed_here' => false, 'requires_separate_process_starter_readiness_contract' => true],
            'source_post_start_process_start_envelope_gate_preflight' => $envelope,
            'source_codex_real_invoker_start_execution_gate_preflight' => $startGate,
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-start-execution-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_start_execution_gate_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_start_execution_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_start_execution_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_start_execution_gate_contract_template' => $template,
            'codex_real_invoker_post_start_start_execution_gate_contract_template_hash' => ReadinessHash::stable($template),
            'source_post_start_process_start_envelope_gate_preflight' => $envelope,
            'source_codex_real_invoker_start_execution_gate_preflight' => $startGate,
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_start_execution_gate_contract_template_does_not_start_codex', 'agent_codex_real_invoker_post_start_start_execution_gate_contract_template_does_not_call_codex', 'agent_codex_real_invoker_post_start_start_execution_gate_contract_template_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_start_execution_gate_contract_template_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start start execution gate template authorizes the next guarded layer while actual process start remains disabled.',
        ];
    }


public function agentCodexRealInvokerPostStartStartExecutionGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartStartExecutionGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_start_execution_gate_contract_template', []);
        $gateReady = class_exists(AgentCodexRealInvokerPostStartStartExecutionGate::class);
        $postStartEnvelopeReady = class_exists(AgentCodexRealInvokerPostStartProcessStartEnvelopeGate::class);
        $startGateReady = class_exists(AgentCodexRealInvokerStartExecutionGate::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([$gateReady ? null : 'codex_real_invoker_post_start_start_execution_gate_missing', $postStartEnvelopeReady ? null : 'codex_real_invoker_post_start_process_start_envelope_gate_missing', $startGateReady ? null : 'codex_real_invoker_start_execution_gate_missing', $runsTableReady ? null : 'agent_runs_table_missing', $ledgerReady ? null : 'ledger_table_missing']));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_start_execution_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_start_execution_gate_contract_template_hash'),
            'source_codex_real_invoker_post_start_process_start_envelope_gate_status' => data_get($contract, 'source_codex_real_invoker_post_start_process_start_envelope_gate_status'),
            'source_codex_real_invoker_start_execution_gate_status' => data_get($contract, 'source_codex_real_invoker_start_execution_gate_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => ['codex_real_invoker_post_start_start_execution_gate_ready' => $gateReady, 'codex_real_invoker_post_start_process_start_envelope_gate_ready' => $postStartEnvelopeReady, 'codex_real_invoker_start_execution_gate_ready' => $startGateReady, 'agent_runs_table_ready' => $runsTableReady, 'ledger_table_ready' => $ledgerReady],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_start_execution_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => ['create_codex_real_invoker_post_start_start_execution_gate', 'require_post_start_process_start_envelope_metadata', 'require_post_start_evidence_acceptance_bridge_from_process_start_envelope', 'delegate_to_codex_real_invoker_start_execution_gate_without_invoking_codex', 'record_start_execution_bridge_on_observed_run'],
            'required_gates' => ['php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartStartExecutionGate.php', 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartStartExecutionGateTest.php', 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_start_execution_gate', 'php artisan atlas:engineering:knowledge docs-health --json', 'php artisan atlas:ai:architecture-validate --json', 'git diff --check'],
            'preflight_policy' => ['preflight_is_read_only' => true, 'post_start_start_execution_gate_file_creation_allowed_here' => false, 'post_start_start_execution_bridge_allowed_by_service' => true, 'codex_real_invoker_start_execution_allowed_by_service' => true, 'start_execution_authorized_here' => true, 'actual_process_start_allowed_here' => false, 'provider_process_start_allowed_here' => false, 'adapter_invocation_allowed_here' => false, 'adapter_execution_allowed_here' => false, 'codex_invocation_allowed_here' => false, 'token_spend_allowed_here' => false, 'dispatch_allowed_here' => false, 'requires_separate_process_starter_readiness_contract' => true],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-start-execution-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_start_execution_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_start_execution_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_start_execution_gate_preflight' => $preflight,
            'codex_real_invoker_post_start_start_execution_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_start_execution_gate_preflight_does_not_start_codex', 'agent_codex_real_invoker_post_start_start_execution_gate_preflight_does_not_call_codex', 'agent_codex_real_invoker_post_start_start_execution_gate_preflight_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_start_execution_gate_preflight_does_not_dispatch_work'],
            'human_summary' => $blockingReasons === [] ? 'Codex real invoker post-start start execution gate is ready; it authorizes the next guarded layer while keeping actual start, token spend and dispatch blocked.' : 'Codex real invoker post-start start execution gate is blocked until envelope, start gate and ledger prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartStartExecutionGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartStartExecutionGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_start_execution_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_start_execution_gate_preflight_hash');
        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start start execution gate', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartStartExecutionGate.php'], 'acceptance' => 'Gate consumes post-start process start envelope metadata, including the accepted evidence bridge id, and delegates to Codex real invoker start execution gate without invoking Codex.'],
            ['id' => 'T2', 'title' => 'Enforce post-start start execution policy', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartStartExecutionGate.php'], 'acceptance' => 'Gate records start execution metadata while keeping actual process start, token spend, adapter execution and dispatch flags false.'],
            ['id' => 'T3', 'title' => 'Add post-start start execution tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartStartExecutionGateTest.php'], 'acceptance' => 'Tests prove start execution authorization, idempotency, missing envelope bridge rejection, missing evidence bridge rejection, forbidden process-start rejection, missing provider run rejection and rollback.'],
            ['id' => 'T4', 'title' => 'Expose post-start start execution readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare process starter readiness still separate.'],
        ];
        $packet = ['status' => 'ready_for_scoped_codex_real_invoker_post_start_start_execution_gate_implementation', 'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-START-EXECUTION-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001', 'source_preflight_hash' => $preflightHash, 'objective' => 'Implement the Codex real invoker post-start start execution gate that bridges process start envelope to start execution authorization while forbidding actual process start and dispatch.', 'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_enable_adapter_execution', 'do_not_run_real_external_process_invoker', 'do_not_run_process_starter_readiness_gate'], 'allowed_files' => data_get($preflight, 'allowed_future_files', []), 'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'real_external_process_invoker_execution', 'provider_process_call', 'adapter_execution_runtime', 'actual_process_start_runtime', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'], 'tasks' => $tasks, 'task_count' => count($tasks), 'acceptance_criteria' => ['post_start_start_execution_gate_requires_process_start_envelope_bridge_metadata', 'post_start_start_execution_gate_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_start_execution_gate_delegates_to_codex_real_invoker_start_execution_gate', 'post_start_start_execution_gate_records_observed_bridge_metadata', 'post_start_start_execution_gate_does_not_start_codex_or_dispatch_work'], 'required_gates' => data_get($preflight, 'required_gates', []), 'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'post_start_start_execution_bridge_allowed_by_service' => true, 'start_execution_authorized_by_packet' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'adapter_invocation_allowed_by_packet' => false, 'adapter_execution_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false, 'requires_separate_process_starter_readiness_contract' => true]];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_start_execution_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_start_execution_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_start_execution_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_start_execution_gate_implementation_packet' => $packet,
            'codex_real_invoker_post_start_start_execution_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_start_execution_gate_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_start_execution_gate_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_start_execution_gate_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_start_execution_gate_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start start execution gate implementation packet is ready; it authorizes the next guarded layer but does not start Codex or dispatch work.',
        ];
    }


public function agentCodexRealInvokerPostStartProcessStarterReadinessGateContractTemplate(array $options = []): array
    {
        $postStartStartExecutionPayload = $this->section->agentCodexRealInvokerPostStartStartExecutionGatePreflight($options);
        $postStartStartExecution = (array) data_get($postStartStartExecutionPayload, 'codex_real_invoker_post_start_start_execution_gate_preflight', []);
        $processStarterPayload = $this->section->agentCodexRealInvokerProcessStarterReadinessGatePreflight($options);
        $processStarter = (array) data_get($processStarterPayload, 'codex_real_invoker_process_starter_readiness_gate_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_process_starter_readiness_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-PROCESS-STARTER-READINESS-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_start_execution_hash' => data_get($postStartStartExecutionPayload, 'codex_real_invoker_post_start_start_execution_gate_preflight_hash'),
                'process_starter_readiness_hash' => data_get($processStarterPayload, 'codex_real_invoker_process_starter_readiness_gate_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_start_execution_gate_status' => data_get($postStartStartExecutionPayload, 'status'),
            'source_codex_real_invoker_process_starter_readiness_gate_status' => data_get($processStarterPayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartProcessStarterReadinessGate',
                'method' => 'preparePostStartProcessStarterReadiness',
                'input_contract' => ['run_key', 'post_start_process_starter_readiness_gate_id', 'real_invoker_process_starter_readiness_gate_id', 'post_start_start_execution_gate_id', 'post_start_evidence_acceptance_bridge_id', 'provider_start_attempt_id', 'codex_execution_id', 'real_invoker_start_execution_gate_id', 'process_starter_manifest_hash', 'supervisor_binding_hash', 'liveness_monitor_binding_hash', 'cancellation_contract_hash', 'output_capture_contract_hash', 'cost_meter_contract_hash', 'start_replay_guard_hash', 'operator_process_starter_signature_hash', 'actor', 'session', 'reason'],
                'result_contract' => ['post_start_process_starter_readiness_gate_id', 'post_start_evidence_acceptance_bridge_id', 'real_invoker_process_starter_readiness_gate_id', 'real_invoker_start_execution_gate_id', 'process_starter_ready', 'actual_process_start_allowed', 'dispatch_allowed'],
            ],
            'real_invoker_post_start_process_starter_readiness_gate_must' => ['require_codex_real_invoker_post_start_start_execution_metadata', 'require_post_start_evidence_acceptance_bridge_from_start_execution', 'require_start_execution_flags_blocking_process_token_dispatch', 'delegate_to_codex_real_invoker_process_starter_readiness_gate', 'record_process_starter_readiness_bridge_on_observed_post_start_run', 'require_manual_start_executor_as_separate_later_contract'],
            'real_invoker_post_start_process_starter_readiness_gate_must_not' => ['call_codex_cli_or_codex_app', 'spawn_process_or_shell_command', 'spend_provider_tokens', 'start_codex_process', 'dispatch_work_to_codex', 'enable_adapter_execution', 'mark_actual_process_start_allowed', 'mark_observed_run_running_or_terminal'],
            'implementation_files_allowed_future' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProcessStarterReadinessGate.php', 'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartProcessStarterReadinessGateTest.php'],
            'contract_policy' => ['template_is_read_only' => true, 'post_start_process_starter_readiness_bridge_allowed_by_service' => true, 'codex_real_invoker_process_starter_readiness_allowed_by_service' => true, 'process_starter_ready_here' => true, 'actual_process_start_allowed_here' => false, 'provider_process_start_allowed_here' => false, 'adapter_invocation_allowed_here' => false, 'adapter_execution_allowed_here' => false, 'codex_invocation_allowed_here' => false, 'token_spend_allowed_here' => false, 'dispatch_allowed_here' => false, 'requires_separate_manual_start_executor_contract' => true],
            'source_post_start_start_execution_gate_preflight' => $postStartStartExecution,
            'source_codex_real_invoker_process_starter_readiness_gate_preflight' => $processStarter,
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-process-starter-readiness-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_process_starter_readiness_gate_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_process_starter_readiness_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_process_starter_readiness_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_process_starter_readiness_gate_contract_template' => $template,
            'codex_real_invoker_post_start_process_starter_readiness_gate_contract_template_hash' => ReadinessHash::stable($template),
            'source_post_start_start_execution_gate_preflight' => $postStartStartExecution,
            'source_codex_real_invoker_process_starter_readiness_gate_preflight' => $processStarter,
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_process_starter_readiness_gate_contract_template_does_not_start_codex', 'agent_codex_real_invoker_post_start_process_starter_readiness_gate_contract_template_does_not_call_codex', 'agent_codex_real_invoker_post_start_process_starter_readiness_gate_contract_template_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_process_starter_readiness_gate_contract_template_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start process starter readiness gate template prepares the manual starter boundary while actual process start remains disabled.',
        ];
    }


public function agentCodexRealInvokerPostStartProcessStarterReadinessGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartProcessStarterReadinessGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_process_starter_readiness_gate_contract_template', []);
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProcessStarterReadinessGate::class);
        $postStartStartExecutionReady = class_exists(AgentCodexRealInvokerPostStartStartExecutionGate::class);
        $processStarterReady = class_exists(AgentCodexRealInvokerProcessStarterReadinessGate::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([$gateReady ? null : 'codex_real_invoker_post_start_process_starter_readiness_gate_missing', $postStartStartExecutionReady ? null : 'codex_real_invoker_post_start_start_execution_gate_missing', $processStarterReady ? null : 'codex_real_invoker_process_starter_readiness_gate_missing', $runsTableReady ? null : 'agent_runs_table_missing', $ledgerReady ? null : 'ledger_table_missing']));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_process_starter_readiness_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_process_starter_readiness_gate_contract_template_hash'),
            'source_codex_real_invoker_post_start_start_execution_gate_status' => data_get($contract, 'source_codex_real_invoker_post_start_start_execution_gate_status'),
            'source_codex_real_invoker_process_starter_readiness_gate_status' => data_get($contract, 'source_codex_real_invoker_process_starter_readiness_gate_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => ['codex_real_invoker_post_start_process_starter_readiness_gate_ready' => $gateReady, 'codex_real_invoker_post_start_start_execution_gate_ready' => $postStartStartExecutionReady, 'codex_real_invoker_process_starter_readiness_gate_ready' => $processStarterReady, 'agent_runs_table_ready' => $runsTableReady, 'ledger_table_ready' => $ledgerReady],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_process_starter_readiness_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => ['create_codex_real_invoker_post_start_process_starter_readiness_gate', 'require_post_start_start_execution_metadata', 'require_post_start_evidence_acceptance_bridge_from_start_execution', 'delegate_to_codex_real_invoker_process_starter_readiness_gate_without_invoking_codex', 'record_process_starter_readiness_bridge_on_observed_run'],
            'required_gates' => ['php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProcessStarterReadinessGate.php', 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartProcessStarterReadinessGateTest.php', 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_process_starter_readiness_gate', 'php artisan atlas:engineering:knowledge docs-health --json', 'php artisan atlas:ai:architecture-validate --json', 'git diff --check'],
            'preflight_policy' => ['preflight_is_read_only' => true, 'post_start_process_starter_readiness_gate_file_creation_allowed_here' => false, 'post_start_process_starter_readiness_bridge_allowed_by_service' => true, 'codex_real_invoker_process_starter_readiness_allowed_by_service' => true, 'process_starter_ready_here' => true, 'actual_process_start_allowed_here' => false, 'provider_process_start_allowed_here' => false, 'adapter_invocation_allowed_here' => false, 'adapter_execution_allowed_here' => false, 'codex_invocation_allowed_here' => false, 'token_spend_allowed_here' => false, 'dispatch_allowed_here' => false, 'requires_separate_manual_start_executor_contract' => true],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-process-starter-readiness-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_process_starter_readiness_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_process_starter_readiness_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_process_starter_readiness_gate_preflight' => $preflight,
            'codex_real_invoker_post_start_process_starter_readiness_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_process_starter_readiness_gate_preflight_does_not_start_codex', 'agent_codex_real_invoker_post_start_process_starter_readiness_gate_preflight_does_not_call_codex', 'agent_codex_real_invoker_post_start_process_starter_readiness_gate_preflight_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_process_starter_readiness_gate_preflight_does_not_dispatch_work'],
            'human_summary' => $blockingReasons === [] ? 'Codex real invoker post-start process starter readiness gate is ready; it prepares the manual starter boundary while keeping actual start, token spend and dispatch blocked.' : 'Codex real invoker post-start process starter readiness gate is blocked until start execution, process starter and ledger prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartProcessStarterReadinessGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartProcessStarterReadinessGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_process_starter_readiness_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_process_starter_readiness_gate_preflight_hash');
        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start process starter readiness gate', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProcessStarterReadinessGate.php'], 'acceptance' => 'Gate consumes post-start start execution metadata, including the accepted evidence bridge id, and delegates to Codex real invoker process starter readiness gate without invoking Codex.'],
            ['id' => 'T2', 'title' => 'Enforce post-start process starter readiness policy', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProcessStarterReadinessGate.php'], 'acceptance' => 'Gate records process starter readiness metadata while keeping actual process start, token spend, adapter execution and dispatch flags false.'],
            ['id' => 'T3', 'title' => 'Add post-start process starter readiness tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartProcessStarterReadinessGateTest.php'], 'acceptance' => 'Tests prove readiness preparation, idempotency, missing start execution bridge rejection, missing evidence bridge rejection, forbidden process-start rejection, missing provider run rejection and rollback.'],
            ['id' => 'T4', 'title' => 'Expose post-start process starter readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare manual start executor still separate.'],
        ];
        $packet = ['status' => 'ready_for_scoped_codex_real_invoker_post_start_process_starter_readiness_gate_implementation', 'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-PROCESS-STARTER-READINESS-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001', 'source_preflight_hash' => $preflightHash, 'objective' => 'Implement the Codex real invoker post-start process starter readiness gate that bridges start execution authorization to process starter readiness while forbidding actual process start and dispatch.', 'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_enable_adapter_execution', 'do_not_run_real_external_process_invoker', 'do_not_run_manual_start_executor'], 'allowed_files' => data_get($preflight, 'allowed_future_files', []), 'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'real_external_process_invoker_execution', 'provider_process_call', 'adapter_execution_runtime', 'actual_process_start_runtime', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'], 'tasks' => $tasks, 'task_count' => count($tasks), 'acceptance_criteria' => ['post_start_process_starter_readiness_gate_requires_start_execution_bridge_metadata', 'post_start_process_starter_readiness_gate_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_process_starter_readiness_gate_delegates_to_codex_real_invoker_process_starter_readiness_gate', 'post_start_process_starter_readiness_gate_records_observed_bridge_metadata', 'post_start_process_starter_readiness_gate_does_not_start_codex_or_dispatch_work'], 'required_gates' => data_get($preflight, 'required_gates', []), 'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'post_start_process_starter_readiness_bridge_allowed_by_service' => true, 'process_starter_ready_by_packet' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'adapter_invocation_allowed_by_packet' => false, 'adapter_execution_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false, 'requires_separate_manual_start_executor_contract' => true]];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_process_starter_readiness_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_process_starter_readiness_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_process_starter_readiness_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_process_starter_readiness_gate_implementation_packet' => $packet,
            'codex_real_invoker_post_start_process_starter_readiness_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_process_starter_readiness_gate_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_process_starter_readiness_gate_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_process_starter_readiness_gate_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_process_starter_readiness_gate_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start process starter readiness gate implementation packet is ready; it prepares the manual starter boundary but does not start Codex or dispatch work.',
        ];
    }


public function agentCodexRealInvokerPostStartManualStartExecutorReceiptWriterContractTemplate(array $options = []): array
    {
        $postStartReadinessPayload = $this->section->agentCodexRealInvokerPostStartProcessStarterReadinessGatePreflight($options);
        $postStartReadiness = (array) data_get($postStartReadinessPayload, 'codex_real_invoker_post_start_process_starter_readiness_gate_preflight', []);
        $manualReceiptPayload = $this->section->agentCodexRealInvokerManualStartExecutorReceiptWriterPreflight($options);
        $manualReceipt = (array) data_get($manualReceiptPayload, 'codex_real_invoker_manual_start_executor_receipt_writer_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_manual_start_executor_receipt_writer_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-MANUAL-START-RECEIPT-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_process_starter_readiness_hash' => data_get($postStartReadinessPayload, 'codex_real_invoker_post_start_process_starter_readiness_gate_preflight_hash'),
                'manual_start_executor_receipt_hash' => data_get($manualReceiptPayload, 'codex_real_invoker_manual_start_executor_receipt_writer_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_process_starter_readiness_gate_status' => data_get($postStartReadinessPayload, 'status'),
            'source_codex_real_invoker_manual_start_executor_receipt_writer_status' => data_get($manualReceiptPayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter',
                'method' => 'writePostStartManualStartExecutorReceipt',
                'input_contract' => ['run_key', 'post_start_manual_start_executor_receipt_id', 'manual_start_executor_receipt_id', 'post_start_process_starter_readiness_gate_id', 'provider_start_attempt_id', 'codex_execution_id', 'real_invoker_process_starter_readiness_gate_id', 'post_start_evidence_acceptance_bridge_id', 'manual_start_command_hash', 'terminal_session_binding_hash', 'operator_presence_hash', 'live_supervisor_ack_hash', 'initial_liveness_probe_hash', 'kill_switch_ack_hash', 'output_stream_capture_hash', 'cost_meter_initial_hash', 'no_autostart_attestation_hash', 'actor', 'session', 'reason'],
                'result_contract' => ['post_start_manual_start_executor_receipt_id', 'manual_start_executor_receipt_id', 'real_invoker_process_starter_readiness_gate_id', 'post_start_evidence_acceptance_bridge_id', 'manual_start_executor_receipt_written', 'manual_operator_start_required', 'actual_process_start_allowed', 'dispatch_allowed'],
            ],
            'real_invoker_post_start_manual_start_executor_receipt_must' => ['require_codex_real_invoker_post_start_process_starter_readiness_metadata', 'require_post_start_evidence_acceptance_bridge_from_process_starter_readiness', 'require_process_starter_ready_flags_blocking_process_token_dispatch', 'delegate_to_codex_real_invoker_manual_start_executor_receipt_writer', 'record_manual_start_receipt_bridge_on_observed_post_start_run', 'require_operator_handoff_as_separate_later_contract'],
            'real_invoker_post_start_manual_start_executor_receipt_must_not' => ['call_codex_cli_or_codex_app', 'spawn_process_or_shell_command', 'spend_provider_tokens', 'start_codex_process', 'dispatch_work_to_codex', 'enable_adapter_execution', 'mark_actual_process_start_allowed', 'mark_observed_run_running_or_terminal'],
            'implementation_files_allowed_future' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter.php', 'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartManualStartExecutorReceiptWriterTest.php'],
            'contract_policy' => ['template_is_read_only' => true, 'post_start_manual_start_executor_receipt_bridge_allowed_by_service' => true, 'codex_real_invoker_manual_start_executor_receipt_allowed_by_service' => true, 'manual_start_executor_receipt_written_here' => true, 'manual_operator_start_required_here' => true, 'actual_process_start_allowed_here' => false, 'provider_process_start_allowed_here' => false, 'adapter_invocation_allowed_here' => false, 'adapter_execution_allowed_here' => false, 'codex_invocation_allowed_here' => false, 'token_spend_allowed_here' => false, 'dispatch_allowed_here' => false, 'requires_separate_operator_handoff_contract' => true],
            'source_post_start_process_starter_readiness_gate_preflight' => $postStartReadiness,
            'source_codex_real_invoker_manual_start_executor_receipt_writer_preflight' => $manualReceipt,
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-manual-start-executor-receipt-writer-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_manual_start_executor_receipt_writer_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_manual_start_executor_receipt_writer_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_manual_start_executor_receipt_writer_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_manual_start_executor_receipt_writer_contract_template' => $template,
            'codex_real_invoker_post_start_manual_start_executor_receipt_writer_contract_template_hash' => ReadinessHash::stable($template),
            'source_post_start_process_starter_readiness_gate_preflight' => $postStartReadiness,
            'source_codex_real_invoker_manual_start_executor_receipt_writer_preflight' => $manualReceipt,
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_manual_start_executor_receipt_writer_contract_template_does_not_start_codex', 'agent_codex_real_invoker_post_start_manual_start_executor_receipt_writer_contract_template_does_not_call_codex', 'agent_codex_real_invoker_post_start_manual_start_executor_receipt_writer_contract_template_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_manual_start_executor_receipt_writer_contract_template_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start manual start executor receipt writer template records the manual receipt bridge while actual process start remains disabled.',
        ];
    }


public function agentCodexRealInvokerPostStartManualStartExecutorReceiptWriterPreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartManualStartExecutorReceiptWriterContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_manual_start_executor_receipt_writer_contract_template', []);
        $writerReady = class_exists(AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter::class);
        $postStartReadinessReady = class_exists(AgentCodexRealInvokerPostStartProcessStarterReadinessGate::class);
        $manualReceiptReady = class_exists(AgentCodexRealInvokerManualStartExecutorReceiptWriter::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([$writerReady ? null : 'codex_real_invoker_post_start_manual_start_executor_receipt_writer_missing', $postStartReadinessReady ? null : 'codex_real_invoker_post_start_process_starter_readiness_gate_missing', $manualReceiptReady ? null : 'codex_real_invoker_manual_start_executor_receipt_writer_missing', $runsTableReady ? null : 'agent_runs_table_missing', $ledgerReady ? null : 'ledger_table_missing']));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_manual_start_executor_receipt_writer_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_manual_start_executor_receipt_writer_contract_template_hash'),
            'source_codex_real_invoker_post_start_process_starter_readiness_gate_status' => data_get($contract, 'source_codex_real_invoker_post_start_process_starter_readiness_gate_status'),
            'source_codex_real_invoker_manual_start_executor_receipt_writer_status' => data_get($contract, 'source_codex_real_invoker_manual_start_executor_receipt_writer_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => ['codex_real_invoker_post_start_manual_start_executor_receipt_writer_ready' => $writerReady, 'codex_real_invoker_post_start_process_starter_readiness_gate_ready' => $postStartReadinessReady, 'codex_real_invoker_manual_start_executor_receipt_writer_ready' => $manualReceiptReady, 'agent_runs_table_ready' => $runsTableReady, 'ledger_table_ready' => $ledgerReady],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_manual_start_executor_receipt_writer_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => ['create_codex_real_invoker_post_start_manual_start_executor_receipt_writer', 'require_post_start_process_starter_readiness_metadata', 'require_post_start_evidence_acceptance_bridge_from_process_starter_readiness', 'delegate_to_codex_real_invoker_manual_start_executor_receipt_writer_without_starting_codex', 'record_manual_start_receipt_bridge_on_observed_run'],
            'required_gates' => ['php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter.php', 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartManualStartExecutorReceiptWriterTest.php', 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_manual_start_executor_receipt_writer', 'php artisan atlas:engineering:knowledge docs-health --json', 'php artisan atlas:ai:architecture-validate --json', 'git diff --check'],
            'preflight_policy' => ['preflight_is_read_only' => true, 'post_start_manual_start_executor_receipt_writer_file_creation_allowed_here' => false, 'post_start_manual_start_executor_receipt_bridge_allowed_by_service' => true, 'codex_real_invoker_manual_start_executor_receipt_allowed_by_service' => true, 'manual_start_executor_receipt_written_here' => true, 'actual_process_start_allowed_here' => false, 'provider_process_start_allowed_here' => false, 'adapter_invocation_allowed_here' => false, 'adapter_execution_allowed_here' => false, 'codex_invocation_allowed_here' => false, 'token_spend_allowed_here' => false, 'dispatch_allowed_here' => false, 'requires_separate_operator_handoff_contract' => true],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-manual-start-executor-receipt-writer-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_manual_start_executor_receipt_writer_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_manual_start_executor_receipt_writer_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_manual_start_executor_receipt_writer_preflight' => $preflight,
            'codex_real_invoker_post_start_manual_start_executor_receipt_writer_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_manual_start_executor_receipt_writer_preflight_does_not_start_codex', 'agent_codex_real_invoker_post_start_manual_start_executor_receipt_writer_preflight_does_not_call_codex', 'agent_codex_real_invoker_post_start_manual_start_executor_receipt_writer_preflight_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_manual_start_executor_receipt_writer_preflight_does_not_dispatch_work'],
            'human_summary' => $blockingReasons === [] ? 'Codex real invoker post-start manual start executor receipt writer is ready; it records the manual receipt bridge while keeping actual start, token spend and dispatch blocked.' : 'Codex real invoker post-start manual start executor receipt writer is blocked until post-start readiness, manual receipt and ledger prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartManualStartExecutorReceiptWriterImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartManualStartExecutorReceiptWriterPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_manual_start_executor_receipt_writer_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_manual_start_executor_receipt_writer_preflight_hash');
        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start manual start executor receipt writer', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter.php'], 'acceptance' => 'Writer consumes post-start process starter readiness metadata, including the accepted evidence bridge id, and delegates to Codex real invoker manual start executor receipt writer without invoking Codex.'],
            ['id' => 'T2', 'title' => 'Enforce post-start manual receipt policy', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter.php'], 'acceptance' => 'Writer records manual receipt bridge metadata while keeping actual process start, token spend, adapter execution and dispatch flags false.'],
            ['id' => 'T3', 'title' => 'Add post-start manual receipt tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartManualStartExecutorReceiptWriterTest.php'], 'acceptance' => 'Tests prove manual receipt bridge, idempotency, missing readiness bridge rejection, missing evidence bridge rejection, forbidden process-start rejection, missing provider run rejection and rollback.'],
            ['id' => 'T4', 'title' => 'Expose post-start manual receipt commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare operator handoff still separate.'],
        ];
        $packet = ['status' => 'ready_for_scoped_codex_real_invoker_post_start_manual_start_executor_receipt_writer_implementation', 'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-MANUAL-START-EXECUTOR-RECEIPT-WRITER-IMPLEMENTATION-SELF-CONSTRUCTION-0001', 'source_preflight_hash' => $preflightHash, 'objective' => 'Implement the Codex real invoker post-start manual start executor receipt writer that bridges process starter readiness to a manual start receipt while forbidding actual process start and dispatch.', 'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_enable_adapter_execution', 'do_not_run_real_external_process_invoker', 'do_not_run_operator_handoff_builder'], 'allowed_files' => data_get($preflight, 'allowed_future_files', []), 'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'real_external_process_invoker_execution', 'provider_process_call', 'adapter_execution_runtime', 'actual_process_start_runtime', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'], 'tasks' => $tasks, 'task_count' => count($tasks), 'acceptance_criteria' => ['post_start_manual_start_executor_receipt_requires_process_starter_readiness_bridge_metadata', 'post_start_manual_start_executor_receipt_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_manual_start_executor_receipt_delegates_to_codex_real_invoker_manual_start_executor_receipt_writer', 'post_start_manual_start_executor_receipt_records_observed_bridge_metadata', 'post_start_manual_start_executor_receipt_does_not_start_codex_or_dispatch_work'], 'required_gates' => data_get($preflight, 'required_gates', []), 'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'post_start_manual_start_executor_receipt_bridge_allowed_by_service' => true, 'manual_start_executor_receipt_written_by_packet' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'adapter_invocation_allowed_by_packet' => false, 'adapter_execution_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false, 'requires_separate_operator_handoff_contract' => true]];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_manual_start_executor_receipt_writer_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_manual_start_executor_receipt_writer_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_manual_start_executor_receipt_writer_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_manual_start_executor_receipt_writer_implementation_packet' => $packet,
            'codex_real_invoker_post_start_manual_start_executor_receipt_writer_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_manual_start_executor_receipt_writer_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_manual_start_executor_receipt_writer_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_manual_start_executor_receipt_writer_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_manual_start_executor_receipt_writer_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start manual start executor receipt writer implementation packet is ready; it records the manual receipt bridge but does not start Codex or dispatch work.',
        ];
    }


public function agentCodexRealInvokerPostStartOperatorStartHandoffBuilderContractTemplate(array $options = []): array
    {
        $postStartManualReceiptPayload = $this->section->agentCodexRealInvokerPostStartManualStartExecutorReceiptWriterPreflight($options);
        $postStartManualReceipt = (array) data_get($postStartManualReceiptPayload, 'codex_real_invoker_post_start_manual_start_executor_receipt_writer_preflight', []);
        $operatorHandoffPayload = $this->section->agentCodexRealInvokerOperatorStartHandoffBuilderPreflight($options);
        $operatorHandoff = (array) data_get($operatorHandoffPayload, 'codex_real_invoker_operator_start_handoff_builder_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_operator_start_handoff_builder_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-OPERATOR-HANDOFF-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_manual_receipt_hash' => data_get($postStartManualReceiptPayload, 'codex_real_invoker_post_start_manual_start_executor_receipt_writer_preflight_hash'),
                'operator_start_handoff_hash' => data_get($operatorHandoffPayload, 'codex_real_invoker_operator_start_handoff_builder_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_manual_start_executor_receipt_writer_status' => data_get($postStartManualReceiptPayload, 'status'),
            'source_codex_real_invoker_operator_start_handoff_builder_status' => data_get($operatorHandoffPayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartOperatorStartHandoffBuilder',
                'method' => 'buildPostStartOperatorStartHandoff',
                'input_contract' => ['run_key', 'post_start_operator_start_handoff_id', 'operator_start_handoff_id', 'post_start_manual_start_executor_receipt_id', 'manual_start_executor_receipt_id', 'post_start_process_starter_readiness_gate_id', 'provider_start_attempt_id', 'codex_execution_id', 'real_invoker_process_starter_readiness_gate_id', 'post_start_evidence_acceptance_bridge_id', 'handoff_packet_hash', 'operator_runbook_hash', 'external_terminal_handoff_hash', 'post_start_liveness_probe_contract_hash', 'post_start_receipt_contract_hash', 'failure_escalation_contract_hash', 'actor', 'session', 'reason'],
                'result_contract' => ['post_start_operator_start_handoff_id', 'operator_start_handoff_id', 'manual_start_executor_receipt_id', 'post_start_evidence_acceptance_bridge_id', 'post_start_operator_start_handoff_built', 'operator_start_handoff_built', 'actual_process_start_allowed', 'dispatch_allowed'],
            ],
            'real_invoker_post_start_operator_start_handoff_must' => ['require_codex_real_invoker_post_start_manual_start_executor_receipt_metadata', 'require_post_start_evidence_acceptance_bridge_from_manual_start_executor_receipt', 'delegate_to_codex_real_invoker_operator_start_handoff_builder', 'mirror_operator_start_handoff_metadata_on_observed_post_start_run', 'keep_post_start_receipt_contract_as_separate_later_contract'],
            'real_invoker_post_start_operator_start_handoff_must_not' => ['call_codex_cli_or_codex_app', 'spawn_process_or_shell_command', 'spend_provider_tokens', 'start_codex_process', 'dispatch_work_to_codex', 'enable_adapter_execution', 'mark_actual_process_start_allowed', 'accept_external_process_evidence'],
            'implementation_files_allowed_future' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartOperatorStartHandoffBuilder.php', 'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartOperatorStartHandoffBuilderTest.php'],
            'contract_policy' => ['template_is_read_only' => true, 'post_start_operator_start_handoff_allowed_by_service' => true, 'codex_real_invoker_operator_start_handoff_allowed_by_service' => true, 'operator_start_handoff_built_here' => true, 'manual_operator_start_required_here' => true, 'actual_process_start_allowed_here' => false, 'provider_process_start_allowed_here' => false, 'adapter_invocation_allowed_here' => false, 'adapter_execution_allowed_here' => false, 'codex_invocation_allowed_here' => false, 'token_spend_allowed_here' => false, 'dispatch_allowed_here' => false, 'requires_separate_post_start_receipt_contract' => true],
            'source_post_start_manual_start_executor_receipt_writer_preflight' => $postStartManualReceipt,
            'source_codex_real_invoker_operator_start_handoff_builder_preflight' => $operatorHandoff,
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-operator-start-handoff-builder-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_operator_start_handoff_builder_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_operator_start_handoff_builder_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_operator_start_handoff_builder_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_operator_start_handoff_builder_contract_template' => $template,
            'codex_real_invoker_post_start_operator_start_handoff_builder_contract_template_hash' => ReadinessHash::stable($template),
            'source_post_start_manual_start_executor_receipt_writer_preflight' => $postStartManualReceipt,
            'source_codex_real_invoker_operator_start_handoff_builder_preflight' => $operatorHandoff,
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_operator_start_handoff_builder_contract_template_does_not_start_codex', 'agent_codex_real_invoker_post_start_operator_start_handoff_builder_contract_template_does_not_call_codex', 'agent_codex_real_invoker_post_start_operator_start_handoff_builder_contract_template_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_operator_start_handoff_builder_contract_template_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start operator start handoff builder template prepares operator handoff metadata while actual start and dispatch remain disabled.',
        ];
    }


public function agentCodexRealInvokerPostStartOperatorStartHandoffBuilderPreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartOperatorStartHandoffBuilderContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_operator_start_handoff_builder_contract_template', []);
        $builderReady = class_exists(AgentCodexRealInvokerPostStartOperatorStartHandoffBuilder::class);
        $postStartManualReceiptReady = class_exists(AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter::class);
        $operatorHandoffReady = class_exists(AgentCodexRealInvokerOperatorStartHandoffBuilder::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([$builderReady ? null : 'codex_real_invoker_post_start_operator_start_handoff_builder_missing', $postStartManualReceiptReady ? null : 'codex_real_invoker_post_start_manual_start_executor_receipt_writer_missing', $operatorHandoffReady ? null : 'codex_real_invoker_operator_start_handoff_builder_missing', $runsTableReady ? null : 'agent_runs_table_missing', $ledgerReady ? null : 'ledger_table_missing']));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_operator_start_handoff_builder_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_operator_start_handoff_builder_contract_template_hash'),
            'source_codex_real_invoker_post_start_manual_start_executor_receipt_writer_status' => data_get($contract, 'source_codex_real_invoker_post_start_manual_start_executor_receipt_writer_status'),
            'source_codex_real_invoker_operator_start_handoff_builder_status' => data_get($contract, 'source_codex_real_invoker_operator_start_handoff_builder_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => ['codex_real_invoker_post_start_operator_start_handoff_builder_ready' => $builderReady, 'codex_real_invoker_post_start_manual_start_executor_receipt_writer_ready' => $postStartManualReceiptReady, 'codex_real_invoker_operator_start_handoff_builder_ready' => $operatorHandoffReady, 'agent_runs_table_ready' => $runsTableReady, 'ledger_table_ready' => $ledgerReady],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_operator_start_handoff_builder_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => ['create_codex_real_invoker_post_start_operator_start_handoff_builder', 'require_post_start_manual_start_executor_receipt_metadata', 'require_post_start_evidence_acceptance_bridge_from_manual_start_executor_receipt', 'delegate_to_codex_real_invoker_operator_start_handoff_builder_without_starting_codex', 'mirror_operator_start_handoff_metadata_on_observed_run'],
            'required_gates' => ['php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartOperatorStartHandoffBuilder.php', 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartOperatorStartHandoffBuilderTest.php', 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_operator_start_handoff_builder', 'php artisan atlas:engineering:knowledge docs-health --json', 'php artisan atlas:ai:architecture-validate --json', 'git diff --check'],
            'preflight_policy' => ['preflight_is_read_only' => true, 'post_start_operator_start_handoff_builder_file_creation_allowed_here' => false, 'post_start_operator_start_handoff_allowed_by_service' => true, 'codex_real_invoker_operator_start_handoff_allowed_by_service' => true, 'operator_start_handoff_built_here' => true, 'actual_process_start_allowed_here' => false, 'provider_process_start_allowed_here' => false, 'adapter_invocation_allowed_here' => false, 'adapter_execution_allowed_here' => false, 'codex_invocation_allowed_here' => false, 'token_spend_allowed_here' => false, 'dispatch_allowed_here' => false, 'requires_separate_post_start_receipt_contract' => true],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-operator-start-handoff-builder-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_operator_start_handoff_builder_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_operator_start_handoff_builder_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_operator_start_handoff_builder_preflight' => $preflight,
            'codex_real_invoker_post_start_operator_start_handoff_builder_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_operator_start_handoff_builder_preflight_does_not_start_codex', 'agent_codex_real_invoker_post_start_operator_start_handoff_builder_preflight_does_not_call_codex', 'agent_codex_real_invoker_post_start_operator_start_handoff_builder_preflight_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_operator_start_handoff_builder_preflight_does_not_dispatch_work'],
            'human_summary' => $blockingReasons === [] ? 'Codex real invoker post-start operator start handoff builder is ready; it mirrors handoff metadata while keeping actual start, token spend and dispatch blocked.' : 'Codex real invoker post-start operator start handoff builder is blocked until post-start manual receipt, operator handoff and ledger prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartOperatorStartHandoffBuilderImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartOperatorStartHandoffBuilderPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_operator_start_handoff_builder_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_operator_start_handoff_builder_preflight_hash');
        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start operator start handoff builder', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartOperatorStartHandoffBuilder.php'], 'acceptance' => 'Builder consumes post-start manual receipt metadata, including the accepted evidence bridge id, and delegates to Codex real invoker operator start handoff builder without invoking Codex.'],
            ['id' => 'T2', 'title' => 'Mirror handoff metadata on observed post-start run', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartOperatorStartHandoffBuilder.php'], 'acceptance' => 'Observed run receives codex_real_invoker_operator_start_handoff metadata for the next post-start receipt contract while all execution flags remain false.'],
            ['id' => 'T3', 'title' => 'Add post-start operator handoff tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartOperatorStartHandoffBuilderTest.php'], 'acceptance' => 'Tests prove handoff bridge, idempotency, missing manual receipt rejection, missing evidence bridge rejection, forbidden process-start rejection, missing provider run rejection and rollback.'],
            ['id' => 'T4', 'title' => 'Expose post-start operator handoff commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare post-start receipt contract still separate.'],
        ];
        $packet = ['status' => 'ready_for_scoped_codex_real_invoker_post_start_operator_start_handoff_builder_implementation', 'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-OPERATOR-HANDOFF-BUILDER-IMPLEMENTATION-SELF-CONSTRUCTION-0001', 'source_preflight_hash' => $preflightHash, 'objective' => 'Implement the Codex real invoker post-start operator start handoff builder that bridges manual receipt metadata to operator handoff metadata while forbidding actual process start and dispatch.', 'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_enable_adapter_execution', 'do_not_run_real_external_process_invoker', 'do_not_run_post_start_receipt_contract_builder'], 'allowed_files' => data_get($preflight, 'allowed_future_files', []), 'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'real_external_process_invoker_execution', 'provider_process_call', 'adapter_execution_runtime', 'actual_process_start_runtime', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'], 'tasks' => $tasks, 'task_count' => count($tasks), 'acceptance_criteria' => ['post_start_operator_start_handoff_requires_post_start_manual_receipt_metadata', 'post_start_operator_start_handoff_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_operator_start_handoff_delegates_to_codex_real_invoker_operator_start_handoff_builder', 'post_start_operator_start_handoff_mirrors_operator_handoff_metadata_on_observed_run', 'post_start_operator_start_handoff_does_not_start_codex_or_dispatch_work'], 'required_gates' => data_get($preflight, 'required_gates', []), 'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'post_start_operator_start_handoff_allowed_by_service' => true, 'operator_start_handoff_built_by_packet' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'adapter_invocation_allowed_by_packet' => false, 'adapter_execution_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false, 'requires_separate_post_start_receipt_contract' => true]];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_operator_start_handoff_builder_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_operator_start_handoff_builder_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_operator_start_handoff_builder_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_operator_start_handoff_builder_implementation_packet' => $packet,
            'codex_real_invoker_post_start_operator_start_handoff_builder_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_operator_start_handoff_builder_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_operator_start_handoff_builder_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_operator_start_handoff_builder_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_operator_start_handoff_builder_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start operator start handoff builder implementation packet is ready; it mirrors handoff metadata but does not start Codex or dispatch work.',
        ];
    }

}
