<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\SelfConstruction\Maestro\Provenance\AtlasMaestroPacketProvenanceVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasMaestroPacketProvenanceVerifier::verifyPacketBinding rejects a packet
 * whose generated_at cannot be parsed by strtotime.
 */
final class AtlasMaestroPacketProvenanceVerifierHardeningTest extends TestCase
{
    private function makeValidPacket(): array
    {
        $payload = ['test' => true];
        return [
            'source' => 'test-source',
            'target' => 'test-target',
            'content_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'composer_version' => '1.0.0',
            'queued_record_hash' => hash('sha256', 'test'),
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z', time()),
            'payload' => $payload,
        ];
    }

    public function test_valid_packet_binding_passes(): void
    {
        $verifier = new AtlasMaestroPacketProvenanceVerifier();
        $packet = $this->makeValidPacket();
        $packet['now'] = gmdate('Y-m-d\TH:i:s\Z', time());
        $packet['max_age_seconds'] = 3600;

        $result = $verifier->verifyPacketBinding($packet);

        $this->assertTrue($result['ok']);
    }

    public function test_invalid_generated_at_is_rejected(): void
    {
        $verifier = new AtlasMaestroPacketProvenanceVerifier();
        $packet = $this->makeValidPacket();
        $packet['generated_at'] = 'not-a-date';
        $packet['now'] = gmdate('Y-m-d\TH:i:s\Z', time());
        $packet['max_age_seconds'] = 3600;

        $result = $verifier->verifyPacketBinding($packet);

        $this->assertFalse($result['ok']);
        $this->assertSame('STALE_PACKET', $result['reason_code']);
    }

    public function test_invalid_now_is_rejected(): void
    {
        $verifier = new AtlasMaestroPacketProvenanceVerifier();
        $packet = $this->makeValidPacket();
        $packet['now'] = 'not-a-date';
        $packet['max_age_seconds'] = 3600;

        $result = $verifier->verifyPacketBinding($packet);

        $this->assertFalse($result['ok']);
        $this->assertSame('STALE_PACKET', $result['reason_code']);
    }

    public function test_both_invalid_timestamps_are_rejected(): void
    {
        $verifier = new AtlasMaestroPacketProvenanceVerifier();
        $packet = $this->makeValidPacket();
        $packet['generated_at'] = '???';
        $packet['now'] = '???';
        $packet['max_age_seconds'] = 3600;

        $result = $verifier->verifyPacketBinding($packet);

        $this->assertFalse($result['ok']);
        $this->assertSame('STALE_PACKET', $result['reason_code']);
    }
}
