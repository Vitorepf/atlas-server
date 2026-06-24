<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Telemetry\RollingWindows;

use App\Services\Ai\AutonomousEvolution\Telemetry\RollingWindows\AtlasLoopRollingWindowReceiptLedger;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class AtlasLoopRollingWindowReceiptLedgerTest extends TestCase
{
    #[Test]
    public function it_is_idempotent_for_identical_snapshot_writes(): void
    {
        $base = $this->basePath();
        $ledger = new AtlasLoopRollingWindowReceiptLedger($base, fn (): string => '2026-06-24T14:00:00+00:00');
        $snapshot = $this->snapshot(['claim' => 2]);

        $first = $ledger->recordSnapshot($snapshot);
        clearstatcache();
        $path = $base.'/1h/2026-06-24T13:00:00+00:00.json';
        $inode = fileinode($path);
        $second = $ledger->recordSnapshot($snapshot);

        self::assertSame($first['payload_sha256'], $second['payload_sha256']);
        self::assertSame($first['recorded_at_iso'], $second['recorded_at_iso']);
        self::assertCount(1, glob($base.'/1h/*.json') ?: []);
        self::assertSame($inode, fileinode($path));
    }

    #[Test]
    public function it_throws_and_preserves_the_existing_receipt_when_the_payload_differs(): void
    {
        $base = $this->basePath();
        $ledger = new AtlasLoopRollingWindowReceiptLedger($base, fn (): string => '2026-06-24T14:00:00+00:00');
        $path = $base.'/1h/2026-06-24T13:00:00+00:00.json';
        $ledger->recordSnapshot($this->snapshot(['claim' => 2]));
        $beforeContent = file_get_contents($path);
        $beforeMtime = filemtime($path);

        $this->expectException(RuntimeException::class);

        try {
            $ledger->recordSnapshot($this->snapshot(['claim' => 3]));
        } finally {
            clearstatcache();
            self::assertSame($beforeContent, file_get_contents($path));
            self::assertSame($beforeMtime, filemtime($path));
        }
    }

    #[Test]
    public function it_includes_payload_hash_schema_version_recorded_at_and_lists_in_chronological_order(): void
    {
        $base = $this->basePath();
        $ledger = new AtlasLoopRollingWindowReceiptLedger($base, fn (): string => '2026-06-24T14:00:00+00:00');
        $first = $ledger->recordSnapshot($this->snapshot(['claim' => 2], '2026-06-24T13:00:00+00:00', '2026-06-24T14:00:00+00:00'));
        $ledger->recordSnapshot($this->snapshot(['claim' => 1], '2026-06-24T14:00:00+00:00', '2026-06-24T15:00:00+00:00'));

        $listed = $ledger->list('1h', '2026-06-24T12:59:59+00:00');
        $stored = json_decode((string) file_get_contents($base.'/1h/2026-06-24T13:00:00+00:00.json'), true);

        self::assertSame($stored['payload_sha256'], $first['payload_sha256']);
        self::assertSame(1, $first['schema_version']);
        self::assertSame('2026-06-24T14:00:00+00:00', $first['recorded_at_iso']);
        self::assertSame([
            '2026-06-24T13:00:00+00:00',
            '2026-06-24T14:00:00+00:00',
        ], array_column($listed, 'window_start_iso'));
    }

    #[Test]
    public function it_rejects_path_traversal_window_labels(): void
    {
        $ledger = new AtlasLoopRollingWindowReceiptLedger($this->basePath(), fn (): string => '2026-06-24T14:00:00+00:00');

        $this->expectException(RuntimeException::class);

        $ledger->recordSnapshot($this->snapshot(['claim' => 2], '2026-06-24T13:00:00+00:00', '2026-06-24T14:00:00+00:00', '..'));
    }

    /**
     * @param  array<string,int>  $counts
     * @return array<string,mixed>
     */
    private function snapshot(
        array $counts,
        string $startIso = '2026-06-24T13:00:00+00:00',
        string $endIso = '2026-06-24T14:00:00+00:00',
        string $label = '1h',
    ): array {
        return [
            'window_label' => $label,
            'window_start_iso' => $startIso,
            'window_end_iso' => $endIso,
            'counts' => $counts,
            'durations_ms' => [],
            'cost_sum_micros' => 0,
            'anomaly_count' => 0,
        ];
    }

    private function basePath(): string
    {
        $path = sys_get_temp_dir().'/atlas-loop-rolling-window-ledger-'.bin2hex(random_bytes(8));
        mkdir($path, 0777, true);

        return $path;
    }
}
