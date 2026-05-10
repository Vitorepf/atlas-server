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
