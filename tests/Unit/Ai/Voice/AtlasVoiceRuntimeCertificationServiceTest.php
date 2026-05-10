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
        $this->assertContains('rivals_voice_comparison', data_get($payload, 'production_promotion_gate.review_packet.required_evidence'));
        $this->assertContains('bypass_kernel_decision_receipt', data_get($payload, 'production_promotion_gate.review_packet.forbidden_actions'));
        $this->assertSame('rerun_runtime_certification_with_require_sdk', data_get($payload, 'production_promotion_gate.next_action'));
        $this->assertSame('rerun_runtime_certification_with_require_sdk', $payload['next_action']);
        $this->assertContains('sdk_certification_required', data_get($payload, 'production_promotion_gate.summary.failed_keys'));
        $this->assertContains('livekit_agents_sdk_ready', data_get($payload, 'production_promotion_gate.summary.failed_keys'));
        $this->assertContains('livekit_token_issuer_ready', data_get($payload, 'production_promotion_gate.summary.failed_keys'));
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
        $this->assertTrue(data_get($payload, 'gates.product_loop_check_available.production_promotion_blocked'));
        $this->assertFalse(data_get($payload, 'gates.product_loop_check_available.production_review_receipt_valid'));
        $this->assertFalse(data_get($payload, 'gates.product_loop_check_available.boolean_approval_is_sufficient'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.passed'));
        $this->assertSame('atlas.voice_realtime.product_loop_check.v1', data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.schema_version'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.sdk_probe_import_safe'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.sdk_handler_blueprint_available'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.sdk_kernel_normalizer_required'));
        $this->assertTrue(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.production_promotion_blocked'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.production_review_receipt_valid'));
        $this->assertFalse(data_get($payload, 'production_promotion_gate.machine_gates.product_loop_check_available.boolean_approval_is_sufficient'));
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
        $this->assertSame('blocked', data_get($payload, 'artifacts.product_loop_check.status'));
        $this->assertSame('install_livekit_agents_sdk', data_get($payload, 'artifacts.product_loop_check.next_action'));
        $this->assertFalse(data_get($payload, 'artifacts.product_loop_check.daemon_started'));
        $this->assertSame('blocked', data_get($payload, 'artifacts.livekit_token_issuer.status'));
        $this->assertFalse(data_get($payload, 'artifacts.livekit_token_issuer.secrets_exposed'));
        $this->assertStringNotContainsString('preflight-secret', $encoded);
        $this->assertStringNotContainsString('worker-start-secret', $encoded);
        $this->assertStringNotContainsString('product-loop-secret', $encoded);
        $this->assertStringNotContainsString('"api_secret"', $encoded);
        $this->assertStringNotContainsString('"raw_audio"', $encoded);
        $this->assertStringNotContainsString('"response_text"', $encoded);
    }
}
