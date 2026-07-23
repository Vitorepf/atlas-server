<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\Codex;

use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentDispatchProviderSection;
use App\Services\Ai\SelfConstruction\Support\AgentCodexExternalProcessInvocationAuthorizationGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexExternalProcessInvokerDryRun;
use App\Services\Ai\SelfConstruction\Support\AgentCodexExternalProcessRuntimeDriver;
use App\Services\Ai\SelfConstruction\Support\AgentCodexProcessSpawnEnablementGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexProcessSpawnExecutor;
use App\Services\Ai\SelfConstruction\Support\AgentCodexProcessStartReleaseGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartExternalProcessRuntimeGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartProcessSpawnEnablementGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartProcessStartReleaseGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartProviderExecutionContractGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartSupervisedStartExecutorGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexSupervisedStartExecutor;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;
use Illuminate\Support\Facades\Schema;

/**
 * AGENT CODEX projection sub-section 09 of 11, sub-split from the god
 * {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling agentCodex* pipeline calls route through the
 * injected Section facade (`$this->section->agentCodex*`), which re-dispatches
 * to whichever sub-section owns the target stage. The dispatch-provider
 * collaborator is injected verbatim for the one stage that consumes it.
 *
 * Stage range: agentCodexRealInvokerPostStartProcessStartReleaseGateContractTemplate
 *           .. agentCodexRealInvokerPostStartExternalProcessInvokerDryRunGateImplementationPacket
 */
final class CodexPart09SubSection
{
    public function __construct(
        private readonly ReadinessProjectionAgentCodexSection $section,
        private readonly ReadinessProjectionAgentDispatchProviderSection $agentDispatchProviderSection,
    ) {}

public function agentCodexRealInvokerPostStartProcessStartReleaseGateContractTemplate(array $options = []): array
    {
        $providerExecutionPayload = $this->section->agentCodexRealInvokerPostStartProviderExecutionContractGatePreflight($options);
        $providerExecution = (array) data_get($providerExecutionPayload, 'codex_real_invoker_post_start_provider_execution_contract_gate_preflight', []);
        $releasePayload = $this->section->agentCodexProcessStartReleasePreflight($options);
        $release = (array) data_get($releasePayload, 'codex_process_start_release_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_process_start_release_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-PROCESS-START-RELEASE-GATE-'.strtoupper(substr(ReadinessHash::stable([
                'provider_execution_contract_gate_preflight_hash' => data_get($providerExecutionPayload, 'codex_real_invoker_post_start_provider_execution_contract_gate_preflight_hash'),
                'codex_process_start_release_preflight_hash' => data_get($releasePayload, 'codex_process_start_release_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_provider_execution_contract_gate_status' => data_get($providerExecutionPayload, 'status'),
            'source_codex_process_start_release_status' => data_get($releasePayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartProcessStartReleaseGate',
                'method' => 'authorizePostStartProcessStartRelease',
                'input_contract' => ['run_key', 'post_start_process_start_release_gate_id', 'process_start_release_id', 'provider_execution_contract_gate_id', 'codex_execution_id', 'adapter_execution_guard_gate_id', 'execution_guard_id', 'adapter_invocation_boundary_gate_id', 'adapter_invocation_id', 'provider_start_driver_gate_id', 'provider_start_attempt_id', 'post_start_evidence_acceptance_bridge_id', 'signed_dispatch_receipt_hash', 'operator_release_receipt_hash', 'codex_execution_contract_hash', 'actor', 'session', 'reason'],
                'result_contract' => ['post_start_process_start_release_gate_id', 'process_start_release_id', 'provider_execution_contract_gate_id', 'codex_execution_id', 'post_start_evidence_acceptance_bridge_id', 'process_start_release_authorized', 'supervised_start_executor_required', 'actual_process_start_allowed', 'dispatch_allowed'],
            ],
            'real_invoker_post_start_process_start_release_gate_must' => [
                'require_codex_real_invoker_post_start_provider_execution_contract_metadata',
                'require_post_start_evidence_acceptance_bridge_from_provider_execution_contract',
                'require_provider_execution_contract_flags_blocking_start_token_dispatch',
                'delegate_to_codex_process_start_release_gate',
                'record_process_start_release_bridge_on_observed_post_start_run',
                'require_supervised_start_executor_as_separate_later_contract',
            ],
            'real_invoker_post_start_process_start_release_gate_must_not' => [
                'call_codex_cli_or_codex_app',
                'spawn_process_or_shell_command',
                'spend_provider_tokens',
                'start_codex_process',
                'dispatch_work_to_codex',
                'enable_adapter_execution',
                'run_supervised_start_executor',
                'mark_observed_run_running_or_terminal',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProcessStartReleaseGate.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartProcessStartReleaseGateTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'post_start_process_start_release_bridge_allowed_by_service' => true,
                'codex_process_start_release_gate_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_supervised_start_executor_contract' => true,
            ],
            'source_post_start_provider_execution_contract_gate_preflight' => $providerExecution,
            'source_codex_process_start_release_preflight' => $release,
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-process-start-release-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_process_start_release_gate_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_process_start_release_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_process_start_release_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_process_start_release_gate_contract_template' => $template,
            'codex_real_invoker_post_start_process_start_release_gate_contract_template_hash' => ReadinessHash::stable($template),
            'source_post_start_provider_execution_contract_gate_preflight' => $providerExecution,
            'source_codex_process_start_release_preflight' => $release,
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_process_start_release_gate_contract_template_does_not_start_codex', 'agent_codex_real_invoker_post_start_process_start_release_gate_contract_template_does_not_call_codex', 'agent_codex_real_invoker_post_start_process_start_release_gate_contract_template_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_process_start_release_gate_contract_template_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start process start release gate template bridges the post-start provider execution contract to the Codex process start release gate without starting Codex.',
        ];
    }


public function agentCodexRealInvokerPostStartProcessStartReleaseGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartProcessStartReleaseGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_process_start_release_gate_contract_template', []);
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProcessStartReleaseGate::class);
        $providerExecutionGateReady = class_exists(AgentCodexRealInvokerPostStartProviderExecutionContractGate::class);
        $releaseGateReady = class_exists(AgentCodexProcessStartReleaseGate::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $gateReady ? null : 'codex_real_invoker_post_start_process_start_release_gate_missing',
            $providerExecutionGateReady ? null : 'codex_real_invoker_post_start_provider_execution_contract_gate_missing',
            $releaseGateReady ? null : 'codex_process_start_release_gate_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_process_start_release_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_process_start_release_gate_contract_template_hash'),
            'source_codex_real_invoker_post_start_provider_execution_contract_gate_status' => data_get($contract, 'source_codex_real_invoker_post_start_provider_execution_contract_gate_status'),
            'source_codex_process_start_release_status' => data_get($contract, 'source_codex_process_start_release_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_post_start_process_start_release_gate_ready' => $gateReady,
                'codex_real_invoker_post_start_provider_execution_contract_gate_ready' => $providerExecutionGateReady,
                'codex_process_start_release_gate_ready' => $releaseGateReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_process_start_release_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => ['create_codex_real_invoker_post_start_process_start_release_gate', 'require_post_start_provider_execution_contract_metadata', 'require_post_start_evidence_acceptance_bridge_from_provider_execution_contract', 'delegate_to_codex_process_start_release_gate_without_starting_codex', 'record_process_start_release_bridge_on_observed_run'],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProcessStartReleaseGate.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartProcessStartReleaseGateTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_process_start_release_gate',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'post_start_process_start_release_gate_file_creation_allowed_here' => false,
                'post_start_process_start_release_bridge_allowed_by_service' => true,
                'codex_process_start_release_gate_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_supervised_start_executor_contract' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-process-start-release-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_process_start_release_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_process_start_release_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_process_start_release_gate_preflight' => $preflight,
            'codex_real_invoker_post_start_process_start_release_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_process_start_release_gate_preflight_does_not_start_codex', 'agent_codex_real_invoker_post_start_process_start_release_gate_preflight_does_not_call_codex', 'agent_codex_real_invoker_post_start_process_start_release_gate_preflight_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_process_start_release_gate_preflight_does_not_dispatch_work'],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker post-start process start release gate is ready; it authorizes only the release bridge and still requires a separate supervised start executor.'
                : 'Codex real invoker post-start process start release gate is blocked until provider execution contract, release gate and ledger prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartProcessStartReleaseGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartProcessStartReleaseGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_process_start_release_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_process_start_release_gate_preflight_hash');

        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start process start release gate', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProcessStartReleaseGate.php'], 'acceptance' => 'Gate consumes post-start provider execution contract metadata and delegates to the Codex process start release gate without starting Codex.'],
            ['id' => 'T2', 'title' => 'Enforce post-start release bridge policy', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProcessStartReleaseGate.php'], 'acceptance' => 'Gate requires accepted post-start evidence bridge, blocking provider execution flags, matching hashes and a separate supervised start executor before any real process start.'],
            ['id' => 'T3', 'title' => 'Add post-start process start release gate tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartProcessStartReleaseGateTest.php'], 'acceptance' => 'Tests prove release authorization, idempotency, duplicate rejection, missing contract rejection, missing evidence bridge rejection, forbidden dispatch rejection and rollback.'],
            ['id' => 'T4', 'title' => 'Expose post-start process start release gate readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare supervised start executor still separate.'],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_process_start_release_gate_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-PROCESS-START-RELEASE-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker post-start process start release gate that bridges the post-start provider execution contract to the Codex process start release gate while forbidding real process start.',
            'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_enable_adapter_execution', 'do_not_run_supervised_start_executor'],
            'allowed_files' => data_get($preflight, 'allowed_future_files', []),
            'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'process_start', 'provider_process_call', 'adapter_execution_runtime', 'supervised_start_executor_runtime', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => ['post_start_process_start_release_gate_requires_provider_execution_contract_metadata', 'post_start_process_start_release_gate_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_process_start_release_gate_delegates_to_codex_process_start_release_gate', 'post_start_process_start_release_gate_records_observed_bridge_metadata', 'post_start_process_start_release_gate_does_not_start_codex'],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'post_start_process_start_release_bridge_allowed_by_service' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'adapter_invocation_allowed_by_packet' => false, 'adapter_execution_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_process_start_release_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_process_start_release_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_process_start_release_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_process_start_release_gate_implementation_packet' => $packet,
            'codex_real_invoker_post_start_process_start_release_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_process_start_release_gate_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_process_start_release_gate_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_process_start_release_gate_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_process_start_release_gate_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start process start release gate implementation packet is ready; it authorizes only the release bridge and does not run the supervised start executor.',
        ];
    }


public function agentCodexRealInvokerPostStartSupervisedStartExecutorGateContractTemplate(array $options = []): array
    {
        $postStartReleasePayload = $this->section->agentCodexRealInvokerPostStartProcessStartReleaseGatePreflight($options);
        $postStartRelease = (array) data_get($postStartReleasePayload, 'codex_real_invoker_post_start_process_start_release_gate_preflight', []);
        $supervisedStartPayload = $this->section->agentCodexSupervisedStartExecutorPreflight($options);
        $supervisedStart = (array) data_get($supervisedStartPayload, 'codex_supervised_start_executor_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_supervised_start_executor_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-SUPERVISED-START-EXECUTOR-GATE-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_process_start_release_gate_preflight_hash' => data_get($postStartReleasePayload, 'codex_real_invoker_post_start_process_start_release_gate_preflight_hash'),
                'codex_supervised_start_executor_preflight_hash' => data_get($supervisedStartPayload, 'codex_supervised_start_executor_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_process_start_release_gate_status' => data_get($postStartReleasePayload, 'status'),
            'source_codex_supervised_start_executor_status' => data_get($supervisedStartPayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartSupervisedStartExecutorGate',
                'method' => 'preparePostStartSupervisedStart',
                'input_contract' => ['run_key', 'post_start_supervised_start_gate_id', 'post_start_process_start_release_gate_id', 'process_start_release_id', 'provider_execution_contract_gate_id', 'codex_execution_id', 'supervised_start_id', 'adapter_execution_guard_gate_id', 'execution_guard_id', 'adapter_invocation_boundary_gate_id', 'adapter_invocation_id', 'provider_start_driver_gate_id', 'provider_start_attempt_id', 'post_start_evidence_acceptance_bridge_id', 'signed_dispatch_receipt_hash', 'operator_release_receipt_hash', 'codex_execution_contract_hash', 'stdout_stderr_sanitizer_hash', 'ready_probe_plan_hash', 'rollback_plan_hash', 'actor', 'session', 'reason'],
                'result_contract' => ['post_start_supervised_start_gate_id', 'post_start_process_start_release_gate_id', 'process_start_release_id', 'supervised_start_id', 'codex_execution_id', 'post_start_evidence_acceptance_bridge_id', 'supervised_start_prepared', 'spawn_enablement_required', 'actual_process_start_allowed', 'dispatch_allowed'],
            ],
            'real_invoker_post_start_supervised_start_executor_gate_must' => [
                'require_codex_real_invoker_post_start_process_start_release_metadata',
                'require_post_start_evidence_acceptance_bridge_from_process_start_release',
                'require_process_start_release_flags_blocking_start_token_dispatch',
                'delegate_to_codex_supervised_start_executor',
                'record_supervised_start_bridge_on_observed_post_start_run',
                'require_spawn_enablement_as_separate_later_contract',
            ],
            'real_invoker_post_start_supervised_start_executor_gate_must_not' => [
                'call_codex_cli_or_codex_app',
                'spawn_process_or_shell_command',
                'spend_provider_tokens',
                'start_codex_process',
                'dispatch_work_to_codex',
                'enable_adapter_execution',
                'run_process_spawn_enablement',
                'mark_observed_run_running_or_terminal',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartSupervisedStartExecutorGate.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartSupervisedStartExecutorGateTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'post_start_supervised_start_bridge_allowed_by_service' => true,
                'codex_supervised_start_executor_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_process_spawn_enablement_contract' => true,
            ],
            'source_post_start_process_start_release_gate_preflight' => $postStartRelease,
            'source_codex_supervised_start_executor_preflight' => $supervisedStart,
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-supervised-start-executor-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_supervised_start_executor_gate_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_supervised_start_executor_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_supervised_start_executor_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_supervised_start_executor_gate_contract_template' => $template,
            'codex_real_invoker_post_start_supervised_start_executor_gate_contract_template_hash' => ReadinessHash::stable($template),
            'source_post_start_process_start_release_gate_preflight' => $postStartRelease,
            'source_codex_supervised_start_executor_preflight' => $supervisedStart,
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_supervised_start_executor_gate_contract_template_does_not_start_codex', 'agent_codex_real_invoker_post_start_supervised_start_executor_gate_contract_template_does_not_call_codex', 'agent_codex_real_invoker_post_start_supervised_start_executor_gate_contract_template_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_supervised_start_executor_gate_contract_template_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start supervised start executor gate template bridges the post-start release gate to the Codex supervised start executor without spawning Codex.',
        ];
    }


public function agentCodexRealInvokerPostStartSupervisedStartExecutorGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartSupervisedStartExecutorGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_supervised_start_executor_gate_contract_template', []);
        $gateReady = class_exists(AgentCodexRealInvokerPostStartSupervisedStartExecutorGate::class);
        $postStartReleaseGateReady = class_exists(AgentCodexRealInvokerPostStartProcessStartReleaseGate::class);
        $supervisedStartReady = class_exists(AgentCodexSupervisedStartExecutor::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $gateReady ? null : 'codex_real_invoker_post_start_supervised_start_executor_gate_missing',
            $postStartReleaseGateReady ? null : 'codex_real_invoker_post_start_process_start_release_gate_missing',
            $supervisedStartReady ? null : 'codex_supervised_start_executor_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_supervised_start_executor_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_supervised_start_executor_gate_contract_template_hash'),
            'source_codex_real_invoker_post_start_process_start_release_gate_status' => data_get($contract, 'source_codex_real_invoker_post_start_process_start_release_gate_status'),
            'source_codex_supervised_start_executor_status' => data_get($contract, 'source_codex_supervised_start_executor_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_post_start_supervised_start_executor_gate_ready' => $gateReady,
                'codex_real_invoker_post_start_process_start_release_gate_ready' => $postStartReleaseGateReady,
                'codex_supervised_start_executor_ready' => $supervisedStartReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_supervised_start_executor_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => ['create_codex_real_invoker_post_start_supervised_start_executor_gate', 'require_post_start_process_start_release_metadata', 'require_post_start_evidence_acceptance_bridge_from_process_start_release', 'delegate_to_codex_supervised_start_executor_without_spawning_codex', 'record_supervised_start_bridge_on_observed_run'],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartSupervisedStartExecutorGate.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartSupervisedStartExecutorGateTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_supervised_start_executor_gate',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'post_start_supervised_start_executor_gate_file_creation_allowed_here' => false,
                'post_start_supervised_start_bridge_allowed_by_service' => true,
                'codex_supervised_start_executor_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_process_spawn_enablement_contract' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-supervised-start-executor-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_supervised_start_executor_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_supervised_start_executor_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_supervised_start_executor_gate_preflight' => $preflight,
            'codex_real_invoker_post_start_supervised_start_executor_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_supervised_start_executor_gate_preflight_does_not_start_codex', 'agent_codex_real_invoker_post_start_supervised_start_executor_gate_preflight_does_not_call_codex', 'agent_codex_real_invoker_post_start_supervised_start_executor_gate_preflight_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_supervised_start_executor_gate_preflight_does_not_dispatch_work'],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker post-start supervised start executor gate is ready; it prepares supervised start and still requires separate spawn enablement.'
                : 'Codex real invoker post-start supervised start executor gate is blocked until post-start release, supervised executor and ledger prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartSupervisedStartExecutorGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartSupervisedStartExecutorGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_supervised_start_executor_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_supervised_start_executor_gate_preflight_hash');

        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start supervised start executor gate', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartSupervisedStartExecutorGate.php'], 'acceptance' => 'Gate consumes post-start process release metadata and delegates to the Codex supervised start executor without spawning Codex.'],
            ['id' => 'T2', 'title' => 'Enforce supervised start bridge policy', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartSupervisedStartExecutorGate.php'], 'acceptance' => 'Gate requires accepted post-start evidence bridge, release flags to remain blocking, supervision hashes to be valid and process spawn enablement to stay separate.'],
            ['id' => 'T3', 'title' => 'Add post-start supervised start executor gate tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartSupervisedStartExecutorGateTest.php'], 'acceptance' => 'Tests prove preparation, idempotency, duplicate rejection, missing release rejection, missing evidence bridge rejection, forbidden dispatch rejection, missing provider run rejection and rollback.'],
            ['id' => 'T4', 'title' => 'Expose post-start supervised start executor gate readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare process spawn enablement still separate.'],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_supervised_start_executor_gate_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-SUPERVISED-START-EXECUTOR-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker post-start supervised start executor gate that bridges the post-start process start release to the Codex supervised start executor while forbidding process spawn.',
            'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_enable_adapter_execution', 'do_not_run_process_spawn_enablement'],
            'allowed_files' => data_get($preflight, 'allowed_future_files', []),
            'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'process_start', 'provider_process_call', 'adapter_execution_runtime', 'process_spawn_enablement_runtime', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => ['post_start_supervised_start_executor_gate_requires_process_start_release_metadata', 'post_start_supervised_start_executor_gate_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_supervised_start_executor_gate_delegates_to_codex_supervised_start_executor', 'post_start_supervised_start_executor_gate_records_observed_bridge_metadata', 'post_start_supervised_start_executor_gate_does_not_spawn_codex'],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'post_start_supervised_start_bridge_allowed_by_service' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'adapter_invocation_allowed_by_packet' => false, 'adapter_execution_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_supervised_start_executor_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_supervised_start_executor_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_supervised_start_executor_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_supervised_start_executor_gate_implementation_packet' => $packet,
            'codex_real_invoker_post_start_supervised_start_executor_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_supervised_start_executor_gate_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_supervised_start_executor_gate_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_supervised_start_executor_gate_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_supervised_start_executor_gate_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start supervised start executor gate implementation packet is ready; it prepares supervised start and does not enable process spawn.',
        ];
    }


public function agentCodexRealInvokerPostStartProcessSpawnEnablementGateContractTemplate(array $options = []): array
    {
        $postStartSupervisedPayload = $this->section->agentCodexRealInvokerPostStartSupervisedStartExecutorGatePreflight($options);
        $postStartSupervised = (array) data_get($postStartSupervisedPayload, 'codex_real_invoker_post_start_supervised_start_executor_gate_preflight', []);
        $spawnEnablementPayload = $this->section->agentCodexProcessSpawnEnablementPreflight($options);
        $spawnEnablement = (array) data_get($spawnEnablementPayload, 'codex_process_spawn_enablement_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_process_spawn_enablement_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-PROCESS-SPAWN-ENABLEMENT-GATE-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_supervised_start_executor_gate_preflight_hash' => data_get($postStartSupervisedPayload, 'codex_real_invoker_post_start_supervised_start_executor_gate_preflight_hash'),
                'codex_process_spawn_enablement_preflight_hash' => data_get($spawnEnablementPayload, 'codex_process_spawn_enablement_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_supervised_start_executor_gate_status' => data_get($postStartSupervisedPayload, 'status'),
            'source_codex_process_spawn_enablement_status' => data_get($spawnEnablementPayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartProcessSpawnEnablementGate',
                'method' => 'enablePostStartProcessSpawn',
                'input_contract' => ['run_key', 'post_start_process_spawn_enablement_gate_id', 'post_start_supervised_start_gate_id', 'post_start_process_start_release_gate_id', 'process_start_release_id', 'provider_execution_contract_gate_id', 'codex_execution_id', 'supervised_start_id', 'spawn_enablement_id', 'adapter_execution_guard_gate_id', 'execution_guard_id', 'adapter_invocation_boundary_gate_id', 'adapter_invocation_id', 'provider_start_driver_gate_id', 'provider_start_attempt_id', 'post_start_evidence_acceptance_bridge_id', 'signed_dispatch_receipt_hash', 'operator_release_receipt_hash', 'operator_spawn_receipt_hash', 'codex_execution_contract_hash', 'supervised_start_contract_hash', 'actor', 'session', 'reason'],
                'result_contract' => ['post_start_process_spawn_enablement_gate_id', 'supervised_start_id', 'spawn_enablement_id', 'codex_execution_id', 'post_start_evidence_acceptance_bridge_id', 'process_spawn_enabled', 'final_process_spawn_executor_required', 'actual_process_start_allowed', 'dispatch_allowed'],
            ],
            'real_invoker_post_start_process_spawn_enablement_gate_must' => [
                'require_codex_real_invoker_post_start_supervised_start_metadata',
                'require_post_start_evidence_acceptance_bridge_from_supervised_start',
                'require_supervised_start_flags_blocking_process_token_dispatch',
                'delegate_to_codex_process_spawn_enablement_gate',
                'record_spawn_enablement_bridge_on_observed_post_start_run',
                'require_final_process_spawn_executor_as_separate_later_contract',
            ],
            'real_invoker_post_start_process_spawn_enablement_gate_must_not' => [
                'call_codex_cli_or_codex_app',
                'spawn_process_or_shell_command',
                'spend_provider_tokens',
                'start_codex_process',
                'dispatch_work_to_codex',
                'enable_adapter_execution',
                'run_final_process_spawn_executor',
                'mark_observed_run_running_or_terminal',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProcessSpawnEnablementGate.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartProcessSpawnEnablementGateTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'post_start_process_spawn_enablement_bridge_allowed_by_service' => true,
                'codex_process_spawn_enablement_gate_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_final_process_spawn_executor_contract' => true,
            ],
            'source_post_start_supervised_start_executor_gate_preflight' => $postStartSupervised,
            'source_codex_process_spawn_enablement_preflight' => $spawnEnablement,
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-process-spawn-enablement-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_process_spawn_enablement_gate_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_process_spawn_enablement_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_process_spawn_enablement_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_process_spawn_enablement_gate_contract_template' => $template,
            'codex_real_invoker_post_start_process_spawn_enablement_gate_contract_template_hash' => ReadinessHash::stable($template),
            'source_post_start_supervised_start_executor_gate_preflight' => $postStartSupervised,
            'source_codex_process_spawn_enablement_preflight' => $spawnEnablement,
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_process_spawn_enablement_gate_contract_template_does_not_start_codex', 'agent_codex_real_invoker_post_start_process_spawn_enablement_gate_contract_template_does_not_call_codex', 'agent_codex_real_invoker_post_start_process_spawn_enablement_gate_contract_template_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_process_spawn_enablement_gate_contract_template_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start process spawn enablement gate template bridges supervised start to spawn enablement without starting Codex.',
        ];
    }


public function agentCodexRealInvokerPostStartProcessSpawnEnablementGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartProcessSpawnEnablementGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_process_spawn_enablement_gate_contract_template', []);
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProcessSpawnEnablementGate::class);
        $postStartSupervisedReady = class_exists(AgentCodexRealInvokerPostStartSupervisedStartExecutorGate::class);
        $spawnEnablementReady = class_exists(AgentCodexProcessSpawnEnablementGate::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $gateReady ? null : 'codex_real_invoker_post_start_process_spawn_enablement_gate_missing',
            $postStartSupervisedReady ? null : 'codex_real_invoker_post_start_supervised_start_executor_gate_missing',
            $spawnEnablementReady ? null : 'codex_process_spawn_enablement_gate_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_process_spawn_enablement_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_process_spawn_enablement_gate_contract_template_hash'),
            'source_codex_real_invoker_post_start_supervised_start_executor_gate_status' => data_get($contract, 'source_codex_real_invoker_post_start_supervised_start_executor_gate_status'),
            'source_codex_process_spawn_enablement_status' => data_get($contract, 'source_codex_process_spawn_enablement_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_post_start_process_spawn_enablement_gate_ready' => $gateReady,
                'codex_real_invoker_post_start_supervised_start_executor_gate_ready' => $postStartSupervisedReady,
                'codex_process_spawn_enablement_gate_ready' => $spawnEnablementReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_process_spawn_enablement_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => ['create_codex_real_invoker_post_start_process_spawn_enablement_gate', 'require_post_start_supervised_start_metadata', 'require_post_start_evidence_acceptance_bridge_from_supervised_start', 'delegate_to_codex_process_spawn_enablement_gate_without_starting_codex', 'record_spawn_enablement_bridge_on_observed_run'],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProcessSpawnEnablementGate.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartProcessSpawnEnablementGateTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_process_spawn_enablement_gate',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'post_start_process_spawn_enablement_gate_file_creation_allowed_here' => false,
                'post_start_process_spawn_enablement_bridge_allowed_by_service' => true,
                'codex_process_spawn_enablement_gate_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_final_process_spawn_executor_contract' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-process-spawn-enablement-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_process_spawn_enablement_gate_preflight' => $preflight,
            'codex_real_invoker_post_start_process_spawn_enablement_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight_does_not_start_codex', 'agent_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight_does_not_call_codex', 'agent_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight_does_not_dispatch_work'],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker post-start process spawn enablement gate is ready; it records spawn enablement and still requires final process spawn executor.'
                : 'Codex real invoker post-start process spawn enablement gate is blocked until supervised start, spawn enablement and ledger prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartProcessSpawnEnablementGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartProcessSpawnEnablementGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_process_spawn_enablement_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_process_spawn_enablement_gate_preflight_hash');

        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start process spawn enablement gate', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProcessSpawnEnablementGate.php'], 'acceptance' => 'Gate consumes post-start supervised start metadata and delegates to Codex process spawn enablement without starting Codex.'],
            ['id' => 'T2', 'title' => 'Enforce spawn enablement bridge policy', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProcessSpawnEnablementGate.php'], 'acceptance' => 'Gate requires accepted post-start evidence bridge and records enablement while keeping final process spawn executor separate and all runtime/token/dispatch flags false.'],
            ['id' => 'T3', 'title' => 'Add post-start process spawn enablement tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartProcessSpawnEnablementGateTest.php'], 'acceptance' => 'Tests prove enablement, idempotency, duplicate rejection, missing bridge rejection, missing evidence bridge rejection, forbidden dispatch rejection, missing provider run rejection and rollback.'],
            ['id' => 'T4', 'title' => 'Expose post-start process spawn enablement readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare final process spawn executor still separate.'],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_process_spawn_enablement_gate_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-PROCESS-SPAWN-ENABLEMENT-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker post-start process spawn enablement gate that bridges post-start supervised start to process spawn enablement while forbidding actual process start.',
            'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_enable_adapter_execution', 'do_not_run_final_process_spawn_executor'],
            'allowed_files' => data_get($preflight, 'allowed_future_files', []),
            'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'process_start', 'provider_process_call', 'adapter_execution_runtime', 'final_process_spawn_executor_runtime', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => ['post_start_process_spawn_enablement_gate_requires_supervised_start_bridge_metadata', 'post_start_process_spawn_enablement_gate_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_process_spawn_enablement_gate_delegates_to_codex_process_spawn_enablement_gate', 'post_start_process_spawn_enablement_gate_records_observed_bridge_metadata', 'post_start_process_spawn_enablement_gate_does_not_start_codex'],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'post_start_process_spawn_enablement_bridge_allowed_by_service' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'adapter_invocation_allowed_by_packet' => false, 'adapter_execution_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_process_spawn_enablement_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_process_spawn_enablement_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_process_spawn_enablement_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_process_spawn_enablement_gate_implementation_packet' => $packet,
            'codex_real_invoker_post_start_process_spawn_enablement_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_process_spawn_enablement_gate_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_process_spawn_enablement_gate_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_process_spawn_enablement_gate_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_process_spawn_enablement_gate_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start process spawn enablement gate implementation packet is ready; it records enablement and does not run the final process spawn executor.',
        ];
    }


public function agentCodexRealInvokerPostStartFinalProcessSpawnExecutorGateContractTemplate(array $options = []): array
    {
        $postStartSpawnEnablementPayload = $this->section->agentCodexRealInvokerPostStartProcessSpawnEnablementGatePreflight($options);
        $postStartSpawnEnablement = (array) data_get($postStartSpawnEnablementPayload, 'codex_real_invoker_post_start_process_spawn_enablement_gate_preflight', []);
        $spawnExecutorPayload = $this->section->agentCodexProcessSpawnExecutorPreflight($options);
        $spawnExecutor = (array) data_get($spawnExecutorPayload, 'codex_process_spawn_executor_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_final_process_spawn_executor_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-FINAL-PROCESS-SPAWN-EXECUTOR-GATE-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_process_spawn_enablement_gate_preflight_hash' => data_get($postStartSpawnEnablementPayload, 'codex_real_invoker_post_start_process_spawn_enablement_gate_preflight_hash'),
                'codex_process_spawn_executor_preflight_hash' => data_get($spawnExecutorPayload, 'codex_process_spawn_executor_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_process_spawn_enablement_gate_status' => data_get($postStartSpawnEnablementPayload, 'status'),
            'source_codex_process_spawn_executor_status' => data_get($spawnExecutorPayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate',
                'method' => 'preparePostStartFinalProcessSpawn',
                'input_contract' => ['run_key', 'post_start_final_process_spawn_executor_gate_id', 'post_start_process_spawn_enablement_gate_id', 'post_start_supervised_start_gate_id', 'post_start_process_start_release_gate_id', 'process_start_release_id', 'provider_execution_contract_gate_id', 'codex_execution_id', 'supervised_start_id', 'spawn_enablement_id', 'spawn_executor_id', 'adapter_execution_guard_gate_id', 'execution_guard_id', 'adapter_invocation_boundary_gate_id', 'adapter_invocation_id', 'provider_start_driver_gate_id', 'provider_start_attempt_id', 'post_start_evidence_acceptance_bridge_id', 'signed_dispatch_receipt_hash', 'operator_release_receipt_hash', 'operator_spawn_receipt_hash', 'operator_final_spawn_receipt_hash', 'codex_execution_contract_hash', 'supervised_start_contract_hash', 'runtime_supervision_plan_hash', 'stdout_stderr_sink_hash', 'liveness_probe_hash', 'actor', 'session', 'reason'],
                'result_contract' => ['post_start_final_process_spawn_executor_gate_id', 'spawn_enablement_id', 'spawn_executor_id', 'codex_execution_id', 'post_start_evidence_acceptance_bridge_id', 'process_spawn_executor_prepared', 'external_process_runtime_required', 'actual_process_start_allowed', 'dispatch_allowed'],
            ],
            'real_invoker_post_start_final_process_spawn_executor_gate_must' => [
                'require_codex_real_invoker_post_start_process_spawn_enablement_metadata',
                'require_post_start_evidence_acceptance_bridge_from_process_spawn_enablement',
                'require_spawn_enablement_flags_blocking_process_token_dispatch',
                'delegate_to_codex_process_spawn_executor',
                'record_final_process_spawn_executor_bridge_on_observed_post_start_run',
                'require_external_process_runtime_as_separate_later_contract',
            ],
            'real_invoker_post_start_final_process_spawn_executor_gate_must_not' => [
                'call_codex_cli_or_codex_app',
                'spawn_process_or_shell_command',
                'spend_provider_tokens',
                'start_codex_process',
                'dispatch_work_to_codex',
                'enable_adapter_execution',
                'run_external_process_runtime',
                'mark_observed_run_running_or_terminal',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGateTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'post_start_final_process_spawn_executor_bridge_allowed_by_service' => true,
                'codex_process_spawn_executor_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_external_process_runtime_contract' => true,
            ],
            'source_post_start_process_spawn_enablement_gate_preflight' => $postStartSpawnEnablement,
            'source_codex_process_spawn_executor_preflight' => $spawnExecutor,
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-final-process-spawn-executor-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_final_process_spawn_executor_gate_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_final_process_spawn_executor_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_final_process_spawn_executor_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_final_process_spawn_executor_gate_contract_template' => $template,
            'codex_real_invoker_post_start_final_process_spawn_executor_gate_contract_template_hash' => ReadinessHash::stable($template),
            'source_post_start_process_spawn_enablement_gate_preflight' => $postStartSpawnEnablement,
            'source_codex_process_spawn_executor_preflight' => $spawnExecutor,
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_final_process_spawn_executor_gate_contract_template_does_not_start_codex', 'agent_codex_real_invoker_post_start_final_process_spawn_executor_gate_contract_template_does_not_call_codex', 'agent_codex_real_invoker_post_start_final_process_spawn_executor_gate_contract_template_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_final_process_spawn_executor_gate_contract_template_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start final process spawn executor gate template bridges spawn enablement to final spawn executor without starting Codex.',
        ];
    }


public function agentCodexRealInvokerPostStartFinalProcessSpawnExecutorGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartFinalProcessSpawnExecutorGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_final_process_spawn_executor_gate_contract_template', []);
        $gateReady = class_exists(AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate::class);
        $postStartSpawnEnablementReady = class_exists(AgentCodexRealInvokerPostStartProcessSpawnEnablementGate::class);
        $spawnExecutorReady = class_exists(AgentCodexProcessSpawnExecutor::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $gateReady ? null : 'codex_real_invoker_post_start_final_process_spawn_executor_gate_missing',
            $postStartSpawnEnablementReady ? null : 'codex_real_invoker_post_start_process_spawn_enablement_gate_missing',
            $spawnExecutorReady ? null : 'codex_process_spawn_executor_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_final_process_spawn_executor_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_final_process_spawn_executor_gate_contract_template_hash'),
            'source_codex_real_invoker_post_start_process_spawn_enablement_gate_status' => data_get($contract, 'source_codex_real_invoker_post_start_process_spawn_enablement_gate_status'),
            'source_codex_process_spawn_executor_status' => data_get($contract, 'source_codex_process_spawn_executor_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_post_start_final_process_spawn_executor_gate_ready' => $gateReady,
                'codex_real_invoker_post_start_process_spawn_enablement_gate_ready' => $postStartSpawnEnablementReady,
                'codex_process_spawn_executor_ready' => $spawnExecutorReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_final_process_spawn_executor_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => ['create_codex_real_invoker_post_start_final_process_spawn_executor_gate', 'require_post_start_process_spawn_enablement_metadata', 'require_post_start_evidence_acceptance_bridge_from_process_spawn_enablement', 'delegate_to_codex_process_spawn_executor_without_starting_codex', 'record_final_process_spawn_executor_bridge_on_observed_run'],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGateTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_final_process_spawn_executor_gate',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'post_start_final_process_spawn_executor_gate_file_creation_allowed_here' => false,
                'post_start_final_process_spawn_executor_bridge_allowed_by_service' => true,
                'codex_process_spawn_executor_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_external_process_runtime_contract' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-final-process-spawn-executor-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight' => $preflight,
            'codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight_does_not_start_codex', 'agent_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight_does_not_call_codex', 'agent_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight_does_not_dispatch_work'],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker post-start final process spawn executor gate is ready; it prepares final spawn executor and still requires external process runtime.'
                : 'Codex real invoker post-start final process spawn executor gate is blocked until spawn enablement, spawn executor and ledger prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartFinalProcessSpawnExecutorGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartFinalProcessSpawnExecutorGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight_hash');

        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start final process spawn executor gate', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate.php'], 'acceptance' => 'Gate consumes post-start process spawn enablement metadata and delegates to Codex process spawn executor without starting Codex.'],
            ['id' => 'T2', 'title' => 'Enforce final spawn executor bridge policy', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate.php'], 'acceptance' => 'Gate requires accepted post-start evidence bridge from process spawn enablement and prepares final executor while keeping external process runtime separate and all runtime/token/dispatch flags false.'],
            ['id' => 'T3', 'title' => 'Add post-start final process spawn executor tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGateTest.php'], 'acceptance' => 'Tests prove preparation, idempotency, duplicate rejection, missing bridge rejection, missing evidence bridge rejection, forbidden dispatch rejection, missing provider run rejection and rollback.'],
            ['id' => 'T4', 'title' => 'Expose post-start final process spawn executor readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare external process runtime still separate.'],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_final_process_spawn_executor_gate_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-FINAL-PROCESS-SPAWN-EXECUTOR-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker post-start final process spawn executor gate that bridges process spawn enablement to final spawn executor while forbidding actual process runtime.',
            'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_enable_adapter_execution', 'do_not_run_external_process_runtime'],
            'allowed_files' => data_get($preflight, 'allowed_future_files', []),
            'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'process_start', 'provider_process_call', 'adapter_execution_runtime', 'external_process_runtime', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => ['post_start_final_process_spawn_executor_gate_requires_spawn_enablement_bridge_metadata', 'post_start_final_process_spawn_executor_gate_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_final_process_spawn_executor_gate_delegates_to_codex_process_spawn_executor', 'post_start_final_process_spawn_executor_gate_records_observed_bridge_metadata', 'post_start_final_process_spawn_executor_gate_does_not_start_codex'],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'post_start_final_process_spawn_executor_bridge_allowed_by_service' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'adapter_invocation_allowed_by_packet' => false, 'adapter_execution_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_final_process_spawn_executor_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_final_process_spawn_executor_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_final_process_spawn_executor_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_final_process_spawn_executor_gate_implementation_packet' => $packet,
            'codex_real_invoker_post_start_final_process_spawn_executor_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_final_process_spawn_executor_gate_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_final_process_spawn_executor_gate_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_final_process_spawn_executor_gate_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_final_process_spawn_executor_gate_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start final process spawn executor gate implementation packet is ready; it prepares final executor and does not run external process runtime.',
        ];
    }


public function agentCodexRealInvokerPostStartExternalProcessRuntimeGateContractTemplate(array $options = []): array
    {
        $finalSpawnPayload = $this->section->agentCodexRealInvokerPostStartFinalProcessSpawnExecutorGatePreflight($options);
        $finalSpawn = (array) data_get($finalSpawnPayload, 'codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight', []);
        $runtimeDriverPayload = $this->section->agentCodexExternalProcessRuntimeDriverPreflight($options);
        $runtimeDriver = (array) data_get($runtimeDriverPayload, 'codex_external_process_runtime_driver_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_external_process_runtime_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-EXTERNAL-PROCESS-RUNTIME-GATE-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_final_process_spawn_executor_gate_preflight_hash' => data_get($finalSpawnPayload, 'codex_real_invoker_post_start_final_process_spawn_executor_gate_preflight_hash'),
                'codex_external_process_runtime_driver_preflight_hash' => data_get($runtimeDriverPayload, 'codex_external_process_runtime_driver_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_final_process_spawn_executor_gate_status' => data_get($finalSpawnPayload, 'status'),
            'source_codex_external_process_runtime_driver_status' => data_get($runtimeDriverPayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartExternalProcessRuntimeGate',
                'method' => 'preparePostStartExternalRuntime',
                'input_contract' => ['run_key', 'post_start_external_process_runtime_gate_id', 'post_start_final_process_spawn_executor_gate_id', 'post_start_process_spawn_enablement_gate_id', 'post_start_supervised_start_gate_id', 'post_start_process_start_release_gate_id', 'process_start_release_id', 'provider_execution_contract_gate_id', 'codex_execution_id', 'supervised_start_id', 'spawn_enablement_id', 'spawn_executor_id', 'runtime_driver_id', 'adapter_execution_guard_gate_id', 'execution_guard_id', 'adapter_invocation_boundary_gate_id', 'adapter_invocation_id', 'provider_start_driver_gate_id', 'provider_start_attempt_id', 'post_start_evidence_acceptance_bridge_id', 'signed_dispatch_receipt_hash', 'operator_release_receipt_hash', 'operator_spawn_receipt_hash', 'operator_final_spawn_receipt_hash', 'operator_runtime_receipt_hash', 'codex_execution_contract_hash', 'supervised_start_contract_hash', 'runtime_supervision_plan_hash', 'stdout_stderr_sink_hash', 'liveness_probe_hash', 'process_command_hash', 'environment_contract_hash', 'termination_policy_hash', 'actor', 'session', 'reason'],
                'result_contract' => ['post_start_external_process_runtime_gate_id', 'runtime_driver_id', 'spawn_executor_id', 'codex_execution_id', 'post_start_evidence_acceptance_bridge_id', 'external_runtime_driver_prepared', 'process_invocation_required', 'actual_process_start_allowed', 'dispatch_allowed'],
            ],
            'real_invoker_post_start_external_process_runtime_gate_must' => [
                'require_codex_real_invoker_post_start_final_process_spawn_executor_metadata',
                'require_post_start_evidence_acceptance_bridge_from_final_process_spawn_executor',
                'require_final_spawn_flags_blocking_process_token_dispatch',
                'delegate_to_codex_external_process_runtime_driver',
                'record_external_runtime_bridge_on_observed_post_start_run',
                'require_process_invocation_as_separate_later_contract',
            ],
            'real_invoker_post_start_external_process_runtime_gate_must_not' => [
                'call_codex_cli_or_codex_app',
                'spawn_process_or_shell_command',
                'spend_provider_tokens',
                'start_codex_process',
                'dispatch_work_to_codex',
                'enable_adapter_execution',
                'run_process_invocation',
                'mark_observed_run_running_or_terminal',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartExternalProcessRuntimeGate.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartExternalProcessRuntimeGateTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'post_start_external_process_runtime_bridge_allowed_by_service' => true,
                'codex_external_process_runtime_driver_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_process_invocation_contract' => true,
            ],
            'source_post_start_final_process_spawn_executor_gate_preflight' => $finalSpawn,
            'source_codex_external_process_runtime_driver_preflight' => $runtimeDriver,
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-external-process-runtime-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_external_process_runtime_gate_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_external_process_runtime_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_external_process_runtime_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_external_process_runtime_gate_contract_template' => $template,
            'codex_real_invoker_post_start_external_process_runtime_gate_contract_template_hash' => ReadinessHash::stable($template),
            'source_post_start_final_process_spawn_executor_gate_preflight' => $finalSpawn,
            'source_codex_external_process_runtime_driver_preflight' => $runtimeDriver,
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_external_process_runtime_gate_contract_template_does_not_start_codex', 'agent_codex_real_invoker_post_start_external_process_runtime_gate_contract_template_does_not_call_codex', 'agent_codex_real_invoker_post_start_external_process_runtime_gate_contract_template_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_external_process_runtime_gate_contract_template_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start external process runtime gate template bridges final spawn executor to external runtime preparation without invoking Codex.',
        ];
    }


public function agentCodexRealInvokerPostStartExternalProcessRuntimeGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartExternalProcessRuntimeGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_external_process_runtime_gate_contract_template', []);
        $gateReady = class_exists(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class);
        $finalSpawnReady = class_exists(AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate::class);
        $runtimeDriverReady = class_exists(AgentCodexExternalProcessRuntimeDriver::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $gateReady ? null : 'codex_real_invoker_post_start_external_process_runtime_gate_missing',
            $finalSpawnReady ? null : 'codex_real_invoker_post_start_final_process_spawn_executor_gate_missing',
            $runtimeDriverReady ? null : 'codex_external_process_runtime_driver_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_external_process_runtime_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_external_process_runtime_gate_contract_template_hash'),
            'source_codex_real_invoker_post_start_final_process_spawn_executor_gate_status' => data_get($contract, 'source_codex_real_invoker_post_start_final_process_spawn_executor_gate_status'),
            'source_codex_external_process_runtime_driver_status' => data_get($contract, 'source_codex_external_process_runtime_driver_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_post_start_external_process_runtime_gate_ready' => $gateReady,
                'codex_real_invoker_post_start_final_process_spawn_executor_gate_ready' => $finalSpawnReady,
                'codex_external_process_runtime_driver_ready' => $runtimeDriverReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_external_process_runtime_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => ['create_codex_real_invoker_post_start_external_process_runtime_gate', 'require_post_start_final_process_spawn_executor_metadata', 'require_post_start_evidence_acceptance_bridge_from_final_process_spawn_executor', 'delegate_to_codex_external_process_runtime_driver_without_invoking_codex', 'record_external_runtime_bridge_on_observed_run'],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartExternalProcessRuntimeGate.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartExternalProcessRuntimeGateTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_external_process_runtime_gate',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'post_start_external_process_runtime_gate_file_creation_allowed_here' => false,
                'post_start_external_process_runtime_bridge_allowed_by_service' => true,
                'codex_external_process_runtime_driver_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_process_invocation_contract' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-external-process-runtime-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_external_process_runtime_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_external_process_runtime_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_external_process_runtime_gate_preflight' => $preflight,
            'codex_real_invoker_post_start_external_process_runtime_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_external_process_runtime_gate_preflight_does_not_start_codex', 'agent_codex_real_invoker_post_start_external_process_runtime_gate_preflight_does_not_call_codex', 'agent_codex_real_invoker_post_start_external_process_runtime_gate_preflight_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_external_process_runtime_gate_preflight_does_not_dispatch_work'],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker post-start external process runtime gate is ready; it prepares runtime metadata and still requires process invocation authorization.'
                : 'Codex real invoker post-start external process runtime gate is blocked until final spawn executor, external runtime driver and ledger prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartExternalProcessRuntimeGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartExternalProcessRuntimeGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_external_process_runtime_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_external_process_runtime_gate_preflight_hash');

        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start external process runtime gate', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartExternalProcessRuntimeGate.php'], 'acceptance' => 'Gate consumes post-start final process spawn executor metadata and delegates to Codex external process runtime driver without invoking Codex.'],
            ['id' => 'T2', 'title' => 'Enforce external runtime bridge policy', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartExternalProcessRuntimeGate.php'], 'acceptance' => 'Gate requires accepted post-start evidence bridge from final process spawn executor and prepares external runtime metadata while keeping process invocation, token spend, adapter execution and dispatch flags false.'],
            ['id' => 'T3', 'title' => 'Add post-start external process runtime tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartExternalProcessRuntimeGateTest.php'], 'acceptance' => 'Tests prove preparation, idempotency, duplicate rejection, missing final spawn bridge rejection, missing evidence bridge rejection, forbidden dispatch rejection, missing provider run rejection and rollback.'],
            ['id' => 'T4', 'title' => 'Expose post-start external process runtime readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare process invocation still separate.'],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_external_process_runtime_gate_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-EXTERNAL-PROCESS-RUNTIME-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker post-start external process runtime gate that bridges final spawn executor to external runtime preparation while forbidding process invocation.',
            'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_enable_adapter_execution', 'do_not_run_process_invocation'],
            'allowed_files' => data_get($preflight, 'allowed_future_files', []),
            'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'process_invocation', 'provider_process_call', 'adapter_execution_runtime', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => ['post_start_external_process_runtime_gate_requires_final_spawn_executor_bridge_metadata', 'post_start_external_process_runtime_gate_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_external_process_runtime_gate_delegates_to_codex_external_process_runtime_driver', 'post_start_external_process_runtime_gate_records_observed_bridge_metadata', 'post_start_external_process_runtime_gate_does_not_invoke_codex'],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'post_start_external_process_runtime_bridge_allowed_by_service' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'adapter_invocation_allowed_by_packet' => false, 'adapter_execution_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false, 'requires_separate_process_invocation_contract' => true],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_external_process_runtime_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_external_process_runtime_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_external_process_runtime_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_external_process_runtime_gate_implementation_packet' => $packet,
            'codex_real_invoker_post_start_external_process_runtime_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_external_process_runtime_gate_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_external_process_runtime_gate_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_external_process_runtime_gate_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_external_process_runtime_gate_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start external process runtime gate implementation packet is ready; it prepares external runtime and does not run process invocation.',
        ];
    }


public function agentCodexRealInvokerPostStartProcessInvocationAuthorizationGateContractTemplate(array $options = []): array
    {
        $externalRuntimePayload = $this->section->agentCodexRealInvokerPostStartExternalProcessRuntimeGatePreflight($options);
        $externalRuntime = (array) data_get($externalRuntimePayload, 'codex_real_invoker_post_start_external_process_runtime_gate_preflight', []);
        $authorizationPayload = $this->section->agentCodexExternalProcessInvocationAuthorizationPreflight($options);
        $authorization = (array) data_get($authorizationPayload, 'codex_external_process_invocation_authorization_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_process_invocation_authorization_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-PROCESS-INVOCATION-AUTHORIZATION-GATE-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_external_process_runtime_gate_preflight_hash' => data_get($externalRuntimePayload, 'codex_real_invoker_post_start_external_process_runtime_gate_preflight_hash'),
                'codex_external_process_invocation_authorization_preflight_hash' => data_get($authorizationPayload, 'codex_external_process_invocation_authorization_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_external_process_runtime_gate_status' => data_get($externalRuntimePayload, 'status'),
            'source_codex_external_process_invocation_authorization_status' => data_get($authorizationPayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate',
                'method' => 'authorizePostStartProcessInvocation',
                'input_contract' => ['run_key', 'post_start_process_invocation_authorization_gate_id', 'post_start_external_process_runtime_gate_id', 'post_start_final_process_spawn_executor_gate_id', 'post_start_process_spawn_enablement_gate_id', 'post_start_supervised_start_gate_id', 'post_start_process_start_release_gate_id', 'process_start_release_id', 'provider_execution_contract_gate_id', 'codex_execution_id', 'supervised_start_id', 'spawn_enablement_id', 'spawn_executor_id', 'runtime_driver_id', 'invocation_authorization_id', 'adapter_execution_guard_gate_id', 'execution_guard_id', 'adapter_invocation_boundary_gate_id', 'adapter_invocation_id', 'provider_start_driver_gate_id', 'provider_start_attempt_id', 'post_start_evidence_acceptance_bridge_id', 'signed_dispatch_receipt_hash', 'operator_release_receipt_hash', 'operator_spawn_receipt_hash', 'operator_final_spawn_receipt_hash', 'operator_runtime_receipt_hash', 'operator_invocation_receipt_hash', 'codex_execution_contract_hash', 'supervised_start_contract_hash', 'runtime_supervision_plan_hash', 'stdout_stderr_sink_hash', 'liveness_probe_hash', 'runtime_driver_contract_hash', 'process_command_hash', 'environment_contract_hash', 'termination_policy_hash', 'actor', 'session', 'reason'],
                'result_contract' => ['post_start_process_invocation_authorization_gate_id', 'invocation_authorization_id', 'runtime_driver_id', 'spawn_executor_id', 'codex_execution_id', 'post_start_evidence_acceptance_bridge_id', 'external_process_invocation_authorized', 'external_process_invoker_dry_run_required', 'actual_process_start_allowed', 'dispatch_allowed'],
            ],
            'real_invoker_post_start_process_invocation_authorization_gate_must' => [
                'require_codex_real_invoker_post_start_external_process_runtime_metadata',
                'require_post_start_evidence_acceptance_bridge_from_external_process_runtime',
                'require_external_runtime_flags_blocking_process_token_dispatch',
                'delegate_to_codex_external_process_invocation_authorization_gate',
                'record_process_invocation_authorization_bridge_on_observed_post_start_run',
                'require_external_process_invoker_dry_run_as_separate_later_contract',
            ],
            'real_invoker_post_start_process_invocation_authorization_gate_must_not' => [
                'call_codex_cli_or_codex_app',
                'spawn_process_or_shell_command',
                'spend_provider_tokens',
                'start_codex_process',
                'dispatch_work_to_codex',
                'enable_adapter_execution',
                'run_external_process_invoker_dry_run',
                'mark_observed_run_running_or_terminal',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartProcessInvocationAuthorizationGateTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'post_start_process_invocation_authorization_bridge_allowed_by_service' => true,
                'codex_external_process_invocation_authorization_gate_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_external_process_invoker_dry_run_contract' => true,
            ],
            'source_post_start_external_process_runtime_gate_preflight' => $externalRuntime,
            'source_codex_external_process_invocation_authorization_preflight' => $authorization,
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-process-invocation-authorization-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_process_invocation_authorization_gate_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_process_invocation_authorization_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_process_invocation_authorization_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_process_invocation_authorization_gate_contract_template' => $template,
            'codex_real_invoker_post_start_process_invocation_authorization_gate_contract_template_hash' => ReadinessHash::stable($template),
            'source_post_start_external_process_runtime_gate_preflight' => $externalRuntime,
            'source_codex_external_process_invocation_authorization_preflight' => $authorization,
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_process_invocation_authorization_gate_contract_template_does_not_start_codex', 'agent_codex_real_invoker_post_start_process_invocation_authorization_gate_contract_template_does_not_call_codex', 'agent_codex_real_invoker_post_start_process_invocation_authorization_gate_contract_template_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_process_invocation_authorization_gate_contract_template_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start process invocation authorization gate template bridges external runtime preparation to invocation authorization without invoking Codex.',
        ];
    }


public function agentCodexRealInvokerPostStartProcessInvocationAuthorizationGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartProcessInvocationAuthorizationGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_process_invocation_authorization_gate_contract_template', []);
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate::class);
        $externalRuntimeReady = class_exists(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class);
        $authorizationReady = class_exists(AgentCodexExternalProcessInvocationAuthorizationGate::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $gateReady ? null : 'codex_real_invoker_post_start_process_invocation_authorization_gate_missing',
            $externalRuntimeReady ? null : 'codex_real_invoker_post_start_external_process_runtime_gate_missing',
            $authorizationReady ? null : 'codex_external_process_invocation_authorization_gate_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_process_invocation_authorization_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_process_invocation_authorization_gate_contract_template_hash'),
            'source_codex_real_invoker_post_start_external_process_runtime_gate_status' => data_get($contract, 'source_codex_real_invoker_post_start_external_process_runtime_gate_status'),
            'source_codex_external_process_invocation_authorization_status' => data_get($contract, 'source_codex_external_process_invocation_authorization_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_post_start_process_invocation_authorization_gate_ready' => $gateReady,
                'codex_real_invoker_post_start_external_process_runtime_gate_ready' => $externalRuntimeReady,
                'codex_external_process_invocation_authorization_gate_ready' => $authorizationReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_process_invocation_authorization_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => ['create_codex_real_invoker_post_start_process_invocation_authorization_gate', 'require_post_start_external_process_runtime_metadata', 'require_post_start_evidence_acceptance_bridge_from_external_process_runtime', 'delegate_to_codex_external_process_invocation_authorization_gate_without_invoking_codex', 'record_process_invocation_authorization_bridge_on_observed_run'],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartProcessInvocationAuthorizationGateTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_process_invocation_authorization_gate',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'post_start_process_invocation_authorization_gate_file_creation_allowed_here' => false,
                'post_start_process_invocation_authorization_bridge_allowed_by_service' => true,
                'codex_external_process_invocation_authorization_gate_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_external_process_invoker_dry_run_contract' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-process-invocation-authorization-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_process_invocation_authorization_gate_preflight' => $preflight,
            'codex_real_invoker_post_start_process_invocation_authorization_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight_does_not_start_codex', 'agent_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight_does_not_call_codex', 'agent_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight_does_not_dispatch_work'],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker post-start process invocation authorization gate is ready; it records authorization and still requires invoker dry-run.'
                : 'Codex real invoker post-start process invocation authorization gate is blocked until external runtime, authorization gate and ledger prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartProcessInvocationAuthorizationGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartProcessInvocationAuthorizationGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_process_invocation_authorization_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_process_invocation_authorization_gate_preflight_hash');

        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start process invocation authorization gate', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate.php'], 'acceptance' => 'Gate consumes post-start external runtime metadata and delegates to Codex external process invocation authorization gate without invoking Codex.'],
            ['id' => 'T2', 'title' => 'Enforce process invocation authorization bridge policy', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate.php'], 'acceptance' => 'Gate requires accepted post-start evidence bridge from external runtime and records authorization metadata while keeping process start, token spend, adapter execution and dispatch flags false.'],
            ['id' => 'T3', 'title' => 'Add post-start process invocation authorization tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartProcessInvocationAuthorizationGateTest.php'], 'acceptance' => 'Tests prove preparation, idempotency, duplicate rejection, missing external runtime bridge rejection, missing evidence bridge rejection, forbidden dispatch rejection, missing provider run rejection and rollback.'],
            ['id' => 'T4', 'title' => 'Expose post-start process invocation authorization readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare invoker dry-run still separate.'],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_process_invocation_authorization_gate_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-PROCESS-INVOCATION-AUTHORIZATION-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker post-start process invocation authorization gate that bridges external runtime preparation to invocation authorization while forbidding invoker execution.',
            'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_enable_adapter_execution', 'do_not_run_external_process_invoker_dry_run'],
            'allowed_files' => data_get($preflight, 'allowed_future_files', []),
            'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'external_process_invoker_dry_run', 'provider_process_call', 'adapter_execution_runtime', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => ['post_start_process_invocation_authorization_gate_requires_external_runtime_bridge_metadata', 'post_start_process_invocation_authorization_gate_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_process_invocation_authorization_gate_delegates_to_codex_external_process_invocation_authorization_gate', 'post_start_process_invocation_authorization_gate_records_observed_bridge_metadata', 'post_start_process_invocation_authorization_gate_does_not_invoke_codex'],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'post_start_process_invocation_authorization_bridge_allowed_by_service' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'adapter_invocation_allowed_by_packet' => false, 'adapter_execution_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false, 'requires_separate_external_process_invoker_dry_run_contract' => true],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_process_invocation_authorization_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_process_invocation_authorization_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_process_invocation_authorization_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_process_invocation_authorization_gate_implementation_packet' => $packet,
            'codex_real_invoker_post_start_process_invocation_authorization_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_process_invocation_authorization_gate_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_process_invocation_authorization_gate_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_process_invocation_authorization_gate_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_process_invocation_authorization_gate_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start process invocation authorization gate implementation packet is ready; it records authorization and does not run invoker dry-run.',
        ];
    }


public function agentCodexRealInvokerPostStartExternalProcessInvokerDryRunGateContractTemplate(array $options = []): array
    {
        $authorizationPayload = $this->section->agentCodexRealInvokerPostStartProcessInvocationAuthorizationGatePreflight($options);
        $authorization = (array) data_get($authorizationPayload, 'codex_real_invoker_post_start_process_invocation_authorization_gate_preflight', []);
        $dryRunPayload = $this->section->agentCodexExternalProcessInvokerDryRunPreflight($options);
        $dryRun = (array) data_get($dryRunPayload, 'codex_external_process_invoker_dry_run_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-EXTERNAL-PROCESS-INVOKER-DRY-RUN-GATE-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_process_invocation_authorization_gate_preflight_hash' => data_get($authorizationPayload, 'codex_real_invoker_post_start_process_invocation_authorization_gate_preflight_hash'),
                'codex_external_process_invoker_dry_run_preflight_hash' => data_get($dryRunPayload, 'codex_external_process_invoker_dry_run_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_process_invocation_authorization_gate_status' => data_get($authorizationPayload, 'status'),
            'source_codex_external_process_invoker_dry_run_status' => data_get($dryRunPayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate',
                'method' => 'preparePostStartExternalProcessInvokerDryRun',
                'input_contract' => ['run_key', 'post_start_external_process_invoker_dry_run_gate_id', 'post_start_process_invocation_authorization_gate_id', 'post_start_external_process_runtime_gate_id', 'post_start_final_process_spawn_executor_gate_id', 'post_start_process_spawn_enablement_gate_id', 'post_start_supervised_start_gate_id', 'post_start_process_start_release_gate_id', 'process_start_release_id', 'provider_execution_contract_gate_id', 'codex_execution_id', 'supervised_start_id', 'spawn_enablement_id', 'spawn_executor_id', 'runtime_driver_id', 'invocation_authorization_id', 'dry_run_id', 'adapter_execution_guard_gate_id', 'execution_guard_id', 'adapter_invocation_boundary_gate_id', 'adapter_invocation_id', 'provider_start_driver_gate_id', 'provider_start_attempt_id', 'post_start_evidence_acceptance_bridge_id', 'signed_dispatch_receipt_hash', 'operator_release_receipt_hash', 'operator_spawn_receipt_hash', 'operator_final_spawn_receipt_hash', 'operator_runtime_receipt_hash', 'operator_invocation_receipt_hash', 'operator_dry_run_receipt_hash', 'codex_execution_contract_hash', 'supervised_start_contract_hash', 'runtime_supervision_plan_hash', 'stdout_stderr_sink_hash', 'liveness_probe_hash', 'runtime_driver_contract_hash', 'invoker_contract_hash', 'process_command_hash', 'environment_contract_hash', 'termination_policy_hash', 'actor', 'session', 'reason'],
                'result_contract' => ['post_start_external_process_invoker_dry_run_gate_id', 'dry_run_id', 'invocation_authorization_id', 'runtime_driver_id', 'spawn_executor_id', 'codex_execution_id', 'post_start_evidence_acceptance_bridge_id', 'external_process_invoker_dry_run_prepared', 'real_invoker_execution_gate_required', 'actual_process_start_allowed', 'dispatch_allowed'],
            ],
            'real_invoker_post_start_external_process_invoker_dry_run_gate_must' => [
                'require_codex_real_invoker_post_start_process_invocation_authorization_metadata',
                'require_post_start_evidence_acceptance_bridge_from_process_invocation_authorization',
                'require_invocation_authorization_flags_blocking_process_token_dispatch',
                'delegate_to_codex_external_process_invoker_dry_run',
                'record_external_process_invoker_dry_run_bridge_on_observed_post_start_run',
                'require_real_invoker_execution_as_separate_later_contract',
            ],
            'real_invoker_post_start_external_process_invoker_dry_run_gate_must_not' => [
                'call_codex_cli_or_codex_app',
                'spawn_process_or_shell_command',
                'spend_provider_tokens',
                'start_codex_process',
                'dispatch_work_to_codex',
                'enable_adapter_execution',
                'run_real_external_process_invoker',
                'mark_observed_run_running_or_terminal',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGateTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'post_start_external_process_invoker_dry_run_bridge_allowed_by_service' => true,
                'codex_external_process_invoker_dry_run_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_real_invoker_execution_contract' => true,
            ],
            'source_post_start_process_invocation_authorization_gate_preflight' => $authorization,
            'source_codex_external_process_invoker_dry_run_preflight' => $dryRun,
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-external-process-invoker-dry-run-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract_template' => $template,
            'codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract_template_hash' => ReadinessHash::stable($template),
            'source_post_start_process_invocation_authorization_gate_preflight' => $authorization,
            'source_codex_external_process_invoker_dry_run_preflight' => $dryRun,
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract_template_does_not_start_codex', 'agent_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract_template_does_not_call_codex', 'agent_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract_template_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract_template_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start external process invoker dry-run gate template bridges invocation authorization to dry-run preparation without invoking Codex.',
        ];
    }


public function agentCodexRealInvokerPostStartExternalProcessInvokerDryRunGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartExternalProcessInvokerDryRunGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract_template', []);
        $gateReady = class_exists(AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate::class);
        $authorizationReady = class_exists(AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate::class);
        $dryRunReady = class_exists(AgentCodexExternalProcessInvokerDryRun::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $gateReady ? null : 'codex_real_invoker_post_start_external_process_invoker_dry_run_gate_missing',
            $authorizationReady ? null : 'codex_real_invoker_post_start_process_invocation_authorization_gate_missing',
            $dryRunReady ? null : 'codex_external_process_invoker_dry_run_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_external_process_invoker_dry_run_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract_template_hash'),
            'source_codex_real_invoker_post_start_process_invocation_authorization_gate_status' => data_get($contract, 'source_codex_real_invoker_post_start_process_invocation_authorization_gate_status'),
            'source_codex_external_process_invoker_dry_run_status' => data_get($contract, 'source_codex_external_process_invoker_dry_run_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_post_start_external_process_invoker_dry_run_gate_ready' => $gateReady,
                'codex_real_invoker_post_start_process_invocation_authorization_gate_ready' => $authorizationReady,
                'codex_external_process_invoker_dry_run_ready' => $dryRunReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_external_process_invoker_dry_run_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => ['create_codex_real_invoker_post_start_external_process_invoker_dry_run_gate', 'require_post_start_process_invocation_authorization_metadata', 'require_post_start_evidence_acceptance_bridge_from_process_invocation_authorization', 'delegate_to_codex_external_process_invoker_dry_run_without_invoking_codex', 'record_external_process_invoker_dry_run_bridge_on_observed_run'],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGateTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_external_process_invoker_dry_run_gate',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'post_start_external_process_invoker_dry_run_gate_file_creation_allowed_here' => false,
                'post_start_external_process_invoker_dry_run_bridge_allowed_by_service' => true,
                'codex_external_process_invoker_dry_run_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_real_invoker_execution_contract' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-external-process-invoker-dry-run-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight' => $preflight,
            'codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight_does_not_start_codex', 'agent_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight_does_not_call_codex', 'agent_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight_does_not_dispatch_work'],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker post-start external process invoker dry-run gate is ready; it prepares dry-run and still requires real invoker execution gate.'
                : 'Codex real invoker post-start external process invoker dry-run gate is blocked until authorization, dry-run and ledger prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartExternalProcessInvokerDryRunGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartExternalProcessInvokerDryRunGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight_hash');

        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start external process invoker dry-run gate', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate.php'], 'acceptance' => 'Gate consumes post-start process invocation authorization metadata and delegates to Codex external process invoker dry-run without invoking Codex.'],
            ['id' => 'T2', 'title' => 'Enforce post-start dry-run bridge policy', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate.php'], 'acceptance' => 'Gate requires accepted post-start evidence bridge from process invocation authorization and records dry-run metadata while keeping process start, token spend, adapter execution and dispatch flags false.'],
            ['id' => 'T3', 'title' => 'Add post-start external process invoker dry-run tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGateTest.php'], 'acceptance' => 'Tests prove dry-run bridge preparation, idempotency, duplicate rejection, missing authorization bridge rejection, missing evidence bridge rejection, forbidden dispatch rejection, missing provider run rejection and rollback.'],
            ['id' => 'T4', 'title' => 'Expose post-start external process invoker dry-run readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare real invoker execution still separate.'],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-EXTERNAL-PROCESS-INVOKER-DRY-RUN-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker post-start external process invoker dry-run gate that bridges invocation authorization to dry-run preparation while forbidding real invoker execution.',
            'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_enable_adapter_execution', 'do_not_run_real_external_process_invoker'],
            'allowed_files' => data_get($preflight, 'allowed_future_files', []),
            'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'real_external_process_invoker_execution', 'provider_process_call', 'adapter_execution_runtime', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => ['post_start_external_process_invoker_dry_run_gate_requires_process_invocation_authorization_bridge_metadata', 'post_start_external_process_invoker_dry_run_gate_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_external_process_invoker_dry_run_gate_delegates_to_codex_external_process_invoker_dry_run', 'post_start_external_process_invoker_dry_run_gate_records_observed_bridge_metadata', 'post_start_external_process_invoker_dry_run_gate_does_not_invoke_codex'],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'post_start_external_process_invoker_dry_run_bridge_allowed_by_service' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'adapter_invocation_allowed_by_packet' => false, 'adapter_execution_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false, 'requires_separate_real_invoker_execution_contract' => true],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_external_process_invoker_dry_run_gate_implementation_packet' => $packet,
            'codex_real_invoker_post_start_external_process_invoker_dry_run_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start external process invoker dry-run gate implementation packet is ready; it prepares dry-run and does not run real invoker execution.',
        ];
    }


}
