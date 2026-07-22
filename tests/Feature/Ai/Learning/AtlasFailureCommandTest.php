<?php

namespace Tests\Feature\Ai\Cognitive;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtlasFailureCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_07_160000_create_failure_signatures_table.php'))->up();
        (require database_path('migrations/2026_06_13_090000_create_atlas_suite_red_snapshots_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Schema::dropIfExists('atlas_suite_red_snapshots');
        Schema::dropIfExists('failure_repetition_alerts');
        Schema::dropIfExists('failure_diversity_metrics');
        Schema::dropIfExists('failure_signatures');
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_failure_command_records_lists_computes_diversity_and_acknowledges_alert(): void
    {
        for ($i = 0; $i < 3; $i++) {
            Artisan::call('atlas:failure', [
                'action' => 'record',
                'subject' => 'runtime failed while executing provider',
                '--domain' => 'programming',
                '--json' => true,
            ]);
        }

        $recorded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('recorded', $recorded['status']);
        $this->assertSame('triggered', data_get($recorded, 'alert.status'));
        $this->assertSame(3, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::FailureSignatureRecorded->value)->count());
        $this->assertSame(1, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::FailureRepetitionAlert->value)->count());

        Artisan::call('atlas:failure', ['action' => 'recent', '--domain' => 'programming', '--json' => true]);
        $recent = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(3, $recent['signatures']);

        Artisan::call('atlas:failure', ['action' => 'diversity', '--domain' => 'programming', '--json' => true]);
        $diversity = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('computed', $diversity['status']);
        $this->assertSame(1, $diversity['unique_signatures']);

        Artisan::call('atlas:failure', ['action' => 'alerts', '--json' => true]);
        $alerts = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(1, $alerts['alerts']);

        Artisan::call('atlas:failure', [
            'action' => 'ack',
            'subject' => (string) $alerts['alerts'][0]['id'],
            '--reflection' => 'Vou revisar o runtime antes de repetir.',
            '--json' => true,
        ]);
        $ack = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('acknowledged', data_get($ack, 'alert.alert_status'));
    }

    public function test_failure_review_triages_red_lot_and_supplied_history_is_display_only_and_never_unlocks_gate(): void
    {
        $path = storage_path('framework/testing/failure-review-tests-'.Str::uuid().'.json');
        File::ensureDirectoryExists(dirname($path));
        // Synthetic decreasing history is supplied IN the report — it must NOT unlock the gate.
        File::put($path, json_encode([
            'tests' => [
                [
                    'name' => 'Tests\\Feature\\ExternalDatabaseTest',
                    'status' => 'failed',
                    'message' => 'SQLSTATE[HY000] [2002] Connection refused while connecting to mysql',
                ],
                [
                    'name' => 'Tests\\Unit\\ContractTest',
                    'status' => 'failed',
                    'message' => 'Failed asserting that false is true.',
                ],
                [
                    'name' => 'Tests\\Unit\\GreenTest',
                    'status' => 'passed',
                    'message' => '',
                ],
            ],
            // Forged decreasing trend — the gate must IGNORE this.
            'history' => [
                ['week' => '2026-W23', 'failed' => 99],
                ['week' => '2026-W24', 'failed' => 0],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        try {
            Artisan::call('atlas:failure', [
                'action' => 'review',
                '--domain' => 'programming',
                '--test-report' => $path,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame('triage_ready', $payload['status']);
            $this->assertSame('triaged', data_get($payload, 'test_suite_triage.status'));
            $this->assertSame(3, data_get($payload, 'test_suite_triage.total_tests_seen'));
            $this->assertSame(2, data_get($payload, 'test_suite_triage.failed_tests_seen'));

            // Red-lot triage helper classifies environmental vs real.
            $this->assertSame(1, data_get($payload, 'test_suite_triage.red_lot_triage.environmental'));
            $this->assertSame(1, data_get($payload, 'test_suite_triage.red_lot_triage.real_failure'));

            // Supplied history is display-only and explicitly flagged so.
            $this->assertTrue((bool) data_get($payload, 'test_suite_triage.supplied_history_is_display_only'));
            $this->assertSame('decreasing', data_get($payload, 'test_suite_triage.supplied_history_trend.status'));

            // The GATE reads the REAL persisted snapshots — none recorded → insufficient.
            $this->assertSame('real_persisted_snapshots', data_get($payload, 'claim_policy.trend_source'));
            $this->assertSame(0, data_get($payload, 'claim_policy.real_weekly_snapshot_count'));
            $this->assertFalse((bool) data_get($payload, 'claim_policy.l5_3_completion_claim_allowed'));
            $this->assertContains('insufficient_real_weekly_snapshots', data_get($payload, 'claim_policy.blockers'));
            $this->assertContains('real_failures_remain_fix_forward_required', data_get($payload, 'claim_policy.blockers'));
            $this->assertTrue((bool) data_get($payload, 'claim_policy.synthetic_history_cannot_pass_gate'));
            $this->assertFalse((bool) data_get($payload, 'test_suite_triage.claim_policy.auto_corrects_tests'));
            $this->assertFalse((bool) data_get($payload, 'test_suite_triage.claim_policy.auto_quarantines_tests'));
            $this->assertTrue((bool) data_get($payload, 'rules.environmental_quarantine_requires_operator_review'));
            $this->assertTrue((bool) data_get($payload, 'rules.gate_trend_source_is_real_persisted_snapshots'));
        } finally {
            @File::delete($path);
        }
    }

    public function test_gate_unlocks_only_after_two_real_decreasing_recorded_snapshots(): void
    {
        // Week 1: a clean report with a real failed count of 2 → recorded snapshot.
        $weekOnePath = $this->writeReport([
            'tests' => [
                ['name' => 'Tests\\Unit\\AlphaTest', 'status' => 'failed', 'message' => 'Failed asserting that false is true.'],
                ['name' => 'Tests\\Unit\\BetaTest', 'status' => 'failed', 'message' => 'Failed asserting that 1 matches expected 2.'],
                ['name' => 'Tests\\Unit\\GreenTest', 'status' => 'passed', 'message' => ''],
            ],
        ]);

        $reportPath = storage_path('framework/testing/failure-review-receipt-'.Str::uuid().'.json');

        try {
            // --- WEEK 1: record snapshot (failed=2). Gate stays RED: only 1 real snapshot. ---
            Carbon::setTestNow(Carbon::parse('2026-06-08 09:00:00')); // ISO 2026-W24
            Artisan::call('atlas:failure', [
                'action' => 'review',
                '--domain' => 'programming',
                '--test-report' => $weekOnePath,
                '--record-snapshot' => true,
                '--json' => true,
            ]);
            $week1 = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertTrue((bool) data_get($week1, 'snapshot_record.recorded'));
            $this->assertSame('2026-W24', data_get($week1, 'snapshot_record.iso_week'));
            $this->assertSame(2, data_get($week1, 'snapshot_record.failed_count'));
            $this->assertSame(1, data_get($week1, 'claim_policy.real_weekly_snapshot_count'));
            $this->assertFalse((bool) data_get($week1, 'claim_policy.l5_3_completion_claim_allowed'));
            $this->assertContains('insufficient_real_weekly_snapshots', data_get($week1, 'claim_policy.blockers'));

            // --- WEEK 2: a real all-green report (failed=0). Two REAL decreasing weeks now. ---
            $weekTwoPath = $this->writeReport([
                'tests' => [
                    ['name' => 'Tests\\Unit\\AlphaTest', 'status' => 'passed', 'message' => ''],
                    ['name' => 'Tests\\Unit\\BetaTest', 'status' => 'passed', 'message' => ''],
                ],
            ]);

            Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00')); // ISO 2026-W25
            Artisan::call('atlas:failure', [
                'action' => 'review',
                '--domain' => 'programming',
                '--test-report' => $weekTwoPath,
                '--record-snapshot' => true,
                '--write-report' => true,
                '--report-path' => $reportPath,
                '--json' => true,
            ]);
            $week2 = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame('2026-W25', data_get($week2, 'snapshot_record.iso_week'));
            $this->assertSame(0, data_get($week2, 'snapshot_record.failed_count'));

            // GATE GREEN — and ONLY because two REAL recorded weeks strictly decreased (2 → 0).
            $this->assertSame('suite_healing_trend_proven', $week2['status']);
            $this->assertSame(2, data_get($week2, 'claim_policy.real_weekly_snapshot_count'));
            $this->assertSame('real_persisted_snapshots', data_get($week2, 'real_weekly_red_trend.source'));
            $this->assertTrue((bool) data_get($week2, 'real_weekly_red_trend.weekly_reds_decreasing'));
            $this->assertSame(2, data_get($week2, 'real_weekly_red_trend.first_failed'));
            $this->assertSame(0, data_get($week2, 'real_weekly_red_trend.last_failed'));
            $this->assertTrue((bool) data_get($week2, 'claim_policy.l5_3_completion_claim_allowed'));
            $this->assertSame([], data_get($week2, 'claim_policy.blockers'));

            // Receipt persisted with the real-trend claim.
            $this->assertFileExists($reportPath);
            $written = json_decode((string) File::get($reportPath), true, flags: JSON_THROW_ON_ERROR);
            $this->assertTrue((bool) data_get($written, 'claim_policy.l5_3_completion_claim_allowed'));
            $this->assertSame('real_persisted_snapshots', data_get($written, 'claim_policy.trend_source'));

            @File::delete($weekTwoPath);
        } finally {
            @File::delete($weekOnePath);
            @File::delete($reportPath);
        }
    }

    public function test_gate_stays_red_when_two_real_snapshots_do_not_decrease(): void
    {
        $reportA = $this->writeReport([
            'tests' => [
                ['name' => 'Tests\\Unit\\AlphaTest', 'status' => 'failed', 'message' => 'Failed asserting that false is true.'],
            ],
        ]);
        $reportB = $this->writeReport([
            'tests' => [
                ['name' => 'Tests\\Unit\\AlphaTest', 'status' => 'failed', 'message' => 'Failed asserting that false is true.'],
                ['name' => 'Tests\\Unit\\BetaTest', 'status' => 'failed', 'message' => 'Failed asserting that 1 matches expected 2.'],
            ],
        ]);

        try {
            // Week A: 1 real red.
            Carbon::setTestNow(Carbon::parse('2026-06-08 09:00:00'));
            Artisan::call('atlas:failure', [
                'action' => 'review', '--domain' => 'programming',
                '--test-report' => $reportA, '--record-snapshot' => true, '--json' => true,
            ]);
            json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            // Week B: reds went UP (1 → 2). Two real snapshots, but increasing.
            Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));
            Artisan::call('atlas:failure', [
                'action' => 'review', '--domain' => 'programming',
                '--test-report' => $reportB, '--record-snapshot' => true, '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(2, data_get($payload, 'claim_policy.real_weekly_snapshot_count'));
            $this->assertSame('increasing', data_get($payload, 'real_weekly_red_trend.status'));
            $this->assertFalse((bool) data_get($payload, 'claim_policy.l5_3_completion_claim_allowed'));
            $this->assertContains('real_weekly_red_trend_not_decreasing', data_get($payload, 'claim_policy.blockers'));
            // Real failures still present too.
            $this->assertContains('real_failures_remain_fix_forward_required', data_get($payload, 'claim_policy.blockers'));
        } finally {
            @File::delete($reportA);
            @File::delete($reportB);
        }
    }

    public function test_recording_twice_in_same_week_updates_not_appends_a_fake_point(): void
    {
        $reportHigh = $this->writeReport([
            'tests' => [
                ['name' => 'Tests\\Unit\\AlphaTest', 'status' => 'failed', 'message' => 'Failed asserting that false is true.'],
                ['name' => 'Tests\\Unit\\BetaTest', 'status' => 'failed', 'message' => 'Failed asserting that false is true.'],
            ],
        ]);
        $reportLow = $this->writeReport([
            'tests' => [
                ['name' => 'Tests\\Unit\\AlphaTest', 'status' => 'failed', 'message' => 'Failed asserting that false is true.'],
            ],
        ]);

        try {
            // Two records in the SAME ISO week must not mint two decreasing points.
            Carbon::setTestNow(Carbon::parse('2026-06-08 09:00:00'));
            Artisan::call('atlas:failure', [
                'action' => 'red-triage', '--domain' => 'programming',
                '--test-report' => $reportHigh, '--record-snapshot' => true, '--json' => true,
            ]);
            json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            Carbon::setTestNow(Carbon::parse('2026-06-08 18:00:00')); // same ISO week
            Artisan::call('atlas:failure', [
                'action' => 'red-triage', '--domain' => 'programming',
                '--test-report' => $reportLow, '--record-snapshot' => true, '--json' => true,
            ]);
            $second = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame('updated', data_get($second, 'snapshot_record.action'));
            $this->assertSame(1, data_get($second, 'snapshot_record.distinct_weeks_recorded'));
            // Only one real week → still insufficient. No fake decreasing trend minted.
            $this->assertSame('insufficient_real_history', data_get($second, 'real_weekly_red_trend.status'));
            $this->assertFalse((bool) data_get($second, 'real_weekly_red_trend.weekly_reds_decreasing'));
            $this->assertSame(1, data_get($second, 'real_weekly_red_trend.real_snapshot_count'));
        } finally {
            @File::delete($reportHigh);
            @File::delete($reportLow);
        }
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function writeReport(array $report): string
    {
        $path = storage_path('framework/testing/failure-review-'.Str::uuid().'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }
}
