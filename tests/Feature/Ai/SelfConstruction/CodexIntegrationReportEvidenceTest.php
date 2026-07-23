<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CodexIntegrationReportEvidenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory(storage_path('app/atlas/self-construction/reservations'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/atlas/self-construction/reservations'));

        parent::tearDown();
    }

    public function test_completed_packet_with_malformed_evidence_is_not_ready_for_review(): void
    {
        $packetId = 'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001';

        $this->assertSame(0, Artisan::call('atlas:ai:self-construction', [
            '--claim-packet' => true,
            '--packet' => $packetId,
            '--actor' => 'codex-evidence',
            '--session' => 'evidence-session',
            '--json' => true,
        ]));
        $this->assertSame(0, Artisan::call('atlas:ai:self-construction', [
            '--complete-packet' => true,
            '--packet' => $packetId,
            '--actor' => 'codex-evidence',
            '--session' => 'evidence-session',
            '--reason' => 'packet_scope_finished',
            '--evidence-hash' => 'not-a-sha256-receipt',
            '--json' => true,
        ]));
        $this->assertSame(0, Artisan::call('atlas:ai:self-construction', [
            '--codex-integration-report' => true,
            '--json' => true,
        ]));

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, data_get($payload, 'report.counts.ready_to_review'));
        $this->assertSame(5, data_get($payload, 'report.counts.missing_packets'));
        $invalidPackets = array_values(array_filter(
            data_get($payload, 'report.missing_packets', []),
            static fn (array $packet): bool => data_get($packet, 'packet_id') === $packetId,
        ));

        $this->assertSame(
            'completion_evidence_hash_invalid',
            data_get($invalidPackets, '0.evidence_status')
        );
    }
}
