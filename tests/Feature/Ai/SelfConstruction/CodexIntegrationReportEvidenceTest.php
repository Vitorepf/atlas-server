<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionReservationRepository;
use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
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

    public function test_report_fails_closed_when_the_queue_changes_during_its_read_snapshot(): void
    {
        $packetId = 'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001';
        $completedProjection = [
            $packetId => [
                'reservation_id' => 'RES-CODEX-INTEGRATION-SNAPSHOT-DRIFT',
                'packet_id' => $packetId,
                'actor' => 'snapshot-drift',
                'session' => 'snapshot-drift-session',
                'state' => 'completed',
                'allowed_files' => [],
                'completed_at' => '2026-07-23T12:00:00+00:00',
            ],
        ];
        CodexIntegrationReportSnapshotDriftStreamWrapper::configure($completedProjection);
        $this->assertTrue(stream_wrapper_register(
            'codex-integration-snapshot-drift',
            CodexIntegrationReportSnapshotDriftStreamWrapper::class,
        ));

        try {
            $payload = (new AtlasSelfConstructionReadinessService(
                new AtlasSelfConstructionReservationRepository('codex-integration-snapshot-drift://ledger'),
            ))->codexIntegrationReport();
        } finally {
            stream_wrapper_unregister('codex-integration-snapshot-drift');
            CodexIntegrationReportSnapshotDriftStreamWrapper::reset();
        }

        $this->assertSame('codex_integration_report_snapshot_changed', data_get($payload, 'status'));
        $this->assertSame('source_snapshot_changed', data_get($payload, 'report.snapshot_consistency.status'));
        $this->assertFalse(data_get($payload, 'report.snapshot_consistency.actionable'));
        $this->assertSame([], data_get($payload, 'report.ready_to_review_packets'));
    }
}

final class CodexIntegrationReportSnapshotDriftStreamWrapper
{
    public mixed $context = null;

    private static string $completedProjection = '[]';

    private static int $projectionReadCount = 0;

    private string $contents = '';

    private int $position = 0;

    /** @param array<string, array<string, mixed>> $completedProjection */
    public static function configure(array $completedProjection): void
    {
        self::$completedProjection = json_encode($completedProjection, JSON_THROW_ON_ERROR);
        self::$projectionReadCount = 0;
    }

    public static function reset(): void
    {
        self::$completedProjection = '[]';
        self::$projectionReadCount = 0;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->contents = str_ends_with($path, '/projection.json') && self::$projectionReadCount++ < 2
            ? self::$completedProjection
            : '';
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->contents, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->contents);
    }

    /** @return array<int|string, int> */
    public function stream_stat(): array
    {
        return self::statFor(strlen($this->contents));
    }

    /** @return array<int|string, int> */
    public function url_stat(string $path, int $flags): array
    {
        return self::statFor(
            str_ends_with($path, 'ledger') ? 0 : strlen(self::$completedProjection),
            str_ends_with($path, 'ledger'),
        );
    }

    public function stream_lock(int $operation): bool
    {
        return true;
    }

    /** @return array<int|string, int> */
    private static function statFor(int $size, bool $directory = false): array
    {
        $mode = $directory ? 0040755 : 0100644;

        return [
            0 => 0,
            1 => 0,
            2 => $mode,
            3 => 1,
            4 => 0,
            5 => 0,
            6 => 0,
            7 => $size,
            8 => 0,
            9 => 0,
            10 => 0,
            11 => -1,
            12 => -1,
            'dev' => 0,
            'ino' => 0,
            'mode' => $mode,
            'nlink' => 1,
            'uid' => 0,
            'gid' => 0,
            'rdev' => 0,
            'size' => $size,
            'atime' => 0,
            'mtime' => 0,
            'ctime' => 0,
            'blksize' => -1,
            'blocks' => -1,
        ];
    }
}
