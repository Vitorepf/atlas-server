<?php

namespace Tests\Unit\Ai\Voice;

use App\Services\Ai\Voice\AtlasVoiceRuntimeCertificationService;
use Tests\TestCase;

final class AtlasVoiceRuntimeCertificationServiceTest extends TestCase
{
    public function test_certification_service_sanitizes_base_url_before_generating_runtime_env_files(): void
    {
        config()->set('app.url', 'http://atlas.test');

        $service = app(AtlasVoiceRuntimeCertificationService::class);
        $method = new \ReflectionMethod($service, 'baseUrl');
        $method->setAccessible(true);

        $this->assertSame('https://atlas.local:8443/api', $method->invoke($service, [
            'base_url' => 'https://atlas.local:8443/api/',
        ]));
        $this->assertSame('http://atlas.test', $method->invoke($service, [
            'base_url' => "http://atlas.test\nLIVEKIT_API_SECRET=injected",
        ]));
        $this->assertSame('http://atlas.test', $method->invoke($service, [
            'base_url' => 'file:///tmp/atlas-voice.env',
        ]));
    }

    public function test_certification_service_rejects_unknown_runtime_without_starting_checks(): void
    {
        $payload = app(AtlasVoiceRuntimeCertificationService::class)->certify([
            'runtime' => 'direct_provider_voice',
        ]);

        $this->assertSame('atlas.voice_realtime.runtime_certification.v1', $payload['schema_version']);
        $this->assertSame('invalid_runtime', $payload['status']);
        $this->assertSame('direct_provider_voice', $payload['runtime_id']);
        $this->assertSame(['livekit_agents_sdk'], $payload['allowed_runtimes']);
        $this->assertFalse($payload['daemon_started']);
        $this->assertSame(['runtime_allowlist'], data_get($payload, 'summary.failed_keys'));
        $this->assertSame('use_allowed_voice_runtime', $payload['next_action']);
    }

    public function test_certification_service_propagates_wiring_flags_to_runtime_checks_without_starting_daemon(): void
    {
        $payload = app(AtlasVoiceRuntimeCertificationService::class)->certify([
            'runtime' => 'livekit_agents_sdk',
            'base_url' => 'http://atlas.test',
            'callback_loop_wired' => true,
            'production_sdk_loop_wired' => true,
        ]);

        $this->assertSame('certified_scaffold', $payload['status']);
        $this->assertFalse($payload['daemon_started']);
        $this->assertStringContainsString(
            '--callback-loop-wired --production-sdk-loop-wired',
            (string) data_get($payload, 'artifacts.worker_start_check.command'),
        );
        $this->assertStringContainsString(
            '--callback-loop-wired --production-sdk-loop-wired',
            (string) data_get($payload, 'artifacts.product_loop_check.command'),
        );
        $this->assertFalse(data_get($payload, 'artifacts.worker_start_check.started'));
        $this->assertFalse(data_get($payload, 'artifacts.product_loop_check.daemon_started'));
        $this->assertArrayNotHasKey('worker_plan', data_get($payload, 'artifacts.worker_start_check'));
        $this->assertArrayNotHasKey('worker_start', data_get($payload, 'artifacts.product_loop_check'));
    }

    public function test_certification_service_exposes_artifact_sanitization_as_hard_gate(): void
    {
        $payload = app(AtlasVoiceRuntimeCertificationService::class)->certify([
            'runtime' => 'livekit_agents_sdk',
            'base_url' => 'http://atlas.test',
        ]);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertSame('certified_scaffold', $payload['status']);
        $this->assertTrue(data_get($payload, 'gates.certification_artifacts_sanitized.passed'));
        $this->assertSame(0, data_get($payload, 'gates.certification_artifacts_sanitized.forbidden_key_count'));
        $this->assertSame([], data_get($payload, 'gates.certification_artifacts_sanitized.forbidden_keys'));
        $this->assertSame('blocked', data_get($payload, 'production_promotion_gate.status'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.human_review_required'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.promotion_allowed'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.auto_promotion_allowed'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.decision_receipt_required'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.rollback_plan_required'));
        $this->assertSame('atlas.voice_realtime.production_promotion_review_packet.v1', data_get($payload, 'production_promotion_gate.review_packet.schema_version'));
        $this->assertSame('blocked_until_machine_gates_pass', data_get($payload, 'production_promotion_gate.review_packet.status'));
        $this->assertSame('approve_or_reject_voice_production_promotion', data_get($payload, 'production_promotion_gate.review_packet.required_human_decision'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.review_packet.required_decision_receipt'));
        $this->assertContains('disable_livekit_token_issuer', data_get($payload, 'production_promotion_gate.review_packet.required_rollback_plan'));
        $this->assertContains('revert_runtime_policy', data_get($payload, 'production_promotion_gate.review_packet.required_rollback_plan'));
        $this->assertContains('product_loop_check', data_get($payload, 'production_promotion_gate.review_packet.required_evidence'));
        $this->assertContains('pre_start_health_checks_smoke', data_get($payload, 'production_promotion_gate.review_packet.required_evidence'));
        $this->assertContains('livekit_token_issuer_smoke', data_get($payload, 'production_promotion_gate.review_packet.required_evidence'));
        $this->assertContains('rivals_voice_comparison', data_get($payload, 'production_promotion_gate.review_packet.required_evidence'));
        $this->assertContains('bypass_kernel_decision_receipt', data_get($payload, 'production_promotion_gate.review_packet.forbidden_actions'));
        $this->assertSame('rerun_runtime_certification_with_require_sdk', data_get($payload, 'production_promotion_gate.next_action'));
        $this->assertSame('rerun_runtime_certification_with_require_sdk', $payload['next_action']);
        $this->assertContains('sdk_certification_required', data_get($payload, 'production_promotion_gate.summary.failed_keys'));
        $this->assertContains('livekit_token_issuer_ready', data_get($payload, 'production_promotion_gate.summary.failed_keys'));
        $this->assertContains('livekit_token_issuer_smoke_passed', data_get($payload, 'production_promotion_gate.summary.failed_keys'));
        $this->assertContains(data_get($payload, 'production_promotion_gate.machine_gates.livekit_agents_sdk_ready.status'), [
            'missing_optional_dependency',
            'ready',
        ], true);
        if (data_get($payload, 'production_promotion_gate.machine_gates.livekit_agents_sdk_ready.status') === 'ready') {
            $this->assertNotContains('livekit_agents_sdk_ready', data_get($payload, 'production_promotion_gate.summary.failed_keys'));
        } else {
            $this->assertContains('livekit_agents_sdk_ready', data_get($payload, 'production_promotion_gate.summary.failed_keys'));
        }
        $this->assertSame('production_promotion_must_run_with_require_sdk', data_get($payload, 'production_promotion_gate.machine_gates.sdk_certification_required.reason'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.machine_gates.sdk_probe_import_safe.passed'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.sdk_probe_import_safe.sdk_imported'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.machine_gates.sdk_probe_import_safe.import_probe_only'));
        $this->assertTrue(data_get($payload, 'gates.product_loop_check_available.passed'));
        $this->assertSame('atlas.voice_realtime.product_loop_check.v1', data_get($payload, 'gates.product_loop_check_available.schema_version'));
        $this->assertFalse(data_get($payload, 'gates.product_loop_check_available.daemon_started'));
        $this->assertTrue(data_get($payload, 'gates.product_loop_check_available.sdk_probe_import_safe'));
        $this->assertTrue(data_get($payload, 'gates.product_loop_check_available.sdk_handler_blueprint_available'));
        $this->assertTrue(data_get($payload, 'gates.product_loop_check_available.sdk_kernel_normalizer_required'));
        $this->assertTrue(data_get($payload, 'gates.product_loop_check_available.daemon_supervisor_contract_available'));
        $this->assertTrue(data_get($payload, 'gates.product_loop_check_available.daemon_supervisor_health_snapshot_available'));
        $this->assertTrue(data_get($payload, 'gates.product_loop_check_available.daemon_supervisor_preflight_available'));
        $this->assertTrue(data_get($payload, 'gates.product_loop_check_available.daemon_supervisor_execution_available'));
        $this->assertTrue(data_get($payload, 'gates.product_loop_check_available.daemon_supervisor_process_launch_disabled'));
        $this->assertTrue(data_get($payload, 'gates.product_loop_check_available.daemon_process_adapter_blueprint_available'));
        $this->assertTrue(data_get($payload, 'gates.product_loop_check_available.supervised_process_adapter_available'));
        $this->assertTrue(data_get($payload, 'gates.product_loop_check_available.managed_env_contract_available'));
        $this->assertTrue(data_get($payload, 'gates.product_loop_check_available.launch_authorization_contract_available'));
        $this->assertFalse(data_get($payload, 'gates.product_loop_check_available.launch_authorization_contract_ready'));
        $this->assertTrue(data_get($payload, 'gates.product_loop_check_available.managed_env_writer_contract_available'));
        $this->assertTrue(data_get($payload, 'gates.product_loop_check_available.supervised_launch_execution_contract_available'));
        $this->assertTrue(data_get($payload, 'gates.product_loop_check_available.subprocess_start_contract_available'));
        $this->assertSame('atlas.voice_realtime.daemon_supervisor_health_snapshot.v1', data_get($payload, 'gates.product_loop_check_available.supervisor_health_snapshot_schema_version'));
        $this->assertFalse(data_get($payload, 'gates.product_loop_check_available.supervisor_health_snapshot_daemon_started'));
        $this->assertSame('atlas.voice_realtime.daemon_supervisor_preflight.v1', data_get($payload, 'gates.product_loop_check_available.supervisor_preflight_schema_version'));
        $this->assertFalse(data_get($payload, 'gates.product_loop_check_available.supervisor_preflight_process_launch_attempted'));
        $this->assertSame('atlas.voice_realtime.daemon_supervisor_execution.v1', data_get($payload, 'gates.product_loop_check_available.daemon_supervisor_execution_schema_version'));
        $this->assertFalse(data_get($payload, 'gates.product_loop_check_available.daemon_supervisor_execution_process_launch_attempted'));
        $this->assertFalse(data_get($payload, 'gates.product_loop_check_available.daemon_supervisor_execution_start_allowed'));
        $this->assertSame('atlas.voice_realtime.daemon_process_adapter_blueprint.v1', data_get($payload, 'gates.product_loop_check_available.daemon_process_adapter_blueprint_schema_version'));
        $this->assertFalse(data_get($payload, 'gates.product_loop_check_available.daemon_process_adapter_blueprint_launch_allowed'));
        $this->assertSame('atlas.voice_realtime.supervised_process_adapter.v1', data_get($payload, 'gates.product_loop_check_available.supervised_process_adapter_schema_version'));
        $this->assertFalse(data_get($payload, 'gates.product_loop_check_available.supervised_process_adapter_process_launch_attempted'));
        $this->assertSame('atlas.voice_realtime.managed_env_contract.v1', data_get($payload, 'gates.product_loop_check_available.managed_env_contract_schema_version'));
        $this->assertFalse(data_get($payload, 'gates.product_loop_check_available.managed_env_contract_write_attempted'));
        $this->assertFalse(data_get($payload, 'gates.product_loop_check_available.managed_env_contract_secret_values_present'));
        $this->assertSame('atlas.voice_realtime.launch_authorization_contract.v1', data_get($payload, 'gates.product_loop_check_available.launch_authorization_contract_schema_version'));
        $this->assertFalse(data_get($payload, 'gates.product_loop_check_available.launch_authorization_contract_launch_allowed'));
        $this->assertFalse(data_get($payload, 'gates.product_loop_check_available.launch_authorization_contract_process_launch_attempted'));
        $this->assertSame('atlas.voice_realtime.managed_env_writer.v1', data_get($payload, 'gates.product_loop_check_available.managed_env_writer_schema_version'));
        $this->assertTrue(data_get($payload, 'gates.product_loop_check_available.managed_env_writer_write_execution_available'));
        $this->assertFalse(data_get($payload, 'gates.product_loop_check_available.managed_env_writer_write_execution_implemented'));
        $this->assertFalse(data_get($payload, 'gates.product_loop_check_available.managed_env_writer_write_attempted'));
        $this->assertSame('atlas.voice_realtime.supervised_launch_execution.v1', data_get($payload, 'gates.product_loop_check_available.supervised_launch_execution_schema_version'));
        $this->assertFalse(data_get($payload, 'gates.product_loop_check_available.supervised_launch_execution_process_launch_attempted'));
        $this->assertFalse(data_get($payload, 'gates.product_loop_check_available.supervised_launch_execution_daemon_started'));
        $this->assertTrue(data_get($payload, 'gates.product_loop_check_available.supervised_launch_execution_pre_start_health_checks_available'));
        $this->assertFalse(data_get($payload, 'gates.product_loop_check_available.supervised_launch_execution_pre_start_health_checks_executed'));
        $this->assertSame('atlas.voice_realtime.subprocess_start_contract.v1', data_get($payload, 'gates.product_loop_check_available.subprocess_start_contract_schema_version'));
        $this->assertSame('blocked', data_get($payload, 'gates.product_loop_check_available.subprocess_start_contract_status'));
        $this->assertFalse(data_get($payload, 'gates.product_loop_check_available.subprocess_start_contract_process_launch_attempted'));
        $this->assertFalse(data_get($payload, 'gates.product_loop_check_available.subprocess_start_contract_daemon_started'));
        $this->assertFalse(data_get($payload, 'gates.product_loop_check_available.subprocess_start_contract_subprocess_launch_implemented'));
        $this->assertTrue(data_get($payload, 'gates.product_loop_check_available.production_promotion_blocked'));
        $this->assertFalse(data_get($payload, 'gates.product_loop_check_available.production_review_receipt_valid'));
        $this->assertFalse(data_get($payload, 'gates.product_loop_check_available.production_review_bound_to_expected_bundle'));
        $this->assertFalse(data_get($payload, 'gates.product_loop_check_available.production_review_expected_bundle_validated'));
        $this->assertFalse(data_get($payload, 'gates.product_loop_check_available.daemon_implementation_review_valid'));
        $this->assertFalse(data_get($payload, 'gates.product_loop_check_available.boolean_approval_is_sufficient'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.passed'));
        $this->assertSame('atlas.voice_realtime.product_loop_check.v1', data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.schema_version'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.sdk_probe_import_safe'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.sdk_handler_blueprint_available'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.sdk_kernel_normalizer_required'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.daemon_supervisor_contract_available'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.daemon_supervisor_health_snapshot_available'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.daemon_supervisor_preflight_available'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.daemon_supervisor_execution_available'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.daemon_supervisor_process_launch_disabled'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.daemon_process_adapter_blueprint_available'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.supervised_process_adapter_available'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.managed_env_contract_available'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.launch_authorization_contract_available'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.launch_authorization_contract_ready'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.managed_env_writer_contract_available'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.supervised_launch_execution_contract_available'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.subprocess_start_contract_available'));
        $this->assertSame('atlas.voice_realtime.daemon_supervisor_health_snapshot.v1', data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.supervisor_health_snapshot_schema_version'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.supervisor_health_snapshot_daemon_started'));
        $this->assertSame('atlas.voice_realtime.daemon_supervisor_preflight.v1', data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.supervisor_preflight_schema_version'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.supervisor_preflight_process_launch_attempted'));
        $this->assertSame('atlas.voice_realtime.daemon_supervisor_execution.v1', data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.daemon_supervisor_execution_schema_version'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.daemon_supervisor_execution_process_launch_attempted'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.daemon_supervisor_execution_start_allowed'));
        $this->assertSame('atlas.voice_realtime.daemon_process_adapter_blueprint.v1', data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.daemon_process_adapter_blueprint_schema_version'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.daemon_process_adapter_blueprint_launch_allowed'));
        $this->assertSame('atlas.voice_realtime.supervised_process_adapter.v1', data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.supervised_process_adapter_schema_version'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.supervised_process_adapter_process_launch_attempted'));
        $this->assertSame('atlas.voice_realtime.managed_env_contract.v1', data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.managed_env_contract_schema_version'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.managed_env_contract_write_attempted'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.managed_env_contract_secret_values_present'));
        $this->assertSame('atlas.voice_realtime.launch_authorization_contract.v1', data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.launch_authorization_contract_schema_version'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.launch_authorization_contract_launch_allowed'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.launch_authorization_contract_process_launch_attempted'));
        $this->assertSame('atlas.voice_realtime.managed_env_writer.v1', data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.managed_env_writer_schema_version'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.managed_env_writer_write_execution_available'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.managed_env_writer_write_execution_implemented'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.managed_env_writer_write_attempted'));
        $this->assertSame('atlas.voice_realtime.supervised_launch_execution.v1', data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.supervised_launch_execution_schema_version'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.supervised_launch_execution_process_launch_attempted'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.supervised_launch_execution_daemon_started'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.supervised_launch_execution_pre_start_health_checks_available'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.supervised_launch_execution_pre_start_health_checks_executed'));
        $this->assertSame('atlas.voice_realtime.subprocess_start_contract.v1', data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.subprocess_start_contract_schema_version'));
        $this->assertSame('blocked', data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.subprocess_start_contract_status'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.subprocess_start_contract_process_launch_attempted'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.subprocess_start_contract_daemon_started'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.subprocess_start_contract_subprocess_launch_implemented'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.production_promotion_blocked'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.production_review_receipt_valid'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.production_review_bound_to_expected_bundle'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.production_review_expected_bundle_validated'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.daemon_implementation_review_valid'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.boolean_approval_is_sufficient'));
        $this->assertTrue(data_get($payload, 'gates.pre_start_health_checks_smoke_passed.passed'));
        $this->assertSame('atlas.voice_realtime.pre_start_health_checks_smoke.v1', data_get($payload, 'gates.pre_start_health_checks_smoke_passed.schema_version'));
        $this->assertSame('passed_no_process_start', data_get($payload, 'gates.pre_start_health_checks_smoke_passed.status'));
        $this->assertTrue(data_get($payload, 'gates.pre_start_health_checks_smoke_passed.smoke_only'));
        $this->assertSame('not_proven_by_smoke', data_get($payload, 'gates.pre_start_health_checks_smoke_passed.production_readiness'));
        $this->assertFalse(data_get($payload, 'gates.pre_start_health_checks_smoke_passed.process_launch_attempted'));
        $this->assertFalse(data_get($payload, 'gates.pre_start_health_checks_smoke_passed.daemon_started'));
        $this->assertFalse(data_get($payload, 'gates.pre_start_health_checks_smoke_passed.subprocess_module_imported'));
        $this->assertFalse(data_get($payload, 'gates.pre_start_health_checks_smoke_passed.livekit_sdk_imported'));
        $this->assertFalse(data_get($payload, 'gates.pre_start_health_checks_smoke_passed.provider_calls_made'));
        $this->assertFalse(data_get($payload, 'gates.pre_start_health_checks_smoke_passed.tool_calls_made'));
        $this->assertFalse(data_get($payload, 'gates.pre_start_health_checks_smoke_passed.raw_audio_touched'));
        $this->assertTrue(data_get($payload, 'gates.pre_start_health_checks_smoke_passed.temporary_env_file_removed_after_smoke'));
        $this->assertSame('ready_for_reviewed_subprocess_start_implementation', data_get($payload, 'gates.pre_start_health_checks_smoke_passed.subprocess_start_contract_status'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.machine_gates.pre_start_health_checks_smoke_passed.passed'));
        $this->assertSame('atlas.voice_realtime.pre_start_health_checks_smoke.v1', data_get($payload, 'production_promotion_gate.machine_gates.pre_start_health_checks_smoke_passed.schema_version'));
        $this->assertSame('passed_no_process_start', data_get($payload, 'artifacts.pre_start_health_checks_smoke.status'));
        $this->assertTrue(data_get($payload, 'artifacts.pre_start_health_checks_smoke.smoke_only'));
        $this->assertFalse(data_get($payload, 'artifacts.pre_start_health_checks_smoke.daemon_started'));
        $this->assertFalse(data_get($payload, 'artifacts.pre_start_health_checks_smoke.process_launch_attempted'));
        $this->assertFalse(data_get($payload, 'artifacts.pre_start_health_checks_smoke.provider_calls_made'));
        $this->assertFalse(data_get($payload, 'artifacts.pre_start_health_checks_smoke.tool_calls_made'));
        $this->assertFalse(data_get($payload, 'artifacts.pre_start_health_checks_smoke.raw_audio_touched'));
        $this->assertSame(30, data_get($payload, 'artifacts.pre_start_health_checks_smoke.timeout_seconds'));
        $this->assertSame(30, data_get($payload, 'artifacts.production_loop_smoke.timeout_seconds'));
        $this->assertSame('valid', data_get($payload, 'gates.production_loop_smoke_passed.kernel_normalizer_contract_status'));
        $this->assertSame('valid', data_get($payload, 'production_promotion_gate.machine_gates.production_loop_smoke_passed.kernel_normalizer_contract_status'));
        $this->assertSame('atlas.voice_realtime.kernel_normalizer_contract_report.v1', data_get($payload, 'artifacts.production_loop_smoke.kernel_normalizer_contract_report.schema_version'));
        $this->assertSame('valid', data_get($payload, 'artifacts.production_loop_smoke.kernel_normalizer_contract_report.status'));
        $this->assertTrue(data_get($payload, 'artifacts.production_loop_smoke.kernel_normalizer_contract_report.normalizer_schema_valid'));
        $this->assertTrue(data_get($payload, 'artifacts.production_loop_smoke.kernel_normalizer_contract_report.normalizer_valid'));
        $this->assertSame('valid', data_get($payload, 'gates.production_loop_smoke_passed.bridge_contract_status'));
        $this->assertSame('valid', data_get($payload, 'production_promotion_gate.machine_gates.production_loop_smoke_passed.bridge_contract_status'));
        $this->assertSame('atlas.voice_realtime.bridge_contract_report.v1', data_get($payload, 'artifacts.production_loop_smoke.bridge_contract_report.schema_version'));
        $this->assertSame('valid', data_get($payload, 'artifacts.production_loop_smoke.bridge_contract_report.status'));
        $this->assertSame('valid', data_get($payload, 'gates.production_loop_smoke_passed.handler_registry_contract_status'));
        $this->assertSame('valid', data_get($payload, 'production_promotion_gate.machine_gates.production_loop_smoke_passed.handler_registry_contract_status'));
        $this->assertSame('atlas.voice_realtime.handler_registry_contract_report.v1', data_get($payload, 'artifacts.production_loop_smoke.handler_registry_contract_report.schema_version'));
        $this->assertSame('valid', data_get($payload, 'artifacts.production_loop_smoke.handler_registry_contract_report.status'));
        $this->assertSame('valid', data_get($payload, 'gates.production_loop_smoke_passed.worker_return_contract_status'));
        $this->assertSame('valid', data_get($payload, 'production_promotion_gate.machine_gates.production_loop_smoke_passed.worker_return_contract_status'));
        $this->assertSame('atlas.voice_realtime.worker_return_contract_report.v1', data_get($payload, 'artifacts.production_loop_smoke.worker_return_contract.schema_version'));
        $this->assertSame('valid', data_get($payload, 'artifacts.production_loop_smoke.worker_return_contract.status'));
        $this->assertSame(30, data_get($payload, 'artifacts.worker_start_check.timeout_seconds'));
        $this->assertSame(30, data_get($payload, 'artifacts.product_loop_check.timeout_seconds'));
        $this->assertContains(data_get($payload, 'artifacts.product_loop_check.status'), ['blocked', 'ready_for_human_review'], true);
        $this->assertContains(data_get($payload, 'artifacts.product_loop_check.next_action'), [
            'upgrade_python_runtime_for_livekit_agents_sdk',
            'install_livekit_agents_sdk',
            'submit_voice_production_promotion_for_human_review',
        ], true);
        $this->assertFalse(data_get($payload, 'artifacts.product_loop_check.daemon_started'));
        $this->assertSame('blocked', data_get($payload, 'artifacts.livekit_token_issuer.status'));
        $this->assertFalse(data_get($payload, 'artifacts.livekit_token_issuer.secrets_exposed'));
        $this->assertSame('atlas.voice_realtime.livekit_token_issuer_smoke.v1', data_get($payload, 'artifacts.livekit_token_issuer_smoke.schema_version'));
        $this->assertSame('blocked', data_get($payload, 'artifacts.livekit_token_issuer_smoke.status'));
        $this->assertFalse(data_get($payload, 'artifacts.livekit_token_issuer_smoke.access_token_exposed'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.livekit_token_issuer_smoke_passed.passed'));
        $this->assertSame('run_token_issuer_smoke_after_configuring_livekit', data_get($payload, 'production_promotion_gate.machine_gates.livekit_token_issuer_smoke_passed.reason'));
        $this->assertStringNotContainsString('preflight-secret', $encoded);
        $this->assertStringNotContainsString('worker-start-secret', $encoded);
        $this->assertStringNotContainsString('product-loop-secret', $encoded);
        $this->assertStringNotContainsString('"api_secret"', $encoded);
        $this->assertStringNotContainsString('"raw_audio"', $encoded);
        $this->assertStringNotContainsString('"response_text"', $encoded);
    }
}
