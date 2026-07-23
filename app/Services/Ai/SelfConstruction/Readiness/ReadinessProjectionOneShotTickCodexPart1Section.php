<?php

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Models\AtlasSelfConstructionAgentRun;
use Illuminate\Support\Facades\Schema;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexProcessSpawnEnablementInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorInvoker;
use App\Services\Ai\SelfConstruction\Support\AgentCodexProcessSpawnEnablementGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexProcessSpawnExecutor;
use App\Services\Ai\SelfConstruction\Support\AgentCodexProcessStartReleaseGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexSupervisedStartExecutor;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * GOD-DEBULK extracted projection family from AtlasSelfConstructionReadinessService.
 * Pure read-only projections. The mother is passed as $parent so cross-family sibling
 * calls route back through the preserved public mother surface (ReflectionMethod /
 * method_exists probes on the mother stay intact).
 */
final class ReadinessProjectionOneShotTickCodexPart1Section
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseContract(array $options = []): array
    {
        $providerSpecificStatusPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickProviderSpecificExecutionContractStatus($options);
        $providerSpecificStatus = (array) data_get($providerSpecificStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_status', []);
        $codexReleasePayload = $this->parent->agentCodexProcessStartReleaseContractTemplate($options);
        $codexRelease = (array) data_get($codexReleasePayload, 'codex_process_start_release_contract_template', []);

        $contract = [
            'status' => 'one_shot_tick_codex_process_start_release_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-PROCESS-START-RELEASE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_provider_specific_execution_contract_status' => data_get($providerSpecificStatus, 'status'),
            'source_provider_specific_execution_contract_status_hash' => data_get($providerSpecificStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_status_hash'),
            'source_codex_process_start_release_contract_status' => data_get($codexReleasePayload, 'status'),
            'source_codex_process_start_release_contract_hash' => data_get($codexReleasePayload, 'codex_process_start_release_contract_template_hash'),
            'release_boundary' => [
                'canonical_gate' => AgentCodexProcessStartReleaseGate::class,
                'canonical_gate_method' => 'authorizeCodexProcessStart',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseInvoker::class,
                'scheduler_invoker_method' => 'authorizeCodexProcessStartRelease',
                'gate_effect' => 'authorize_future_supervised_codex_start_release_without_process_start',
                'external_process_started_by_gate' => false,
                'provider_started_by_gate' => false,
                'adapter_execution_allowed_by_gate' => false,
                'token_spend_allowed_by_gate' => false,
                'required_provider_execution_status_before_gate' => 'prepared_pending_explicit_codex_process_release',
                'prepared_status_after_gate' => 'authorized_pending_supervised_start_executor',
                'idempotency_key' => 'process_start_release_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'process_start_release_id',
                'operator_release_receipt_hash',
                'codex_execution_contract_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_process_start_release_metadata_on_agent_run',
                'append_codex_process_start_release_authorized_evidence_event',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'mark_packet_completed',
                'merge_work_products',
                'enable_self_programming',
            ],
            'handoff_policy' => [
                'process_start_release_is_authorization_not_process_start' => true,
                'supervised_start_executor_requires_separate_signed_release' => true,
                'operator_release_receipt_hash_required' => true,
                'codex_execution_contract_hash_required' => true,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_process_start_release_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_process_start_release_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_contract_hash' => ReadinessHash::stable($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_contract_does_not_call_release_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_contract_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex process start release contract is ready; it authorizes a future supervised path but still cannot start Codex.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleasePreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_contract_hash');
        $gateReady = class_exists(AgentCodexProcessStartReleaseGate::class)
            && method_exists(AgentCodexProcessStartReleaseGate::class, 'authorizeCodexProcessStart');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseInvoker::class, 'authorizeCodexProcessStartRelease');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'release_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_process_start_release_contract_ready',
            'release_contract_hash_present' => $contractHash !== '',
            'provider_specific_execution_contract_service_ready' => data_get($contract, 'source_provider_specific_execution_contract_status') === 'one_shot_tick_provider_specific_execution_contract_service_ready',
            'codex_process_start_release_contract_template_ready' => data_get($contract, 'source_codex_process_start_release_contract_status') === 'codex_process_start_release_contract_template_ready',
            'codex_process_start_release_gate_ready' => $gateReady,
            'codex_process_start_release_invoker_ready' => $invokerReady,
            'canonical_gate_method_ready' => data_get($contract, 'release_boundary.canonical_gate_method') === 'authorizeCodexProcessStart',
            'gate_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_gate') === false,
            'gate_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_gate') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_process_start_release_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-PROCESS-START-RELEASE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_release_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_process_start_release_invoker',
                'delegate_to_agent_codex_process_start_release_gate',
                'require_codex_provider_execution_prepared_metadata',
                'require_operator_release_receipt_hash',
                'preserve_no_codex_process_start_after_release_authorization',
                'return_codex_process_start_release_authorized_result_without_spawning_codex_process',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_process_start_release_gate_call_allowed_here' => false,
                'codex_process_start_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_process_start_release_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_process_start_release_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_preflight_does_not_call_release_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_preflight_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex process start release preflight is ready; the next slice can expose the scoped release invoker packet.'
                : 'Automatic dispatch scheduler one-shot tick Codex process start release preflight is blocked until provider execution, release gate, storage and no-process-start prerequisites are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleasePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_process_start_release_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-PROCESS-START-RELEASE-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_process_start_release_preflight_status' => data_get($preflight, 'status'),
            'source_codex_process_start_release_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex process start release invoker', 'type' => 'service', 'acceptance' => 'Invoker validates release input and delegates to AgentCodexProcessStartReleaseGate.'],
                ['id' => 'T2', 'title' => 'Preserve no Codex process start boundary', 'type' => 'service_logic', 'acceptance' => 'Invoker authorizes release metadata while process start, token spend and self-programming remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex process start release invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful release authorization, idempotent retry, invalid receipt hash rejection, missing Codex execution metadata and duplicate release rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex process start release status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next supervised start release slice without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_process_start_release_invoker_authorizes_release_without_starting_codex',
                'codex_process_start_release_invoker_requires_codex_provider_execution_metadata',
                'codex_process_start_release_invoker_requires_operator_release_receipt_hash',
                'codex_process_start_release_invoker_is_idempotent_by_process_start_release_id',
                'codex_process_start_release_invoker_never_starts_codex_or_spends_tokens',
            ],
            'required_gates' => [
                'php_lint_codex_process_start_release_invoker',
                'dedicated_codex_process_start_release_invoker_feature_tests',
                'generic_codex_process_start_release_gate_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_process_start_release_gate_call_allowed_by_future_invoker' => true,
                'codex_process_start_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'stop_conditions' => [
                'need_to_spawn_codex_process',
                'need_to_call_codex_cli_or_codex_app',
                'need_to_spend_provider_tokens',
                'need_to_mark_run_running_or_terminal',
                'need_to_enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_process_start_release_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_process_start_release_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_implementation_packet_does_not_call_release_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_implementation_packet_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex process start release implementation packet is ready; it scopes release authorization and still stops before supervised start.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexProcessStartReleaseGate::class)
            && method_exists(AgentCodexProcessStartReleaseGate::class, 'authorizeCodexProcessStart');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseInvoker::class, 'authorizeCodexProcessStartRelease');

        $releasedRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_process_start_release->process_start_release_id')
            : null;
        $latestReleasedRun = $releasedRunsQuery === null
            ? null
            : (clone $releasedRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady && $ledgerTableReady && $gateReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_process_start_release_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'authorizeCodexProcessStartRelease',
            'generic_gate_service' => AgentCodexProcessStartReleaseGate::class,
            'generic_gate_service_ready' => $gateReady,
            'generic_gate_canonical_method' => 'authorizeCodexProcessStart',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_process_start_released_run_count' => $releasedRunsQuery === null ? null : (clone $releasedRunsQuery)->count(),
            'latest_codex_process_start_released_run' => $latestReleasedRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestReleasedRun->id,
                    'run_key' => $latestReleasedRun->run_key,
                    'packet_id' => $latestReleasedRun->packet_id,
                    'provider' => $latestReleasedRun->provider,
                    'status' => $latestReleasedRun->status,
                    'process_start_release_id' => data_get($latestReleasedRun->metadata, 'codex_process_start_release.process_start_release_id'),
                    'codex_execution_id' => data_get($latestReleasedRun->metadata, 'codex_process_start_release.codex_execution_id'),
                    'process_start_release_authorized' => data_get($latestReleasedRun->metadata, 'codex_process_start_release.process_start_release_authorized'),
                    'external_process_started' => data_get($latestReleasedRun->metadata, 'codex_process_start_release.external_process_started'),
                    'token_spend_allowed' => data_get($latestReleasedRun->metadata, 'codex_process_start_release.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_authorize_codex_process_start_release_when_called_with_signed_input' => true,
                'process_start_release_is_not_process_start' => true,
                'codex_process_start_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_supervised_start_executor_release_contract'
                : 'repair_one_shot_scheduler_tick_codex_process_start_release_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_process_start_release_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_status_does_not_call_release_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_status_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_start_release_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex process start release service is ready and inspectable; status remains read-only and supervised start is still separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex process start release service is blocked until invoker, generic gate and ledger are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorPreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorReleaseContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_release_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_release_contract_hash');
        $executorReady = class_exists(AgentCodexSupervisedStartExecutor::class)
            && method_exists(AgentCodexSupervisedStartExecutor::class, 'prepareSupervisedStart');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorInvoker::class, 'prepareCodexSupervisedStart');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'release_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_supervised_start_executor_release_contract_ready',
            'release_contract_hash_present' => $contractHash !== '',
            'process_start_release_service_ready' => data_get($contract, 'source_codex_process_start_release_status') === 'one_shot_tick_codex_process_start_release_service_ready',
            'codex_supervised_start_executor_contract_template_ready' => data_get($contract, 'source_codex_supervised_start_executor_contract_status') === 'codex_supervised_start_executor_contract_template_ready',
            'codex_supervised_start_executor_ready' => $executorReady,
            'codex_supervised_start_invoker_ready' => $invokerReady,
            'canonical_executor_method_ready' => data_get($contract, 'release_boundary.canonical_executor_method') === 'prepareSupervisedStart',
            'executor_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_executor') === false,
            'executor_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_executor') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_supervised_start_executor_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-SUPERVISED-START-EXECUTOR-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_release_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_supervised_start_executor_invoker',
                'delegate_to_agent_codex_supervised_start_executor',
                'require_codex_process_start_release_authorized_metadata',
                'require_operator_release_receipt_hash',
                'require_sanitizer_probe_and_rollback_hashes',
                'preserve_no_codex_process_spawn_after_supervised_start_preparation',
                'return_codex_supervised_start_prepared_result_without_spawning_codex_process',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_supervised_start_executor_call_allowed_here' => false,
                'codex_process_spawn_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_supervised_start_executor_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_supervised_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_preflight_does_not_call_supervised_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_preflight_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex supervised start executor preflight is ready; the next slice can expose the scoped invoker packet.'
                : 'Automatic dispatch scheduler one-shot tick Codex supervised start executor preflight is blocked until release, executor, storage and no-process-spawn prerequisites are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_supervised_start_executor_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-SUPERVISED-START-EXECUTOR-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_supervised_start_executor_preflight_status' => data_get($preflight, 'status'),
            'source_codex_supervised_start_executor_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex supervised start executor invoker', 'type' => 'service', 'acceptance' => 'Invoker validates supervised start input and delegates to AgentCodexSupervisedStartExecutor.'],
                ['id' => 'T2', 'title' => 'Preserve no Codex process spawn boundary', 'type' => 'service_logic', 'acceptance' => 'Invoker prepares supervised start metadata while process spawn, token spend and self-programming remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex supervised start executor invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful supervised preparation, idempotent retry, invalid supervision hash rejection, missing release metadata and duplicate start rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex supervised start executor status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next process spawn enablement slice without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_supervised_start_invoker_prepares_without_starting_codex',
                'codex_supervised_start_invoker_requires_process_start_release_metadata',
                'codex_supervised_start_invoker_requires_sanitizer_probe_and_rollback_hashes',
                'codex_supervised_start_invoker_is_idempotent_by_supervised_start_id',
                'codex_supervised_start_invoker_never_spawns_codex_or_spends_tokens',
            ],
            'required_gates' => [
                'php_lint_codex_supervised_start_invoker',
                'dedicated_codex_supervised_start_invoker_feature_tests',
                'generic_codex_supervised_start_executor_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_supervised_start_executor_call_allowed_by_future_invoker' => true,
                'codex_process_spawn_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'stop_conditions' => [
                'need_to_spawn_codex_process',
                'need_to_call_codex_cli_or_codex_app',
                'need_to_spend_provider_tokens',
                'need_to_mark_run_running_or_terminal',
                'need_to_enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_supervised_start_executor_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_supervised_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_implementation_packet_does_not_call_supervised_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_implementation_packet_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex supervised start executor implementation packet is ready; it scopes supervised preparation and still stops before process spawn.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $executorReady = class_exists(AgentCodexSupervisedStartExecutor::class)
            && method_exists(AgentCodexSupervisedStartExecutor::class, 'prepareSupervisedStart');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorInvoker::class, 'prepareCodexSupervisedStart');

        $preparedRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_supervised_start->supervised_start_id')
            : null;
        $latestPreparedRun = $preparedRunsQuery === null
            ? null
            : (clone $preparedRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady && $ledgerTableReady && $executorReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_supervised_start_executor_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexSupervisedStart',
            'generic_executor_service' => AgentCodexSupervisedStartExecutor::class,
            'generic_executor_service_ready' => $executorReady,
            'generic_executor_canonical_method' => 'prepareSupervisedStart',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_supervised_start_prepared_run_count' => $preparedRunsQuery === null ? null : (clone $preparedRunsQuery)->count(),
            'latest_codex_supervised_start_prepared_run' => $latestPreparedRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestPreparedRun->id,
                    'run_key' => $latestPreparedRun->run_key,
                    'packet_id' => $latestPreparedRun->packet_id,
                    'provider' => $latestPreparedRun->provider,
                    'status' => $latestPreparedRun->status,
                    'supervised_start_id' => data_get($latestPreparedRun->metadata, 'codex_supervised_start.supervised_start_id'),
                    'process_start_release_id' => data_get($latestPreparedRun->metadata, 'codex_supervised_start.process_start_release_id'),
                    'codex_execution_id' => data_get($latestPreparedRun->metadata, 'codex_supervised_start.codex_execution_id'),
                    'supervised_start_prepared' => data_get($latestPreparedRun->metadata, 'codex_supervised_start.supervised_start_prepared'),
                    'external_process_started' => data_get($latestPreparedRun->metadata, 'codex_supervised_start.external_process_started'),
                    'token_spend_allowed' => data_get($latestPreparedRun->metadata, 'codex_supervised_start.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_codex_supervised_start_when_called_with_signed_input' => true,
                'supervised_start_executor_is_not_process_spawn' => true,
                'codex_process_spawn_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_process_spawn_enablement_contract'
                : 'repair_one_shot_scheduler_tick_codex_supervised_start_executor_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_supervised_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_status_does_not_call_supervised_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_status_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex supervised start executor service is ready and inspectable; status remains read-only and process spawn is still separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex supervised start executor service is blocked until invoker, generic executor and ledger are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexProcessSpawnEnablementContract(array $options = []): array
    {
        $supervisedStatusPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorStatus($options);
        $supervisedStatus = (array) data_get($supervisedStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_status', []);
        $enablementPayload = $this->parent->agentCodexProcessSpawnEnablementContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_process_spawn_enablement_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-PROCESS-SPAWN-ENABLEMENT-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_supervised_start_executor_status' => data_get($supervisedStatus, 'status'),
            'source_codex_supervised_start_executor_status_hash' => data_get($supervisedStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_supervised_start_executor_status_hash'),
            'source_codex_process_spawn_enablement_contract_status' => data_get($enablementPayload, 'status'),
            'source_codex_process_spawn_enablement_contract_hash' => data_get($enablementPayload, 'codex_process_spawn_enablement_contract_template_hash'),
            'release_boundary' => [
                'canonical_gate' => AgentCodexProcessSpawnEnablementGate::class,
                'canonical_gate_method' => 'enableCodexProcessSpawn',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexProcessSpawnEnablementInvoker::class,
                'scheduler_invoker_method' => 'enableCodexProcessSpawn',
                'gate_effect' => 'record_codex_process_spawn_enablement_without_process_spawn',
                'external_process_started_by_gate' => false,
                'provider_started_by_gate' => false,
                'adapter_execution_allowed_by_gate' => false,
                'token_spend_allowed_by_gate' => false,
                'required_supervised_start_status_before_gate' => 'prepared_pending_process_spawn_enablement_contract',
                'prepared_status_after_gate' => 'enabled_pending_final_process_spawn_executor',
                'idempotency_key' => 'spawn_enablement_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'process_start_release_id',
                'supervised_start_id',
                'spawn_enablement_id',
                'operator_spawn_receipt_hash',
                'supervised_start_contract_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_process_spawn_enablement_metadata_on_agent_run',
                'append_codex_process_spawn_enablement_recorded_evidence_event',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'mark_packet_completed',
                'merge_work_products',
                'enable_self_programming',
            ],
            'handoff_policy' => [
                'process_spawn_enablement_is_authorization_not_spawn' => true,
                'final_process_spawn_executor_requires_separate_signed_release' => true,
                'operator_spawn_receipt_hash_required' => true,
                'supervised_start_contract_hash_required' => true,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_process_spawn_enablement_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_process_spawn_enablement_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_contract_hash' => ReadinessHash::stable($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_contract_does_not_call_enablement_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_contract_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex process spawn enablement contract is ready; it records authorization for a later final spawn executor but still cannot spawn Codex.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexProcessSpawnEnablementPreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexProcessSpawnEnablementContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_contract_hash');
        $gateReady = class_exists(AgentCodexProcessSpawnEnablementGate::class)
            && method_exists(AgentCodexProcessSpawnEnablementGate::class, 'enableCodexProcessSpawn');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexProcessSpawnEnablementInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexProcessSpawnEnablementInvoker::class, 'enableCodexProcessSpawn');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'release_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_process_spawn_enablement_contract_ready',
            'release_contract_hash_present' => $contractHash !== '',
            'supervised_start_executor_service_ready' => data_get($contract, 'source_codex_supervised_start_executor_status') === 'one_shot_tick_codex_supervised_start_executor_service_ready',
            'codex_process_spawn_enablement_contract_template_ready' => data_get($contract, 'source_codex_process_spawn_enablement_contract_status') === 'codex_process_spawn_enablement_contract_template_ready',
            'codex_process_spawn_enablement_gate_ready' => $gateReady,
            'codex_process_spawn_enablement_invoker_ready' => $invokerReady,
            'canonical_gate_method_ready' => data_get($contract, 'release_boundary.canonical_gate_method') === 'enableCodexProcessSpawn',
            'gate_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_gate') === false,
            'gate_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_gate') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_process_spawn_enablement_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-PROCESS-SPAWN-ENABLEMENT-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_release_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_process_spawn_enablement_invoker',
                'delegate_to_agent_codex_process_spawn_enablement_gate',
                'require_codex_supervised_start_prepared_metadata',
                'require_operator_spawn_receipt_hash',
                'require_supervised_start_contract_hash',
                'preserve_no_codex_process_spawn_after_enablement_recording',
                'return_codex_process_spawn_enablement_recorded_result_without_spawning_codex_process',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_process_spawn_enablement_gate_call_allowed_here' => false,
                'codex_process_spawn_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_process_spawn_enablement_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_process_spawn_enablement_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_preflight_does_not_call_enablement_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_preflight_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex process spawn enablement preflight is ready; the next slice can expose the scoped invoker packet.'
                : 'Automatic dispatch scheduler one-shot tick Codex process spawn enablement preflight is blocked until supervised start, enablement gate, storage and no-process-spawn prerequisites are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexProcessSpawnEnablementImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexProcessSpawnEnablementPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_process_spawn_enablement_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-PROCESS-SPAWN-ENABLEMENT-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_process_spawn_enablement_preflight_status' => data_get($preflight, 'status'),
            'source_codex_process_spawn_enablement_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexProcessSpawnEnablementInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexProcessSpawnEnablementInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex process spawn enablement invoker', 'type' => 'service', 'acceptance' => 'Invoker validates spawn enablement input and delegates to AgentCodexProcessSpawnEnablementGate.'],
                ['id' => 'T2', 'title' => 'Preserve no Codex process spawn boundary', 'type' => 'service_logic', 'acceptance' => 'Invoker records spawn enablement metadata while actual process spawn, token spend and self-programming remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex process spawn enablement invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful enablement, idempotent retry, invalid spawn hash rejection, missing supervised start metadata and duplicate enablement rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex process spawn enablement status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next final process spawn executor slice without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_process_spawn_enablement_invoker_records_without_starting_codex',
                'codex_process_spawn_enablement_invoker_requires_supervised_start_metadata',
                'codex_process_spawn_enablement_invoker_requires_spawn_receipt_and_contract_hashes',
                'codex_process_spawn_enablement_invoker_is_idempotent_by_spawn_enablement_id',
                'codex_process_spawn_enablement_invoker_never_spawns_codex_or_spends_tokens',
            ],
            'required_gates' => [
                'php_lint_codex_process_spawn_enablement_invoker',
                'dedicated_codex_process_spawn_enablement_invoker_feature_tests',
                'generic_codex_process_spawn_enablement_gate_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_process_spawn_enablement_gate_call_allowed_by_future_invoker' => true,
                'codex_process_spawn_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'stop_conditions' => [
                'need_to_spawn_codex_process',
                'need_to_call_codex_cli_or_codex_app',
                'need_to_spend_provider_tokens',
                'need_to_mark_run_running_or_terminal',
                'need_to_enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_process_spawn_enablement_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_process_spawn_enablement_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_implementation_packet_does_not_call_enablement_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_implementation_packet_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex process spawn enablement implementation packet is ready; it scopes enablement recording and still stops before final process spawn.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexProcessSpawnEnablementStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexProcessSpawnEnablementGate::class)
            && method_exists(AgentCodexProcessSpawnEnablementGate::class, 'enableCodexProcessSpawn');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexProcessSpawnEnablementInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexProcessSpawnEnablementInvoker::class, 'enableCodexProcessSpawn');

        $enabledRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_process_spawn_enablement->spawn_enablement_id')
            : null;
        $latestEnabledRun = $enabledRunsQuery === null
            ? null
            : (clone $enabledRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady && $ledgerTableReady && $gateReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_process_spawn_enablement_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexProcessSpawnEnablementInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'enableCodexProcessSpawn',
            'generic_gate_service' => AgentCodexProcessSpawnEnablementGate::class,
            'generic_gate_service_ready' => $gateReady,
            'generic_gate_canonical_method' => 'enableCodexProcessSpawn',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_process_spawn_enabled_run_count' => $enabledRunsQuery === null ? null : (clone $enabledRunsQuery)->count(),
            'latest_codex_process_spawn_enabled_run' => $latestEnabledRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestEnabledRun->id,
                    'run_key' => $latestEnabledRun->run_key,
                    'packet_id' => $latestEnabledRun->packet_id,
                    'provider' => $latestEnabledRun->provider,
                    'status' => $latestEnabledRun->status,
                    'spawn_enablement_id' => data_get($latestEnabledRun->metadata, 'codex_process_spawn_enablement.spawn_enablement_id'),
                    'supervised_start_id' => data_get($latestEnabledRun->metadata, 'codex_process_spawn_enablement.supervised_start_id'),
                    'process_start_release_id' => data_get($latestEnabledRun->metadata, 'codex_process_spawn_enablement.process_start_release_id'),
                    'codex_execution_id' => data_get($latestEnabledRun->metadata, 'codex_process_spawn_enablement.codex_execution_id'),
                    'process_spawn_enabled' => data_get($latestEnabledRun->metadata, 'codex_process_spawn_enablement.process_spawn_enabled'),
                    'external_process_started' => data_get($latestEnabledRun->metadata, 'codex_process_spawn_enablement.external_process_started'),
                    'token_spend_allowed' => data_get($latestEnabledRun->metadata, 'codex_process_spawn_enablement.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_record_codex_process_spawn_enablement_when_called_with_signed_input' => true,
                'process_spawn_enablement_is_not_process_spawn' => true,
                'codex_process_spawn_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_final_process_spawn_executor_contract'
                : 'repair_one_shot_scheduler_tick_codex_process_spawn_enablement_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_process_spawn_enablement_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_status_does_not_call_enablement_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_status_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_spawn_enablement_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex process spawn enablement service is ready and inspectable; status remains read-only and final process spawn is still separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex process spawn enablement service is blocked until invoker, generic gate and ledger are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorPreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_contract_hash');
        $executorReady = class_exists(AgentCodexProcessSpawnExecutor::class)
            && method_exists(AgentCodexProcessSpawnExecutor::class, 'prepareProcessSpawn');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorInvoker::class, 'prepareCodexFinalProcessSpawn');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'release_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_final_process_spawn_executor_contract_ready',
            'release_contract_hash_present' => $contractHash !== '',
            'process_spawn_enablement_service_ready' => data_get($contract, 'source_codex_process_spawn_enablement_status') === 'one_shot_tick_codex_process_spawn_enablement_service_ready',
            'codex_process_spawn_executor_contract_template_ready' => data_get($contract, 'source_codex_process_spawn_executor_contract_status') === 'codex_process_spawn_executor_contract_template_ready',
            'codex_process_spawn_executor_ready' => $executorReady,
            'codex_final_process_spawn_executor_invoker_ready' => $invokerReady,
            'canonical_executor_method_ready' => data_get($contract, 'release_boundary.canonical_executor_method') === 'prepareProcessSpawn',
            'executor_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_executor') === false,
            'executor_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_executor') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_final_process_spawn_executor_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-FINAL-PROCESS-SPAWN-EXECUTOR-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_release_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_final_process_spawn_executor_invoker',
                'delegate_to_agent_codex_process_spawn_executor',
                'require_codex_process_spawn_enablement_recorded_metadata',
                'require_operator_final_spawn_receipt_hash',
                'require_runtime_supervision_plan_hash',
                'require_stdout_stderr_sink_hash',
                'require_liveness_probe_hash',
                'preserve_no_codex_process_start_after_executor_preparation',
                'return_codex_process_spawn_executor_prepared_result_without_spawning_codex_process',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_process_spawn_executor_call_allowed_here' => false,
                'codex_external_process_runtime_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_final_process_spawn_executor_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_final_process_spawn_executor_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_preflight_does_not_call_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_preflight_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex final process spawn executor preflight is ready; the next slice can expose the scoped invoker packet.'
                : 'Automatic dispatch scheduler one-shot tick Codex final process spawn executor preflight is blocked until enablement, executor, storage and no-runtime prerequisites are ready.',
        ];
    }
}
