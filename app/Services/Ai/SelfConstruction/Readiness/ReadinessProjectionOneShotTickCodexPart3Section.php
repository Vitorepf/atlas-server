<?php

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Models\AtlasSelfConstructionAgentRun;
use Illuminate\Support\Facades\Schema;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorEnablementGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateInvoker;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerExecutorEnablementGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerExecutorFreshReleaseGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerImplementationBoundary;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerReleasePreflight;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerSupervisedStartActivationGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexSignedRealInvokerReleaseGate;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * GOD-DEBULK extracted projection family from AtlasSelfConstructionReadinessService.
 * Pure read-only projections. The mother is passed as $parent so cross-family sibling
 * calls route back through the preserved public mother surface (ReflectionMethod /
 * method_exists probes on the mother stay intact).
 */
final class ReadinessProjectionOneShotTickCodexPart3Section
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_release_preflight_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-RELEASE-PREFLIGHT-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_release_preflight_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_release_preflight_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker release preflight invoker', 'type' => 'service', 'acceptance' => 'Invoker validates release preflight input and delegates to AgentCodexRealInvokerReleasePreflight.'],
                ['id' => 'T2', 'title' => 'Preserve no real process invocation boundary', 'type' => 'service_logic', 'acceptance' => 'Invoker records release preflight metadata while actual process start, token spend and self-programming remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker release preflight invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful release preflight, idempotent retry, invalid runtime policy hash rejection, missing dry-run metadata and duplicate preflight rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker release preflight status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next signed real invoker release gate without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_release_preflight_invoker_records_without_starting_codex',
                'codex_real_invoker_release_preflight_invoker_requires_dry_run_metadata',
                'codex_real_invoker_release_preflight_invoker_requires_release_contract_rollback_and_runtime_hashes',
                'codex_real_invoker_release_preflight_invoker_is_idempotent_by_preflight_id',
                'codex_real_invoker_release_preflight_invoker_never_invokes_codex_or_spends_tokens',
            ],
            'required_gates' => [
                'php_lint_codex_real_invoker_release_preflight_invoker',
                'dedicated_codex_real_invoker_release_preflight_invoker_feature_tests',
                'generic_codex_real_invoker_release_preflight_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_real_invoker_release_preflight_call_allowed_by_future_invoker' => true,
                'codex_signed_real_invoker_release_gate_allowed_by_packet' => false,
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
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_release_preflight_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_release_preflight_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_implementation_packet_does_not_call_release_preflight',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_implementation_packet_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker release preflight implementation packet is ready; it scopes release preflight and still stops before signed real invocation.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $releasePreflightReady = class_exists(AgentCodexRealInvokerReleasePreflight::class)
            && method_exists(AgentCodexRealInvokerReleasePreflight::class, 'recordPreflight');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightInvoker::class, 'prepareCodexRealInvokerReleasePreflight');

        $preparedRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_release_preflight->real_invoker_release_preflight_id')
            : null;
        $latestPreparedRun = $preparedRunsQuery === null
            ? null
            : (clone $preparedRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady && $ledgerTableReady && $releasePreflightReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_release_preflight_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerReleasePreflight',
            'generic_release_preflight_service' => AgentCodexRealInvokerReleasePreflight::class,
            'generic_release_preflight_service_ready' => $releasePreflightReady,
            'generic_release_preflight_canonical_method' => 'recordPreflight',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_release_preflight_recorded_run_count' => $preparedRunsQuery === null ? null : (clone $preparedRunsQuery)->count(),
            'latest_codex_real_invoker_release_preflight_recorded_run' => $latestPreparedRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestPreparedRun->id,
                    'run_key' => $latestPreparedRun->run_key,
                    'packet_id' => $latestPreparedRun->packet_id,
                    'provider' => $latestPreparedRun->provider,
                    'status' => $latestPreparedRun->status,
                    'real_invoker_release_preflight_id' => data_get($latestPreparedRun->metadata, 'codex_real_invoker_release_preflight.real_invoker_release_preflight_id'),
                    'dry_run_id' => data_get($latestPreparedRun->metadata, 'codex_real_invoker_release_preflight.dry_run_id'),
                    'invocation_authorization_id' => data_get($latestPreparedRun->metadata, 'codex_real_invoker_release_preflight.invocation_authorization_id'),
                    'runtime_driver_id' => data_get($latestPreparedRun->metadata, 'codex_real_invoker_release_preflight.runtime_driver_id'),
                    'codex_execution_id' => data_get($latestPreparedRun->metadata, 'codex_real_invoker_release_preflight.codex_execution_id'),
                    'real_invoker_release_preflight_passed' => data_get($latestPreparedRun->metadata, 'codex_real_invoker_release_preflight.real_invoker_release_preflight_passed'),
                    'external_process_started' => data_get($latestPreparedRun->metadata, 'codex_real_invoker_release_preflight.external_process_started'),
                    'token_spend_allowed' => data_get($latestPreparedRun->metadata, 'codex_real_invoker_release_preflight.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_record_codex_real_invoker_release_preflight_when_called_with_signed_input' => true,
                'real_invoker_release_preflight_is_not_real_process_invocation' => true,
                'codex_signed_real_invoker_release_gate_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_signed_real_invoker_release_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_release_preflight_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_release_preflight_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_status_does_not_call_release_preflight',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_status_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker release preflight service is ready and inspectable; status remains read-only and signed real invoker release is still separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker release preflight service is blocked until invoker, generic release preflight and ledger are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGatePreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_contract_hash');
        $signedGateReady = class_exists(AgentCodexSignedRealInvokerReleaseGate::class)
            && method_exists(AgentCodexSignedRealInvokerReleaseGate::class, 'authorizeSignedRelease');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateInvoker::class, 'authorizeCodexSignedRealInvokerRelease');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'release_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_signed_real_invoker_release_gate_contract_ready',
            'release_contract_hash_present' => $contractHash !== '',
            'codex_real_invoker_release_preflight_status_ready' => data_get($contract, 'source_codex_real_invoker_release_preflight_status') === 'one_shot_tick_codex_real_invoker_release_preflight_service_ready',
            'codex_signed_real_invoker_release_gate_contract_template_ready' => data_get($contract, 'source_codex_signed_real_invoker_release_gate_contract_status') === 'codex_signed_real_invoker_release_gate_contract_template_ready',
            'codex_signed_real_invoker_release_gate_ready' => $signedGateReady,
            'codex_signed_real_invoker_release_gate_invoker_ready' => $invokerReady,
            'canonical_signed_gate_method_ready' => data_get($contract, 'release_boundary.canonical_signed_gate_method') === 'authorizeSignedRelease',
            'signed_gate_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_signed_gate') === false,
            'signed_gate_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_signed_gate') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_signed_real_invoker_release_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-SIGNED-REAL-INVOKER-RELEASE-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_release_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_signed_real_invoker_release_gate_invoker',
                'delegate_to_agent_codex_signed_real_invoker_release_gate',
                'require_codex_real_invoker_release_preflight_passed_metadata',
                'require_operator_signed_release_receipt_hash',
                'require_signature_verification_report_hash',
                'require_release_policy_hash',
                'preserve_no_codex_process_invocation_after_signed_gate',
                'return_codex_signed_real_invoker_release_gate_result_without_invoking_codex_process',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_signed_real_invoker_release_gate_call_allowed_here' => false,
                'codex_real_invoker_implementation_boundary_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_signed_real_invoker_release_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_signed_real_invoker_release_gate_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_preflight_does_not_call_signed_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_preflight_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex signed real invoker release gate preflight is ready; the next slice can expose the scoped invoker packet.'
                : 'Automatic dispatch scheduler one-shot tick Codex signed real invoker release gate preflight is blocked until release preflight, signed gate, storage and no-runtime prerequisites are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_signed_real_invoker_release_gate_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-SIGNED-REAL-INVOKER-RELEASE-GATE-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_signed_real_invoker_release_gate_preflight_status' => data_get($preflight, 'status'),
            'source_codex_signed_real_invoker_release_gate_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex signed real invoker release gate invoker', 'type' => 'service', 'acceptance' => 'Invoker validates signed release input and delegates to AgentCodexSignedRealInvokerReleaseGate.'],
                ['id' => 'T2', 'title' => 'Preserve no real process invocation boundary', 'type' => 'service_logic', 'acceptance' => 'Invoker records signed release authorization while actual process start, token spend and self-programming remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex signed real invoker release gate invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful signed release, idempotent retry, invalid signature hash rejection, missing preflight metadata and duplicate signed release rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex signed real invoker release gate status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next implementation boundary without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_signed_real_invoker_release_gate_invoker_authorizes_without_starting_codex',
                'codex_signed_real_invoker_release_gate_invoker_requires_release_preflight_metadata',
                'codex_signed_real_invoker_release_gate_invoker_requires_signature_release_policy_and_runtime_hashes',
                'codex_signed_real_invoker_release_gate_invoker_is_idempotent_by_signed_release_id',
                'codex_signed_real_invoker_release_gate_invoker_never_invokes_codex_or_spends_tokens',
            ],
            'required_gates' => [
                'php_lint_codex_signed_real_invoker_release_gate_invoker',
                'dedicated_codex_signed_real_invoker_release_gate_invoker_feature_tests',
                'generic_codex_signed_real_invoker_release_gate_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_signed_real_invoker_release_gate_call_allowed_by_future_invoker' => true,
                'codex_real_invoker_implementation_boundary_allowed_by_packet' => false,
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
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_signed_real_invoker_release_gate_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_signed_real_invoker_release_gate_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_implementation_packet_does_not_call_signed_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_implementation_packet_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex signed real invoker release gate implementation packet is ready; it scopes signed release authorization and still stops before implementation boundary.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $signedGateReady = class_exists(AgentCodexSignedRealInvokerReleaseGate::class)
            && method_exists(AgentCodexSignedRealInvokerReleaseGate::class, 'authorizeSignedRelease');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateInvoker::class, 'authorizeCodexSignedRealInvokerRelease');

        $preparedRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_signed_real_invoker_release->signed_real_invoker_release_id')
            : null;
        $latestPreparedRun = $preparedRunsQuery === null
            ? null
            : (clone $preparedRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady && $ledgerTableReady && $signedGateReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_signed_real_invoker_release_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'authorizeCodexSignedRealInvokerRelease',
            'generic_signed_gate_service' => AgentCodexSignedRealInvokerReleaseGate::class,
            'generic_signed_gate_service_ready' => $signedGateReady,
            'generic_signed_gate_canonical_method' => 'authorizeSignedRelease',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_signed_real_invoker_release_authorized_run_count' => $preparedRunsQuery === null ? null : (clone $preparedRunsQuery)->count(),
            'latest_codex_signed_real_invoker_release_authorized_run' => $latestPreparedRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestPreparedRun->id,
                    'run_key' => $latestPreparedRun->run_key,
                    'packet_id' => $latestPreparedRun->packet_id,
                    'provider' => $latestPreparedRun->provider,
                    'status' => $latestPreparedRun->status,
                    'signed_real_invoker_release_id' => data_get($latestPreparedRun->metadata, 'codex_signed_real_invoker_release.signed_real_invoker_release_id'),
                    'real_invoker_release_preflight_id' => data_get($latestPreparedRun->metadata, 'codex_signed_real_invoker_release.real_invoker_release_preflight_id'),
                    'dry_run_id' => data_get($latestPreparedRun->metadata, 'codex_signed_real_invoker_release.dry_run_id'),
                    'codex_execution_id' => data_get($latestPreparedRun->metadata, 'codex_signed_real_invoker_release.codex_execution_id'),
                    'signed_real_invoker_release_authorized' => data_get($latestPreparedRun->metadata, 'codex_signed_real_invoker_release.signed_real_invoker_release_authorized'),
                    'external_process_started' => data_get($latestPreparedRun->metadata, 'codex_signed_real_invoker_release.external_process_started'),
                    'token_spend_allowed' => data_get($latestPreparedRun->metadata, 'codex_signed_real_invoker_release.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_authorize_codex_signed_real_invoker_release_when_called_with_signed_input' => true,
                'signed_real_invoker_release_gate_is_not_real_process_invocation' => true,
                'codex_real_invoker_implementation_boundary_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_implementation_boundary_contract'
                : 'repair_one_shot_scheduler_tick_codex_signed_real_invoker_release_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_signed_real_invoker_release_gate_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_status_does_not_call_signed_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_status_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex signed real invoker release gate service is ready and inspectable; status remains read-only and implementation boundary is still separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex signed real invoker release gate service is blocked until invoker, generic signed gate and ledger are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryPreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_contract_hash');
        $boundaryReady = class_exists(AgentCodexRealInvokerImplementationBoundary::class)
            && method_exists(AgentCodexRealInvokerImplementationBoundary::class, 'prepareBoundary');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryInvoker::class, 'prepareCodexRealInvokerImplementationBoundary');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'boundary_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_implementation_boundary_contract_ready',
            'boundary_contract_hash_present' => $contractHash !== '',
            'codex_signed_real_invoker_release_gate_status_ready' => data_get($contract, 'source_codex_signed_real_invoker_release_gate_status') === 'one_shot_tick_codex_signed_real_invoker_release_gate_service_ready',
            'codex_real_invoker_implementation_boundary_contract_template_ready' => data_get($contract, 'source_codex_real_invoker_implementation_boundary_contract_status') === 'codex_real_invoker_implementation_boundary_contract_template_ready',
            'codex_real_invoker_implementation_boundary_ready' => $boundaryReady,
            'codex_real_invoker_implementation_boundary_invoker_ready' => $invokerReady,
            'canonical_implementation_boundary_method_ready' => data_get($contract, 'release_boundary.canonical_implementation_boundary_method') === 'prepareBoundary',
            'implementation_boundary_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_implementation_boundary') === false,
            'implementation_boundary_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_implementation_boundary') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_implementation_boundary_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-IMPLEMENTATION-BOUNDARY-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_boundary_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_implementation_boundary_invoker',
                'delegate_to_agent_codex_real_invoker_implementation_boundary',
                'require_codex_signed_real_invoker_release_authorized_metadata',
                'require_operator_implementation_boundary_receipt_hash',
                'require_implementation_plan_hash',
                'preserve_no_codex_process_invocation_after_implementation_boundary',
                'return_codex_real_invoker_implementation_boundary_result_without_invoking_codex_process',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_real_invoker_implementation_boundary_call_allowed_here' => false,
                'codex_real_invoker_executor_plan_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_implementation_boundary_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_implementation_boundary_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_preflight_does_not_call_boundary',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_preflight_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker implementation boundary preflight is ready; the next slice can expose the scoped invoker packet.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker implementation boundary preflight is blocked until signed release, boundary, storage and no-runtime prerequisites are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_implementation_boundary_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-IMPLEMENTATION-BOUNDARY-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_implementation_boundary_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_implementation_boundary_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker implementation boundary invoker', 'type' => 'service', 'acceptance' => 'Invoker validates implementation boundary input and delegates to AgentCodexRealInvokerImplementationBoundary.'],
                ['id' => 'T2', 'title' => 'Preserve no real executor invocation boundary', 'type' => 'service_logic', 'acceptance' => 'Invoker records implementation boundary while actual process start, token spend and self-programming remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker implementation boundary invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful boundary, idempotent retry, invalid implementation plan hash rejection, missing signed release metadata and duplicate boundary rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker implementation boundary status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next executor plan without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_implementation_boundary_invoker_prepares_without_starting_codex',
                'codex_real_invoker_implementation_boundary_invoker_requires_signed_release_metadata',
                'codex_real_invoker_implementation_boundary_invoker_requires_implementation_plan_hash',
                'codex_real_invoker_implementation_boundary_invoker_is_idempotent_by_boundary_id',
                'codex_real_invoker_implementation_boundary_invoker_never_invokes_codex_or_spends_tokens',
            ],
            'required_gates' => [
                'php_lint_codex_real_invoker_implementation_boundary_invoker',
                'dedicated_codex_real_invoker_implementation_boundary_invoker_feature_tests',
                'generic_codex_real_invoker_implementation_boundary_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_real_invoker_implementation_boundary_call_allowed_by_future_invoker' => true,
                'codex_real_invoker_executor_plan_allowed_by_packet' => false,
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
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_implementation_boundary_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_implementation_boundary_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_implementation_packet_does_not_call_boundary',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_implementation_packet_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker implementation boundary implementation packet is ready; it scopes boundary preparation and still stops before executor plan.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $boundaryReady = class_exists(AgentCodexRealInvokerImplementationBoundary::class)
            && method_exists(AgentCodexRealInvokerImplementationBoundary::class, 'prepareBoundary');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryInvoker::class, 'prepareCodexRealInvokerImplementationBoundary');

        $preparedRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_implementation_boundary->real_invoker_implementation_boundary_id')
            : null;
        $latestPreparedRun = $preparedRunsQuery === null
            ? null
            : (clone $preparedRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady && $ledgerTableReady && $boundaryReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_implementation_boundary_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerImplementationBoundaryInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerImplementationBoundary',
            'generic_implementation_boundary_service' => AgentCodexRealInvokerImplementationBoundary::class,
            'generic_implementation_boundary_service_ready' => $boundaryReady,
            'generic_implementation_boundary_canonical_method' => 'prepareBoundary',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_implementation_boundary_prepared_run_count' => $preparedRunsQuery === null ? null : (clone $preparedRunsQuery)->count(),
            'latest_codex_real_invoker_implementation_boundary_prepared_run' => $latestPreparedRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestPreparedRun->id,
                    'run_key' => $latestPreparedRun->run_key,
                    'packet_id' => $latestPreparedRun->packet_id,
                    'provider' => $latestPreparedRun->provider,
                    'status' => $latestPreparedRun->status,
                    'real_invoker_implementation_boundary_id' => data_get($latestPreparedRun->metadata, 'codex_real_invoker_implementation_boundary.real_invoker_implementation_boundary_id'),
                    'signed_real_invoker_release_id' => data_get($latestPreparedRun->metadata, 'codex_real_invoker_implementation_boundary.signed_real_invoker_release_id'),
                    'real_invoker_release_preflight_id' => data_get($latestPreparedRun->metadata, 'codex_real_invoker_implementation_boundary.real_invoker_release_preflight_id'),
                    'dry_run_id' => data_get($latestPreparedRun->metadata, 'codex_real_invoker_implementation_boundary.dry_run_id'),
                    'codex_execution_id' => data_get($latestPreparedRun->metadata, 'codex_real_invoker_implementation_boundary.codex_execution_id'),
                    'real_invoker_implementation_boundary_prepared' => data_get($latestPreparedRun->metadata, 'codex_real_invoker_implementation_boundary.real_invoker_implementation_boundary_prepared'),
                    'external_process_started' => data_get($latestPreparedRun->metadata, 'codex_real_invoker_implementation_boundary.external_process_started'),
                    'token_spend_allowed' => data_get($latestPreparedRun->metadata, 'codex_real_invoker_implementation_boundary.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_codex_real_invoker_implementation_boundary_when_called_with_signed_input' => true,
                'real_invoker_implementation_boundary_is_not_real_process_invocation' => true,
                'codex_real_invoker_executor_plan_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_executor_plan_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_implementation_boundary_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_implementation_boundary_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_status_does_not_call_boundary',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_status_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_implementation_boundary_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker implementation boundary service is ready and inspectable; status remains read-only and executor plan is still separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker implementation boundary service is blocked until invoker, generic boundary and ledger are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorEnablementGatePreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorEnablementGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_contract_hash');
        $enablementReady = class_exists(AgentCodexRealInvokerExecutorEnablementGate::class)
            && method_exists(AgentCodexRealInvokerExecutorEnablementGate::class, 'enableExecutor');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorEnablementGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorEnablementGateInvoker::class, 'enableCodexRealInvokerExecutor');
        $freshReleaseReady = class_exists(AgentCodexRealInvokerExecutorFreshReleaseGate::class)
            && method_exists(AgentCodexRealInvokerExecutorFreshReleaseGate::class, 'authorizeFreshRelease');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'enablement_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_executor_enablement_gate_contract_ready',
            'enablement_gate_contract_hash_present' => $contractHash !== '',
            'fresh_release_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_executor_fresh_release_gate_status') === 'one_shot_tick_codex_real_invoker_executor_fresh_release_gate_service_ready',
            'generic_enablement_gate_contract_template_ready' => data_get($contract, 'source_codex_real_invoker_executor_enablement_gate_contract_status') === 'codex_real_invoker_executor_enablement_gate_contract_template_ready',
            'codex_real_invoker_executor_enablement_gate_ready' => $enablementReady,
            'codex_real_invoker_executor_enablement_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_executor_fresh_release_gate_ready' => $freshReleaseReady,
            'canonical_enablement_gate_method_ready' => data_get($contract, 'release_boundary.canonical_executor_enablement_gate_method') === 'enableExecutor',
            'enablement_allows_executor_enabled_metadata' => data_get($contract, 'release_boundary.executor_enabled_by_enablement') === true,
            'enablement_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_enablement') === false,
            'enablement_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_enablement') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_executor_enablement_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-EXECUTOR-ENABLEMENT-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_executor_enablement_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_executor_enablement_gate_invoker',
                'delegate_to_agent_codex_real_invoker_executor_enablement_gate',
                'require_codex_real_invoker_executor_fresh_release_metadata',
                'require_operator_enablement_receipt_hash',
                'require_enablement_policy_hash',
                'require_pre_start_checklist_hash',
                'require_disable_switch_hash',
                'preserve_process_start_disabled_until_supervised_start_activation_gate',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_real_invoker_executor_enablement_gate_call_allowed_here' => false,
                'executor_enabled_metadata_allowed_by_future_invoker' => true,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_executor_enablement_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_enablement_gate_allowed' => false,
            'executor_enabled' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_preflight_does_not_enable_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker executor enablement gate preflight is ready; the next slice can expose the scoped enablement invoker packet.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker executor enablement gate preflight is blocked until fresh release, enablement, storage and no-runtime prerequisites are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorEnablementGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorEnablementGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_executor_enablement_gate_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-EXECUTOR-ENABLEMENT-GATE-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_executor_enablement_gate_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_executor_enablement_gate_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorEnablementGateInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorEnablementGateInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker executor enablement invoker', 'type' => 'service', 'acceptance' => 'Invoker validates enablement input and delegates to AgentCodexRealInvokerExecutorEnablementGate.'],
                ['id' => 'T2', 'title' => 'Preserve process-start boundary after enablement', 'type' => 'service_logic', 'acceptance' => 'Invoker enables executor metadata while actual process start, token spend and self-programming remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker executor enablement invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful enablement, idempotent retry, invalid disable switch hash rejection, missing fresh release metadata and duplicate enablement rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker executor enablement status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next supervised start activation gate without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_executor_enablement_gate_invoker_enables_executor_without_starting_codex',
                'codex_real_invoker_executor_enablement_gate_invoker_requires_fresh_release_metadata',
                'codex_real_invoker_executor_enablement_gate_invoker_requires_disable_switch_hash',
                'codex_real_invoker_executor_enablement_gate_invoker_is_idempotent_by_enablement_id',
                'codex_real_invoker_executor_enablement_gate_invoker_never_starts_codex_or_spends_tokens',
            ],
            'required_gates' => [
                'php_lint_codex_real_invoker_executor_enablement_gate_invoker',
                'dedicated_codex_real_invoker_executor_enablement_gate_invoker_feature_tests',
                'generic_codex_real_invoker_executor_enablement_gate_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_real_invoker_executor_enablement_gate_call_allowed_by_future_invoker' => true,
                'executor_enabled_metadata_allowed_by_future_invoker' => true,
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
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_executor_enablement_gate_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_enablement_gate_allowed' => false,
            'executor_enabled' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_implementation_packet_does_not_enable_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker executor enablement gate implementation packet is ready; it scopes executor enablement metadata and still stops before supervised start activation.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorEnablementGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $enablementReady = class_exists(AgentCodexRealInvokerExecutorEnablementGate::class)
            && method_exists(AgentCodexRealInvokerExecutorEnablementGate::class, 'enableExecutor');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorEnablementGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorEnablementGateInvoker::class, 'enableCodexRealInvokerExecutor');

        $enabledRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_executor_enablement->real_invoker_executor_enablement_id')
            : null;
        $latestEnabledRun = $enabledRunsQuery === null
            ? null
            : (clone $enabledRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady && $ledgerTableReady && $enablementReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_executor_enablement_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorEnablementGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'enableCodexRealInvokerExecutor',
            'generic_enablement_gate_service' => AgentCodexRealInvokerExecutorEnablementGate::class,
            'generic_enablement_gate_service_ready' => $enablementReady,
            'generic_enablement_gate_canonical_method' => 'enableExecutor',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_executor_enabled_run_count' => $enabledRunsQuery === null ? null : (clone $enabledRunsQuery)->count(),
            'latest_codex_real_invoker_executor_enabled_run' => $latestEnabledRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestEnabledRun->id,
                    'run_key' => $latestEnabledRun->run_key,
                    'packet_id' => $latestEnabledRun->packet_id,
                    'provider' => $latestEnabledRun->provider,
                    'status' => $latestEnabledRun->status,
                    'real_invoker_executor_enablement_id' => data_get($latestEnabledRun->metadata, 'codex_real_invoker_executor_enablement.real_invoker_executor_enablement_id'),
                    'real_invoker_executor_fresh_release_id' => data_get($latestEnabledRun->metadata, 'codex_real_invoker_executor_enablement.real_invoker_executor_fresh_release_id'),
                    'real_invoker_executor_plan_id' => data_get($latestEnabledRun->metadata, 'codex_real_invoker_executor_enablement.real_invoker_executor_plan_id'),
                    'codex_execution_id' => data_get($latestEnabledRun->metadata, 'codex_real_invoker_executor_enablement.codex_execution_id'),
                    'real_invoker_executor_enabled' => data_get($latestEnabledRun->metadata, 'codex_real_invoker_executor_enablement.real_invoker_executor_enabled'),
                    'executor_enabled' => data_get($latestEnabledRun->metadata, 'codex_real_invoker_executor_enablement.executor_enabled'),
                    'external_process_started' => data_get($latestEnabledRun->metadata, 'codex_real_invoker_executor_enablement.external_process_started'),
                    'token_spend_allowed' => data_get($latestEnabledRun->metadata, 'codex_real_invoker_executor_enablement.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_enable_codex_real_invoker_executor_when_called_with_fresh_release_input' => true,
                'real_invoker_executor_enablement_is_not_real_process_invocation' => true,
                'real_invoker_executor_enablement_keeps_process_start_disabled' => true,
                'codex_real_invoker_supervised_start_activation_gate_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_supervised_start_activation_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_executor_enablement_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_enablement_gate_allowed' => false,
            'executor_enabled' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_status_does_not_call_enablement_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker executor enablement gate service is ready and inspectable; status remains read-only and supervised start activation is still separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker executor enablement gate service is blocked until invoker, generic enablement gate and ledger are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateContract(array $options = []): array
    {
        $enablementStatusPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorEnablementGateStatus($options);
        $enablementStatus = (array) data_get($enablementStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_status', []);
        $activationPayload = $this->parent->agentCodexRealInvokerSupervisedStartActivationGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_supervised_start_activation_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-SUPERVISED-START-ACTIVATION-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_executor_enablement_gate_status' => data_get($enablementStatus, 'status'),
            'source_codex_real_invoker_executor_enablement_gate_status_hash' => data_get($enablementStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_enablement_gate_status_hash'),
            'source_codex_real_invoker_supervised_start_activation_gate_contract_status' => data_get($activationPayload, 'status'),
            'source_codex_real_invoker_supervised_start_activation_gate_contract_hash' => data_get($activationPayload, 'codex_real_invoker_supervised_start_activation_gate_contract_template_hash'),
            'release_boundary' => [
                'canonical_supervised_start_activation_gate' => AgentCodexRealInvokerSupervisedStartActivationGate::class,
                'canonical_supervised_start_activation_gate_method' => 'prepareActivation',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexRealInvokerSupervisedStartActivation',
                'activation_effect' => 'arm_codex_real_invoker_process_start_metadata_without_starting_process',
                'executor_enabled_required_before_activation' => true,
                'process_start_armed_by_activation' => true,
                'external_process_started_by_activation' => false,
                'provider_started_by_activation' => false,
                'adapter_execution_allowed_by_activation' => false,
                'token_spend_allowed_by_activation' => false,
                'idempotency_key' => 'real_invoker_supervised_start_activation_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'real_invoker_executor_enablement_id',
                'real_invoker_supervised_start_activation_id',
                'operator_start_activation_receipt_hash',
                'start_window_hash',
                'process_start_guard_hash',
                'supervisor_observer_hash',
                'pid_guard_hash',
                'cwd_integrity_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_supervised_start_activation_metadata_on_agent_run',
                'append_codex_real_invoker_supervised_start_activation_evidence_event',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'spawn_shell_or_subprocess',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'mark_packet_completed',
                'merge_work_products',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_supervised_start_activation_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_supervised_start_activation_gate_allowed' => false,
            'executor_enabled' => false,
            'process_start_armed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_contract_hash' => ReadinessHash::stable($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_contract_does_not_arm_process_start',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker supervised start activation gate contract is ready; it defines start-armed metadata but still cannot start Codex.',
        ];
    }
}
