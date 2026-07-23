<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\Codex;

use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentDispatchProviderSection;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerActualProcessStartRehearsalExecutor;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerExecutorEnablementGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerExecutorFreshReleaseGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerExecutorPlan;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerFinalProcessStartAuthorizationGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerGuardedProcessStartExecutor;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerImplementationBoundary;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartActualProcessStartRehearsalGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartExecutorEnablementGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartExecutorFreshReleaseGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartExecutorPlanGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartGuardedProcessStartExecutorGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartImplementationBoundaryGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartSupervisedStartActivationGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerReleasePreflight;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerSupervisedStartActivationGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexSignedRealInvokerReleaseGate;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;
use Illuminate\Support\Facades\Schema;

/**
 * AGENT CODEX projection sub-section 10 of 11, sub-split from the god
 * {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling agentCodex* pipeline calls route through the
 * injected Section facade (`$this->section->agentCodex*`), which re-dispatches
 * to whichever sub-section owns the target stage. The dispatch-provider
 * collaborator is injected verbatim for the one stage that consumes it.
 *
 * Stage range: agentCodexRealInvokerPostStartRealInvokerReleasePreflightGateContractTemplate
 *           .. agentCodexRealInvokerPostStartActualProcessStartRehearsalGateImplementationPacket
 */
final class CodexPart10SubSection
{
    public function __construct(
        private readonly ReadinessProjectionAgentCodexSection $section,
        private readonly ReadinessProjectionAgentDispatchProviderSection $agentDispatchProviderSection,
    ) {}

public function agentCodexRealInvokerPostStartRealInvokerReleasePreflightGateContractTemplate(array $options = []): array
    {
        $dryRunPayload = $this->section->agentCodexRealInvokerPostStartExternalProcessInvokerDryRunGatePreflight($options);
        $dryRun = (array) data_get($dryRunPayload, 'codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight', []);
        $releasePreflightPayload = $this->section->agentCodexRealInvokerReleasePreflightPreflight($options);
        $releasePreflight = (array) data_get($releasePreflightPayload, 'codex_real_invoker_release_preflight_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-REAL-INVOKER-RELEASE-PREFLIGHT-GATE-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_external_process_invoker_dry_run_gate_preflight_hash' => data_get($dryRunPayload, 'codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight_hash'),
                'codex_real_invoker_release_preflight_hash' => data_get($releasePreflightPayload, 'codex_real_invoker_release_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status' => data_get($dryRunPayload, 'status'),
            'source_codex_real_invoker_release_preflight_status' => data_get($releasePreflightPayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate',
                'method' => 'recordPostStartRealInvokerReleasePreflight',
                'input_contract' => ['run_key', 'post_start_real_invoker_release_preflight_gate_id', 'post_start_external_process_invoker_dry_run_gate_id', 'post_start_process_invocation_authorization_gate_id', 'post_start_external_process_runtime_gate_id', 'post_start_final_process_spawn_executor_gate_id', 'post_start_process_spawn_enablement_gate_id', 'post_start_supervised_start_gate_id', 'post_start_process_start_release_gate_id', 'process_start_release_id', 'provider_execution_contract_gate_id', 'codex_execution_id', 'supervised_start_id', 'spawn_enablement_id', 'spawn_executor_id', 'runtime_driver_id', 'invocation_authorization_id', 'dry_run_id', 'real_invoker_release_preflight_id', 'adapter_execution_guard_gate_id', 'execution_guard_id', 'adapter_invocation_boundary_gate_id', 'adapter_invocation_id', 'provider_start_driver_gate_id', 'provider_start_attempt_id', 'post_start_evidence_acceptance_bridge_id', 'signed_dispatch_receipt_hash', 'operator_release_receipt_hash', 'operator_spawn_receipt_hash', 'operator_final_spawn_receipt_hash', 'operator_runtime_receipt_hash', 'operator_invocation_receipt_hash', 'operator_dry_run_receipt_hash', 'operator_release_preflight_receipt_hash', 'codex_execution_contract_hash', 'supervised_start_contract_hash', 'runtime_supervision_plan_hash', 'stdout_stderr_sink_hash', 'liveness_probe_hash', 'runtime_driver_contract_hash', 'invoker_contract_hash', 'real_invoker_contract_hash', 'process_command_hash', 'environment_contract_hash', 'termination_policy_hash', 'rollback_plan_hash', 'max_runtime_policy_hash', 'actor', 'session', 'reason'],
                'result_contract' => ['post_start_real_invoker_release_preflight_gate_id', 'real_invoker_release_preflight_id', 'dry_run_id', 'invocation_authorization_id', 'runtime_driver_id', 'codex_execution_id', 'post_start_evidence_acceptance_bridge_id', 'real_invoker_release_preflight_passed', 'signed_release_gate_required', 'actual_process_start_allowed', 'dispatch_allowed'],
            ],
            'real_invoker_post_start_release_preflight_gate_must' => [
                'require_codex_real_invoker_post_start_external_process_invoker_dry_run_metadata',
                'require_post_start_evidence_acceptance_bridge_from_external_process_invoker_dry_run',
                'require_dry_run_flags_blocking_process_token_dispatch',
                'delegate_to_codex_real_invoker_release_preflight',
                'record_real_invoker_release_preflight_bridge_on_observed_post_start_run',
                'require_signed_release_gate_as_separate_later_contract',
            ],
            'real_invoker_post_start_release_preflight_gate_must_not' => [
                'call_codex_cli_or_codex_app',
                'spawn_process_or_shell_command',
                'spend_provider_tokens',
                'start_codex_process',
                'dispatch_work_to_codex',
                'enable_adapter_execution',
                'sign_real_invoker_release',
                'run_real_external_process_invoker',
                'mark_observed_run_running_or_terminal',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartRealInvokerReleasePreflightGateTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'post_start_real_invoker_release_preflight_bridge_allowed_by_service' => true,
                'codex_real_invoker_release_preflight_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_signed_release_gate_contract' => true,
            ],
            'source_post_start_external_process_invoker_dry_run_gate_preflight' => $dryRun,
            'source_codex_real_invoker_release_preflight_preflight' => $releasePreflight,
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-real-invoker-release-preflight-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract_template' => $template,
            'codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract_template_hash' => ReadinessHash::stable($template),
            'source_post_start_external_process_invoker_dry_run_gate_preflight' => $dryRun,
            'source_codex_real_invoker_release_preflight_preflight' => $releasePreflight,
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract_template_does_not_start_codex', 'agent_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract_template_does_not_call_codex', 'agent_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract_template_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract_template_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start release preflight gate template bridges dry-run preparation to release preflight without invoking Codex.',
        ];
    }


public function agentCodexRealInvokerPostStartRealInvokerReleasePreflightGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartRealInvokerReleasePreflightGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract_template', []);
        $gateReady = class_exists(AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class);
        $dryRunGateReady = class_exists(AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate::class);
        $releasePreflightReady = class_exists(AgentCodexRealInvokerReleasePreflight::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $gateReady ? null : 'codex_real_invoker_post_start_real_invoker_release_preflight_gate_missing',
            $dryRunGateReady ? null : 'codex_real_invoker_post_start_external_process_invoker_dry_run_gate_missing',
            $releasePreflightReady ? null : 'codex_real_invoker_release_preflight_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_real_invoker_release_preflight_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract_template_hash'),
            'source_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status' => data_get($contract, 'source_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status'),
            'source_codex_real_invoker_release_preflight_status' => data_get($contract, 'source_codex_real_invoker_release_preflight_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_post_start_real_invoker_release_preflight_gate_ready' => $gateReady,
                'codex_real_invoker_post_start_external_process_invoker_dry_run_gate_ready' => $dryRunGateReady,
                'codex_real_invoker_release_preflight_ready' => $releasePreflightReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_real_invoker_release_preflight_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => ['create_codex_real_invoker_post_start_real_invoker_release_preflight_gate', 'require_post_start_external_process_invoker_dry_run_metadata', 'require_post_start_evidence_acceptance_bridge_from_external_process_invoker_dry_run', 'delegate_to_codex_real_invoker_release_preflight_without_invoking_codex', 'record_release_preflight_bridge_on_observed_run'],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartRealInvokerReleasePreflightGateTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_real_invoker_release_preflight_gate',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'post_start_real_invoker_release_preflight_gate_file_creation_allowed_here' => false,
                'post_start_real_invoker_release_preflight_bridge_allowed_by_service' => true,
                'codex_real_invoker_release_preflight_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_signed_release_gate_contract' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-real-invoker-release-preflight-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_real_invoker_release_preflight_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_real_invoker_release_preflight_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_real_invoker_release_preflight_gate_preflight' => $preflight,
            'codex_real_invoker_post_start_real_invoker_release_preflight_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_real_invoker_release_preflight_gate_preflight_does_not_start_codex', 'agent_codex_real_invoker_post_start_real_invoker_release_preflight_gate_preflight_does_not_call_codex', 'agent_codex_real_invoker_post_start_real_invoker_release_preflight_gate_preflight_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_real_invoker_release_preflight_gate_preflight_does_not_dispatch_work'],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker post-start release preflight gate is ready; it records preflight and still requires signed release gate.'
                : 'Codex real invoker post-start release preflight gate is blocked until dry-run, release preflight and ledger prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartRealInvokerReleasePreflightGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartRealInvokerReleasePreflightGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_real_invoker_release_preflight_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_real_invoker_release_preflight_gate_preflight_hash');

        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start release preflight gate', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate.php'], 'acceptance' => 'Gate consumes post-start external process invoker dry-run metadata and delegates to Codex real invoker release preflight without invoking Codex.'],
            ['id' => 'T2', 'title' => 'Enforce post-start release preflight bridge policy', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate.php'], 'acceptance' => 'Gate requires accepted post-start evidence bridge from external process invoker dry-run and records release preflight metadata while keeping process start, token spend, adapter execution and dispatch flags false.'],
            ['id' => 'T3', 'title' => 'Add post-start release preflight tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartRealInvokerReleasePreflightGateTest.php'], 'acceptance' => 'Tests prove release preflight bridge preparation, idempotency, duplicate rejection, missing dry-run bridge rejection, missing evidence bridge rejection, forbidden dispatch rejection, missing provider run rejection and rollback.'],
            ['id' => 'T4', 'title' => 'Expose post-start release preflight readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare signed release gate still separate.'],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_real_invoker_release_preflight_gate_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-REAL-INVOKER-RELEASE-PREFLIGHT-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker post-start release preflight gate that bridges dry-run preparation to release preflight while forbidding signed release and real invoker execution.',
            'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_enable_adapter_execution', 'do_not_sign_real_invoker_release', 'do_not_run_real_external_process_invoker'],
            'allowed_files' => data_get($preflight, 'allowed_future_files', []),
            'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'signed_real_invoker_release', 'real_external_process_invoker_execution', 'provider_process_call', 'adapter_execution_runtime', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => ['post_start_real_invoker_release_preflight_gate_requires_external_process_invoker_dry_run_bridge_metadata', 'post_start_real_invoker_release_preflight_gate_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_real_invoker_release_preflight_gate_delegates_to_codex_real_invoker_release_preflight', 'post_start_real_invoker_release_preflight_gate_records_observed_bridge_metadata', 'post_start_real_invoker_release_preflight_gate_does_not_invoke_codex'],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'post_start_real_invoker_release_preflight_bridge_allowed_by_service' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'adapter_invocation_allowed_by_packet' => false, 'adapter_execution_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false, 'requires_separate_signed_release_gate_contract' => true],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_real_invoker_release_preflight_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_real_invoker_release_preflight_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_real_invoker_release_preflight_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_real_invoker_release_preflight_gate_implementation_packet' => $packet,
            'codex_real_invoker_post_start_real_invoker_release_preflight_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_real_invoker_release_preflight_gate_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_real_invoker_release_preflight_gate_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_real_invoker_release_preflight_gate_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_real_invoker_release_preflight_gate_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start release preflight gate implementation packet is ready; it records preflight and does not sign release or run real invoker execution.',
        ];
    }


public function agentCodexRealInvokerPostStartSignedRealInvokerReleaseGateContractTemplate(array $options = []): array
    {
        $postStartReleasePreflightPayload = $this->section->agentCodexRealInvokerPostStartRealInvokerReleasePreflightGatePreflight($options);
        $postStartReleasePreflight = (array) data_get($postStartReleasePreflightPayload, 'codex_real_invoker_post_start_real_invoker_release_preflight_gate_preflight', []);
        $signedReleasePayload = $this->section->agentCodexSignedRealInvokerReleaseGatePreflight($options);
        $signedRelease = (array) data_get($signedReleasePayload, 'codex_signed_real_invoker_release_gate_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_signed_real_invoker_release_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-SIGNED-REAL-INVOKER-RELEASE-GATE-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_release_preflight_hash' => data_get($postStartReleasePreflightPayload, 'codex_real_invoker_post_start_real_invoker_release_preflight_gate_preflight_hash'),
                'signed_release_preflight_hash' => data_get($signedReleasePayload, 'codex_signed_real_invoker_release_gate_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status' => data_get($postStartReleasePreflightPayload, 'status'),
            'source_codex_signed_real_invoker_release_gate_status' => data_get($signedReleasePayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate',
                'method' => 'authorizePostStartSignedRealInvokerRelease',
                'input_contract' => ['run_key', 'post_start_signed_real_invoker_release_gate_id', 'post_start_real_invoker_release_preflight_gate_id', 'post_start_external_process_invoker_dry_run_gate_id', 'post_start_process_invocation_authorization_gate_id', 'post_start_external_process_runtime_gate_id', 'post_start_final_process_spawn_executor_gate_id', 'post_start_process_spawn_enablement_gate_id', 'post_start_supervised_start_gate_id', 'post_start_process_start_release_gate_id', 'process_start_release_id', 'provider_execution_contract_gate_id', 'codex_execution_id', 'supervised_start_id', 'spawn_enablement_id', 'spawn_executor_id', 'runtime_driver_id', 'invocation_authorization_id', 'dry_run_id', 'real_invoker_release_preflight_id', 'signed_real_invoker_release_id', 'adapter_execution_guard_gate_id', 'execution_guard_id', 'adapter_invocation_boundary_gate_id', 'adapter_invocation_id', 'provider_start_driver_gate_id', 'provider_start_attempt_id', 'post_start_evidence_acceptance_bridge_id', 'signed_dispatch_receipt_hash', 'operator_release_receipt_hash', 'operator_spawn_receipt_hash', 'operator_final_spawn_receipt_hash', 'operator_runtime_receipt_hash', 'operator_invocation_receipt_hash', 'operator_dry_run_receipt_hash', 'operator_release_preflight_receipt_hash', 'operator_signed_release_receipt_hash', 'signature_verification_report_hash', 'codex_execution_contract_hash', 'supervised_start_contract_hash', 'runtime_supervision_plan_hash', 'stdout_stderr_sink_hash', 'liveness_probe_hash', 'runtime_driver_contract_hash', 'invoker_contract_hash', 'real_invoker_contract_hash', 'release_policy_hash', 'process_command_hash', 'environment_contract_hash', 'termination_policy_hash', 'rollback_plan_hash', 'max_runtime_policy_hash', 'actor', 'session', 'reason'],
                'result_contract' => ['post_start_signed_real_invoker_release_gate_id', 'signed_real_invoker_release_id', 'real_invoker_release_preflight_id', 'dry_run_id', 'codex_execution_id', 'post_start_evidence_acceptance_bridge_id', 'signed_real_invoker_release_authorized', 'implementation_boundary_required', 'actual_process_start_allowed', 'dispatch_allowed'],
            ],
            'real_invoker_post_start_signed_release_gate_must' => [
                'require_codex_real_invoker_post_start_real_invoker_release_preflight_metadata',
                'require_post_start_evidence_acceptance_bridge_from_real_invoker_release_preflight',
                'require_release_preflight_flags_blocking_process_token_dispatch',
                'delegate_to_codex_signed_real_invoker_release_gate',
                'record_signed_release_bridge_on_observed_post_start_run',
                'require_implementation_boundary_as_separate_later_contract',
            ],
            'real_invoker_post_start_signed_release_gate_must_not' => [
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
                'app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartSignedRealInvokerReleaseGateTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'post_start_signed_real_invoker_release_bridge_allowed_by_service' => true,
                'codex_signed_real_invoker_release_gate_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_boundary_contract' => true,
            ],
            'source_post_start_real_invoker_release_preflight_gate_preflight' => $postStartReleasePreflight,
            'source_codex_signed_real_invoker_release_gate_preflight' => $signedRelease,
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-signed-real-invoker-release-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_signed_real_invoker_release_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_signed_real_invoker_release_gate_contract_template' => $template,
            'codex_real_invoker_post_start_signed_real_invoker_release_gate_contract_template_hash' => ReadinessHash::stable($template),
            'source_post_start_real_invoker_release_preflight_gate_preflight' => $postStartReleasePreflight,
            'source_codex_signed_real_invoker_release_gate_preflight' => $signedRelease,
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract_template_does_not_start_codex', 'agent_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract_template_does_not_call_codex', 'agent_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract_template_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract_template_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start signed release gate template bridges release preflight to signed release authorization without invoking Codex.',
        ];
    }


public function agentCodexRealInvokerPostStartSignedRealInvokerReleaseGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartSignedRealInvokerReleaseGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_signed_real_invoker_release_gate_contract_template', []);
        $gateReady = class_exists(AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate::class);
        $postStartReleasePreflightReady = class_exists(AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class);
        $signedReleaseReady = class_exists(AgentCodexSignedRealInvokerReleaseGate::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([
            $gateReady ? null : 'codex_real_invoker_post_start_signed_real_invoker_release_gate_missing',
            $postStartReleasePreflightReady ? null : 'codex_real_invoker_post_start_real_invoker_release_preflight_gate_missing',
            $signedReleaseReady ? null : 'codex_signed_real_invoker_release_gate_missing',
            $runsTableReady ? null : 'agent_runs_table_missing',
            $ledgerReady ? null : 'ledger_table_missing',
        ]));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_signed_real_invoker_release_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_signed_real_invoker_release_gate_contract_template_hash'),
            'source_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status' => data_get($contract, 'source_codex_real_invoker_post_start_real_invoker_release_preflight_gate_status'),
            'source_codex_signed_real_invoker_release_gate_status' => data_get($contract, 'source_codex_signed_real_invoker_release_gate_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => [
                'codex_real_invoker_post_start_signed_real_invoker_release_gate_ready' => $gateReady,
                'codex_real_invoker_post_start_real_invoker_release_preflight_gate_ready' => $postStartReleasePreflightReady,
                'codex_signed_real_invoker_release_gate_ready' => $signedReleaseReady,
                'agent_runs_table_ready' => $runsTableReady,
                'ledger_table_ready' => $ledgerReady,
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_signed_real_invoker_release_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => ['create_codex_real_invoker_post_start_signed_real_invoker_release_gate', 'require_post_start_real_invoker_release_preflight_metadata', 'require_post_start_evidence_acceptance_bridge_from_real_invoker_release_preflight', 'delegate_to_codex_signed_real_invoker_release_gate_without_invoking_codex', 'record_signed_release_bridge_on_observed_run'],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartSignedRealInvokerReleaseGateTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_signed_real_invoker_release_gate',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'post_start_signed_real_invoker_release_gate_file_creation_allowed_here' => false,
                'post_start_signed_real_invoker_release_bridge_allowed_by_service' => true,
                'codex_signed_real_invoker_release_gate_allowed_by_service' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'codex_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'requires_separate_implementation_boundary_contract' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-signed-real-invoker-release-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_signed_real_invoker_release_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_signed_real_invoker_release_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_signed_real_invoker_release_gate_preflight' => $preflight,
            'codex_real_invoker_post_start_signed_real_invoker_release_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_signed_real_invoker_release_gate_preflight_does_not_start_codex', 'agent_codex_real_invoker_post_start_signed_real_invoker_release_gate_preflight_does_not_call_codex', 'agent_codex_real_invoker_post_start_signed_real_invoker_release_gate_preflight_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_signed_real_invoker_release_gate_preflight_does_not_dispatch_work'],
            'human_summary' => $blockingReasons === []
                ? 'Codex real invoker post-start signed release gate is ready; it records signed release authorization and still requires implementation boundary.'
                : 'Codex real invoker post-start signed release gate is blocked until release preflight, signed release gate and ledger prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartSignedRealInvokerReleaseGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartSignedRealInvokerReleaseGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_signed_real_invoker_release_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_signed_real_invoker_release_gate_preflight_hash');

        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start signed release gate', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate.php'], 'acceptance' => 'Gate consumes post-start release preflight metadata and delegates to Codex signed real invoker release gate without invoking Codex.'],
            ['id' => 'T2', 'title' => 'Enforce post-start signed release bridge policy', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate.php'], 'acceptance' => 'Gate requires accepted post-start evidence bridge from release preflight and records signed release metadata while keeping process start, token spend, adapter execution and dispatch flags false.'],
            ['id' => 'T3', 'title' => 'Add post-start signed release tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartSignedRealInvokerReleaseGateTest.php'], 'acceptance' => 'Tests prove signed release bridge authorization, idempotency, duplicate rejection, missing release preflight bridge rejection, missing evidence bridge rejection, forbidden dispatch rejection, missing provider run rejection and rollback.'],
            ['id' => 'T4', 'title' => 'Expose post-start signed release readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare implementation boundary still separate.'],
        ];

        $packet = [
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_signed_real_invoker_release_gate_implementation',
            'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-SIGNED-REAL-INVOKER-RELEASE-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the Codex real invoker post-start signed release gate that bridges release preflight to signed release authorization while forbidding real invoker execution.',
            'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_enable_adapter_execution', 'do_not_run_real_external_process_invoker', 'do_not_enter_implementation_boundary'],
            'allowed_files' => data_get($preflight, 'allowed_future_files', []),
            'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'real_external_process_invoker_execution', 'provider_process_call', 'adapter_execution_runtime', 'implementation_boundary_runtime', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => ['post_start_signed_real_invoker_release_gate_requires_release_preflight_bridge_metadata', 'post_start_signed_real_invoker_release_gate_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_signed_real_invoker_release_gate_delegates_to_codex_signed_real_invoker_release_gate', 'post_start_signed_real_invoker_release_gate_records_observed_bridge_metadata', 'post_start_signed_real_invoker_release_gate_does_not_invoke_codex'],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'post_start_signed_real_invoker_release_bridge_allowed_by_service' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'adapter_invocation_allowed_by_packet' => false, 'adapter_execution_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false, 'requires_separate_implementation_boundary_contract' => true],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_signed_real_invoker_release_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_signed_real_invoker_release_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_signed_real_invoker_release_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_signed_real_invoker_release_gate_implementation_packet' => $packet,
            'codex_real_invoker_post_start_signed_real_invoker_release_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_signed_real_invoker_release_gate_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_signed_real_invoker_release_gate_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_signed_real_invoker_release_gate_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_signed_real_invoker_release_gate_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start signed release gate implementation packet is ready; it records signed release authorization and does not run real invoker execution.',
        ];
    }


public function agentCodexRealInvokerPostStartImplementationBoundaryGateContractTemplate(array $options = []): array
    {
        $postStartSignedPayload = $this->section->agentCodexRealInvokerPostStartSignedRealInvokerReleaseGatePreflight($options);
        $postStartSigned = (array) data_get($postStartSignedPayload, 'codex_real_invoker_post_start_signed_real_invoker_release_gate_preflight', []);
        $boundaryPayload = $this->section->agentCodexRealInvokerImplementationBoundaryPreflight($options);
        $boundary = (array) data_get($boundaryPayload, 'codex_real_invoker_implementation_boundary_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_implementation_boundary_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-IMPLEMENTATION-BOUNDARY-GATE-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_signed_release_hash' => data_get($postStartSignedPayload, 'codex_real_invoker_post_start_signed_real_invoker_release_gate_preflight_hash'),
                'implementation_boundary_hash' => data_get($boundaryPayload, 'codex_real_invoker_implementation_boundary_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_signed_real_invoker_release_gate_status' => data_get($postStartSignedPayload, 'status'),
            'source_codex_real_invoker_implementation_boundary_status' => data_get($boundaryPayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartImplementationBoundaryGate',
                'method' => 'preparePostStartImplementationBoundary',
                'input_contract' => ['run_key', 'post_start_implementation_boundary_gate_id', 'post_start_evidence_acceptance_bridge_id', 'post_start_signed_real_invoker_release_gate_id', 'post_start_real_invoker_release_preflight_gate_id', 'post_start_external_process_invoker_dry_run_gate_id', 'post_start_process_invocation_authorization_gate_id', 'post_start_external_process_runtime_gate_id', 'post_start_final_process_spawn_executor_gate_id', 'post_start_process_spawn_enablement_gate_id', 'post_start_supervised_start_gate_id', 'post_start_process_start_release_gate_id', 'process_start_release_id', 'provider_execution_contract_gate_id', 'codex_execution_id', 'supervised_start_id', 'spawn_enablement_id', 'spawn_executor_id', 'runtime_driver_id', 'invocation_authorization_id', 'dry_run_id', 'real_invoker_release_preflight_id', 'signed_real_invoker_release_id', 'real_invoker_implementation_boundary_id', 'adapter_execution_guard_gate_id', 'execution_guard_id', 'adapter_invocation_boundary_gate_id', 'adapter_invocation_id', 'provider_start_driver_gate_id', 'provider_start_attempt_id', 'signed_dispatch_receipt_hash', 'operator_release_receipt_hash', 'operator_spawn_receipt_hash', 'operator_final_spawn_receipt_hash', 'operator_runtime_receipt_hash', 'operator_invocation_receipt_hash', 'operator_dry_run_receipt_hash', 'operator_release_preflight_receipt_hash', 'operator_signed_release_receipt_hash', 'operator_implementation_boundary_receipt_hash', 'signature_verification_report_hash', 'codex_execution_contract_hash', 'supervised_start_contract_hash', 'runtime_supervision_plan_hash', 'stdout_stderr_sink_hash', 'liveness_probe_hash', 'runtime_driver_contract_hash', 'invoker_contract_hash', 'real_invoker_contract_hash', 'release_policy_hash', 'implementation_plan_hash', 'process_command_hash', 'environment_contract_hash', 'termination_policy_hash', 'rollback_plan_hash', 'max_runtime_policy_hash', 'actor', 'session', 'reason'],
                'result_contract' => ['post_start_implementation_boundary_gate_id', 'post_start_evidence_acceptance_bridge_id', 'real_invoker_implementation_boundary_id', 'signed_real_invoker_release_id', 'codex_execution_id', 'real_invoker_implementation_boundary_prepared', 'executor_plan_required', 'actual_process_start_allowed', 'dispatch_allowed'],
            ],
            'real_invoker_post_start_implementation_boundary_gate_must' => ['require_codex_real_invoker_post_start_signed_real_invoker_release_metadata', 'require_post_start_evidence_acceptance_bridge_from_signed_real_invoker_release', 'require_signed_release_flags_blocking_process_token_dispatch', 'delegate_to_codex_real_invoker_implementation_boundary', 'record_implementation_boundary_bridge_on_observed_post_start_run', 'require_executor_plan_as_separate_later_contract'],
            'real_invoker_post_start_implementation_boundary_gate_must_not' => ['call_codex_cli_or_codex_app', 'spawn_process_or_shell_command', 'spend_provider_tokens', 'start_codex_process', 'dispatch_work_to_codex', 'enable_adapter_execution', 'run_real_external_process_invoker', 'mark_observed_run_running_or_terminal'],
            'implementation_files_allowed_future' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartImplementationBoundaryGate.php', 'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartImplementationBoundaryGateTest.php'],
            'contract_policy' => ['template_is_read_only' => true, 'post_start_implementation_boundary_bridge_allowed_by_service' => true, 'codex_real_invoker_implementation_boundary_allowed_by_service' => true, 'actual_process_start_allowed_here' => false, 'provider_process_start_allowed_here' => false, 'adapter_invocation_allowed_here' => false, 'adapter_execution_allowed_here' => false, 'codex_invocation_allowed_here' => false, 'token_spend_allowed_here' => false, 'dispatch_allowed_here' => false, 'requires_separate_executor_plan_contract' => true],
            'source_post_start_signed_real_invoker_release_gate_preflight' => $postStartSigned,
            'source_codex_real_invoker_implementation_boundary_preflight' => $boundary,
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-implementation-boundary-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_implementation_boundary_gate_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_implementation_boundary_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_implementation_boundary_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_implementation_boundary_gate_contract_template' => $template,
            'codex_real_invoker_post_start_implementation_boundary_gate_contract_template_hash' => ReadinessHash::stable($template),
            'source_post_start_signed_real_invoker_release_gate_preflight' => $postStartSigned,
            'source_codex_real_invoker_implementation_boundary_preflight' => $boundary,
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_implementation_boundary_gate_contract_template_does_not_start_codex', 'agent_codex_real_invoker_post_start_implementation_boundary_gate_contract_template_does_not_call_codex', 'agent_codex_real_invoker_post_start_implementation_boundary_gate_contract_template_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_implementation_boundary_gate_contract_template_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start implementation boundary gate template bridges signed release authorization to implementation boundary without invoking Codex.',
        ];
    }


public function agentCodexRealInvokerPostStartImplementationBoundaryGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartImplementationBoundaryGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_implementation_boundary_gate_contract_template', []);
        $gateReady = class_exists(AgentCodexRealInvokerPostStartImplementationBoundaryGate::class);
        $postStartSignedReady = class_exists(AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate::class);
        $boundaryReady = class_exists(AgentCodexRealInvokerImplementationBoundary::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([$gateReady ? null : 'codex_real_invoker_post_start_implementation_boundary_gate_missing', $postStartSignedReady ? null : 'codex_real_invoker_post_start_signed_real_invoker_release_gate_missing', $boundaryReady ? null : 'codex_real_invoker_implementation_boundary_missing', $runsTableReady ? null : 'agent_runs_table_missing', $ledgerReady ? null : 'ledger_table_missing']));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_implementation_boundary_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_implementation_boundary_gate_contract_template_hash'),
            'source_codex_real_invoker_post_start_signed_real_invoker_release_gate_status' => data_get($contract, 'source_codex_real_invoker_post_start_signed_real_invoker_release_gate_status'),
            'source_codex_real_invoker_implementation_boundary_status' => data_get($contract, 'source_codex_real_invoker_implementation_boundary_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => ['codex_real_invoker_post_start_implementation_boundary_gate_ready' => $gateReady, 'codex_real_invoker_post_start_signed_real_invoker_release_gate_ready' => $postStartSignedReady, 'codex_real_invoker_implementation_boundary_ready' => $boundaryReady, 'agent_runs_table_ready' => $runsTableReady, 'ledger_table_ready' => $ledgerReady],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_implementation_boundary_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => ['create_codex_real_invoker_post_start_implementation_boundary_gate', 'require_post_start_signed_real_invoker_release_metadata', 'require_post_start_evidence_acceptance_bridge_from_signed_real_invoker_release', 'delegate_to_codex_real_invoker_implementation_boundary_without_invoking_codex', 'record_implementation_boundary_bridge_on_observed_run'],
            'required_gates' => ['php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartImplementationBoundaryGate.php', 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartImplementationBoundaryGateTest.php', 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_implementation_boundary_gate', 'php artisan atlas:engineering:knowledge docs-health --json', 'php artisan atlas:ai:architecture-validate --json', 'git diff --check'],
            'preflight_policy' => ['preflight_is_read_only' => true, 'post_start_implementation_boundary_gate_file_creation_allowed_here' => false, 'post_start_implementation_boundary_bridge_allowed_by_service' => true, 'codex_real_invoker_implementation_boundary_allowed_by_service' => true, 'actual_process_start_allowed_here' => false, 'provider_process_start_allowed_here' => false, 'adapter_invocation_allowed_here' => false, 'adapter_execution_allowed_here' => false, 'codex_invocation_allowed_here' => false, 'token_spend_allowed_here' => false, 'dispatch_allowed_here' => false, 'requires_separate_executor_plan_contract' => true],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-implementation-boundary-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_implementation_boundary_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_implementation_boundary_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_implementation_boundary_gate_preflight' => $preflight,
            'codex_real_invoker_post_start_implementation_boundary_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_implementation_boundary_gate_preflight_does_not_start_codex', 'agent_codex_real_invoker_post_start_implementation_boundary_gate_preflight_does_not_call_codex', 'agent_codex_real_invoker_post_start_implementation_boundary_gate_preflight_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_implementation_boundary_gate_preflight_does_not_dispatch_work'],
            'human_summary' => $blockingReasons === [] ? 'Codex real invoker post-start implementation boundary gate is ready; it records boundary preparation and still requires executor plan.' : 'Codex real invoker post-start implementation boundary gate is blocked until signed release, boundary and ledger prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartImplementationBoundaryGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartImplementationBoundaryGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_implementation_boundary_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_implementation_boundary_gate_preflight_hash');
        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start implementation boundary gate', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartImplementationBoundaryGate.php'], 'acceptance' => 'Gate consumes post-start signed release metadata and delegates to Codex real invoker implementation boundary without invoking Codex.'],
            ['id' => 'T2', 'title' => 'Enforce post-start implementation boundary bridge policy', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartImplementationBoundaryGate.php'], 'acceptance' => 'Gate requires accepted post-start evidence bridge from signed release and records boundary metadata while keeping process start, token spend, adapter execution and dispatch flags false.'],
            ['id' => 'T3', 'title' => 'Add post-start implementation boundary tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartImplementationBoundaryGateTest.php'], 'acceptance' => 'Tests prove boundary preparation, idempotency, duplicate rejection, missing signed release bridge rejection, missing evidence bridge rejection, forbidden dispatch rejection, missing provider run rejection and rollback.'],
            ['id' => 'T4', 'title' => 'Expose post-start implementation boundary readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare executor plan still separate.'],
        ];
        $packet = ['status' => 'ready_for_scoped_codex_real_invoker_post_start_implementation_boundary_gate_implementation', 'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-IMPLEMENTATION-BOUNDARY-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001', 'source_preflight_hash' => $preflightHash, 'objective' => 'Implement the Codex real invoker post-start implementation boundary gate that bridges signed release authorization to implementation boundary while forbidding real invoker execution.', 'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_enable_adapter_execution', 'do_not_run_real_external_process_invoker', 'do_not_prepare_executor_plan'], 'allowed_files' => data_get($preflight, 'allowed_future_files', []), 'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'real_external_process_invoker_execution', 'provider_process_call', 'adapter_execution_runtime', 'executor_plan_runtime', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'], 'tasks' => $tasks, 'task_count' => count($tasks), 'acceptance_criteria' => ['post_start_implementation_boundary_gate_requires_signed_release_bridge_metadata', 'post_start_implementation_boundary_gate_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_implementation_boundary_gate_delegates_to_codex_real_invoker_implementation_boundary', 'post_start_implementation_boundary_gate_records_observed_bridge_metadata', 'post_start_implementation_boundary_gate_does_not_invoke_codex'], 'required_gates' => data_get($preflight, 'required_gates', []), 'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'post_start_implementation_boundary_bridge_allowed_by_service' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'adapter_invocation_allowed_by_packet' => false, 'adapter_execution_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false, 'requires_separate_executor_plan_contract' => true]];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_implementation_boundary_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_implementation_boundary_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_implementation_boundary_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_implementation_boundary_gate_implementation_packet' => $packet,
            'codex_real_invoker_post_start_implementation_boundary_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_implementation_boundary_gate_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_implementation_boundary_gate_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_implementation_boundary_gate_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_implementation_boundary_gate_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start implementation boundary gate implementation packet is ready; it records boundary preparation and does not run real invoker execution.',
        ];
    }


public function agentCodexRealInvokerPostStartExecutorPlanGateContractTemplate(array $options = []): array
    {
        $postStartBoundaryPayload = $this->section->agentCodexRealInvokerPostStartImplementationBoundaryGatePreflight($options);
        $postStartBoundary = (array) data_get($postStartBoundaryPayload, 'codex_real_invoker_post_start_implementation_boundary_gate_preflight', []);
        $executorPlanPayload = $this->section->agentCodexRealInvokerExecutorPlanPreflight($options);
        $executorPlan = (array) data_get($executorPlanPayload, 'codex_real_invoker_executor_plan_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_executor_plan_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-EXECUTOR-PLAN-GATE-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_implementation_boundary_hash' => data_get($postStartBoundaryPayload, 'codex_real_invoker_post_start_implementation_boundary_gate_preflight_hash'),
                'executor_plan_hash' => data_get($executorPlanPayload, 'codex_real_invoker_executor_plan_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_implementation_boundary_gate_status' => data_get($postStartBoundaryPayload, 'status'),
            'source_codex_real_invoker_executor_plan_status' => data_get($executorPlanPayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartExecutorPlanGate',
                'method' => 'preparePostStartExecutorPlan',
                'input_contract' => ['run_key', 'post_start_executor_plan_gate_id', 'post_start_evidence_acceptance_bridge_id', 'post_start_implementation_boundary_gate_id', 'provider_start_attempt_id', 'codex_execution_id', 'real_invoker_implementation_boundary_id', 'real_invoker_executor_plan_id', 'operator_executor_plan_receipt_hash', 'executor_binary_contract_hash', 'executor_observability_contract_hash', 'actor', 'session', 'reason'],
                'result_contract' => ['post_start_executor_plan_gate_id', 'post_start_evidence_acceptance_bridge_id', 'real_invoker_executor_plan_id', 'real_invoker_executor_plan_prepared', 'executor_fresh_release_required', 'executor_enabled', 'actual_process_start_allowed', 'dispatch_allowed'],
            ],
            'real_invoker_post_start_executor_plan_gate_must' => ['require_codex_real_invoker_post_start_implementation_boundary_metadata', 'require_post_start_evidence_acceptance_bridge_from_implementation_boundary', 'require_implementation_boundary_flags_blocking_process_token_dispatch', 'delegate_to_codex_real_invoker_executor_plan', 'record_executor_plan_bridge_on_observed_post_start_run', 'require_executor_fresh_release_as_separate_later_contract'],
            'real_invoker_post_start_executor_plan_gate_must_not' => ['call_codex_cli_or_codex_app', 'spawn_process_or_shell_command', 'spend_provider_tokens', 'start_codex_process', 'dispatch_work_to_codex', 'enable_adapter_execution', 'enable_executor', 'run_real_external_process_invoker', 'mark_observed_run_running_or_terminal'],
            'implementation_files_allowed_future' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartExecutorPlanGate.php', 'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartExecutorPlanGateTest.php'],
            'contract_policy' => ['template_is_read_only' => true, 'post_start_executor_plan_bridge_allowed_by_service' => true, 'codex_real_invoker_executor_plan_allowed_by_service' => true, 'actual_process_start_allowed_here' => false, 'provider_process_start_allowed_here' => false, 'executor_enabled_here' => false, 'adapter_invocation_allowed_here' => false, 'adapter_execution_allowed_here' => false, 'codex_invocation_allowed_here' => false, 'token_spend_allowed_here' => false, 'dispatch_allowed_here' => false, 'requires_separate_executor_fresh_release_contract' => true],
            'source_post_start_implementation_boundary_gate_preflight' => $postStartBoundary,
            'source_codex_real_invoker_executor_plan_preflight' => $executorPlan,
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-executor-plan-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_executor_plan_gate_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_executor_plan_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_executor_plan_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_executor_plan_gate_contract_template' => $template,
            'codex_real_invoker_post_start_executor_plan_gate_contract_template_hash' => ReadinessHash::stable($template),
            'source_post_start_implementation_boundary_gate_preflight' => $postStartBoundary,
            'source_codex_real_invoker_executor_plan_preflight' => $executorPlan,
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_executor_plan_gate_contract_template_does_not_start_codex', 'agent_codex_real_invoker_post_start_executor_plan_gate_contract_template_does_not_call_codex', 'agent_codex_real_invoker_post_start_executor_plan_gate_contract_template_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_executor_plan_gate_contract_template_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start executor plan gate template bridges implementation boundary to executor plan without enabling executor or invoking Codex.',
        ];
    }


public function agentCodexRealInvokerPostStartExecutorPlanGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartExecutorPlanGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_executor_plan_gate_contract_template', []);
        $gateReady = class_exists(AgentCodexRealInvokerPostStartExecutorPlanGate::class);
        $postStartBoundaryReady = class_exists(AgentCodexRealInvokerPostStartImplementationBoundaryGate::class);
        $executorPlanReady = class_exists(AgentCodexRealInvokerExecutorPlan::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([$gateReady ? null : 'codex_real_invoker_post_start_executor_plan_gate_missing', $postStartBoundaryReady ? null : 'codex_real_invoker_post_start_implementation_boundary_gate_missing', $executorPlanReady ? null : 'codex_real_invoker_executor_plan_missing', $runsTableReady ? null : 'agent_runs_table_missing', $ledgerReady ? null : 'ledger_table_missing']));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_executor_plan_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_executor_plan_gate_contract_template_hash'),
            'source_codex_real_invoker_post_start_implementation_boundary_gate_status' => data_get($contract, 'source_codex_real_invoker_post_start_implementation_boundary_gate_status'),
            'source_codex_real_invoker_executor_plan_status' => data_get($contract, 'source_codex_real_invoker_executor_plan_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => ['codex_real_invoker_post_start_executor_plan_gate_ready' => $gateReady, 'codex_real_invoker_post_start_implementation_boundary_gate_ready' => $postStartBoundaryReady, 'codex_real_invoker_executor_plan_ready' => $executorPlanReady, 'agent_runs_table_ready' => $runsTableReady, 'ledger_table_ready' => $ledgerReady],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_executor_plan_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => ['create_codex_real_invoker_post_start_executor_plan_gate', 'require_post_start_implementation_boundary_metadata', 'require_post_start_evidence_acceptance_bridge_from_implementation_boundary', 'delegate_to_codex_real_invoker_executor_plan_without_invoking_codex', 'record_executor_plan_bridge_on_observed_run'],
            'required_gates' => ['php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartExecutorPlanGate.php', 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartExecutorPlanGateTest.php', 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_executor_plan_gate', 'php artisan atlas:engineering:knowledge docs-health --json', 'php artisan atlas:ai:architecture-validate --json', 'git diff --check'],
            'preflight_policy' => ['preflight_is_read_only' => true, 'post_start_executor_plan_gate_file_creation_allowed_here' => false, 'post_start_executor_plan_bridge_allowed_by_service' => true, 'codex_real_invoker_executor_plan_allowed_by_service' => true, 'actual_process_start_allowed_here' => false, 'provider_process_start_allowed_here' => false, 'executor_enabled_here' => false, 'adapter_invocation_allowed_here' => false, 'adapter_execution_allowed_here' => false, 'codex_invocation_allowed_here' => false, 'token_spend_allowed_here' => false, 'dispatch_allowed_here' => false, 'requires_separate_executor_fresh_release_contract' => true],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-executor-plan-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_executor_plan_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_executor_plan_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_executor_plan_gate_preflight' => $preflight,
            'codex_real_invoker_post_start_executor_plan_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_executor_plan_gate_preflight_does_not_start_codex', 'agent_codex_real_invoker_post_start_executor_plan_gate_preflight_does_not_call_codex', 'agent_codex_real_invoker_post_start_executor_plan_gate_preflight_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_executor_plan_gate_preflight_does_not_dispatch_work'],
            'human_summary' => $blockingReasons === [] ? 'Codex real invoker post-start executor plan gate is ready; it records executor plan preparation and still requires fresh release.' : 'Codex real invoker post-start executor plan gate is blocked until implementation boundary, executor plan and ledger prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartExecutorPlanGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartExecutorPlanGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_executor_plan_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_executor_plan_gate_preflight_hash');
        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start executor plan gate', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartExecutorPlanGate.php'], 'acceptance' => 'Gate consumes post-start implementation boundary metadata and delegates to Codex real invoker executor plan without invoking Codex.'],
            ['id' => 'T2', 'title' => 'Enforce post-start executor plan bridge policy', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartExecutorPlanGate.php'], 'acceptance' => 'Gate requires accepted post-start evidence bridge from implementation boundary and records executor plan metadata while keeping process start, token spend, executor enablement, adapter execution and dispatch flags false.'],
            ['id' => 'T3', 'title' => 'Add post-start executor plan tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartExecutorPlanGateTest.php'], 'acceptance' => 'Tests prove executor plan preparation, idempotency, duplicate rejection, missing boundary bridge rejection, missing evidence bridge rejection, forbidden dispatch rejection, missing provider run rejection and rollback.'],
            ['id' => 'T4', 'title' => 'Expose post-start executor plan readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare executor fresh release still separate.'],
        ];
        $packet = ['status' => 'ready_for_scoped_codex_real_invoker_post_start_executor_plan_gate_implementation', 'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-EXECUTOR-PLAN-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001', 'source_preflight_hash' => $preflightHash, 'objective' => 'Implement the Codex real invoker post-start executor plan gate that bridges implementation boundary to executor plan while forbidding real invoker execution.', 'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_enable_adapter_execution', 'do_not_enable_executor', 'do_not_run_real_external_process_invoker', 'do_not_authorize_executor_fresh_release'], 'allowed_files' => data_get($preflight, 'allowed_future_files', []), 'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'real_external_process_invoker_execution', 'provider_process_call', 'adapter_execution_runtime', 'executor_enablement_runtime', 'executor_fresh_release_runtime', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'], 'tasks' => $tasks, 'task_count' => count($tasks), 'acceptance_criteria' => ['post_start_executor_plan_gate_requires_implementation_boundary_bridge_metadata', 'post_start_executor_plan_gate_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_executor_plan_gate_delegates_to_codex_real_invoker_executor_plan', 'post_start_executor_plan_gate_records_observed_bridge_metadata', 'post_start_executor_plan_gate_does_not_enable_executor_or_invoke_codex'], 'required_gates' => data_get($preflight, 'required_gates', []), 'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'post_start_executor_plan_bridge_allowed_by_service' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'executor_enabled_by_packet' => false, 'adapter_invocation_allowed_by_packet' => false, 'adapter_execution_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false, 'requires_separate_executor_fresh_release_contract' => true]];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_executor_plan_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_executor_plan_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_executor_plan_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_executor_plan_gate_implementation_packet' => $packet,
            'codex_real_invoker_post_start_executor_plan_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_executor_plan_gate_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_executor_plan_gate_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_executor_plan_gate_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_executor_plan_gate_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start executor plan gate implementation packet is ready; it records executor plan preparation and does not enable executor execution.',
        ];
    }


public function agentCodexRealInvokerPostStartExecutorFreshReleaseGateContractTemplate(array $options = []): array
    {
        $postStartPlanPayload = $this->section->agentCodexRealInvokerPostStartExecutorPlanGatePreflight($options);
        $postStartPlan = (array) data_get($postStartPlanPayload, 'codex_real_invoker_post_start_executor_plan_gate_preflight', []);
        $freshReleasePayload = $this->section->agentCodexRealInvokerExecutorFreshReleaseGatePreflight($options);
        $freshRelease = (array) data_get($freshReleasePayload, 'codex_real_invoker_executor_fresh_release_gate_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_executor_fresh_release_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-EXECUTOR-FRESH-RELEASE-GATE-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_executor_plan_hash' => data_get($postStartPlanPayload, 'codex_real_invoker_post_start_executor_plan_gate_preflight_hash'),
                'executor_fresh_release_hash' => data_get($freshReleasePayload, 'codex_real_invoker_executor_fresh_release_gate_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_executor_plan_gate_status' => data_get($postStartPlanPayload, 'status'),
            'source_codex_real_invoker_executor_fresh_release_gate_status' => data_get($freshReleasePayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartExecutorFreshReleaseGate',
                'method' => 'authorizePostStartExecutorFreshRelease',
                'input_contract' => ['run_key', 'post_start_executor_fresh_release_gate_id', 'post_start_evidence_acceptance_bridge_id', 'post_start_executor_plan_gate_id', 'provider_start_attempt_id', 'codex_execution_id', 'real_invoker_executor_plan_id', 'real_invoker_executor_fresh_release_id', 'operator_fresh_release_receipt_hash', 'plan_revalidation_report_hash', 'freshness_window_hash', 'final_human_signature_hash', 'actor', 'session', 'reason'],
                'result_contract' => ['post_start_executor_fresh_release_gate_id', 'post_start_evidence_acceptance_bridge_id', 'real_invoker_executor_fresh_release_id', 'real_invoker_executor_fresh_release_authorized', 'executor_enablement_required', 'executor_enabled', 'actual_process_start_allowed', 'dispatch_allowed'],
            ],
            'real_invoker_post_start_executor_fresh_release_gate_must' => ['require_codex_real_invoker_post_start_executor_plan_metadata', 'require_post_start_evidence_acceptance_bridge_from_executor_plan', 'require_executor_plan_flags_blocking_process_token_dispatch', 'delegate_to_codex_real_invoker_executor_fresh_release_gate', 'record_executor_fresh_release_bridge_on_observed_post_start_run', 'require_executor_enablement_as_separate_later_contract'],
            'real_invoker_post_start_executor_fresh_release_gate_must_not' => ['call_codex_cli_or_codex_app', 'spawn_process_or_shell_command', 'spend_provider_tokens', 'start_codex_process', 'dispatch_work_to_codex', 'enable_adapter_execution', 'enable_executor', 'run_real_external_process_invoker', 'mark_observed_run_running_or_terminal'],
            'implementation_files_allowed_future' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartExecutorFreshReleaseGate.php', 'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartExecutorFreshReleaseGateTest.php'],
            'contract_policy' => ['template_is_read_only' => true, 'post_start_executor_fresh_release_bridge_allowed_by_service' => true, 'codex_real_invoker_executor_fresh_release_allowed_by_service' => true, 'actual_process_start_allowed_here' => false, 'provider_process_start_allowed_here' => false, 'executor_enabled_here' => false, 'adapter_invocation_allowed_here' => false, 'adapter_execution_allowed_here' => false, 'codex_invocation_allowed_here' => false, 'token_spend_allowed_here' => false, 'dispatch_allowed_here' => false, 'requires_separate_executor_enablement_contract' => true],
            'source_post_start_executor_plan_gate_preflight' => $postStartPlan,
            'source_codex_real_invoker_executor_fresh_release_gate_preflight' => $freshRelease,
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-executor-fresh-release-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_executor_fresh_release_gate_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_executor_fresh_release_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_executor_fresh_release_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_executor_fresh_release_gate_contract_template' => $template,
            'codex_real_invoker_post_start_executor_fresh_release_gate_contract_template_hash' => ReadinessHash::stable($template),
            'source_post_start_executor_plan_gate_preflight' => $postStartPlan,
            'source_codex_real_invoker_executor_fresh_release_gate_preflight' => $freshRelease,
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_executor_fresh_release_gate_contract_template_does_not_start_codex', 'agent_codex_real_invoker_post_start_executor_fresh_release_gate_contract_template_does_not_call_codex', 'agent_codex_real_invoker_post_start_executor_fresh_release_gate_contract_template_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_executor_fresh_release_gate_contract_template_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start executor fresh release gate template bridges executor plan to fresh release without enabling executor or invoking Codex.',
        ];
    }


public function agentCodexRealInvokerPostStartExecutorFreshReleaseGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartExecutorFreshReleaseGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_executor_fresh_release_gate_contract_template', []);
        $gateReady = class_exists(AgentCodexRealInvokerPostStartExecutorFreshReleaseGate::class);
        $postStartPlanReady = class_exists(AgentCodexRealInvokerPostStartExecutorPlanGate::class);
        $freshReleaseReady = class_exists(AgentCodexRealInvokerExecutorFreshReleaseGate::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([$gateReady ? null : 'codex_real_invoker_post_start_executor_fresh_release_gate_missing', $postStartPlanReady ? null : 'codex_real_invoker_post_start_executor_plan_gate_missing', $freshReleaseReady ? null : 'codex_real_invoker_executor_fresh_release_gate_missing', $runsTableReady ? null : 'agent_runs_table_missing', $ledgerReady ? null : 'ledger_table_missing']));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_executor_fresh_release_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_executor_fresh_release_gate_contract_template_hash'),
            'source_codex_real_invoker_post_start_executor_plan_gate_status' => data_get($contract, 'source_codex_real_invoker_post_start_executor_plan_gate_status'),
            'source_codex_real_invoker_executor_fresh_release_gate_status' => data_get($contract, 'source_codex_real_invoker_executor_fresh_release_gate_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => ['codex_real_invoker_post_start_executor_fresh_release_gate_ready' => $gateReady, 'codex_real_invoker_post_start_executor_plan_gate_ready' => $postStartPlanReady, 'codex_real_invoker_executor_fresh_release_gate_ready' => $freshReleaseReady, 'agent_runs_table_ready' => $runsTableReady, 'ledger_table_ready' => $ledgerReady],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_executor_fresh_release_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => ['create_codex_real_invoker_post_start_executor_fresh_release_gate', 'require_post_start_executor_plan_metadata', 'require_post_start_evidence_acceptance_bridge_from_executor_plan', 'delegate_to_codex_real_invoker_executor_fresh_release_gate_without_invoking_codex', 'record_executor_fresh_release_bridge_on_observed_run'],
            'required_gates' => ['php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartExecutorFreshReleaseGate.php', 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartExecutorFreshReleaseGateTest.php', 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_executor_fresh_release_gate', 'php artisan atlas:engineering:knowledge docs-health --json', 'php artisan atlas:ai:architecture-validate --json', 'git diff --check'],
            'preflight_policy' => ['preflight_is_read_only' => true, 'post_start_executor_fresh_release_gate_file_creation_allowed_here' => false, 'post_start_executor_fresh_release_bridge_allowed_by_service' => true, 'codex_real_invoker_executor_fresh_release_allowed_by_service' => true, 'actual_process_start_allowed_here' => false, 'provider_process_start_allowed_here' => false, 'executor_enabled_here' => false, 'adapter_invocation_allowed_here' => false, 'adapter_execution_allowed_here' => false, 'codex_invocation_allowed_here' => false, 'token_spend_allowed_here' => false, 'dispatch_allowed_here' => false, 'requires_separate_executor_enablement_contract' => true],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-executor-fresh-release-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_executor_fresh_release_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_executor_fresh_release_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_executor_fresh_release_gate_preflight' => $preflight,
            'codex_real_invoker_post_start_executor_fresh_release_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_executor_fresh_release_gate_preflight_does_not_start_codex', 'agent_codex_real_invoker_post_start_executor_fresh_release_gate_preflight_does_not_call_codex', 'agent_codex_real_invoker_post_start_executor_fresh_release_gate_preflight_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_executor_fresh_release_gate_preflight_does_not_dispatch_work'],
            'human_summary' => $blockingReasons === [] ? 'Codex real invoker post-start executor fresh release gate is ready; it records fresh release authorization and still requires executor enablement.' : 'Codex real invoker post-start executor fresh release gate is blocked until executor plan, fresh release and ledger prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartExecutorFreshReleaseGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartExecutorFreshReleaseGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_executor_fresh_release_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_executor_fresh_release_gate_preflight_hash');
        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start executor fresh release gate', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartExecutorFreshReleaseGate.php'], 'acceptance' => 'Gate consumes post-start executor plan metadata and delegates to Codex real invoker executor fresh release gate without invoking Codex.'],
            ['id' => 'T2', 'title' => 'Enforce post-start executor fresh release bridge policy', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartExecutorFreshReleaseGate.php'], 'acceptance' => 'Gate requires accepted post-start evidence bridge from executor plan and records fresh release metadata while keeping process start, token spend, executor enablement, adapter execution and dispatch flags false.'],
            ['id' => 'T3', 'title' => 'Add post-start executor fresh release tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartExecutorFreshReleaseGateTest.php'], 'acceptance' => 'Tests prove fresh release authorization, idempotency, duplicate rejection, missing executor plan bridge rejection, missing evidence bridge rejection, forbidden executor enablement rejection, missing provider run rejection and rollback.'],
            ['id' => 'T4', 'title' => 'Expose post-start executor fresh release readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare executor enablement still separate.'],
        ];
        $packet = ['status' => 'ready_for_scoped_codex_real_invoker_post_start_executor_fresh_release_gate_implementation', 'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-EXECUTOR-FRESH-RELEASE-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001', 'source_preflight_hash' => $preflightHash, 'objective' => 'Implement the Codex real invoker post-start executor fresh release gate that bridges executor plan to fresh release while forbidding real invoker execution.', 'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_enable_adapter_execution', 'do_not_enable_executor', 'do_not_run_real_external_process_invoker', 'do_not_authorize_executor_enablement'], 'allowed_files' => data_get($preflight, 'allowed_future_files', []), 'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'real_external_process_invoker_execution', 'provider_process_call', 'adapter_execution_runtime', 'executor_enablement_runtime', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'], 'tasks' => $tasks, 'task_count' => count($tasks), 'acceptance_criteria' => ['post_start_executor_fresh_release_gate_requires_executor_plan_bridge_metadata', 'post_start_executor_fresh_release_gate_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_executor_fresh_release_gate_delegates_to_codex_real_invoker_executor_fresh_release_gate', 'post_start_executor_fresh_release_gate_records_observed_bridge_metadata', 'post_start_executor_fresh_release_gate_does_not_enable_executor_or_invoke_codex'], 'required_gates' => data_get($preflight, 'required_gates', []), 'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'post_start_executor_fresh_release_bridge_allowed_by_service' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'executor_enabled_by_packet' => false, 'adapter_invocation_allowed_by_packet' => false, 'adapter_execution_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false, 'requires_separate_executor_enablement_contract' => true]];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_executor_fresh_release_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_executor_fresh_release_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_executor_fresh_release_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_executor_fresh_release_gate_implementation_packet' => $packet,
            'codex_real_invoker_post_start_executor_fresh_release_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_executor_fresh_release_gate_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_executor_fresh_release_gate_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_executor_fresh_release_gate_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_executor_fresh_release_gate_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start executor fresh release gate implementation packet is ready; it records fresh release authorization and does not enable executor execution.',
        ];
    }


public function agentCodexRealInvokerPostStartExecutorEnablementGateContractTemplate(array $options = []): array
    {
        $postStartFreshReleasePayload = $this->section->agentCodexRealInvokerPostStartExecutorFreshReleaseGatePreflight($options);
        $postStartFreshRelease = (array) data_get($postStartFreshReleasePayload, 'codex_real_invoker_post_start_executor_fresh_release_gate_preflight', []);
        $enablementPayload = $this->section->agentCodexRealInvokerExecutorEnablementGatePreflight($options);
        $enablement = (array) data_get($enablementPayload, 'codex_real_invoker_executor_enablement_gate_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_executor_enablement_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-EXECUTOR-ENABLEMENT-GATE-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_executor_fresh_release_hash' => data_get($postStartFreshReleasePayload, 'codex_real_invoker_post_start_executor_fresh_release_gate_preflight_hash'),
                'executor_enablement_hash' => data_get($enablementPayload, 'codex_real_invoker_executor_enablement_gate_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_executor_fresh_release_gate_status' => data_get($postStartFreshReleasePayload, 'status'),
            'source_codex_real_invoker_executor_enablement_gate_status' => data_get($enablementPayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartExecutorEnablementGate',
                'method' => 'enablePostStartExecutor',
                'input_contract' => ['run_key', 'post_start_executor_enablement_gate_id', 'post_start_evidence_acceptance_bridge_id', 'post_start_executor_fresh_release_gate_id', 'provider_start_attempt_id', 'codex_execution_id', 'real_invoker_executor_fresh_release_id', 'real_invoker_executor_enablement_id', 'operator_enablement_receipt_hash', 'enablement_policy_hash', 'pre_start_checklist_hash', 'disable_switch_hash', 'actor', 'session', 'reason'],
                'result_contract' => ['post_start_executor_enablement_gate_id', 'post_start_evidence_acceptance_bridge_id', 'real_invoker_executor_enablement_id', 'real_invoker_executor_enabled', 'executor_enabled', 'supervised_start_required', 'actual_process_start_allowed', 'dispatch_allowed'],
            ],
            'real_invoker_post_start_executor_enablement_gate_must' => ['require_codex_real_invoker_post_start_executor_fresh_release_metadata', 'require_post_start_evidence_acceptance_bridge_from_executor_fresh_release', 'require_executor_fresh_release_flags_blocking_process_token_dispatch', 'delegate_to_codex_real_invoker_executor_enablement_gate', 'record_executor_enablement_bridge_on_observed_post_start_run', 'require_supervised_start_as_separate_later_contract'],
            'real_invoker_post_start_executor_enablement_gate_must_not' => ['call_codex_cli_or_codex_app', 'spawn_process_or_shell_command', 'spend_provider_tokens', 'start_codex_process', 'dispatch_work_to_codex', 'enable_adapter_execution', 'run_real_external_process_invoker', 'mark_observed_run_running_or_terminal'],
            'implementation_files_allowed_future' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartExecutorEnablementGate.php', 'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartExecutorEnablementGateTest.php'],
            'contract_policy' => ['template_is_read_only' => true, 'post_start_executor_enablement_bridge_allowed_by_service' => true, 'codex_real_invoker_executor_enablement_allowed_by_service' => true, 'executor_enabled_here' => true, 'actual_process_start_allowed_here' => false, 'provider_process_start_allowed_here' => false, 'adapter_invocation_allowed_here' => false, 'adapter_execution_allowed_here' => false, 'codex_invocation_allowed_here' => false, 'token_spend_allowed_here' => false, 'dispatch_allowed_here' => false, 'requires_separate_supervised_start_contract' => true],
            'source_post_start_executor_fresh_release_gate_preflight' => $postStartFreshRelease,
            'source_codex_real_invoker_executor_enablement_gate_preflight' => $enablement,
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-executor-enablement-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_executor_enablement_gate_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_executor_enablement_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_executor_enablement_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_executor_enablement_gate_contract_template' => $template,
            'codex_real_invoker_post_start_executor_enablement_gate_contract_template_hash' => ReadinessHash::stable($template),
            'source_post_start_executor_fresh_release_gate_preflight' => $postStartFreshRelease,
            'source_codex_real_invoker_executor_enablement_gate_preflight' => $enablement,
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_executor_enablement_gate_contract_template_does_not_start_codex', 'agent_codex_real_invoker_post_start_executor_enablement_gate_contract_template_does_not_call_codex', 'agent_codex_real_invoker_post_start_executor_enablement_gate_contract_template_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_executor_enablement_gate_contract_template_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start executor enablement gate template bridges fresh release to executor enablement without starting Codex, spending tokens or dispatching work.',
        ];
    }


public function agentCodexRealInvokerPostStartExecutorEnablementGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartExecutorEnablementGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_executor_enablement_gate_contract_template', []);
        $gateReady = class_exists(AgentCodexRealInvokerPostStartExecutorEnablementGate::class);
        $postStartFreshReleaseReady = class_exists(AgentCodexRealInvokerPostStartExecutorFreshReleaseGate::class);
        $enablementReady = class_exists(AgentCodexRealInvokerExecutorEnablementGate::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([$gateReady ? null : 'codex_real_invoker_post_start_executor_enablement_gate_missing', $postStartFreshReleaseReady ? null : 'codex_real_invoker_post_start_executor_fresh_release_gate_missing', $enablementReady ? null : 'codex_real_invoker_executor_enablement_gate_missing', $runsTableReady ? null : 'agent_runs_table_missing', $ledgerReady ? null : 'ledger_table_missing']));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_executor_enablement_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_executor_enablement_gate_contract_template_hash'),
            'source_codex_real_invoker_post_start_executor_fresh_release_gate_status' => data_get($contract, 'source_codex_real_invoker_post_start_executor_fresh_release_gate_status'),
            'source_codex_real_invoker_executor_enablement_gate_status' => data_get($contract, 'source_codex_real_invoker_executor_enablement_gate_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => ['codex_real_invoker_post_start_executor_enablement_gate_ready' => $gateReady, 'codex_real_invoker_post_start_executor_fresh_release_gate_ready' => $postStartFreshReleaseReady, 'codex_real_invoker_executor_enablement_gate_ready' => $enablementReady, 'agent_runs_table_ready' => $runsTableReady, 'ledger_table_ready' => $ledgerReady],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_executor_enablement_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => ['create_codex_real_invoker_post_start_executor_enablement_gate', 'require_post_start_executor_fresh_release_metadata', 'require_post_start_evidence_acceptance_bridge_from_executor_fresh_release', 'delegate_to_codex_real_invoker_executor_enablement_gate_without_invoking_codex', 'record_executor_enablement_bridge_on_observed_run'],
            'required_gates' => ['php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartExecutorEnablementGate.php', 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartExecutorEnablementGateTest.php', 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_executor_enablement_gate', 'php artisan atlas:engineering:knowledge docs-health --json', 'php artisan atlas:ai:architecture-validate --json', 'git diff --check'],
            'preflight_policy' => ['preflight_is_read_only' => true, 'post_start_executor_enablement_gate_file_creation_allowed_here' => false, 'post_start_executor_enablement_bridge_allowed_by_service' => true, 'codex_real_invoker_executor_enablement_allowed_by_service' => true, 'executor_enabled_here' => true, 'actual_process_start_allowed_here' => false, 'provider_process_start_allowed_here' => false, 'adapter_invocation_allowed_here' => false, 'adapter_execution_allowed_here' => false, 'codex_invocation_allowed_here' => false, 'token_spend_allowed_here' => false, 'dispatch_allowed_here' => false, 'requires_separate_supervised_start_contract' => true],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-executor-enablement-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_executor_enablement_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_executor_enablement_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_executor_enablement_gate_preflight' => $preflight,
            'codex_real_invoker_post_start_executor_enablement_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_executor_enablement_gate_preflight_does_not_start_codex', 'agent_codex_real_invoker_post_start_executor_enablement_gate_preflight_does_not_call_codex', 'agent_codex_real_invoker_post_start_executor_enablement_gate_preflight_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_executor_enablement_gate_preflight_does_not_dispatch_work'],
            'human_summary' => $blockingReasons === [] ? 'Codex real invoker post-start executor enablement gate is ready; it enables executor metadata while keeping real process start, token spend and dispatch blocked.' : 'Codex real invoker post-start executor enablement gate is blocked until fresh release, enablement and ledger prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartExecutorEnablementGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartExecutorEnablementGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_executor_enablement_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_executor_enablement_gate_preflight_hash');
        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start executor enablement gate', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartExecutorEnablementGate.php'], 'acceptance' => 'Gate consumes post-start executor fresh release metadata and delegates to Codex real invoker executor enablement gate without invoking Codex.'],
            ['id' => 'T2', 'title' => 'Enforce post-start executor enablement bridge policy', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartExecutorEnablementGate.php'], 'acceptance' => 'Gate requires accepted post-start evidence bridge from executor fresh release and records executor enablement metadata while keeping process start, token spend, adapter execution and dispatch flags false.'],
            ['id' => 'T3', 'title' => 'Add post-start executor enablement tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartExecutorEnablementGateTest.php'], 'acceptance' => 'Tests prove executor enablement, idempotency, duplicate rejection, missing fresh release bridge rejection, missing evidence bridge rejection, forbidden dispatch rejection, missing provider run rejection and rollback.'],
            ['id' => 'T4', 'title' => 'Expose post-start executor enablement readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare supervised start still separate.'],
        ];
        $packet = ['status' => 'ready_for_scoped_codex_real_invoker_post_start_executor_enablement_gate_implementation', 'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-EXECUTOR-ENABLEMENT-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001', 'source_preflight_hash' => $preflightHash, 'objective' => 'Implement the Codex real invoker post-start executor enablement gate that bridges executor fresh release to executor enablement while forbidding real process start and dispatch.', 'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_enable_adapter_execution', 'do_not_run_real_external_process_invoker', 'do_not_authorize_supervised_start'], 'allowed_files' => data_get($preflight, 'allowed_future_files', []), 'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'real_external_process_invoker_execution', 'provider_process_call', 'adapter_execution_runtime', 'supervised_start_runtime', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'], 'tasks' => $tasks, 'task_count' => count($tasks), 'acceptance_criteria' => ['post_start_executor_enablement_gate_requires_executor_fresh_release_bridge_metadata', 'post_start_executor_enablement_gate_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_executor_enablement_gate_delegates_to_codex_real_invoker_executor_enablement_gate', 'post_start_executor_enablement_gate_records_observed_bridge_metadata', 'post_start_executor_enablement_gate_does_not_start_codex_or_dispatch_work'], 'required_gates' => data_get($preflight, 'required_gates', []), 'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'post_start_executor_enablement_bridge_allowed_by_service' => true, 'executor_enabled_by_packet' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'adapter_invocation_allowed_by_packet' => false, 'adapter_execution_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false, 'requires_separate_supervised_start_contract' => true]];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_executor_enablement_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_executor_enablement_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_executor_enablement_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_executor_enablement_gate_implementation_packet' => $packet,
            'codex_real_invoker_post_start_executor_enablement_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_executor_enablement_gate_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_executor_enablement_gate_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_executor_enablement_gate_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_executor_enablement_gate_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start executor enablement gate implementation packet is ready; it enables executor metadata but does not start Codex or dispatch work.',
        ];
    }


public function agentCodexRealInvokerPostStartSupervisedStartActivationGateContractTemplate(array $options = []): array
    {
        $postStartEnablementPayload = $this->section->agentCodexRealInvokerPostStartExecutorEnablementGatePreflight($options);
        $postStartEnablement = (array) data_get($postStartEnablementPayload, 'codex_real_invoker_post_start_executor_enablement_gate_preflight', []);
        $activationPayload = $this->section->agentCodexRealInvokerSupervisedStartActivationGatePreflight($options);
        $activation = (array) data_get($activationPayload, 'codex_real_invoker_supervised_start_activation_gate_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_supervised_start_activation_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-SUPERVISED-START-ACTIVATION-GATE-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_executor_enablement_hash' => data_get($postStartEnablementPayload, 'codex_real_invoker_post_start_executor_enablement_gate_preflight_hash'),
                'supervised_start_activation_hash' => data_get($activationPayload, 'codex_real_invoker_supervised_start_activation_gate_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_executor_enablement_gate_status' => data_get($postStartEnablementPayload, 'status'),
            'source_codex_real_invoker_supervised_start_activation_gate_status' => data_get($activationPayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartSupervisedStartActivationGate',
                'method' => 'preparePostStartSupervisedStartActivation',
                'input_contract' => ['run_key', 'post_start_supervised_start_activation_gate_id', 'post_start_evidence_acceptance_bridge_id', 'post_start_executor_enablement_gate_id', 'provider_start_attempt_id', 'codex_execution_id', 'real_invoker_executor_enablement_id', 'real_invoker_supervised_start_activation_id', 'operator_start_activation_receipt_hash', 'start_window_hash', 'process_start_guard_hash', 'supervisor_observer_hash', 'pid_guard_hash', 'cwd_integrity_hash', 'actor', 'session', 'reason'],
                'result_contract' => ['post_start_supervised_start_activation_gate_id', 'post_start_evidence_acceptance_bridge_id', 'real_invoker_supervised_start_activation_id', 'real_invoker_supervised_start_activation_prepared', 'executor_enabled', 'process_start_armed', 'actual_process_start_allowed', 'dispatch_allowed'],
            ],
            'real_invoker_post_start_supervised_start_activation_gate_must' => ['require_codex_real_invoker_post_start_executor_enablement_metadata', 'require_post_start_evidence_acceptance_bridge_from_executor_enablement', 'require_executor_enablement_flags_blocking_process_token_dispatch', 'delegate_to_codex_real_invoker_supervised_start_activation_gate', 'record_supervised_start_activation_bridge_on_observed_post_start_run', 'require_actual_process_start_as_separate_later_contract'],
            'real_invoker_post_start_supervised_start_activation_gate_must_not' => ['call_codex_cli_or_codex_app', 'spawn_process_or_shell_command', 'spend_provider_tokens', 'start_codex_process', 'dispatch_work_to_codex', 'enable_adapter_execution', 'run_real_external_process_invoker', 'mark_observed_run_running_or_terminal'],
            'implementation_files_allowed_future' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartSupervisedStartActivationGate.php', 'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartSupervisedStartActivationGateTest.php'],
            'contract_policy' => ['template_is_read_only' => true, 'post_start_supervised_start_activation_bridge_allowed_by_service' => true, 'codex_real_invoker_supervised_start_activation_allowed_by_service' => true, 'executor_enabled_here' => true, 'process_start_armed_here' => true, 'actual_process_start_allowed_here' => false, 'provider_process_start_allowed_here' => false, 'adapter_invocation_allowed_here' => false, 'adapter_execution_allowed_here' => false, 'codex_invocation_allowed_here' => false, 'token_spend_allowed_here' => false, 'dispatch_allowed_here' => false, 'requires_separate_actual_process_start_contract' => true],
            'source_post_start_executor_enablement_gate_preflight' => $postStartEnablement,
            'source_codex_real_invoker_supervised_start_activation_gate_preflight' => $activation,
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-supervised-start-activation-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_supervised_start_activation_gate_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_supervised_start_activation_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_supervised_start_activation_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_supervised_start_activation_gate_contract_template' => $template,
            'codex_real_invoker_post_start_supervised_start_activation_gate_contract_template_hash' => ReadinessHash::stable($template),
            'source_post_start_executor_enablement_gate_preflight' => $postStartEnablement,
            'source_codex_real_invoker_supervised_start_activation_gate_preflight' => $activation,
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_supervised_start_activation_gate_contract_template_does_not_start_codex', 'agent_codex_real_invoker_post_start_supervised_start_activation_gate_contract_template_does_not_call_codex', 'agent_codex_real_invoker_post_start_supervised_start_activation_gate_contract_template_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_supervised_start_activation_gate_contract_template_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start supervised start activation gate template arms supervised start metadata without starting Codex, spending tokens or dispatching work.',
        ];
    }


public function agentCodexRealInvokerPostStartSupervisedStartActivationGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartSupervisedStartActivationGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_supervised_start_activation_gate_contract_template', []);
        $gateReady = class_exists(AgentCodexRealInvokerPostStartSupervisedStartActivationGate::class);
        $postStartEnablementReady = class_exists(AgentCodexRealInvokerPostStartExecutorEnablementGate::class);
        $activationReady = class_exists(AgentCodexRealInvokerSupervisedStartActivationGate::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([$gateReady ? null : 'codex_real_invoker_post_start_supervised_start_activation_gate_missing', $postStartEnablementReady ? null : 'codex_real_invoker_post_start_executor_enablement_gate_missing', $activationReady ? null : 'codex_real_invoker_supervised_start_activation_gate_missing', $runsTableReady ? null : 'agent_runs_table_missing', $ledgerReady ? null : 'ledger_table_missing']));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_supervised_start_activation_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_supervised_start_activation_gate_contract_template_hash'),
            'source_codex_real_invoker_post_start_executor_enablement_gate_status' => data_get($contract, 'source_codex_real_invoker_post_start_executor_enablement_gate_status'),
            'source_codex_real_invoker_supervised_start_activation_gate_status' => data_get($contract, 'source_codex_real_invoker_supervised_start_activation_gate_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => ['codex_real_invoker_post_start_supervised_start_activation_gate_ready' => $gateReady, 'codex_real_invoker_post_start_executor_enablement_gate_ready' => $postStartEnablementReady, 'codex_real_invoker_supervised_start_activation_gate_ready' => $activationReady, 'agent_runs_table_ready' => $runsTableReady, 'ledger_table_ready' => $ledgerReady],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_supervised_start_activation_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => ['create_codex_real_invoker_post_start_supervised_start_activation_gate', 'require_post_start_executor_enablement_metadata', 'require_post_start_evidence_acceptance_bridge_from_executor_enablement', 'delegate_to_codex_real_invoker_supervised_start_activation_gate_without_invoking_codex', 'record_supervised_start_activation_bridge_on_observed_run'],
            'required_gates' => ['php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartSupervisedStartActivationGate.php', 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartSupervisedStartActivationGateTest.php', 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_supervised_start_activation_gate', 'php artisan atlas:engineering:knowledge docs-health --json', 'php artisan atlas:ai:architecture-validate --json', 'git diff --check'],
            'preflight_policy' => ['preflight_is_read_only' => true, 'post_start_supervised_start_activation_gate_file_creation_allowed_here' => false, 'post_start_supervised_start_activation_bridge_allowed_by_service' => true, 'codex_real_invoker_supervised_start_activation_allowed_by_service' => true, 'executor_enabled_here' => true, 'process_start_armed_here' => true, 'actual_process_start_allowed_here' => false, 'provider_process_start_allowed_here' => false, 'adapter_invocation_allowed_here' => false, 'adapter_execution_allowed_here' => false, 'codex_invocation_allowed_here' => false, 'token_spend_allowed_here' => false, 'dispatch_allowed_here' => false, 'requires_separate_actual_process_start_contract' => true],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-supervised-start-activation-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_supervised_start_activation_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_supervised_start_activation_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_supervised_start_activation_gate_preflight' => $preflight,
            'codex_real_invoker_post_start_supervised_start_activation_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_supervised_start_activation_gate_preflight_does_not_start_codex', 'agent_codex_real_invoker_post_start_supervised_start_activation_gate_preflight_does_not_call_codex', 'agent_codex_real_invoker_post_start_supervised_start_activation_gate_preflight_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_supervised_start_activation_gate_preflight_does_not_dispatch_work'],
            'human_summary' => $blockingReasons === [] ? 'Codex real invoker post-start supervised start activation gate is ready; it arms process start metadata while keeping real process start, token spend and dispatch blocked.' : 'Codex real invoker post-start supervised start activation gate is blocked until executor enablement, activation and ledger prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartSupervisedStartActivationGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartSupervisedStartActivationGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_supervised_start_activation_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_supervised_start_activation_gate_preflight_hash');
        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start supervised start activation gate', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartSupervisedStartActivationGate.php'], 'acceptance' => 'Gate consumes post-start executor enablement metadata and delegates to Codex real invoker supervised start activation gate without invoking Codex.'],
            ['id' => 'T2', 'title' => 'Enforce post-start supervised start activation policy', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartSupervisedStartActivationGate.php'], 'acceptance' => 'Gate requires accepted post-start evidence bridge from executor enablement and records activation metadata while keeping process start, token spend, adapter execution and dispatch flags false.'],
            ['id' => 'T3', 'title' => 'Add post-start supervised start activation tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartSupervisedStartActivationGateTest.php'], 'acceptance' => 'Tests prove activation preparation, idempotency, duplicate rejection, missing enablement bridge rejection, missing evidence bridge rejection, forbidden process-start rejection, missing provider run rejection and rollback.'],
            ['id' => 'T4', 'title' => 'Expose post-start supervised start activation readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare actual process start still separate.'],
        ];
        $packet = ['status' => 'ready_for_scoped_codex_real_invoker_post_start_supervised_start_activation_gate_implementation', 'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-SUPERVISED-START-ACTIVATION-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001', 'source_preflight_hash' => $preflightHash, 'objective' => 'Implement the Codex real invoker post-start supervised start activation gate that bridges executor enablement to supervised start activation while forbidding real process start and dispatch.', 'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_enable_adapter_execution', 'do_not_run_real_external_process_invoker', 'do_not_authorize_actual_process_start'], 'allowed_files' => data_get($preflight, 'allowed_future_files', []), 'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'real_external_process_invoker_execution', 'provider_process_call', 'adapter_execution_runtime', 'actual_process_start_runtime', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'], 'tasks' => $tasks, 'task_count' => count($tasks), 'acceptance_criteria' => ['post_start_supervised_start_activation_gate_requires_executor_enablement_bridge_metadata', 'post_start_supervised_start_activation_gate_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_supervised_start_activation_gate_delegates_to_codex_real_invoker_supervised_start_activation_gate', 'post_start_supervised_start_activation_gate_records_observed_bridge_metadata', 'post_start_supervised_start_activation_gate_does_not_start_codex_or_dispatch_work'], 'required_gates' => data_get($preflight, 'required_gates', []), 'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'post_start_supervised_start_activation_bridge_allowed_by_service' => true, 'executor_enabled_by_packet' => true, 'process_start_armed_by_packet' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'adapter_invocation_allowed_by_packet' => false, 'adapter_execution_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false, 'requires_separate_actual_process_start_contract' => true]];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_supervised_start_activation_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_supervised_start_activation_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_supervised_start_activation_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_supervised_start_activation_gate_implementation_packet' => $packet,
            'codex_real_invoker_post_start_supervised_start_activation_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_supervised_start_activation_gate_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_supervised_start_activation_gate_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_supervised_start_activation_gate_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_supervised_start_activation_gate_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start supervised start activation gate implementation packet is ready; it arms process start metadata but does not start Codex or dispatch work.',
        ];
    }


public function agentCodexRealInvokerPostStartGuardedProcessStartExecutorGateContractTemplate(array $options = []): array
    {
        $activationPayload = $this->section->agentCodexRealInvokerPostStartSupervisedStartActivationGatePreflight($options);
        $activation = (array) data_get($activationPayload, 'codex_real_invoker_post_start_supervised_start_activation_gate_preflight', []);
        $guardedPayload = $this->section->agentCodexRealInvokerGuardedProcessStartExecutorPreflight($options);
        $guarded = (array) data_get($guardedPayload, 'codex_real_invoker_guarded_process_start_executor_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_guarded_process_start_executor_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-GUARDED-PROCESS-START-GATE-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_supervised_activation_hash' => data_get($activationPayload, 'codex_real_invoker_post_start_supervised_start_activation_gate_preflight_hash'),
                'guarded_process_start_hash' => data_get($guardedPayload, 'codex_real_invoker_guarded_process_start_executor_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_supervised_start_activation_gate_status' => data_get($activationPayload, 'status'),
            'source_codex_real_invoker_guarded_process_start_executor_status' => data_get($guardedPayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartGuardedProcessStartExecutorGate',
                'method' => 'preparePostStartGuardedProcessStart',
                'input_contract' => ['run_key', 'post_start_guarded_process_start_gate_id', 'post_start_evidence_acceptance_bridge_id', 'post_start_supervised_start_activation_gate_id', 'provider_start_attempt_id', 'codex_execution_id', 'real_invoker_supervised_start_activation_id', 'real_invoker_guarded_process_start_id', 'operator_guarded_start_receipt_hash', 'process_runner_contract_hash', 'dry_run_rehearsal_hash', 'launch_invocation_contract_hash', 'post_start_observability_hash', 'revoke_guard_hash', 'actor', 'session', 'reason'],
                'result_contract' => ['post_start_guarded_process_start_gate_id', 'post_start_evidence_acceptance_bridge_id', 'real_invoker_guarded_process_start_id', 'real_invoker_guarded_process_start_prepared', 'executor_enabled', 'process_start_armed', 'actual_process_start_allowed', 'dispatch_allowed'],
            ],
            'real_invoker_post_start_guarded_process_start_gate_must' => ['require_codex_real_invoker_post_start_supervised_start_activation_metadata', 'require_post_start_evidence_acceptance_bridge_from_supervised_start_activation', 'require_activation_flags_blocking_process_token_dispatch', 'delegate_to_codex_real_invoker_guarded_process_start_executor', 'record_guarded_process_start_bridge_on_observed_post_start_run', 'require_final_start_authorization_as_separate_later_contract'],
            'real_invoker_post_start_guarded_process_start_gate_must_not' => ['call_codex_cli_or_codex_app', 'spawn_process_or_shell_command', 'spend_provider_tokens', 'start_codex_process', 'dispatch_work_to_codex', 'enable_adapter_execution', 'mark_actual_process_start_allowed', 'mark_observed_run_running_or_terminal'],
            'implementation_files_allowed_future' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartGuardedProcessStartExecutorGate.php', 'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartGuardedProcessStartExecutorGateTest.php'],
            'contract_policy' => ['template_is_read_only' => true, 'post_start_guarded_process_start_bridge_allowed_by_service' => true, 'codex_real_invoker_guarded_process_start_allowed_by_service' => true, 'executor_enabled_here' => true, 'process_start_armed_here' => true, 'actual_process_start_allowed_here' => false, 'provider_process_start_allowed_here' => false, 'adapter_invocation_allowed_here' => false, 'adapter_execution_allowed_here' => false, 'codex_invocation_allowed_here' => false, 'token_spend_allowed_here' => false, 'dispatch_allowed_here' => false, 'requires_separate_final_start_authorization_contract' => true],
            'source_post_start_supervised_start_activation_gate_preflight' => $activation,
            'source_codex_real_invoker_guarded_process_start_executor_preflight' => $guarded,
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-guarded-process-start-executor-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_guarded_process_start_executor_gate_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_guarded_process_start_executor_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_guarded_process_start_executor_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_guarded_process_start_executor_gate_contract_template' => $template,
            'codex_real_invoker_post_start_guarded_process_start_executor_gate_contract_template_hash' => ReadinessHash::stable($template),
            'source_post_start_supervised_start_activation_gate_preflight' => $activation,
            'source_codex_real_invoker_guarded_process_start_executor_preflight' => $guarded,
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_guarded_process_start_executor_gate_contract_template_does_not_start_codex', 'agent_codex_real_invoker_post_start_guarded_process_start_executor_gate_contract_template_does_not_call_codex', 'agent_codex_real_invoker_post_start_guarded_process_start_executor_gate_contract_template_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_guarded_process_start_executor_gate_contract_template_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start guarded process start gate template prepares a disabled guarded start bridge without starting Codex, spending tokens or dispatching work.',
        ];
    }


public function agentCodexRealInvokerPostStartGuardedProcessStartExecutorGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartGuardedProcessStartExecutorGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_guarded_process_start_executor_gate_contract_template', []);
        $gateReady = class_exists(AgentCodexRealInvokerPostStartGuardedProcessStartExecutorGate::class);
        $postStartActivationReady = class_exists(AgentCodexRealInvokerPostStartSupervisedStartActivationGate::class);
        $guardedReady = class_exists(AgentCodexRealInvokerGuardedProcessStartExecutor::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([$gateReady ? null : 'codex_real_invoker_post_start_guarded_process_start_executor_gate_missing', $postStartActivationReady ? null : 'codex_real_invoker_post_start_supervised_start_activation_gate_missing', $guardedReady ? null : 'codex_real_invoker_guarded_process_start_executor_missing', $runsTableReady ? null : 'agent_runs_table_missing', $ledgerReady ? null : 'ledger_table_missing']));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_guarded_process_start_executor_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_guarded_process_start_executor_gate_contract_template_hash'),
            'source_codex_real_invoker_post_start_supervised_start_activation_gate_status' => data_get($contract, 'source_codex_real_invoker_post_start_supervised_start_activation_gate_status'),
            'source_codex_real_invoker_guarded_process_start_executor_status' => data_get($contract, 'source_codex_real_invoker_guarded_process_start_executor_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => ['codex_real_invoker_post_start_guarded_process_start_executor_gate_ready' => $gateReady, 'codex_real_invoker_post_start_supervised_start_activation_gate_ready' => $postStartActivationReady, 'codex_real_invoker_guarded_process_start_executor_ready' => $guardedReady, 'agent_runs_table_ready' => $runsTableReady, 'ledger_table_ready' => $ledgerReady],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_guarded_process_start_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => ['create_codex_real_invoker_post_start_guarded_process_start_executor_gate', 'require_post_start_supervised_start_activation_metadata', 'require_post_start_evidence_acceptance_bridge_from_supervised_start_activation', 'delegate_to_codex_real_invoker_guarded_process_start_executor_without_invoking_codex', 'record_guarded_start_bridge_on_observed_run'],
            'required_gates' => ['php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartGuardedProcessStartExecutorGate.php', 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartGuardedProcessStartExecutorGateTest.php', 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_guarded_process_start_executor_gate', 'php artisan atlas:engineering:knowledge docs-health --json', 'php artisan atlas:ai:architecture-validate --json', 'git diff --check'],
            'preflight_policy' => ['preflight_is_read_only' => true, 'post_start_guarded_process_start_gate_file_creation_allowed_here' => false, 'post_start_guarded_process_start_bridge_allowed_by_service' => true, 'codex_real_invoker_guarded_process_start_allowed_by_service' => true, 'executor_enabled_here' => true, 'process_start_armed_here' => true, 'actual_process_start_allowed_here' => false, 'provider_process_start_allowed_here' => false, 'adapter_invocation_allowed_here' => false, 'adapter_execution_allowed_here' => false, 'codex_invocation_allowed_here' => false, 'token_spend_allowed_here' => false, 'dispatch_allowed_here' => false, 'requires_separate_final_start_authorization_contract' => true],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-guarded-process-start-executor-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight' => $preflight,
            'codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight_does_not_start_codex', 'agent_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight_does_not_call_codex', 'agent_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight_does_not_dispatch_work'],
            'human_summary' => $blockingReasons === [] ? 'Codex real invoker post-start guarded process start gate is ready; it prepares disabled guarded start metadata while keeping actual start, token spend and dispatch blocked.' : 'Codex real invoker post-start guarded process start gate is blocked until supervised activation, guarded executor and ledger prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartGuardedProcessStartExecutorGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartGuardedProcessStartExecutorGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight_hash');
        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start guarded process start gate', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartGuardedProcessStartExecutorGate.php'], 'acceptance' => 'Gate consumes post-start supervised activation metadata and delegates to Codex real invoker guarded process start executor without invoking Codex.'],
            ['id' => 'T2', 'title' => 'Enforce post-start guarded process start policy', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartGuardedProcessStartExecutorGate.php'], 'acceptance' => 'Gate requires accepted post-start evidence bridge from supervised start activation and records guarded start metadata while keeping actual process start, token spend, adapter execution and dispatch flags false.'],
            ['id' => 'T3', 'title' => 'Add post-start guarded process start tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartGuardedProcessStartExecutorGateTest.php'], 'acceptance' => 'Tests prove guarded start preparation, idempotency, duplicate rejection, missing activation bridge rejection, missing evidence bridge rejection, forbidden process-start rejection, missing provider run rejection and rollback.'],
            ['id' => 'T4', 'title' => 'Expose post-start guarded process start readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare final start authorization still separate.'],
        ];
        $packet = ['status' => 'ready_for_scoped_codex_real_invoker_post_start_guarded_process_start_executor_gate_implementation', 'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-GUARDED-PROCESS-START-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001', 'source_preflight_hash' => $preflightHash, 'objective' => 'Implement the Codex real invoker post-start guarded process start gate that bridges supervised start activation to guarded process start while forbidding actual process start and dispatch.', 'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_enable_adapter_execution', 'do_not_run_real_external_process_invoker', 'do_not_authorize_final_start'], 'allowed_files' => data_get($preflight, 'allowed_future_files', []), 'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'real_external_process_invoker_execution', 'provider_process_call', 'adapter_execution_runtime', 'actual_process_start_runtime', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'], 'tasks' => $tasks, 'task_count' => count($tasks), 'acceptance_criteria' => ['post_start_guarded_process_start_gate_requires_supervised_start_activation_bridge_metadata', 'post_start_guarded_process_start_gate_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_guarded_process_start_gate_delegates_to_codex_real_invoker_guarded_process_start_executor', 'post_start_guarded_process_start_gate_records_observed_bridge_metadata', 'post_start_guarded_process_start_gate_does_not_start_codex_or_dispatch_work'], 'required_gates' => data_get($preflight, 'required_gates', []), 'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'post_start_guarded_process_start_bridge_allowed_by_service' => true, 'executor_enabled_by_packet' => true, 'process_start_armed_by_packet' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'adapter_invocation_allowed_by_packet' => false, 'adapter_execution_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false, 'requires_separate_final_start_authorization_contract' => true]];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_guarded_process_start_executor_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_guarded_process_start_executor_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_guarded_process_start_executor_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_guarded_process_start_executor_gate_implementation_packet' => $packet,
            'codex_real_invoker_post_start_guarded_process_start_executor_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_guarded_process_start_executor_gate_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_guarded_process_start_executor_gate_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_guarded_process_start_executor_gate_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_guarded_process_start_executor_gate_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start guarded process start gate implementation packet is ready; it prepares disabled guarded start metadata but does not start Codex or dispatch work.',
        ];
    }


public function agentCodexRealInvokerPostStartFinalProcessStartAuthorizationGateContractTemplate(array $options = []): array
    {
        $guardedPayload = $this->section->agentCodexRealInvokerPostStartGuardedProcessStartExecutorGatePreflight($options);
        $guarded = (array) data_get($guardedPayload, 'codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight', []);
        $authorizationPayload = $this->section->agentCodexRealInvokerFinalProcessStartAuthorizationGatePreflight($options);
        $authorization = (array) data_get($authorizationPayload, 'codex_real_invoker_final_process_start_authorization_gate_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_final_process_start_authorization_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-FINAL-PROCESS-START-AUTH-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_guarded_process_start_hash' => data_get($guardedPayload, 'codex_real_invoker_post_start_guarded_process_start_executor_gate_preflight_hash'),
                'final_process_start_authorization_hash' => data_get($authorizationPayload, 'codex_real_invoker_final_process_start_authorization_gate_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_guarded_process_start_gate_status' => data_get($guardedPayload, 'status'),
            'source_codex_real_invoker_final_process_start_authorization_gate_status' => data_get($authorizationPayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate',
                'method' => 'authorizePostStartFinalProcessStart',
                'input_contract' => ['run_key', 'post_start_final_process_start_authorization_gate_id', 'post_start_evidence_acceptance_bridge_id', 'post_start_guarded_process_start_gate_id', 'provider_start_attempt_id', 'codex_execution_id', 'real_invoker_guarded_process_start_id', 'real_invoker_final_process_start_authorization_id', 'operator_final_start_receipt_hash', 'final_start_signature_hash', 'final_start_policy_hash', 'final_start_window_hash', 'final_start_replay_guard_hash', 'final_start_kill_switch_hash', 'actor', 'session', 'reason'],
                'result_contract' => ['post_start_final_process_start_authorization_gate_id', 'post_start_evidence_acceptance_bridge_id', 'real_invoker_final_process_start_authorization_id', 'real_invoker_guarded_process_start_id', 'final_process_start_authorized', 'actual_process_start_allowed', 'dispatch_allowed'],
            ],
            'real_invoker_post_start_final_process_start_authorization_gate_must' => ['require_codex_real_invoker_post_start_guarded_process_start_metadata', 'require_post_start_evidence_acceptance_bridge_from_guarded_process_start', 'require_guarded_start_flags_blocking_process_token_dispatch', 'delegate_to_codex_real_invoker_final_process_start_authorization_gate', 'record_final_process_start_authorization_bridge_on_observed_post_start_run', 'require_actual_start_rehearsal_as_separate_later_contract'],
            'real_invoker_post_start_final_process_start_authorization_gate_must_not' => ['call_codex_cli_or_codex_app', 'spawn_process_or_shell_command', 'spend_provider_tokens', 'start_codex_process', 'dispatch_work_to_codex', 'enable_adapter_execution', 'mark_actual_process_start_allowed', 'mark_observed_run_running_or_terminal'],
            'implementation_files_allowed_future' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate.php', 'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGateTest.php'],
            'contract_policy' => ['template_is_read_only' => true, 'post_start_final_process_start_authorization_bridge_allowed_by_service' => true, 'codex_real_invoker_final_process_start_authorization_allowed_by_service' => true, 'final_process_start_authorized_here' => true, 'actual_process_start_allowed_here' => false, 'provider_process_start_allowed_here' => false, 'adapter_invocation_allowed_here' => false, 'adapter_execution_allowed_here' => false, 'codex_invocation_allowed_here' => false, 'token_spend_allowed_here' => false, 'dispatch_allowed_here' => false, 'requires_separate_actual_start_rehearsal_contract' => true],
            'source_post_start_guarded_process_start_gate_preflight' => $guarded,
            'source_codex_real_invoker_final_process_start_authorization_gate_preflight' => $authorization,
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-final-process-start-authorization-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_final_process_start_authorization_gate_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_final_process_start_authorization_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_final_process_start_authorization_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_final_process_start_authorization_gate_contract_template' => $template,
            'codex_real_invoker_post_start_final_process_start_authorization_gate_contract_template_hash' => ReadinessHash::stable($template),
            'source_post_start_guarded_process_start_gate_preflight' => $guarded,
            'source_codex_real_invoker_final_process_start_authorization_gate_preflight' => $authorization,
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_final_process_start_authorization_gate_contract_template_does_not_start_codex', 'agent_codex_real_invoker_post_start_final_process_start_authorization_gate_contract_template_does_not_call_codex', 'agent_codex_real_invoker_post_start_final_process_start_authorization_gate_contract_template_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_final_process_start_authorization_gate_contract_template_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start final process start authorization gate template records a final-start authorization bridge while actual start remains delegated to a later rehearsal/start contract.',
        ];
    }


public function agentCodexRealInvokerPostStartFinalProcessStartAuthorizationGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartFinalProcessStartAuthorizationGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_final_process_start_authorization_gate_contract_template', []);
        $gateReady = class_exists(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class);
        $postStartGuardedReady = class_exists(AgentCodexRealInvokerPostStartGuardedProcessStartExecutorGate::class);
        $authorizationReady = class_exists(AgentCodexRealInvokerFinalProcessStartAuthorizationGate::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([$gateReady ? null : 'codex_real_invoker_post_start_final_process_start_authorization_gate_missing', $postStartGuardedReady ? null : 'codex_real_invoker_post_start_guarded_process_start_executor_gate_missing', $authorizationReady ? null : 'codex_real_invoker_final_process_start_authorization_gate_missing', $runsTableReady ? null : 'agent_runs_table_missing', $ledgerReady ? null : 'ledger_table_missing']));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_final_process_start_authorization_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_final_process_start_authorization_gate_contract_template_hash'),
            'source_codex_real_invoker_post_start_guarded_process_start_gate_status' => data_get($contract, 'source_codex_real_invoker_post_start_guarded_process_start_gate_status'),
            'source_codex_real_invoker_final_process_start_authorization_gate_status' => data_get($contract, 'source_codex_real_invoker_final_process_start_authorization_gate_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => ['codex_real_invoker_post_start_final_process_start_authorization_gate_ready' => $gateReady, 'codex_real_invoker_post_start_guarded_process_start_executor_gate_ready' => $postStartGuardedReady, 'codex_real_invoker_final_process_start_authorization_gate_ready' => $authorizationReady, 'agent_runs_table_ready' => $runsTableReady, 'ledger_table_ready' => $ledgerReady],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_final_process_start_authorization_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => ['create_codex_real_invoker_post_start_final_process_start_authorization_gate', 'require_post_start_guarded_process_start_metadata', 'require_post_start_evidence_acceptance_bridge_from_guarded_process_start', 'delegate_to_codex_real_invoker_final_process_start_authorization_gate_without_invoking_codex', 'record_final_authorization_bridge_on_observed_run'],
            'required_gates' => ['php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate.php', 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGateTest.php', 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_final_process_start_authorization_gate', 'php artisan atlas:engineering:knowledge docs-health --json', 'php artisan atlas:ai:architecture-validate --json', 'git diff --check'],
            'preflight_policy' => ['preflight_is_read_only' => true, 'post_start_final_process_start_authorization_gate_file_creation_allowed_here' => false, 'post_start_final_process_start_authorization_bridge_allowed_by_service' => true, 'codex_real_invoker_final_process_start_authorization_allowed_by_service' => true, 'final_process_start_authorized_here' => true, 'actual_process_start_allowed_here' => false, 'provider_process_start_allowed_here' => false, 'adapter_invocation_allowed_here' => false, 'adapter_execution_allowed_here' => false, 'codex_invocation_allowed_here' => false, 'token_spend_allowed_here' => false, 'dispatch_allowed_here' => false, 'requires_separate_actual_start_rehearsal_contract' => true],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-final-process-start-authorization-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_final_process_start_authorization_gate_preflight' => $preflight,
            'codex_real_invoker_post_start_final_process_start_authorization_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight_does_not_start_codex', 'agent_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight_does_not_call_codex', 'agent_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_final_process_start_authorization_gate_preflight_does_not_dispatch_work'],
            'human_summary' => $blockingReasons === [] ? 'Codex real invoker post-start final process start authorization gate is ready; it records final authorization metadata while keeping actual start, token spend and dispatch blocked.' : 'Codex real invoker post-start final process start authorization gate is blocked until guarded start, final authorization and ledger prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartFinalProcessStartAuthorizationGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartFinalProcessStartAuthorizationGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_final_process_start_authorization_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_final_process_start_authorization_gate_preflight_hash');
        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start final process start authorization gate', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate.php'], 'acceptance' => 'Gate consumes post-start guarded process start metadata, including the accepted evidence bridge id, and delegates to Codex real invoker final process start authorization gate without invoking Codex.'],
            ['id' => 'T2', 'title' => 'Enforce post-start final authorization policy', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate.php'], 'acceptance' => 'Gate records final authorization metadata while keeping actual process start, token spend, adapter execution and dispatch flags false.'],
            ['id' => 'T3', 'title' => 'Add post-start final authorization tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGateTest.php'], 'acceptance' => 'Tests prove final authorization preparation, idempotency, duplicate rejection, missing guarded bridge rejection, missing evidence bridge rejection, forbidden process-start rejection, missing provider run rejection and rollback.'],
            ['id' => 'T4', 'title' => 'Expose post-start final authorization readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare actual start rehearsal still separate.'],
        ];
        $packet = ['status' => 'ready_for_scoped_codex_real_invoker_post_start_final_process_start_authorization_gate_implementation', 'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-FINAL-PROCESS-START-AUTHORIZATION-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001', 'source_preflight_hash' => $preflightHash, 'objective' => 'Implement the Codex real invoker post-start final process start authorization gate that bridges guarded process start to final authorization while forbidding actual process start and dispatch.', 'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_enable_adapter_execution', 'do_not_run_real_external_process_invoker', 'do_not_run_actual_start_rehearsal'], 'allowed_files' => data_get($preflight, 'allowed_future_files', []), 'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'real_external_process_invoker_execution', 'provider_process_call', 'adapter_execution_runtime', 'actual_process_start_runtime', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'], 'tasks' => $tasks, 'task_count' => count($tasks), 'acceptance_criteria' => ['post_start_final_process_start_authorization_gate_requires_guarded_process_start_bridge_metadata', 'post_start_final_process_start_authorization_gate_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_final_process_start_authorization_gate_delegates_to_codex_real_invoker_final_process_start_authorization_gate', 'post_start_final_process_start_authorization_gate_records_observed_bridge_metadata', 'post_start_final_process_start_authorization_gate_does_not_start_codex_or_dispatch_work'], 'required_gates' => data_get($preflight, 'required_gates', []), 'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'post_start_final_process_start_authorization_bridge_allowed_by_service' => true, 'final_process_start_authorized_by_packet' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'adapter_invocation_allowed_by_packet' => false, 'adapter_execution_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false, 'requires_separate_actual_start_rehearsal_contract' => true]];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_final_process_start_authorization_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_final_process_start_authorization_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_final_process_start_authorization_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_final_process_start_authorization_gate_implementation_packet' => $packet,
            'codex_real_invoker_post_start_final_process_start_authorization_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_final_process_start_authorization_gate_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_final_process_start_authorization_gate_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_final_process_start_authorization_gate_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_final_process_start_authorization_gate_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start final process start authorization gate implementation packet is ready; it records final authorization metadata but does not start Codex or dispatch work.',
        ];
    }


public function agentCodexRealInvokerPostStartActualProcessStartRehearsalGateContractTemplate(array $options = []): array
    {
        $authorizationPayload = $this->section->agentCodexRealInvokerPostStartFinalProcessStartAuthorizationGatePreflight($options);
        $authorization = (array) data_get($authorizationPayload, 'codex_real_invoker_post_start_final_process_start_authorization_gate_preflight', []);
        $rehearsalPayload = $this->section->agentCodexRealInvokerActualProcessStartRehearsalExecutorPreflight($options);
        $rehearsal = (array) data_get($rehearsalPayload, 'codex_real_invoker_actual_process_start_rehearsal_executor_preflight', []);

        $template = [
            'status' => 'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_template_ready',
            'contract_id' => 'CODEX-REAL-INVOKER-POST-START-ACTUAL-PROCESS-START-REHEARSAL-'.strtoupper(substr(ReadinessHash::stable([
                'post_start_final_authorization_hash' => data_get($authorizationPayload, 'codex_real_invoker_post_start_final_process_start_authorization_gate_preflight_hash'),
                'actual_process_start_rehearsal_hash' => data_get($rehearsalPayload, 'codex_real_invoker_actual_process_start_rehearsal_executor_preflight_hash'),
                'provider' => 'codex',
                'adapter' => 'codex',
            ]), 0, 24)),
            'source_codex_real_invoker_post_start_final_process_start_authorization_gate_status' => data_get($authorizationPayload, 'status'),
            'source_codex_real_invoker_actual_process_start_rehearsal_executor_status' => data_get($rehearsalPayload, 'status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'contract' => [
                'service' => 'App\\Services\\Ai\\SelfConstruction\\AgentCodexRealInvokerPostStartActualProcessStartRehearsalGate',
                'method' => 'rehearsePostStartActualProcessStart',
                'input_contract' => ['run_key', 'post_start_actual_process_start_rehearsal_gate_id', 'post_start_final_process_start_authorization_gate_id', 'post_start_evidence_acceptance_bridge_id', 'provider_start_attempt_id', 'codex_execution_id', 'real_invoker_final_process_start_authorization_id', 'real_invoker_actual_process_start_rehearsal_id', 'process_start_rehearsal_hash', 'command_resolution_hash', 'environment_resolution_hash', 'cwd_verification_hash', 'supervisor_dry_run_hash', 'liveness_probe_rehearsal_hash', 'actor', 'session', 'reason'],
                'result_contract' => ['post_start_actual_process_start_rehearsal_gate_id', 'post_start_evidence_acceptance_bridge_id', 'real_invoker_actual_process_start_rehearsal_id', 'real_invoker_final_process_start_authorization_id', 'process_start_rehearsed', 'actual_process_start_allowed', 'dispatch_allowed'],
            ],
            'real_invoker_post_start_actual_process_start_rehearsal_gate_must' => ['require_codex_real_invoker_post_start_final_process_start_authorization_metadata', 'require_post_start_evidence_acceptance_bridge_from_final_process_start_authorization', 'require_final_authorization_flags_blocking_process_token_dispatch', 'delegate_to_codex_real_invoker_actual_process_start_rehearsal_executor', 'record_actual_process_start_rehearsal_bridge_on_observed_post_start_run', 'require_process_start_envelope_as_separate_later_contract'],
            'real_invoker_post_start_actual_process_start_rehearsal_gate_must_not' => ['call_codex_cli_or_codex_app', 'spawn_process_or_shell_command', 'spend_provider_tokens', 'start_codex_process', 'dispatch_work_to_codex', 'enable_adapter_execution', 'mark_actual_process_start_allowed', 'mark_observed_run_running_or_terminal'],
            'implementation_files_allowed_future' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartActualProcessStartRehearsalGate.php', 'tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartActualProcessStartRehearsalGateTest.php'],
            'contract_policy' => ['template_is_read_only' => true, 'post_start_actual_process_start_rehearsal_bridge_allowed_by_service' => true, 'codex_real_invoker_actual_process_start_rehearsal_allowed_by_service' => true, 'process_start_rehearsed_here' => true, 'actual_process_start_allowed_here' => false, 'provider_process_start_allowed_here' => false, 'adapter_invocation_allowed_here' => false, 'adapter_execution_allowed_here' => false, 'codex_invocation_allowed_here' => false, 'token_spend_allowed_here' => false, 'dispatch_allowed_here' => false, 'requires_separate_process_start_envelope_contract' => true],
            'source_post_start_final_process_start_authorization_gate_preflight' => $authorization,
            'source_codex_real_invoker_actual_process_start_rehearsal_executor_preflight' => $rehearsal,
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-actual-process-start-rehearsal-gate-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_template.v1',
            'status' => 'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_template_ready',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_template' => $template,
            'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_template_hash' => ReadinessHash::stable($template),
            'source_post_start_final_process_start_authorization_gate_preflight' => $authorization,
            'source_codex_real_invoker_actual_process_start_rehearsal_executor_preflight' => $rehearsal,
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_template_does_not_start_codex', 'agent_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_template_does_not_call_codex', 'agent_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_template_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_template_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start actual process start rehearsal gate template records rehearsal metadata while process start remains delegated to a later envelope/start contract.',
        ];
    }


public function agentCodexRealInvokerPostStartActualProcessStartRehearsalGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentCodexRealInvokerPostStartActualProcessStartRehearsalGateContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_template', []);
        $gateReady = class_exists(AgentCodexRealInvokerPostStartActualProcessStartRehearsalGate::class);
        $postStartAuthorizationReady = class_exists(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class);
        $rehearsalReady = class_exists(AgentCodexRealInvokerActualProcessStartRehearsalExecutor::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerReady = Schema::hasTable('atlas_ledger_events');

        $blockingReasons = array_values(array_filter([$gateReady ? null : 'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_missing', $postStartAuthorizationReady ? null : 'codex_real_invoker_post_start_final_process_start_authorization_gate_missing', $rehearsalReady ? null : 'codex_real_invoker_actual_process_start_rehearsal_executor_missing', $runsTableReady ? null : 'agent_runs_table_missing', $ledgerReady ? null : 'ledger_table_missing']));

        $preflight = [
            'status' => $blockingReasons === [] ? 'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_ready' : 'blocked',
            'contract_template_hash' => data_get($contractPayload, 'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract_template_hash'),
            'source_codex_real_invoker_post_start_final_process_start_authorization_gate_status' => data_get($contract, 'source_codex_real_invoker_post_start_final_process_start_authorization_gate_status'),
            'source_codex_real_invoker_actual_process_start_rehearsal_executor_status' => data_get($contract, 'source_codex_real_invoker_actual_process_start_rehearsal_executor_status'),
            'provider' => 'codex',
            'adapter' => 'codex',
            'storage' => ['codex_real_invoker_post_start_actual_process_start_rehearsal_gate_ready' => $gateReady, 'codex_real_invoker_post_start_final_process_start_authorization_gate_ready' => $postStartAuthorizationReady, 'codex_real_invoker_actual_process_start_rehearsal_executor_ready' => $rehearsalReady, 'agent_runs_table_ready' => $runsTableReady, 'ledger_table_ready' => $ledgerReady],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'post_start_actual_process_start_rehearsal_gate_ready_for_future_use' => $blockingReasons === [],
            'allowed_future_files' => data_get($contract, 'implementation_files_allowed_future', []),
            'required_first_changes' => ['create_codex_real_invoker_post_start_actual_process_start_rehearsal_gate', 'require_post_start_final_process_start_authorization_metadata', 'require_post_start_evidence_acceptance_bridge_from_final_process_start_authorization', 'delegate_to_codex_real_invoker_actual_process_start_rehearsal_executor_without_invoking_codex', 'record_rehearsal_bridge_on_observed_run'],
            'required_gates' => ['php -l app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartActualProcessStartRehearsalGate.php', 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartActualProcessStartRehearsalGateTest.php', 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_codex_real_invoker_post_start_actual_process_start_rehearsal_gate', 'php artisan atlas:engineering:knowledge docs-health --json', 'php artisan atlas:ai:architecture-validate --json', 'git diff --check'],
            'preflight_policy' => ['preflight_is_read_only' => true, 'post_start_actual_process_start_rehearsal_gate_file_creation_allowed_here' => false, 'post_start_actual_process_start_rehearsal_bridge_allowed_by_service' => true, 'codex_real_invoker_actual_process_start_rehearsal_allowed_by_service' => true, 'process_start_rehearsed_here' => true, 'actual_process_start_allowed_here' => false, 'provider_process_start_allowed_here' => false, 'adapter_invocation_allowed_here' => false, 'adapter_execution_allowed_here' => false, 'codex_invocation_allowed_here' => false, 'token_spend_allowed_here' => false, 'dispatch_allowed_here' => false, 'requires_separate_process_start_envelope_contract' => true],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-codex-real-invoker-post-start-actual-process-start-rehearsal-gate-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight' => $preflight,
            'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight_does_not_start_codex', 'agent_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight_does_not_call_codex', 'agent_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight_does_not_dispatch_work'],
            'human_summary' => $blockingReasons === [] ? 'Codex real invoker post-start actual process start rehearsal gate is ready; it records rehearsal metadata while keeping actual start, token spend and dispatch blocked.' : 'Codex real invoker post-start actual process start rehearsal gate is blocked until final authorization, rehearsal executor and ledger prerequisites exist.',
        ];
    }


public function agentCodexRealInvokerPostStartActualProcessStartRehearsalGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentCodexRealInvokerPostStartActualProcessStartRehearsalGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_preflight_hash');
        $tasks = [
            ['id' => 'T1', 'title' => 'Create Codex real invoker post-start actual process start rehearsal gate', 'type' => 'service', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartActualProcessStartRehearsalGate.php'], 'acceptance' => 'Gate consumes post-start final authorization metadata, including the accepted evidence bridge id, and delegates to Codex real invoker actual process start rehearsal executor without invoking Codex.'],
            ['id' => 'T2', 'title' => 'Enforce post-start actual rehearsal policy', 'type' => 'service_logic', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartActualProcessStartRehearsalGate.php'], 'acceptance' => 'Gate records rehearsal metadata while keeping actual process start, token spend, adapter execution and dispatch flags false.'],
            ['id' => 'T3', 'title' => 'Add post-start actual rehearsal tests', 'type' => 'test', 'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartActualProcessStartRehearsalGateTest.php'], 'acceptance' => 'Tests prove rehearsal preparation, idempotency, duplicate rejection, missing final authorization bridge rejection, missing evidence bridge rejection, forbidden process-start rejection, missing provider run rejection and rollback.'],
            ['id' => 'T4', 'title' => 'Expose post-start actual rehearsal readiness commands', 'type' => 'command_surface', 'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php', 'app/Console/Commands/AtlasAiSelfConstructionCommand.php', 'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'], 'acceptance' => 'Contract, preflight and implementation packet commands remain read-only and declare process start envelope still separate.'],
        ];
        $packet = ['status' => 'ready_for_scoped_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_implementation', 'implementation_packet_id' => 'AGENT-CODEX-REAL-INVOKER-POST-START-ACTUAL-PROCESS-START-REHEARSAL-GATE-IMPLEMENTATION-SELF-CONSTRUCTION-0001', 'source_preflight_hash' => $preflightHash, 'objective' => 'Implement the Codex real invoker post-start actual process start rehearsal gate that bridges final authorization to actual-start rehearsal while forbidding actual process start and dispatch.', 'non_goals' => ['do_not_call_codex_cli_or_codex_app', 'do_not_spawn_processes_or_shell_commands', 'do_not_spend_provider_tokens', 'do_not_start_codex_process', 'do_not_dispatch_work_to_codex', 'do_not_enable_adapter_execution', 'do_not_run_real_external_process_invoker', 'do_not_build_process_start_envelope'], 'allowed_files' => data_get($preflight, 'allowed_future_files', []), 'forbidden_scopes' => ['actual_codex_process_invocation', 'provider_token_spend', 'real_external_process_invoker_execution', 'provider_process_call', 'adapter_execution_runtime', 'actual_process_start_runtime', 'dispatch_runtime', 'merge_runtime', 'hot_kernel_runtime', 'policy_mutation'], 'tasks' => $tasks, 'task_count' => count($tasks), 'acceptance_criteria' => ['post_start_actual_process_start_rehearsal_gate_requires_final_authorization_bridge_metadata', 'post_start_actual_process_start_rehearsal_gate_requires_post_start_evidence_acceptance_bridge_metadata', 'post_start_actual_process_start_rehearsal_gate_delegates_to_codex_real_invoker_actual_process_start_rehearsal_executor', 'post_start_actual_process_start_rehearsal_gate_records_observed_bridge_metadata', 'post_start_actual_process_start_rehearsal_gate_does_not_start_codex_or_dispatch_work'], 'required_gates' => data_get($preflight, 'required_gates', []), 'implementation_policy' => ['packet_is_read_only' => true, 'implementation_allowed_by_packet' => true, 'post_start_actual_process_start_rehearsal_bridge_allowed_by_service' => true, 'process_start_rehearsed_by_packet' => true, 'actual_process_start_allowed_by_packet' => false, 'provider_process_start_allowed_by_packet' => false, 'adapter_invocation_allowed_by_packet' => false, 'adapter_execution_allowed_by_packet' => false, 'codex_invocation_allowed_by_packet' => false, 'token_spend_allowed_by_packet' => false, 'dispatch_allowed_by_packet' => false, 'requires_separate_process_start_envelope_contract' => true]];

        return [
            'schema_version' => 'atlas.self_construction_agent_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_implementation_packet.v1',
            'status' => 'ready_for_scoped_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_implementation',
            'mode' => 'read_only_agent_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_implementation_packet' => $packet,
            'codex_real_invoker_post_start_actual_process_start_rehearsal_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => ['agent_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_implementation_packet_does_not_start_codex', 'agent_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_implementation_packet_does_not_call_codex', 'agent_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_implementation_packet_does_not_spend_tokens', 'agent_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_implementation_packet_does_not_dispatch_work'],
            'human_summary' => 'Codex real invoker post-start actual process start rehearsal gate implementation packet is ready; it records rehearsal metadata but does not start Codex or dispatch work.',
        ];
    }


}
