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
}
