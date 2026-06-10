<?php

namespace App\Services\Ai\Voice;

use App\Services\Ai\Support\JsonFileStore;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class AtlasVoiceRuntimeCertificationService
{
    private const PYTHON_COMMAND_TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly AtlasVoiceRealtimeService $voice,
        private readonly AtlasVoiceRealtimeFoundationRegistry $foundation,
        private readonly AtlasVoiceLiveKitTokenIssuer $tokens,
        private readonly AtlasVoiceLiveKitServerProbe $liveKitServerProbe,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function certify(array $payload = []): array
    {
        $runtime = $this->runtime($payload);
        if (! in_array($runtime, ['livekit_agents_sdk'], true)) {
            return [
                'schema_version' => 'atlas.voice_realtime.runtime_certification.v1',
                'status' => 'invalid_runtime',
                'surface_id' => 'voice_realtime',
                'runtime_id' => $runtime,
                'allowed_runtimes' => ['livekit_agents_sdk'],
                'kernel_only' => true,
                'mobile_first' => true,
                'daemon_started' => false,
                'summary' => [
                    'gate_count' => 0,
                    'passed_gates' => 0,
                    'failed_gates' => 1,
                    'failed_keys' => ['runtime_allowlist'],
                ],
                'next_action' => 'use_allowed_voice_runtime',
            ];
        }

        $baseUrl = $this->baseUrl($payload);
        $requireSdk = (bool) ($payload['require_sdk'] ?? false);
        $callbackLoopWired = (bool) ($payload['callback_loop_wired'] ?? false);
        $productionSdkLoopWired = (bool) ($payload['production_sdk_loop_wired'] ?? false);

        $preflight = $this->runPreflight($runtime, $baseUrl, $requireSdk);
        $callbackSequence = $this->runCallbackSequenceSmoke($runtime, $baseUrl);
        $productionLoop = $this->runProductionLoopSmoke($runtime, $baseUrl);
        $workerStart = $this->runWorkerStartCheck($runtime, $baseUrl, $callbackLoopWired, $productionSdkLoopWired);
        $productLoopCheck = $this->runProductLoopCheck($runtime, $baseUrl, $callbackLoopWired, $productionSdkLoopWired);
        $preStartHealthChecksSmoke = $this->runPreStartHealthChecksSmoke($runtime, $baseUrl);
        $foundation = $this->foundation->readiness();
        $tokenIssuer = $this->tokens->readiness();
        $tokenIssuerSmoke = $this->tokens->smoke();
        $liveKitServerProbe = $this->liveKitServerProbe->probe();

        $artifacts = [
            'foundation_registry' => $this->certificationArtifact($foundation, ['summary', 'gates']),
            'preflight' => $this->certificationArtifact($preflight, ['preflight']),
            'callback_sequence' => $this->certificationArtifact($callbackSequence, ['callback_sequence']),
            'production_loop_smoke' => $this->certificationArtifact($productionLoop, ['bridge_contract', 'results']),
            'worker_start_check' => $this->certificationArtifact($workerStart, ['worker_plan', 'activation_contract', 'production_loop_plan', 'sdk_wiring_contract']),
            'product_loop_check' => $this->certificationArtifact($productLoopCheck, ['callback_loop', 'production_loop_plan', 'worker_start']),
            'pre_start_health_checks_smoke' => $this->certificationArtifact($preStartHealthChecksSmoke, ['bootstrap', 'pre_start_health_checks']),
            'livekit_token_issuer' => $this->certificationArtifact($tokenIssuer, []),
            'livekit_token_issuer_smoke' => $this->certificationArtifact($tokenIssuerSmoke, []),
            'livekit_server_probe' => $this->certificationArtifact($liveKitServerProbe, []),
        ];
        $forbiddenArtifactKeys = $this->forbiddenArtifactKeys($artifacts);
        $workerStatus = (string) ($workerStart['status'] ?? 'unknown');
        $gates = [
            'foundation_registry_ready' => [
                'passed' => ($foundation['status'] ?? null) === 'ready'
                    && ($foundation['next_allowed_step'] ?? null) === 'connect_through_authorized_adapter_with_existing_kernel_methods',
                'status' => $foundation['status'] ?? 'unknown',
                'next_allowed_step' => $foundation['next_allowed_step'] ?? null,
                'failed_gate_count' => $foundation['failed_gate_count'] ?? null,
            ],
            'preflight_ready' => [
                'passed' => ($preflight['status'] ?? null) === 'ready',
                'status' => $preflight['status'] ?? 'unknown',
            ],
            'callback_sequence_passed' => [
                'passed' => ($callbackSequence['status'] ?? null) === 'passed'
                    && (int) data_get($callbackSequence, 'callback_sequence.active_session_count', 1) === 0,
                'status' => $callbackSequence['status'] ?? 'unknown',
                'active_session_count' => data_get($callbackSequence, 'callback_sequence.active_session_count'),
            ],
            'production_loop_smoke_passed' => [
                'passed' => ($productionLoop['status'] ?? null) === 'production_loop_smoke_completed'
                    && (int) ($productionLoop['active_session_count'] ?? 1) === 0
                    && ($productionLoop['daemon_started'] ?? true) === false
                    && ($productionLoop['sdk_imported'] ?? true) === false
                    && data_get($productionLoop, 'kernel_normalizer_contract_report.status') === 'valid'
                    && data_get($productionLoop, 'bridge_contract_report.status') === 'valid'
                    && data_get($productionLoop, 'handler_registry_contract_report.status') === 'valid'
                    && data_get($productionLoop, 'worker_return_contract.status') === 'valid',
                'status' => $productionLoop['status'] ?? 'unknown',
                'active_session_count' => $productionLoop['active_session_count'] ?? null,
                'daemon_started' => $productionLoop['daemon_started'] ?? null,
                'sdk_imported' => $productionLoop['sdk_imported'] ?? null,
                'kernel_normalizer_contract_status' => data_get($productionLoop, 'kernel_normalizer_contract_report.status'),
                'kernel_normalizer_event_count' => data_get($productionLoop, 'kernel_normalizer_contract_report.event_count'),
                'bridge_contract_status' => data_get($productionLoop, 'bridge_contract_report.status'),
                'bridge_supported_callback_count' => data_get($productionLoop, 'bridge_contract_report.supported_callback_count'),
                'handler_registry_contract_status' => data_get($productionLoop, 'handler_registry_contract_report.status'),
                'handler_registry_handler_count' => data_get($productionLoop, 'handler_registry_contract_report.handler_count'),
                'worker_return_contract_status' => data_get($productionLoop, 'worker_return_contract.status'),
                'worker_return_contract_checked_count' => data_get($productionLoop, 'worker_return_contract.checked_result_count'),
            ],
            'worker_start_blocked_safely' => [
                'passed' => str_starts_with($workerStatus, 'blocked_') && ($workerStart['started'] ?? true) === false,
                'status' => $workerStatus,
                'started' => $workerStart['started'] ?? null,
                'reason' => $workerStart['reason'] ?? null,
            ],
            'product_loop_check_available' => [
                'passed' => ($productLoopCheck['schema_version'] ?? null) === 'atlas.voice_realtime.product_loop_check.v1'
                    && ($productLoopCheck['daemon_started'] ?? true) === false
                    && data_get($productLoopCheck, 'gates.sdk_probe_import_safe') === true
                    && data_get($productLoopCheck, 'gates.sdk_handler_blueprint_available') === true
                    && data_get($productLoopCheck, 'gates.sdk_kernel_normalizer_required') === true
                    && data_get($productLoopCheck, 'gates.daemon_supervisor_health_snapshot_available') === true
                    && data_get($productLoopCheck, 'gates.daemon_supervisor_preflight_available') === true
                    && data_get($productLoopCheck, 'gates.daemon_supervisor_execution_available') === true
                    && data_get($productLoopCheck, 'gates.daemon_supervisor_process_launch_disabled') === true
                    && data_get($productLoopCheck, 'gates.daemon_process_adapter_blueprint_available') === true
                    && data_get($productLoopCheck, 'gates.supervised_process_adapter_available') === true
                    && data_get($productLoopCheck, 'gates.managed_env_contract_available') === true
                    && data_get($productLoopCheck, 'gates.launch_authorization_contract_available') === true
                    && data_get($productLoopCheck, 'gates.managed_env_writer_contract_available') === true
                    && data_get($productLoopCheck, 'gates.supervised_launch_execution_contract_available') === true
                    && data_get($productLoopCheck, 'gates.subprocess_start_contract_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_final_process_start_contract_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_execution_review_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_execution_packet_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_executor_stub_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_executor_review_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_executor_contract_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_runtime_adapter_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_adapter_review_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_adapter_contract_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_runner_contract_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_runner_review_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_runner_packet_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_runner_execution_review_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_runner_execution_contract_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_runner_start_gate_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_runner_final_review_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_runner_promotion_packet_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_runner_operator_release_review_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_runner_release_finalization_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_runner_release_authorization_available') === true
                    && data_get($productLoopCheck, 'gates.controlled_livekit_server_supervised_smoke_contract_available') === true
                    && data_get($productLoopCheck, 'gates.production_promotion_blocked') === true,
                'schema_version' => $productLoopCheck['schema_version'] ?? null,
                'status' => $productLoopCheck['status'] ?? 'unknown',
                'daemon_started' => $productLoopCheck['daemon_started'] ?? null,
                'sdk_probe_import_safe' => data_get($productLoopCheck, 'gates.sdk_probe_import_safe'),
                'sdk_handler_blueprint_available' => data_get($productLoopCheck, 'gates.sdk_handler_blueprint_available'),
                'sdk_kernel_normalizer_required' => data_get($productLoopCheck, 'gates.sdk_kernel_normalizer_required'),
                'daemon_supervisor_contract_available' => data_get($productLoopCheck, 'gates.daemon_supervisor_contract_available'),
                'daemon_supervisor_health_snapshot_available' => data_get($productLoopCheck, 'gates.daemon_supervisor_health_snapshot_available'),
                'daemon_supervisor_preflight_available' => data_get($productLoopCheck, 'gates.daemon_supervisor_preflight_available'),
                'daemon_supervisor_execution_available' => data_get($productLoopCheck, 'gates.daemon_supervisor_execution_available'),
                'daemon_supervisor_process_launch_disabled' => data_get($productLoopCheck, 'gates.daemon_supervisor_process_launch_disabled'),
                'daemon_process_adapter_blueprint_available' => data_get($productLoopCheck, 'gates.daemon_process_adapter_blueprint_available'),
                'supervised_process_adapter_available' => data_get($productLoopCheck, 'gates.supervised_process_adapter_available'),
                'managed_env_contract_available' => data_get($productLoopCheck, 'gates.managed_env_contract_available'),
                'launch_authorization_contract_available' => data_get($productLoopCheck, 'gates.launch_authorization_contract_available'),
                'launch_authorization_contract_ready' => data_get($productLoopCheck, 'gates.launch_authorization_contract_ready'),
                'managed_env_writer_contract_available' => data_get($productLoopCheck, 'gates.managed_env_writer_contract_available'),
                'supervised_launch_execution_contract_available' => data_get($productLoopCheck, 'gates.supervised_launch_execution_contract_available'),
                'subprocess_start_contract_available' => data_get($productLoopCheck, 'gates.subprocess_start_contract_available'),
                'guarded_start_final_process_start_contract_available' => data_get($productLoopCheck, 'gates.guarded_start_final_process_start_contract_available'),
                'guarded_start_process_execution_review_available' => data_get($productLoopCheck, 'gates.guarded_start_process_execution_review_available'),
                'guarded_start_process_execution_packet_available' => data_get($productLoopCheck, 'gates.guarded_start_process_execution_packet_available'),
                'guarded_start_process_executor_stub_available' => data_get($productLoopCheck, 'gates.guarded_start_process_executor_stub_available'),
                'guarded_start_process_executor_review_available' => data_get($productLoopCheck, 'gates.guarded_start_process_executor_review_available'),
                'guarded_start_process_executor_contract_available' => data_get($productLoopCheck, 'gates.guarded_start_process_executor_contract_available'),
                'guarded_start_process_runtime_adapter_available' => data_get($productLoopCheck, 'gates.guarded_start_process_runtime_adapter_available'),
                'guarded_start_process_adapter_review_available' => data_get($productLoopCheck, 'gates.guarded_start_process_adapter_review_available'),
                'guarded_start_process_adapter_contract_available' => data_get($productLoopCheck, 'gates.guarded_start_process_adapter_contract_available'),
                'guarded_start_process_runner_contract_available' => data_get($productLoopCheck, 'gates.guarded_start_process_runner_contract_available'),
                'guarded_start_process_runner_review_available' => data_get($productLoopCheck, 'gates.guarded_start_process_runner_review_available'),
                'guarded_start_process_runner_packet_available' => data_get($productLoopCheck, 'gates.guarded_start_process_runner_packet_available'),
                'guarded_start_process_runner_execution_review_available' => data_get($productLoopCheck, 'gates.guarded_start_process_runner_execution_review_available'),
                'guarded_start_process_runner_execution_contract_available' => data_get($productLoopCheck, 'gates.guarded_start_process_runner_execution_contract_available'),
                'guarded_start_process_runner_start_gate_available' => data_get($productLoopCheck, 'gates.guarded_start_process_runner_start_gate_available'),
                'guarded_start_process_runner_final_review_available' => data_get($productLoopCheck, 'gates.guarded_start_process_runner_final_review_available'),
                'guarded_start_process_runner_promotion_packet_available' => data_get($productLoopCheck, 'gates.guarded_start_process_runner_promotion_packet_available'),
                'guarded_start_process_runner_operator_release_review_available' => data_get($productLoopCheck, 'gates.guarded_start_process_runner_operator_release_review_available'),
                'guarded_start_process_runner_release_finalization_available' => data_get($productLoopCheck, 'gates.guarded_start_process_runner_release_finalization_available'),
                'guarded_start_process_runner_release_authorization_available' => data_get($productLoopCheck, 'gates.guarded_start_process_runner_release_authorization_available'),
                'controlled_livekit_server_supervised_smoke_contract_available' => data_get($productLoopCheck, 'gates.controlled_livekit_server_supervised_smoke_contract_available'),
                'supervisor_health_snapshot_schema_version' => data_get($productLoopCheck, 'supervised_start_plan.supervisor_health_snapshot.schema_version'),
                'supervisor_health_snapshot_daemon_started' => data_get($productLoopCheck, 'supervised_start_plan.supervisor_health_snapshot.daemon_started'),
                'supervisor_preflight_schema_version' => data_get($productLoopCheck, 'supervised_start_plan.supervisor_preflight.schema_version'),
                'supervisor_preflight_process_launch_attempted' => data_get($productLoopCheck, 'supervised_start_plan.supervisor_preflight.process_launch_attempted'),
                'daemon_supervisor_execution_schema_version' => data_get($productLoopCheck, 'daemon_supervisor_execution.schema_version'),
                'daemon_supervisor_execution_process_launch_attempted' => data_get($productLoopCheck, 'daemon_supervisor_execution.process_launch_attempted'),
                'daemon_supervisor_execution_start_allowed' => data_get($productLoopCheck, 'daemon_supervisor_execution.start_allowed'),
                'daemon_process_adapter_blueprint_schema_version' => data_get($productLoopCheck, 'daemon_supervisor_execution.process_adapter_blueprint.schema_version'),
                'daemon_process_adapter_blueprint_launch_allowed' => data_get($productLoopCheck, 'daemon_supervisor_execution.process_adapter_blueprint.launch_allowed'),
                'supervised_process_adapter_schema_version' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.schema_version'),
                'supervised_process_adapter_process_launch_attempted' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.process_launch_attempted'),
                'managed_env_contract_schema_version' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.managed_environment_contract.schema_version'),
                'managed_env_contract_write_attempted' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.managed_environment_contract.env_file_write_attempted'),
                'managed_env_contract_secret_values_present' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.managed_environment_contract.secret_values_present_in_output'),
                'launch_authorization_contract_schema_version' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.launch_authorization_contract.schema_version'),
                'launch_authorization_contract_launch_allowed' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.launch_authorization_contract.launch_allowed'),
                'launch_authorization_contract_process_launch_attempted' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.launch_authorization_contract.process_launch_attempted'),
                'managed_env_writer_schema_version' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.managed_env_writer.schema_version'),
                'managed_env_writer_write_execution_available' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.managed_env_writer.write_execution_available'),
                'managed_env_writer_write_execution_implemented' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.managed_env_writer.write_execution_implemented'),
                'managed_env_writer_write_attempted' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.managed_env_writer.env_file_write_attempted'),
                'supervised_launch_execution_schema_version' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.supervised_launch_execution.schema_version'),
                'supervised_launch_execution_status' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.supervised_launch_execution.status'),
                'supervised_launch_execution_process_launch_attempted' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.supervised_launch_execution.process_launch_attempted'),
                'supervised_launch_execution_daemon_started' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.supervised_launch_execution.daemon_started'),
                'supervised_launch_execution_subprocess_launch_implemented' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.supervised_launch_execution.subprocess_launch_implemented'),
                'supervised_launch_execution_pre_start_health_checks_available' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.supervised_launch_execution.pre_start_health_checks_execution_available'),
                'supervised_launch_execution_pre_start_health_checks_executed' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.supervised_launch_execution.pre_start_health_checks_executed'),
                'subprocess_start_contract_schema_version' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.subprocess_start_contract.schema_version'),
                'subprocess_start_contract_status' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.subprocess_start_contract.status'),
                'subprocess_start_contract_process_launch_attempted' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.subprocess_start_contract.process_launch_attempted'),
                'subprocess_start_contract_daemon_started' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.subprocess_start_contract.daemon_started'),
                'subprocess_start_contract_subprocess_launch_implemented' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.subprocess_start_contract.subprocess_launch_implemented'),
                'production_promotion_blocked' => data_get($productLoopCheck, 'gates.production_promotion_blocked'),
                'production_review_receipt_valid' => data_get($productLoopCheck, 'gates.production_review_receipt_valid'),
                'production_review_bound_to_expected_bundle' => data_get($productLoopCheck, 'gates.production_review_bound_to_expected_bundle'),
                'production_review_expected_bundle_validated' => data_get($productLoopCheck, 'gates.production_review_expected_bundle_validated'),
                'daemon_implementation_review_valid' => data_get($productLoopCheck, 'gates.daemon_implementation_review_valid'),
                'boolean_approval_is_sufficient' => data_get($productLoopCheck, 'gates.boolean_approval_is_sufficient'),
            ],
            'pre_start_health_checks_smoke_passed' => [
                'passed' => ($preStartHealthChecksSmoke['schema_version'] ?? null) === 'atlas.voice_realtime.pre_start_health_checks_smoke.v1'
                    && ($preStartHealthChecksSmoke['status'] ?? null) === 'passed_no_process_start'
                    && ($preStartHealthChecksSmoke['smoke_only'] ?? false) === true
                    && ($preStartHealthChecksSmoke['production_readiness'] ?? null) === 'not_proven_by_smoke'
                    && ($preStartHealthChecksSmoke['process_launch_attempted'] ?? true) === false
                    && ($preStartHealthChecksSmoke['daemon_started'] ?? true) === false
                    && ($preStartHealthChecksSmoke['subprocess_module_imported'] ?? true) === false
                    && ($preStartHealthChecksSmoke['livekit_sdk_imported'] ?? true) === false
                    && ($preStartHealthChecksSmoke['provider_calls_made'] ?? true) === false
                    && ($preStartHealthChecksSmoke['tool_calls_made'] ?? true) === false
                    && ($preStartHealthChecksSmoke['raw_audio_touched'] ?? true) === false
                    && ($preStartHealthChecksSmoke['temporary_env_file_removed_after_smoke'] ?? false) === true
                    && data_get($preStartHealthChecksSmoke, 'subprocess_start_contract.status') === 'ready_for_reviewed_subprocess_start_implementation',
                'schema_version' => $preStartHealthChecksSmoke['schema_version'] ?? null,
                'status' => $preStartHealthChecksSmoke['status'] ?? 'unknown',
                'smoke_only' => $preStartHealthChecksSmoke['smoke_only'] ?? null,
                'production_readiness' => $preStartHealthChecksSmoke['production_readiness'] ?? null,
                'process_launch_attempted' => $preStartHealthChecksSmoke['process_launch_attempted'] ?? null,
                'daemon_started' => $preStartHealthChecksSmoke['daemon_started'] ?? null,
                'subprocess_module_imported' => $preStartHealthChecksSmoke['subprocess_module_imported'] ?? null,
                'livekit_sdk_imported' => $preStartHealthChecksSmoke['livekit_sdk_imported'] ?? null,
                'provider_calls_made' => $preStartHealthChecksSmoke['provider_calls_made'] ?? null,
                'tool_calls_made' => $preStartHealthChecksSmoke['tool_calls_made'] ?? null,
                'raw_audio_touched' => $preStartHealthChecksSmoke['raw_audio_touched'] ?? null,
                'temporary_env_file_removed_after_smoke' => $preStartHealthChecksSmoke['temporary_env_file_removed_after_smoke'] ?? null,
                'subprocess_start_contract_status' => data_get($preStartHealthChecksSmoke, 'subprocess_start_contract.status'),
            ],
            'certification_artifacts_sanitized' => [
                'passed' => $forbiddenArtifactKeys === [],
                'forbidden_key_count' => count($forbiddenArtifactKeys),
                'forbidden_keys' => $forbiddenArtifactKeys,
            ],
        ];

        $failed = collect($gates)
            ->filter(fn (array $gate): bool => ! (bool) ($gate['passed'] ?? false))
            ->keys()
            ->values()
            ->all();
        $certified = $failed === [];
        $productionPromotionGate = $this->productionPromotionGate(
            certified: $certified,
            requireSdk: $requireSdk,
            preflight: $preflight,
            callbackSequence: $callbackSequence,
            productionLoop: $productionLoop,
            workerStart: $workerStart,
            productLoopCheck: $productLoopCheck,
            preStartHealthChecksSmoke: $preStartHealthChecksSmoke,
            tokenIssuer: $tokenIssuer,
            tokenIssuerSmoke: $tokenIssuerSmoke,
            liveKitServerProbe: $liveKitServerProbe,
        );

        $nextAction = $certified
            ? (string) ($productionPromotionGate['next_action'] ?? 'submit_voice_production_promotion_for_human_review')
            : 'fix_failed_runtime_certification_gates';

        return [
            'schema_version' => 'atlas.voice_realtime.runtime_certification.v1',
            'status' => $certified ? 'certified_scaffold' : 'failed',
            'surface_id' => 'voice_realtime',
            'runtime_id' => $runtime,
            'kernel_only' => true,
            'mobile_first' => true,
            'daemon_started' => false,
            'sdk_required_for_certification' => $requireSdk,
            'gates' => $gates,
            'production_promotion_gate' => $productionPromotionGate,
            'summary' => [
                'gate_count' => count($gates),
                'passed_gates' => count($gates) - count($failed),
                'failed_gates' => count($failed),
                'failed_keys' => $failed,
            ],
            'artifacts' => $artifacts,
            'next_action' => $nextAction,
        ];
    }

    /**
     * @param  array<string,mixed>  $preflight
     * @param  array<string,mixed>  $callbackSequence
     * @param  array<string,mixed>  $productionLoop
     * @param  array<string,mixed>  $workerStart
     * @param  array<string,mixed>  $productLoopCheck
     * @param  array<string,mixed>  $preStartHealthChecksSmoke
     * @param  array<string,mixed>  $tokenIssuer
     * @param  array<string,mixed>  $tokenIssuerSmoke
     * @param  array<string,mixed>  $liveKitServerProbe
     * @return array<string,mixed>
     */
    private function productionPromotionGate(
        bool $certified,
        bool $requireSdk,
        array $preflight,
        array $callbackSequence,
        array $productionLoop,
        array $workerStart,
        array $productLoopCheck,
        array $preStartHealthChecksSmoke,
        array $tokenIssuer,
        array $tokenIssuerSmoke,
        array $liveKitServerProbe,
    ): array {
        $machineGates = [
            'scaffold_certified' => [
                'passed' => $certified,
                'reason' => $certified ? null : 'runtime_certification_failed',
            ],
            'sdk_certification_required' => [
                'passed' => $requireSdk,
                'reason' => $requireSdk ? null : 'production_promotion_must_run_with_require_sdk',
            ],
            'livekit_agents_sdk_ready' => [
                'passed' => data_get($preflight, 'preflight.sdk_status.status') === 'ready',
                'status' => data_get($preflight, 'preflight.sdk_status.status', 'unknown'),
                'reason' => data_get($preflight, 'preflight.sdk_status.status') === 'ready'
                    ? null
                    : (string) data_get($preflight, 'preflight.sdk_status.next_action', 'install_livekit_agents_sdk'),
            ],
            'livekit_token_issuer_ready' => [
                'passed' => ($tokenIssuer['status'] ?? null) === 'ready',
                'status' => $tokenIssuer['status'] ?? 'unknown',
                'reason' => ($tokenIssuer['status'] ?? null) === 'ready' ? null : 'configure_livekit_token_issuer',
            ],
            'livekit_token_issuer_smoke_passed' => [
                'passed' => ($tokenIssuerSmoke['status'] ?? null) === 'passed'
                    && ($tokenIssuerSmoke['token_issued'] ?? false) === true
                    && ($tokenIssuerSmoke['access_token_exposed'] ?? true) === false
                    && ($tokenIssuerSmoke['ephemeral_test_config'] ?? true) === false
                    && ($tokenIssuerSmoke['production_readiness'] ?? null) === 'current_environment_checked',
                'status' => $tokenIssuerSmoke['status'] ?? 'unknown',
                'token_issued' => $tokenIssuerSmoke['token_issued'] ?? null,
                'access_token_exposed' => $tokenIssuerSmoke['access_token_exposed'] ?? null,
                'ephemeral_test_config' => $tokenIssuerSmoke['ephemeral_test_config'] ?? null,
                'production_readiness' => $tokenIssuerSmoke['production_readiness'] ?? null,
                'reason' => ($tokenIssuerSmoke['status'] ?? null) === 'passed' ? null : 'run_token_issuer_smoke_after_configuring_livekit',
            ],
            'livekit_server_reachable' => [
                'passed' => ($liveKitServerProbe['schema_version'] ?? null) === 'atlas.voice_realtime.livekit_server_probe.v1'
                    && ($liveKitServerProbe['status'] ?? null) === 'reachable'
                    && data_get($liveKitServerProbe, 'probe.reachable') === true
                    && data_get($liveKitServerProbe, 'security_contract.secrets_exposed') === false
                    && data_get($liveKitServerProbe, 'security_contract.api_key_read') === false
                    && data_get($liveKitServerProbe, 'security_contract.api_secret_read') === false
                    && data_get($liveKitServerProbe, 'security_contract.daemon_started') === false
                    && data_get($liveKitServerProbe, 'security_contract.process_launch_attempted') === false
                    && data_get($liveKitServerProbe, 'security_contract.livekit_sdk_imported') === false,
                'status' => $liveKitServerProbe['status'] ?? 'unknown',
                'livekit_url_configured' => $liveKitServerProbe['livekit_url_configured'] ?? null,
                'livekit_url_redacted' => $liveKitServerProbe['livekit_url_redacted'] ?? null,
                'reachable' => data_get($liveKitServerProbe, 'probe.reachable'),
                'daemon_started' => data_get($liveKitServerProbe, 'security_contract.daemon_started'),
                'reason' => ($liveKitServerProbe['status'] ?? null) === 'reachable'
                    ? null
                    : (string) ($liveKitServerProbe['next_action'] ?? 'start_or_fix_livekit_server_local_then_rerun_probe'),
            ],
            'callback_sequence_passed' => [
                'passed' => ($callbackSequence['status'] ?? null) === 'passed'
                    && (int) data_get($callbackSequence, 'callback_sequence.active_session_count', 1) === 0,
                'status' => $callbackSequence['status'] ?? 'unknown',
            ],
            'production_loop_smoke_passed' => [
                'passed' => ($productionLoop['status'] ?? null) === 'production_loop_smoke_completed'
                    && ($productionLoop['daemon_started'] ?? true) === false
                    && ($productionLoop['sdk_imported'] ?? true) === false
                    && data_get($productionLoop, 'kernel_normalizer_contract_report.status') === 'valid'
                    && data_get($productionLoop, 'bridge_contract_report.status') === 'valid'
                    && data_get($productionLoop, 'handler_registry_contract_report.status') === 'valid'
                    && data_get($productionLoop, 'worker_return_contract.status') === 'valid',
                'status' => $productionLoop['status'] ?? 'unknown',
                'kernel_normalizer_contract_status' => data_get($productionLoop, 'kernel_normalizer_contract_report.status'),
                'bridge_contract_status' => data_get($productionLoop, 'bridge_contract_report.status'),
                'handler_registry_contract_status' => data_get($productionLoop, 'handler_registry_contract_report.status'),
                'worker_return_contract_status' => data_get($productionLoop, 'worker_return_contract.status'),
            ],
            'sdk_probe_import_safe' => [
                'passed' => data_get($workerStart, 'worker_plan.sdk_status.sdk_imported') === false
                    && data_get($workerStart, 'worker_plan.sdk_status.import_probe_only') === true,
                'sdk_imported' => data_get($workerStart, 'worker_plan.sdk_status.sdk_imported'),
                'import_probe_only' => data_get($workerStart, 'worker_plan.sdk_status.import_probe_only'),
                'status' => data_get($workerStart, 'worker_plan.sdk_status.status', 'unknown'),
            ],
            'product_loop_check_available' => [
                'passed' => ($productLoopCheck['schema_version'] ?? null) === 'atlas.voice_realtime.product_loop_check.v1'
                    && ($productLoopCheck['daemon_started'] ?? true) === false
                    && data_get($productLoopCheck, 'gates.sdk_probe_import_safe') === true
                    && data_get($productLoopCheck, 'gates.sdk_handler_blueprint_available') === true
                    && data_get($productLoopCheck, 'gates.sdk_kernel_normalizer_required') === true
                    && data_get($productLoopCheck, 'gates.daemon_supervisor_health_snapshot_available') === true
                    && data_get($productLoopCheck, 'gates.daemon_supervisor_preflight_available') === true
                    && data_get($productLoopCheck, 'gates.daemon_supervisor_execution_available') === true
                    && data_get($productLoopCheck, 'gates.daemon_supervisor_process_launch_disabled') === true
                    && data_get($productLoopCheck, 'gates.daemon_process_adapter_blueprint_available') === true
                    && data_get($productLoopCheck, 'gates.supervised_process_adapter_available') === true
                    && data_get($productLoopCheck, 'gates.managed_env_contract_available') === true
                    && data_get($productLoopCheck, 'gates.launch_authorization_contract_available') === true
                    && data_get($productLoopCheck, 'gates.managed_env_writer_contract_available') === true
                    && data_get($productLoopCheck, 'gates.supervised_launch_execution_contract_available') === true
                    && data_get($productLoopCheck, 'gates.subprocess_start_contract_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_final_process_start_contract_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_execution_review_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_execution_packet_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_executor_stub_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_executor_review_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_executor_contract_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_runtime_adapter_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_adapter_review_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_adapter_contract_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_runner_contract_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_runner_review_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_runner_packet_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_runner_execution_review_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_runner_execution_contract_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_runner_start_gate_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_runner_final_review_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_runner_promotion_packet_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_runner_operator_release_review_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_runner_release_finalization_available') === true
                    && data_get($productLoopCheck, 'gates.guarded_start_process_runner_release_authorization_available') === true
                    && data_get($productLoopCheck, 'gates.controlled_livekit_server_supervised_smoke_contract_available') === true
                    && data_get($productLoopCheck, 'gates.production_promotion_blocked') === true,
                'schema_version' => $productLoopCheck['schema_version'] ?? null,
                'status' => $productLoopCheck['status'] ?? 'unknown',
                'daemon_started' => $productLoopCheck['daemon_started'] ?? null,
                'sdk_probe_import_safe' => data_get($productLoopCheck, 'gates.sdk_probe_import_safe'),
                'sdk_handler_blueprint_available' => data_get($productLoopCheck, 'gates.sdk_handler_blueprint_available'),
                'sdk_kernel_normalizer_required' => data_get($productLoopCheck, 'gates.sdk_kernel_normalizer_required'),
                'daemon_supervisor_contract_available' => data_get($productLoopCheck, 'gates.daemon_supervisor_contract_available'),
                'daemon_supervisor_health_snapshot_available' => data_get($productLoopCheck, 'gates.daemon_supervisor_health_snapshot_available'),
                'daemon_supervisor_preflight_available' => data_get($productLoopCheck, 'gates.daemon_supervisor_preflight_available'),
                'daemon_supervisor_execution_available' => data_get($productLoopCheck, 'gates.daemon_supervisor_execution_available'),
                'daemon_supervisor_process_launch_disabled' => data_get($productLoopCheck, 'gates.daemon_supervisor_process_launch_disabled'),
                'daemon_process_adapter_blueprint_available' => data_get($productLoopCheck, 'gates.daemon_process_adapter_blueprint_available'),
                'supervised_process_adapter_available' => data_get($productLoopCheck, 'gates.supervised_process_adapter_available'),
                'managed_env_contract_available' => data_get($productLoopCheck, 'gates.managed_env_contract_available'),
                'launch_authorization_contract_available' => data_get($productLoopCheck, 'gates.launch_authorization_contract_available'),
                'launch_authorization_contract_ready' => data_get($productLoopCheck, 'gates.launch_authorization_contract_ready'),
                'managed_env_writer_contract_available' => data_get($productLoopCheck, 'gates.managed_env_writer_contract_available'),
                'supervised_launch_execution_contract_available' => data_get($productLoopCheck, 'gates.supervised_launch_execution_contract_available'),
                'subprocess_start_contract_available' => data_get($productLoopCheck, 'gates.subprocess_start_contract_available'),
                'guarded_start_final_process_start_contract_available' => data_get($productLoopCheck, 'gates.guarded_start_final_process_start_contract_available'),
                'guarded_start_process_execution_review_available' => data_get($productLoopCheck, 'gates.guarded_start_process_execution_review_available'),
                'guarded_start_process_execution_packet_available' => data_get($productLoopCheck, 'gates.guarded_start_process_execution_packet_available'),
                'guarded_start_process_executor_stub_available' => data_get($productLoopCheck, 'gates.guarded_start_process_executor_stub_available'),
                'guarded_start_process_executor_review_available' => data_get($productLoopCheck, 'gates.guarded_start_process_executor_review_available'),
                'guarded_start_process_executor_contract_available' => data_get($productLoopCheck, 'gates.guarded_start_process_executor_contract_available'),
                'guarded_start_process_runtime_adapter_available' => data_get($productLoopCheck, 'gates.guarded_start_process_runtime_adapter_available'),
                'guarded_start_process_adapter_review_available' => data_get($productLoopCheck, 'gates.guarded_start_process_adapter_review_available'),
                'guarded_start_process_adapter_contract_available' => data_get($productLoopCheck, 'gates.guarded_start_process_adapter_contract_available'),
                'guarded_start_process_runner_contract_available' => data_get($productLoopCheck, 'gates.guarded_start_process_runner_contract_available'),
                'guarded_start_process_runner_review_available' => data_get($productLoopCheck, 'gates.guarded_start_process_runner_review_available'),
                'guarded_start_process_runner_packet_available' => data_get($productLoopCheck, 'gates.guarded_start_process_runner_packet_available'),
                'guarded_start_process_runner_execution_review_available' => data_get($productLoopCheck, 'gates.guarded_start_process_runner_execution_review_available'),
                'guarded_start_process_runner_execution_contract_available' => data_get($productLoopCheck, 'gates.guarded_start_process_runner_execution_contract_available'),
                'guarded_start_process_runner_start_gate_available' => data_get($productLoopCheck, 'gates.guarded_start_process_runner_start_gate_available'),
                'guarded_start_process_runner_final_review_available' => data_get($productLoopCheck, 'gates.guarded_start_process_runner_final_review_available'),
                'guarded_start_process_runner_promotion_packet_available' => data_get($productLoopCheck, 'gates.guarded_start_process_runner_promotion_packet_available'),
                'guarded_start_process_runner_operator_release_review_available' => data_get($productLoopCheck, 'gates.guarded_start_process_runner_operator_release_review_available'),
                'guarded_start_process_runner_release_finalization_available' => data_get($productLoopCheck, 'gates.guarded_start_process_runner_release_finalization_available'),
                'guarded_start_process_runner_release_authorization_available' => data_get($productLoopCheck, 'gates.guarded_start_process_runner_release_authorization_available'),
                'controlled_livekit_server_supervised_smoke_contract_available' => data_get($productLoopCheck, 'gates.controlled_livekit_server_supervised_smoke_contract_available'),
                'supervisor_health_snapshot_schema_version' => data_get($productLoopCheck, 'supervised_start_plan.supervisor_health_snapshot.schema_version'),
                'supervisor_health_snapshot_daemon_started' => data_get($productLoopCheck, 'supervised_start_plan.supervisor_health_snapshot.daemon_started'),
                'supervisor_preflight_schema_version' => data_get($productLoopCheck, 'supervised_start_plan.supervisor_preflight.schema_version'),
                'supervisor_preflight_process_launch_attempted' => data_get($productLoopCheck, 'supervised_start_plan.supervisor_preflight.process_launch_attempted'),
                'daemon_supervisor_execution_schema_version' => data_get($productLoopCheck, 'daemon_supervisor_execution.schema_version'),
                'daemon_supervisor_execution_process_launch_attempted' => data_get($productLoopCheck, 'daemon_supervisor_execution.process_launch_attempted'),
                'daemon_supervisor_execution_start_allowed' => data_get($productLoopCheck, 'daemon_supervisor_execution.start_allowed'),
                'daemon_process_adapter_blueprint_schema_version' => data_get($productLoopCheck, 'daemon_supervisor_execution.process_adapter_blueprint.schema_version'),
                'daemon_process_adapter_blueprint_launch_allowed' => data_get($productLoopCheck, 'daemon_supervisor_execution.process_adapter_blueprint.launch_allowed'),
                'supervised_process_adapter_schema_version' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.schema_version'),
                'supervised_process_adapter_process_launch_attempted' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.process_launch_attempted'),
                'managed_env_contract_schema_version' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.managed_environment_contract.schema_version'),
                'managed_env_contract_write_attempted' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.managed_environment_contract.env_file_write_attempted'),
                'managed_env_contract_secret_values_present' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.managed_environment_contract.secret_values_present_in_output'),
                'launch_authorization_contract_schema_version' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.launch_authorization_contract.schema_version'),
                'launch_authorization_contract_launch_allowed' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.launch_authorization_contract.launch_allowed'),
                'launch_authorization_contract_process_launch_attempted' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.launch_authorization_contract.process_launch_attempted'),
                'managed_env_writer_schema_version' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.managed_env_writer.schema_version'),
                'managed_env_writer_write_execution_available' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.managed_env_writer.write_execution_available'),
                'managed_env_writer_write_execution_implemented' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.managed_env_writer.write_execution_implemented'),
                'managed_env_writer_write_attempted' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.managed_env_writer.env_file_write_attempted'),
                'supervised_launch_execution_schema_version' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.supervised_launch_execution.schema_version'),
                'supervised_launch_execution_status' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.supervised_launch_execution.status'),
                'supervised_launch_execution_process_launch_attempted' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.supervised_launch_execution.process_launch_attempted'),
                'supervised_launch_execution_daemon_started' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.supervised_launch_execution.daemon_started'),
                'supervised_launch_execution_subprocess_launch_implemented' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.supervised_launch_execution.subprocess_launch_implemented'),
                'supervised_launch_execution_pre_start_health_checks_available' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.supervised_launch_execution.pre_start_health_checks_execution_available'),
                'supervised_launch_execution_pre_start_health_checks_executed' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.supervised_launch_execution.pre_start_health_checks_executed'),
                'subprocess_start_contract_schema_version' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.subprocess_start_contract.schema_version'),
                'subprocess_start_contract_status' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.subprocess_start_contract.status'),
                'subprocess_start_contract_process_launch_attempted' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.subprocess_start_contract.process_launch_attempted'),
                'subprocess_start_contract_daemon_started' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.subprocess_start_contract.daemon_started'),
                'subprocess_start_contract_subprocess_launch_implemented' => data_get($productLoopCheck, 'daemon_supervisor_execution.supervised_process_adapter.subprocess_start_contract.subprocess_launch_implemented'),
                'production_promotion_blocked' => data_get($productLoopCheck, 'gates.production_promotion_blocked'),
                'production_review_receipt_valid' => data_get($productLoopCheck, 'gates.production_review_receipt_valid'),
                'production_review_bound_to_expected_bundle' => data_get($productLoopCheck, 'gates.production_review_bound_to_expected_bundle'),
                'production_review_expected_bundle_validated' => data_get($productLoopCheck, 'gates.production_review_expected_bundle_validated'),
                'daemon_implementation_review_valid' => data_get($productLoopCheck, 'gates.daemon_implementation_review_valid'),
                'boolean_approval_is_sufficient' => data_get($productLoopCheck, 'gates.boolean_approval_is_sufficient'),
            ],
            'pre_start_health_checks_smoke_passed' => [
                'passed' => ($preStartHealthChecksSmoke['schema_version'] ?? null) === 'atlas.voice_realtime.pre_start_health_checks_smoke.v1'
                    && ($preStartHealthChecksSmoke['status'] ?? null) === 'passed_no_process_start'
                    && ($preStartHealthChecksSmoke['smoke_only'] ?? false) === true
                    && ($preStartHealthChecksSmoke['production_readiness'] ?? null) === 'not_proven_by_smoke'
                    && ($preStartHealthChecksSmoke['process_launch_attempted'] ?? true) === false
                    && ($preStartHealthChecksSmoke['daemon_started'] ?? true) === false
                    && ($preStartHealthChecksSmoke['subprocess_module_imported'] ?? true) === false
                    && ($preStartHealthChecksSmoke['livekit_sdk_imported'] ?? true) === false
                    && ($preStartHealthChecksSmoke['provider_calls_made'] ?? true) === false
                    && ($preStartHealthChecksSmoke['tool_calls_made'] ?? true) === false
                    && ($preStartHealthChecksSmoke['raw_audio_touched'] ?? true) === false
                    && ($preStartHealthChecksSmoke['temporary_env_file_removed_after_smoke'] ?? false) === true
                    && data_get($preStartHealthChecksSmoke, 'subprocess_start_contract.status') === 'ready_for_reviewed_subprocess_start_implementation',
                'schema_version' => $preStartHealthChecksSmoke['schema_version'] ?? null,
                'status' => $preStartHealthChecksSmoke['status'] ?? 'unknown',
                'smoke_only' => $preStartHealthChecksSmoke['smoke_only'] ?? null,
                'production_readiness' => $preStartHealthChecksSmoke['production_readiness'] ?? null,
                'process_launch_attempted' => $preStartHealthChecksSmoke['process_launch_attempted'] ?? null,
                'daemon_started' => $preStartHealthChecksSmoke['daemon_started'] ?? null,
                'subprocess_module_imported' => $preStartHealthChecksSmoke['subprocess_module_imported'] ?? null,
                'livekit_sdk_imported' => $preStartHealthChecksSmoke['livekit_sdk_imported'] ?? null,
                'provider_calls_made' => $preStartHealthChecksSmoke['provider_calls_made'] ?? null,
                'tool_calls_made' => $preStartHealthChecksSmoke['tool_calls_made'] ?? null,
                'raw_audio_touched' => $preStartHealthChecksSmoke['raw_audio_touched'] ?? null,
                'temporary_env_file_removed_after_smoke' => $preStartHealthChecksSmoke['temporary_env_file_removed_after_smoke'] ?? null,
                'subprocess_start_contract_status' => data_get($preStartHealthChecksSmoke, 'subprocess_start_contract.status'),
            ],
            'worker_start_still_blocked_until_real_loop' => [
                'passed' => str_starts_with((string) ($workerStart['status'] ?? ''), 'blocked_')
                    && ($workerStart['started'] ?? true) === false,
                'status' => $workerStart['status'] ?? 'unknown',
            ],
        ];

        $failed = collect($machineGates)
            ->filter(fn (array $gate): bool => ! (bool) ($gate['passed'] ?? false))
            ->keys()
            ->values()
            ->all();

        return [
            'schema_version' => 'atlas.voice_realtime.production_promotion_gate.v1',
            'status' => $failed === [] ? 'review_required' : 'blocked',
            'surface_id' => 'voice_realtime',
            'runtime_id' => 'livekit_agents_sdk',
            'mobile_first' => true,
            'kernel_only' => true,
            'human_review_required' => true,
            'promotion_allowed' => false,
            'auto_promotion_allowed' => false,
            'decision_receipt_required' => true,
            'rollback_plan_required' => true,
            'review_packet' => $this->productionPromotionReviewPacket($failed),
            'machine_gates' => $machineGates,
            'summary' => [
                'gate_count' => count($machineGates),
                'passed_gates' => count($machineGates) - count($failed),
                'failed_gates' => count($failed),
                'failed_keys' => $failed,
            ],
            'next_action' => $failed === []
                ? 'submit_voice_production_promotion_for_human_review'
                : $this->productionPromotionNextAction($failed, $machineGates),
        ];
    }

    /**
     * @param  array<int,string>  $failedMachineGates
     * @return array<string,mixed>
     */
    private function productionPromotionReviewPacket(array $failedMachineGates): array
    {
        return [
            'schema_version' => 'atlas.voice_realtime.production_promotion_review_packet.v1',
            'status' => $failedMachineGates === [] ? 'ready_for_human_review' : 'blocked_until_machine_gates_pass',
            'required_human_decision' => 'approve_or_reject_voice_production_promotion',
            'required_decision_receipt' => true,
            'required_rollback_plan' => [
                'disable_livekit_token_issuer',
                'stop_livekit_worker',
                'revert_runtime_policy',
                'return_voice_runtime_to_scaffold_mode',
                'revoke_or_expire_livekit_room_tokens',
                'preserve_evidence_ledger_replay_window',
            ],
            'required_evidence' => [
                'runtime_certification',
                'livekit_agents_sdk_preflight',
                'livekit_token_issuer_readiness',
                'livekit_token_issuer_smoke',
                'production_loop_smoke',
                'product_loop_check',
                'pre_start_health_checks_smoke',
                'rivals_voice_comparison',
                'privacy_eclipse_review',
            ],
            'forbidden_actions' => [
                'auto_promote_voice_runtime',
                'start_daemon_without_review',
                'persist_raw_audio',
                'allow_direct_provider_calls',
                'bypass_kernel_decision_receipt',
            ],
        ];
    }

    /**
     * @param  array<int,string>  $failed
     * @param  array<string,array<string,mixed>>  $machineGates
     */
    private function productionPromotionNextAction(array $failed, array $machineGates): string
    {
        if (in_array('sdk_certification_required', $failed, true)) {
            return 'rerun_runtime_certification_with_require_sdk';
        }
        if (in_array('livekit_agents_sdk_ready', $failed, true)) {
            return (string) data_get($machineGates, 'livekit_agents_sdk_ready.reason', 'install_livekit_agents_sdk');
        }
        if (in_array('livekit_token_issuer_ready', $failed, true)) {
            return 'configure_livekit_token_issuer';
        }
        if (in_array('livekit_token_issuer_smoke_passed', $failed, true)) {
            return 'run_voice_token_issuer_smoke';
        }
        if (in_array('livekit_server_reachable', $failed, true)) {
            return (string) data_get($machineGates, 'livekit_server_reachable.reason', 'start_or_fix_livekit_server_local_then_rerun_probe');
        }
        if (in_array('product_loop_check_available', $failed, true)) {
            return 'run_voice_product_loop_check';
        }
        if (in_array('pre_start_health_checks_smoke_passed', $failed, true)) {
            return 'run_voice_pre_start_health_checks_smoke';
        }

        return 'fix_voice_production_promotion_gates';
    }

    /**
     * @return array<string,mixed>
     */
    private function runPreflight(string $runtime, string $baseUrl, bool $requireSdk): array
    {
        $bootstrapPath = tempnam(sys_get_temp_dir(), 'atlas-voice-bootstrap-');
        $envPath = tempnam(sys_get_temp_dir(), 'atlas-voice-env-');
        if ($bootstrapPath === false || $envPath === false) {
            return $this->failed('atlas.voice_realtime.preflight_command.v1', $runtime, 'could_not_create_temp_preflight_files');
        }

        $bootstrap = $this->voice->runtimeBootstrapManifest([
            'runtime' => $runtime,
            'base_url' => $baseUrl,
        ]);
        JsonFileStore::write($bootstrapPath, $bootstrap, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($envPath, implode("\n", [
            'ATLAS_BASE_URL='.$baseUrl,
            'ATLAS_TOKEN=preflight-token',
            'ATLAS_VOICE_BOOTSTRAP='.$bootstrapPath,
            'LIVEKIT_URL=http://livekit.test',
            'LIVEKIT_API_KEY=preflight-key',
            'LIVEKIT_API_SECRET=preflight-secret',
            'ATLAS_VOICE_STT_PROVIDER=configurable',
            'ATLAS_VOICE_TTS_PROVIDER=configurable',
        ]));

        $args = ['--env-file', $envPath, '--preflight'];
        if ($requireSdk) {
            $args[] = '--require-sdk';
        }

        try {
            $decoded = $this->runPython($args);

            return [
                'schema_version' => 'atlas.voice_realtime.preflight_command.v1',
                'status' => (string) ($decoded['status'] ?? 'failed'),
                'surface_id' => 'voice_realtime',
                'runtime_id' => $runtime,
                'require_sdk' => $requireSdk,
                'command' => 'PYTHONPATH=runtimes/python/voice_realtime '.$this->pythonBinary().' -m atlas_voice_agent.main --env-file <generated> --preflight'.($requireSdk ? ' --require-sdk' : ''),
                'preflight' => $this->sanitize($decoded),
            ];
        } finally {
            @unlink($bootstrapPath);
            @unlink($envPath);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function runCallbackSequenceSmoke(string $runtime, string $baseUrl): array
    {
        $examplePath = base_path('runtimes/python/voice_realtime/callback-events.example.json');
        $payload = $this->runPythonBootstrapCommand($runtime, $baseUrl, [
            '--mock-kernel',
            '--callback-events',
            $examplePath,
        ], 'atlas.voice_realtime.callback_sequence.v1');

        return [
            'schema_version' => 'atlas.voice_realtime.callback_sequence_smoke.v1',
            'status' => (($payload['status'] ?? null) === 'callback_sequence_routed') ? 'passed' : 'failed',
            'surface_id' => 'voice_realtime',
            'runtime_id' => $runtime,
            'mock_kernel' => true,
            'example_path' => 'runtimes/python/voice_realtime/callback-events.example.json',
            'command' => 'PYTHONPATH=runtimes/python/voice_realtime '.$this->pythonBinary().' -m atlas_voice_agent.main --bootstrap <generated> --mock-kernel --callback-events runtimes/python/voice_realtime/callback-events.example.json',
            'callback_sequence' => $payload,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runProductionLoopSmoke(string $runtime, string $baseUrl): array
    {
        return $this->runPythonBootstrapCommand($runtime, $baseUrl, [
            '--mock-kernel',
            '--sdk-events',
            base_path('runtimes/python/voice_realtime/sdk-events.example.json'),
        ], 'atlas.voice_realtime.production_loop_smoke.v1');
    }

    /**
     * @return array<string,mixed>
     */
    private function runWorkerStartCheck(
        string $runtime,
        string $baseUrl,
        bool $callbackLoopWired = false,
        bool $productionSdkLoopWired = false,
    ): array {
        $bootstrapPath = tempnam(sys_get_temp_dir(), 'atlas-voice-bootstrap-');
        $envPath = tempnam(sys_get_temp_dir(), 'atlas-voice-env-');
        if ($bootstrapPath === false || $envPath === false) {
            return $this->failed('atlas.voice_realtime.worker_start.v1', $runtime, 'could_not_create_temp_worker_start_files');
        }

        $bootstrap = $this->voice->runtimeBootstrapManifest([
            'runtime' => $runtime,
            'base_url' => $baseUrl,
        ]);
        JsonFileStore::write($bootstrapPath, $bootstrap, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($envPath, implode("\n", [
            'ATLAS_BASE_URL='.$baseUrl,
            'ATLAS_TOKEN=worker-start-token',
            'ATLAS_VOICE_BOOTSTRAP='.$bootstrapPath,
            'LIVEKIT_URL=http://livekit.test',
            'LIVEKIT_API_KEY=worker-start-key',
            'LIVEKIT_API_SECRET=worker-start-secret',
            'ATLAS_VOICE_STT_PROVIDER=configurable',
            'ATLAS_VOICE_TTS_PROVIDER=configurable',
        ]));

        try {
            $args = ['--env-file', $envPath, '--start-worker'];
            if ($callbackLoopWired) {
                $args[] = '--callback-loop-wired';
            }
            if ($productionSdkLoopWired) {
                $args[] = '--production-sdk-loop-wired';
            }

            $decoded = $this->runPython($args);
            $payload = $this->sanitize($decoded);
            $payload['command'] = 'PYTHONPATH=runtimes/python/voice_realtime '.$this->pythonBinary().' -m atlas_voice_agent.main --env-file <generated> --start-worker'
                .($callbackLoopWired ? ' --callback-loop-wired' : '')
                .($productionSdkLoopWired ? ' --production-sdk-loop-wired' : '');

            return $payload;
        } finally {
            @unlink($bootstrapPath);
            @unlink($envPath);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function runProductLoopCheck(
        string $runtime,
        string $baseUrl,
        bool $callbackLoopWired = false,
        bool $productionSdkLoopWired = false,
    ): array {
        $bootstrapPath = tempnam(sys_get_temp_dir(), 'atlas-voice-bootstrap-');
        $envPath = tempnam(sys_get_temp_dir(), 'atlas-voice-env-');
        if ($bootstrapPath === false || $envPath === false) {
            return $this->failed('atlas.voice_realtime.product_loop_check.v1', $runtime, 'could_not_create_temp_product_loop_files');
        }

        $bootstrap = $this->voice->runtimeBootstrapManifest([
            'runtime' => $runtime,
            'base_url' => $baseUrl,
        ]);
        JsonFileStore::write($bootstrapPath, $bootstrap, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($envPath, implode("\n", [
            'ATLAS_BASE_URL='.$baseUrl,
            'ATLAS_TOKEN=product-loop-token',
            'ATLAS_VOICE_BOOTSTRAP='.$bootstrapPath,
            'LIVEKIT_URL=http://livekit.test',
            'LIVEKIT_API_KEY=product-loop-key',
            'LIVEKIT_API_SECRET=product-loop-secret',
            'ATLAS_VOICE_STT_PROVIDER=configurable',
            'ATLAS_VOICE_TTS_PROVIDER=configurable',
        ]));

        try {
            $args = ['--env-file', $envPath, '--product-loop-check'];
            if ($callbackLoopWired) {
                $args[] = '--callback-loop-wired';
            }
            if ($productionSdkLoopWired) {
                $args[] = '--production-sdk-loop-wired';
            }

            $decoded = $this->runPython($args);
            $payload = $this->sanitize($decoded);
            $payload['command'] = 'PYTHONPATH=runtimes/python/voice_realtime '.$this->pythonBinary().' -m atlas_voice_agent.main --env-file <generated> --product-loop-check'
                .($callbackLoopWired ? ' --callback-loop-wired' : '')
                .($productionSdkLoopWired ? ' --production-sdk-loop-wired' : '');

            return $payload;
        } finally {
            @unlink($bootstrapPath);
            @unlink($envPath);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function runPreStartHealthChecksSmoke(string $runtime, string $baseUrl): array
    {
        $payload = $this->voice->preStartHealthChecksSmoke([
            'runtime' => $runtime,
            'base_url' => $baseUrl,
        ]);

        $payload = $this->sanitize($payload);
        $payload['command'] = 'PYTHONPATH=runtimes/python/voice_realtime '.$this->pythonBinary().' -m atlas_voice_agent.main --bootstrap <generated> --pre-start-health-checks-smoke';

        return $payload;
    }

    /**
     * @param  array<int,string>  $extraArgs
     * @return array<string,mixed>
     */
    private function runPythonBootstrapCommand(string $runtime, string $baseUrl, array $extraArgs, string $schemaVersion): array
    {
        $bootstrapPath = tempnam(sys_get_temp_dir(), 'atlas-voice-bootstrap-');
        if ($bootstrapPath === false) {
            return $this->failed($schemaVersion, $runtime, 'could_not_create_temp_bootstrap');
        }

        $bootstrap = $this->voice->runtimeBootstrapManifest([
            'runtime' => $runtime,
            'base_url' => $baseUrl,
        ]);
        JsonFileStore::write($bootstrapPath, $bootstrap, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        try {
            return $this->sanitize($this->runPython([
                '--bootstrap',
                $bootstrapPath,
                ...$extraArgs,
            ]));
        } finally {
            @unlink($bootstrapPath);
        }
    }

    /**
     * @param  array<int,string>  $args
     * @return array<string,mixed>
     */
    private function runPython(array $args): array
    {
        $process = new Process([
            $this->pythonBinary(),
            '-m',
            'atlas_voice_agent.main',
            ...$args,
        ], base_path(), [
            'PYTHONPATH' => base_path('runtimes/python/voice_realtime'),
        ]);
        $process->setTimeout(self::PYTHON_COMMAND_TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return [
                'schema_version' => 'atlas.voice_realtime.python_command.v1',
                'status' => 'failed',
                'surface_id' => 'voice_realtime',
                'failure' => 'voice_runtime_command_timeout',
                'timeout_seconds' => self::PYTHON_COMMAND_TIMEOUT_SECONDS,
                'exit_code' => null,
                'stderr_hash' => null,
            ];
        }

        $decoded = json_decode($process->getOutput(), true);
        if (is_array($decoded)) {
            $payload = $this->sanitize($decoded);
            $payload['timeout_seconds'] = self::PYTHON_COMMAND_TIMEOUT_SECONDS;
            $payload['exit_code'] = $process->getExitCode();
            $payload['stderr_hash'] = $process->getErrorOutput() !== '' ? hash('sha256', $process->getErrorOutput()) : null;

            return $payload;
        }

        return [
            'schema_version' => 'atlas.voice_realtime.python_command.v1',
            'status' => 'failed',
            'surface_id' => 'voice_realtime',
            'failure' => 'voice_runtime_command_invalid_json',
            'timeout_seconds' => self::PYTHON_COMMAND_TIMEOUT_SECONDS,
            'exit_code' => $process->getExitCode(),
            'stderr_hash' => $process->getErrorOutput() !== '' ? hash('sha256', $process->getErrorOutput()) : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function failed(string $schemaVersion, string $runtime, string $failure): array
    {
        return [
            'schema_version' => $schemaVersion,
            'status' => 'failed',
            'surface_id' => 'voice_realtime',
            'runtime_id' => $runtime,
            'failure' => $failure,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function certificationArtifact(array $payload, array $omitKeys = []): array
    {
        return collect($this->sanitize($payload))
            ->reject(fn (mixed $_, string|int $key): bool => in_array((string) $key, $omitKeys, true))
            ->all();
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function sanitize(array $payload): array
    {
        $forbidden = $this->forbiddenArtifactKeyNames();

        return collect($payload)
            ->reject(fn (mixed $_, string|int $key): bool => in_array((string) $key, $forbidden, true))
            ->map(fn (mixed $value): mixed => is_array($value) ? $this->sanitize($value) : $value)
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function forbiddenArtifactKeyNames(): array
    {
        return [
            'access_token',
            'token',
            'livekit_token',
            'api_key',
            'api_secret',
            'raw_audio',
            'audio_bytes',
            'pcm',
            'wav',
            'response_text',
            'raw_response_text',
            'tts_text',
            'tool_call',
            'tool_args',
            'provider_api_key',
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<int,string>
     */
    private function forbiddenArtifactKeys(array $payload, string $prefix = ''): array
    {
        $forbidden = $this->forbiddenArtifactKeyNames();
        $matches = [];

        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.(string) $key;
            if (in_array((string) $key, $forbidden, true)) {
                $matches[] = $path;
            }

            if (is_array($value)) {
                array_push($matches, ...$this->forbiddenArtifactKeys($value, $path));
            }
        }

        sort($matches);

        return array_values(array_unique($matches));
    }

    private function pythonBinary(): string
    {
        $configured = trim((string) config('atlas_ai.voice_realtime.python_binary', 'python3'));
        if ($configured === '' || str_contains($configured, "\0") || str_contains($configured, "\n") || str_contains($configured, "\r")) {
            return 'python3';
        }

        return $configured;
    }

    private function pythonCommandForDisplay(string $args): string
    {
        return 'PYTHONPATH=runtimes/python/voice_realtime '.$this->pythonBinary().' -m atlas_voice_agent.main '.$args;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function runtime(array $payload): string
    {
        return (string) ($payload['runtime'] ?? 'livekit_agents_sdk');
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function baseUrl(array $payload): string
    {
        $fallback = rtrim((string) config('app.url', 'http://atlas.test'), '/') ?: 'http://atlas.test';
        $baseUrl = rtrim((string) ($payload['base_url'] ?? $fallback), '/');

        if ($baseUrl === '' || preg_match('/[\x00-\x1F\x7F]/', $baseUrl) === 1) {
            return $fallback;
        }

        $parts = parse_url($baseUrl);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return $fallback;
        }

        return $baseUrl;
    }
}
