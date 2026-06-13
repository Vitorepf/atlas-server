<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Ai\Cognition\AtlasAcosLongHorizonGateService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AtlasAcosLongHorizonGateCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'atlas.cognition.acos_long_horizon_gate.enabled' => true,
            'atlas.cognition.acos_long_horizon_gate.schedule_enabled' => true,
            'atlas.cognition.acos_long_horizon_gate.schedule_time' => '06:55',
            'atlas.cognition.acos_long_horizon_gate.min_days' => 30,
            'atlas.cognition.acos_long_horizon_gate.min_overall' => 9.5,
            'atlas.cognition.acos_long_horizon_gate.min_pipeline' => 9.5,
        ]);
    }

    public function test_mature_fixture_certifies_only_when_score_and_30_day_window_exist(): void
    {
        $payload = app(AtlasAcosLongHorizonGateService::class)->evaluate([
            'fixture' => 'mature',
        ]);

        $this->assertSame('acos_long_horizon_ready', $payload['status']);
        $this->assertTrue($payload['certified']);
        $this->assertTrue($payload['completion_claim_allowed']);
        $this->assertGreaterThanOrEqual(30, data_get($payload, 'assessment.series_day_count'));
        $this->assertGreaterThanOrEqual(9.5, data_get($payload, 'assessment.overall_score'));
        $this->assertGreaterThanOrEqual(9.5, data_get($payload, 'assessment.pipeline_score'));
        $this->assertSame([], $payload['blockers']);
    }

    public function test_short_window_fixture_blocks_claim_even_with_command_strict(): void
    {
        $exit = Artisan::call('atlas:cognition:acos-long-horizon-gate', [
            '--fixture' => 'short-window',
            '--json' => true,
            '--strict' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('insufficient_long_horizon_evidence', $payload['status']);
        $this->assertFalse($payload['certified']);
        $this->assertContains('scorecard_overall_below_floor', $payload['blockers']);
        $this->assertContains('pipeline_score_below_floor', $payload['blockers']);
        $this->assertContains('series_day_count_below_floor', $payload['blockers']);
        $this->assertContains('calendar_span_below_floor', $payload['blockers']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.does_not_backfill_time'));
    }

    public function test_command_writes_receipt_without_mutating_score(): void
    {
        $receipt = storage_path('framework/testing/acos-long-horizon-gate.json');
        File::delete($receipt);

        $exit = Artisan::call('atlas:cognition:acos-long-horizon-gate', [
            '--fixture' => 'short-window',
            '--receipt' => $receipt,
            '--write-receipt' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertFileExists($receipt);
        $this->assertSame($receipt, $payload['receipt_path']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.does_not_inflate_score'));
    }

    public function test_schedule_contains_daily_acos_long_horizon_gate(): void
    {
        $exit = Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('atlas:cognition:acos-long-horizon-gate --write-receipt --json', $output);
    }

    public function test_future_dated_series_is_rejected_even_with_mature_score_and_window(): void
    {
        // Adversarial: a forged 31-day window dated entirely in the FUTURE meets
        // the day-count and span floors but is NOT lived evidence. The future-date
        // guard must fire so does_not_backfill_time is mechanically enforced, not
        // merely asserted.
        $today = new \DateTimeImmutable('2026-06-13 00:00:00 UTC');
        $series = [];
        for ($i = 1; $i <= 31; $i++) {
            $date = $today->modify("+$i days")->format('Y-m-d');
            $series[] = [
                'date' => $date,
                'recorded_at' => $date.'T00:00:00+00:00',
                'metrics' => ['scorecard_overall' => 9.7],
                'sources' => ['scorecard_overall' => 'AtlasCognitionScoreCardService::build() (resolved-evidence)'],
            ];
        }

        $payload = app(AtlasAcosLongHorizonGateService::class)->evaluate([
            'fixture' => 'live',
            'now' => $today,
            // A maxed-out scorecard is injected so the ONLY thing that can block
            // the claim is the time-integrity guard under test.
            'scorecard_report' => [
                'schema_version' => 'atlas.cognition.scorecard.v3',
                'score' => [
                    'overall_out_of_10' => 9.8,
                    'dimensions' => ['pipeline' => ['score_out_of_10' => 9.8]],
                ],
                'scorecard_hash' => 'sha256:'.str_repeat('a', 64),
            ],
            'series' => $series,
        ]);

        $this->assertFalse($payload['certified']);
        $this->assertSame('insufficient_long_horizon_evidence', $payload['status']);
        $this->assertContains('delta_series_future_dated_rows', $payload['blockers']);
        $this->assertSame(31, data_get($payload, 'assessment.future_dated_rows'));
        // Day-count and span floors are satisfied — proving the future-date guard
        // is the load-bearing rejection, not a coincidental floor miss.
        $this->assertNotContains('series_day_count_below_floor', $payload['blockers']);
        $this->assertNotContains('calendar_span_below_floor', $payload['blockers']);
    }

    public function test_stale_window_that_stopped_updating_is_rejected(): void
    {
        // Adversarial: 31 real contiguous days that ENDED 90 days ago. The claim
        // requires RECENT live operation; a frozen historical block must not green.
        $today = new \DateTimeImmutable('2026-06-13 00:00:00 UTC');
        $end = $today->modify('-90 days');
        $start = $end->modify('-30 days');
        $series = [];
        for ($i = 0; $i <= 30; $i++) {
            $date = $start->modify("+$i days")->format('Y-m-d');
            $series[] = [
                'date' => $date,
                'recorded_at' => $date.'T00:00:00+00:00',
                'metrics' => ['scorecard_overall' => 9.7],
                'sources' => ['scorecard_overall' => 'AtlasCognitionScoreCardService::build() (resolved-evidence)'],
            ];
        }

        $payload = app(AtlasAcosLongHorizonGateService::class)->evaluate([
            'fixture' => 'live',
            'now' => $today,
            'scorecard_report' => [
                'schema_version' => 'atlas.cognition.scorecard.v3',
                'score' => [
                    'overall_out_of_10' => 9.8,
                    'dimensions' => ['pipeline' => ['score_out_of_10' => 9.8]],
                ],
                'scorecard_hash' => 'sha256:'.str_repeat('b', 64),
            ],
            'series' => $series,
        ]);

        $this->assertFalse($payload['certified']);
        $this->assertContains('delta_series_window_stale', $payload['blockers']);
        $this->assertSame(90, data_get($payload, 'assessment.latest_staleness_days'));
        $this->assertSame(31, data_get($payload, 'assessment.series_day_count'));
        $this->assertNotContains('series_day_count_below_floor', $payload['blockers']);
    }

    public function test_fresh_real_window_with_high_score_auto_greens(): void
    {
        // The auto-green proof: a real, fresh, non-future 31-day window ending on
        // "today" with a 9.5+ scorecard certifies. This is exactly the shape the
        // live delta-series will have after 30 days of real operation — the gate
        // refuses to fabricate it but mints honestly the instant it exists.
        $today = new \DateTimeImmutable('2026-06-13 00:00:00 UTC');
        $series = [];
        for ($i = 30; $i >= 0; $i--) {
            $date = $today->modify("-$i days")->format('Y-m-d');
            $series[] = [
                'date' => $date,
                'recorded_at' => $date.'T00:00:00+00:00',
                'metrics' => ['scorecard_overall' => 9.6],
                'sources' => ['scorecard_overall' => 'AtlasCognitionScoreCardService::build() (resolved-evidence)'],
            ];
        }

        $payload = app(AtlasAcosLongHorizonGateService::class)->evaluate([
            'fixture' => 'live',
            'now' => $today,
            'scorecard_report' => [
                'schema_version' => 'atlas.cognition.scorecard.v3',
                'score' => [
                    'overall_out_of_10' => 9.6,
                    'dimensions' => ['pipeline' => ['score_out_of_10' => 9.55]],
                ],
                'scorecard_hash' => 'sha256:'.str_repeat('c', 64),
            ],
            'series' => $series,
        ]);

        $this->assertTrue($payload['certified']);
        $this->assertSame('acos_long_horizon_ready', $payload['status']);
        $this->assertSame([], $payload['blockers']);
        $this->assertSame(0, data_get($payload, 'assessment.future_dated_rows'));
        $this->assertSame(0, data_get($payload, 'assessment.latest_staleness_days'));
    }
}
