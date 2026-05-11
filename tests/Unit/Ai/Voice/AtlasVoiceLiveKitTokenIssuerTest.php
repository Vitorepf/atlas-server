<?php

namespace Tests\Unit\Ai\Voice;

use App\Services\Ai\Voice\AtlasVoiceLiveKitTokenIssuer;
use Tests\TestCase;

final class AtlasVoiceLiveKitTokenIssuerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.voice.livekit.token_issuer_enabled', true);
        config()->set('atlas.voice.livekit.url', 'http://livekit.test');
        config()->set('atlas.voice.livekit.api_key', 'livekit-test-key');
        config()->set('atlas.voice.livekit.api_secret', 'livekit-test-secret');
    }

    public function test_issuer_rejects_rooms_outside_atlas_voice_namespace(): void
    {
        $payload = app(AtlasVoiceLiveKitTokenIssuer::class)->issue(
            session: ['session_id' => 'voice_session_unit', 'client_surface' => 'mobile'],
            lease: [
                'room_name' => 'prod-room',
                'participant_identity' => 'mobile:vitor',
            ],
        );

        $this->assertFalse($payload['issued']);
        $this->assertSame('not_issued_invalid_lease', $payload['status']);
        $this->assertSame('livekit_room_outside_atlas_voice_namespace', $payload['reason']);
        $this->assertArrayNotHasKey('access_token', $payload);
    }

    public function test_issuer_rejects_participants_outside_client_surface_namespace(): void
    {
        $payload = app(AtlasVoiceLiveKitTokenIssuer::class)->issue(
            session: ['session_id' => 'voice_session_unit', 'client_surface' => 'mobile'],
            lease: [
                'room_name' => 'atlas-voice-unit',
                'participant_identity' => 'admin:vitor',
            ],
        );

        $this->assertFalse($payload['issued']);
        $this->assertSame('not_issued_invalid_lease', $payload['status']);
        $this->assertSame('livekit_participant_outside_client_surface_namespace', $payload['reason']);
        $this->assertArrayNotHasKey('access_token', $payload);
    }

    public function test_issued_token_clamps_ttl_and_embeds_kernel_metadata_without_secret_leak(): void
    {
        config()->set('atlas.voice.livekit.token_ttl_seconds', 999999);

        $payload = app(AtlasVoiceLiveKitTokenIssuer::class)->issue(
            session: ['session_id' => 'voice_session_unit', 'client_surface' => 'mobile'],
            lease: [
                'room_name' => 'atlas-voice-unit',
                'participant_identity' => 'mobile:vitor',
            ],
        );
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $jwtPayload = $this->decodeJwtPayload($payload['access_token']);
        $metadata = json_decode((string) $jwtPayload['metadata'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['issued']);
        $this->assertSame('issued', $payload['status']);
        $this->assertSame(3600, $payload['ttl_seconds']);
        $this->assertSame('atlas-voice-unit', data_get($payload, 'grant.room'));
        $this->assertSame('mobile:vitor', $jwtPayload['sub']);
        $this->assertSame('atlas-voice-unit', data_get($jwtPayload, 'video.room'));
        $this->assertSame('voice_realtime', $metadata['surface_id']);
        $this->assertSame('voice_session_unit', $metadata['session_id']);
        $this->assertTrue($metadata['kernel_decision_required_per_turn']);
        $this->assertStringNotContainsString('livekit-test-secret', $encoded);
    }

    public function test_configuration_plan_is_redacted_and_lists_missing_env_without_side_effects(): void
    {
        config()->set('atlas.voice.livekit.token_issuer_enabled', false);
        config()->set('atlas.voice.livekit.url', '');
        config()->set('atlas.voice.livekit.api_key', '');
        config()->set('atlas.voice.livekit.api_secret', '');

        $payload = app(AtlasVoiceLiveKitTokenIssuer::class)->configurationPlan();
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.voice_realtime.livekit_token_issuer_config_plan.v1', $payload['schema_version']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame([
            'ATLAS_VOICE_LIVEKIT_TOKEN_ISSUER_ENABLED',
            'LIVEKIT_URL',
            'LIVEKIT_API_KEY',
            'LIVEKIT_API_SECRET',
        ], $payload['missing_env']);
        $this->assertFalse(data_get($payload, 'security_contract.secrets_exposed'));
        $this->assertFalse(data_get($payload, 'security_contract.writes_env_file'));
        $this->assertFalse(data_get($payload, 'security_contract.starts_daemon'));
        $this->assertFalse(data_get($payload, 'security_contract.issues_token_during_plan'));
        $this->assertContains('LIVEKIT_API_SECRET=<set-in-local-env-only>', $payload['redacted_env_template']);
        $this->assertStringNotContainsString('livekit-test-secret', $encoded);
    }

    public function test_configuration_plan_becomes_ready_when_required_env_is_configured(): void
    {
        $payload = app(AtlasVoiceLiveKitTokenIssuer::class)->configurationPlan();

        $this->assertSame('ready', $payload['status']);
        $this->assertSame([], $payload['missing_env']);
        $this->assertSame('run_voice_runtime_certification_with_require_sdk', $payload['next_action']);
        $this->assertTrue(data_get($payload, 'required_env.0.configured'));
        $this->assertTrue(data_get($payload, 'required_env.3.secret'));
    }

    public function test_smoke_blocks_when_config_is_missing_without_leaking_secret_or_token(): void
    {
        config()->set('atlas.voice.livekit.token_issuer_enabled', false);
        config()->set('atlas.voice.livekit.url', '');
        config()->set('atlas.voice.livekit.api_key', '');
        config()->set('atlas.voice.livekit.api_secret', 'super-secret-should-not-appear');

        $payload = app(AtlasVoiceLiveKitTokenIssuer::class)->smoke();
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.voice_realtime.livekit_token_issuer_smoke.v1', $payload['schema_version']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse($payload['ephemeral_test_config']);
        $this->assertFalse($payload['token_issued']);
        $this->assertNull($payload['token_hash']);
        $this->assertSame(0, $payload['token_segments_count']);
        $this->assertFalse($payload['access_token_exposed']);
        $this->assertFalse(data_get($payload, 'security_contract.secrets_exposed'));
        $this->assertFalse(data_get($payload, 'security_contract.starts_daemon'));
        $this->assertArrayNotHasKey('access_token', $payload['issued_token']);
        $this->assertStringNotContainsString('super-secret-should-not-appear', $encoded);
    }

    public function test_smoke_can_use_ephemeral_test_config_without_persisting_it_or_exposing_token(): void
    {
        config()->set('atlas.voice.livekit.token_issuer_enabled', false);
        config()->set('atlas.voice.livekit.url', '');
        config()->set('atlas.voice.livekit.api_key', '');
        config()->set('atlas.voice.livekit.api_secret', '');

        $payload = app(AtlasVoiceLiveKitTokenIssuer::class)->smoke(ephemeralTestConfig: true);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertSame('passed', $payload['status']);
        $this->assertTrue($payload['ephemeral_test_config']);
        $this->assertSame('not_proven_by_ephemeral_smoke', $payload['production_readiness']);
        $this->assertTrue($payload['token_issued']);
        $this->assertIsString($payload['token_hash']);
        $this->assertSame(64, strlen($payload['token_hash']));
        $this->assertSame(3, $payload['token_segments_count']);
        $this->assertFalse($payload['access_token_exposed']);
        $this->assertFalse(data_get($payload, 'security_contract.ephemeral_config_persists_after_command'));
        $this->assertSame('atlas-voice-smoke', data_get($payload, 'issued_token.grant.room'));
        $this->assertArrayNotHasKey('access_token', $payload['issued_token']);
        $this->assertStringNotContainsString('atlas-smoke-secret', $encoded);
        $this->assertFalse((bool) config('atlas.voice.livekit.token_issuer_enabled'));
        $this->assertSame('', config('atlas.voice.livekit.api_secret'));
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJwtPayload(string $token): array
    {
        $segments = explode('.', $token);
        $this->assertCount(3, $segments);

        $payload = strtr($segments[1], '-_', '+/');
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $decoded = base64_decode($payload, true);

        $this->assertIsString($decoded);

        return json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
    }
}
