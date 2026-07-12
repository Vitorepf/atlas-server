<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AcosMax;

use App\Console\Commands\AtlasAcosFreezeCommand;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Cognition\Watchdog\Checks\AobgLatencyWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\OpenBrain\AtlasAobgLatencyLedger;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class Maxg01LatencyLedgerTest extends TestCase
{
    private string $ledgerRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledgerRoot = storage_path('framework/testing/maxg01-latency-'.bin2hex(random_bytes(4)));
        $this->deleteDirectory($this->ledgerRoot);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        $this->deleteDirectory($this->ledgerRoot);

        parent::tearDown();
    }

    public function test_latency_append_never_persists_raw_query_text(): void
    {
        $ledger = new AtlasAobgLatencyLedger($this->ledgerRoot);
        $rawQuery = 'secret raw operator query must not be written';

        $ledger->recordPack(123.456, [
            'task' => $rawQuery,
            'markdown' => $rawQuery,
            'counts' => ['code_graph' => 2, 'memory' => 1, 'reality_graph_paths' => 1],
            'budget' => ['total_chars' => 6000],
        ]);

        $contents = (string) file_get_contents($ledger->pathForDay(gmdate('Y-m-d')));
        $row = json_decode(trim($contents), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(['op', 'ms', 'refs', 'budget_chars', 'ts'], array_keys($row));
        self::assertSame('pack', $row['op']);
        self::assertSame(4, $row['refs']);
        self::assertSame(6000, $row['budget_chars']);
        self::assertStringNotContainsString($rawQuery, $contents);
    }

    public function test_latency_report_uses_linear_interpolated_percentiles(): void
    {
        $ledger = new AtlasAobgLatencyLedger($this->ledgerRoot);
        $day = '2026-07-11';

        foreach ([10.0, 20.0, 30.0, 40.0] as $ms) {
            $ledger->record('pack', $ms, refs: 1, budgetChars: 1000, ts: $day.'T12:00:00+00:00');
        }

        $stats = $ledger->report(day: $day)['days'][$day]['ops']['pack'];

        self::assertSame(4, $stats['samples']);
        self::assertSame(25.0, $stats['p50_ms']);
        self::assertSame(38.5, $stats['p95_ms']);
    }

    public function test_empty_latency_ledger_is_insufficient_signal_and_does_not_fake_p95(): void
    {
        $ledger = new AtlasAobgLatencyLedger($this->ledgerRoot);
        $day = '2026-07-11';

        self::assertNull(data_get($ledger->report(day: $day), 'days.'.$day.'.ops.pack.p95_ms'));

        $check = new AobgLatencyWatchdogCheck(
            $ledger,
            null,
            AtlasAcosFreezeCommand::defaultFreezePayload(),
        );

        $result = $check->run();

        self::assertSame(AtlasWatchdogCheckResult::STATUS_SKIPPED, $result->status);
        self::assertSame('insufficient_signal', $result->evidence['reason']);
    }

    public function test_freeze_command_rejects_same_author_and_judge_engine(): void
    {
        $this->migrateLedger();

        $payload = AtlasAcosFreezeCommand::defaultFreezePayload();
        $payload['judge_engine_id'] = $payload['author_engine_id'];

        $this->artisan('atlas:acos:freeze', [
            '--json' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertExitCode(1);

        self::assertSame(
            0,
            AtlasLedgerEvent::query()
                ->where('event_type', LedgerEventType::UnitFrozen->value)
                ->where('payload->kind', 'measure_freeze')
                ->count(),
        );
    }

    private function migrateLedger(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_19_050000_extend_atlas_ledger_events_with_timeline_fields.php'))->up();
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
