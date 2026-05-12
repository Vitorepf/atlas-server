<?php

namespace Tests\Unit\Ai\Voice;

use App\Services\Ai\Voice\AtlasVoiceLiveKitServerProbe;
use Tests\TestCase;

final class AtlasVoiceLiveKitServerProbeTest extends TestCase
{
    public function test_probe_blocks_without_livekit_url_and_does_not_touch_secrets_or_daemon(): void
    {
        config()->set('atlas.voice.livekit.url', '');
        config()->set('atlas.voice.livekit.api_key', 'key-that-must-not-be-read');
        config()->set('atlas.voice.livekit.api_secret', 'secret-that-must-not-appear');

        $payload = app(AtlasVoiceLiveKitServerProbe::class)->probe();
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.voice_realtime.livekit_server_probe.v1', $payload['schema_version']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse($payload['configured']);
        $this->assertFalse(data_get($payload, 'probe.attempted'));
        $this->assertFalse(data_get($payload, 'security_contract.api_key_read'));
        $this->assertFalse(data_get($payload, 'security_contract.api_secret_read'));
        $this->assertFalse(data_get($payload, 'security_contract.daemon_started'));
        $this->assertFalse(data_get($payload, 'security_contract.process_launch_attempted'));
        $this->assertFalse(data_get($payload, 'security_contract.livekit_sdk_imported'));
        $this->assertStringNotContainsString('secret-that-must-not-appear', $encoded);
        $this->assertStringNotContainsString('key-that-must-not-be-read', $encoded);
    }

    public function test_probe_detects_reachable_local_livekit_tcp_endpoint_without_starting_daemon(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $this->assertIsResource($server, $errorMessage);
        $address = stream_socket_get_name($server, false);
        $this->assertIsString($address);
        $port = (int) substr(strrchr($address, ':'), 1);
        $this->assertGreaterThan(0, $port);

        config()->set('atlas.voice.livekit.url', 'http://127.0.0.1:'.$port);
        config()->set('atlas.voice.livekit.api_key', 'reachable-key-not-read');
        config()->set('atlas.voice.livekit.api_secret', 'reachable-secret-not-read');

        try {
            $payload = app(AtlasVoiceLiveKitServerProbe::class)->probe();
        } finally {
            fclose($server);
        }

        $this->assertSame('reachable', $payload['status']);
        $this->assertTrue($payload['configured']);
        $this->assertSame('http://127.0.0.1:'.$port, $payload['livekit_url_redacted']);
        $this->assertTrue(data_get($payload, 'probe.attempted'));
        $this->assertTrue(data_get($payload, 'probe.reachable'));
        $this->assertFalse(data_get($payload, 'security_contract.token_issued'));
        $this->assertFalse(data_get($payload, 'security_contract.daemon_started'));
        $this->assertFalse(data_get($payload, 'security_contract.livekit_sdk_imported'));
        $this->assertSame('run_supervised_voice_worker_handshake_smoke_contract', $payload['next_action']);
    }
}
