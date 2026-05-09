<?php

namespace Tests\Unit\Ai\Voice;

use App\Services\Ai\Voice\AtlasVoiceRuntimeCertificationService;
use Tests\TestCase;

final class AtlasVoiceRuntimeCertificationServiceTest extends TestCase
{
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
        $this->assertStringNotContainsString('preflight-secret', $encoded);
        $this->assertStringNotContainsString('worker-start-secret', $encoded);
        $this->assertStringNotContainsString('"api_secret"', $encoded);
        $this->assertStringNotContainsString('"raw_audio"', $encoded);
        $this->assertStringNotContainsString('"response_text"', $encoded);
    }
}
