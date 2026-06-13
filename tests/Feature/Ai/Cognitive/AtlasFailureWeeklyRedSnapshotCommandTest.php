<?php

namespace Tests\Feature\Ai\Cognitive;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtlasFailureWeeklyRedSnapshotCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_06_13_090000_create_atlas_suite_red_snapshots_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Schema::dropIfExists('atlas_suite_red_snapshots');

        parent::tearDown();
    }

    public function test_command_is_disabled_by_default_and_records_nothing(): void
    {
        config()->set('atlas.ai.suite_red_snapshot.schedule_enabled', false);

        Artisan::call('atlas:failure:weekly-red-snapshot', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('disabled', $payload['status']);
        $this->assertFalse((bool) $payload['recorded']);
        $this->assertSame('ATLAS_SUITE_RED_SNAPSHOT_SCHEDULE_ENABLED', $payload['flag']);
    }

    public function test_force_run_with_missing_report_records_nothing_and_does_not_fabricate(): void
    {
        Artisan::call('atlas:failure:weekly-red-snapshot', [
            '--force' => true,
            '--domain' => 'programming',
            '--test-report' => storage_path('framework/testing/does-not-exist-'.Str::uuid().'.json'),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('no_real_report', $payload['status']);
        $this->assertFalse((bool) $payload['recorded']);
        $this->assertSame('blocked', $payload['reason']);
    }

    public function test_force_run_records_real_snapshot_and_is_idempotent_per_iso_week(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 09:00:00')); // ISO 2026-W24

        $reportTwo = $this->writeReport([
            'tests' => [
                ['name' => 'Tests\\Unit\\AlphaTest', 'status' => 'failed', 'message' => 'Failed asserting that false is true.'],
                ['name' => 'Tests\\Unit\\BetaTest', 'status' => 'failed', 'message' => 'Connection refused'],
                ['name' => 'Tests\\Unit\\GreenTest', 'status' => 'passed', 'message' => ''],
            ],
        ]);

        try {
            Artisan::call('atlas:failure:weekly-red-snapshot', [
                '--force' => true,
                '--domain' => 'programming',
                '--test-report' => $reportTwo,
                '--json' => true,
            ]);
            $first = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame('ok', $first['status']);
            $this->assertTrue((bool) $first['recorded']);
            $this->assertSame('inserted', data_get($first, 'snapshot.action'));
            $this->assertSame('2026-W24', data_get($first, 'snapshot.iso_week'));
            $this->assertSame(2, data_get($first, 'snapshot.failed_count'));
            // Red-lot triage surfaced (1 real + 1 environmental).
            $this->assertSame(1, data_get($first, 'red_lot_triage.real_failure'));
            $this->assertSame(1, data_get($first, 'red_lot_triage.environmental'));
            $this->assertTrue((bool) data_get($first, 'rules.measures_only_never_corrects_or_quarantines'));

            // Re-run SAME week with a lower count → UPDATE, never a second fake point.
            $reportLower = $this->writeReport([
                'tests' => [
                    ['name' => 'Tests\\Unit\\AlphaTest', 'status' => 'failed', 'message' => 'Failed asserting that false is true.'],
                ],
            ]);

            Artisan::call('atlas:failure:weekly-red-snapshot', [
                '--force' => true,
                '--domain' => 'programming',
                '--test-report' => $reportLower,
                '--json' => true,
            ]);
            $second = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame('updated', data_get($second, 'snapshot.action'));
            $this->assertSame(1, data_get($second, 'snapshot.failed_count'));
            $this->assertSame(1, data_get($second, 'snapshot.distinct_weeks_recorded'));
            // One real week only → trend still insufficient (no fabricated decrease).
            $this->assertSame('insufficient_real_history', data_get($second, 'real_weekly_red_trend.status'));
            $this->assertFalse((bool) data_get($second, 'real_weekly_red_trend.weekly_reds_decreasing'));

            @File::delete($reportLower);
        } finally {
            @File::delete($reportTwo);
        }
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function writeReport(array $report): string
    {
        $path = storage_path('framework/testing/weekly-red-'.Str::uuid().'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }
}
