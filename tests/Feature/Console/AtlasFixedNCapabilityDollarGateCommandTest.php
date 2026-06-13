<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Ai\Compounding\FixedNCapabilityDollarSeriesGateService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AtlasFixedNCapabilityDollarGateCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'atlas.compounding.fixed_n_capability_dollar_gate.enabled' => true,
            'atlas.compounding.fixed_n_capability_dollar_gate.schedule_enabled' => true,
            'atlas.compounding.fixed_n_capability_dollar_gate.schedule_time' => '07:35',
            'atlas.compounding.fixed_n_capability_dollar_gate.fixed_provider' => 'codex',
            'atlas.compounding.fixed_n_capability_dollar_gate.fixed_model' => 'gpt-5.5',
            'atlas.compounding.fixed_n_capability_dollar_gate.min_days' => 30,
            'atlas.compounding.fixed_n_capability_dollar_gate.min_cost_coverage_pct' => 80,
            'atlas.compounding.fixed_n_capability_dollar_gate.min_measured_cost_days' => 30,
            'atlas.compounding.fixed_n_capability_dollar_gate.min_positive_trend_delta' => 0.0001,
        ]);
    }

    public function test_mature_fixture_certifies_fixed_n_positive_capability_per_dollar_trend(): void
    {
        $payload = app(FixedNCapabilityDollarSeriesGateService::class)->evaluate([
            'fixture' => 'mature',
        ]);

        $this->assertSame('fixed_n_capability_per_dollar_ready', $payload['status']);
        $this->assertTrue($payload['certified']);
        $this->assertTrue($payload['completion_claim_allowed']);
        $this->assertSame([], $payload['blockers']);
        $this->assertGreaterThanOrEqual(30, data_get($payload, 'assessment.series_day_count'));
        $this->assertSame(1, data_get($payload, 'assessment.fixed_provider_model_count'));
        $this->assertSame('up', data_get($payload, 'assessment.trend_direction'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.external_provider_capability_estimated'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.price_inference_performed'));
    }

    public function test_short_window_fixture_blocks_strict_claim(): void
    {
        $exit = Artisan::call('atlas:compounding:fixed-n-capability-dollar-gate', [
            '--fixture' => 'short-window',
            '--strict' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('insufficient_fixed_n_capability_per_dollar_evidence', $payload['status']);
        $this->assertContains('series_day_count_below_floor', $payload['blockers']);
        $this->assertContains('calendar_span_below_floor', $payload['blockers']);
        $this->assertContains('measured_cost_day_count_below_floor', $payload['blockers']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.does_not_backfill_time'));
    }

    public function test_flat_trend_blocks_even_with_fixed_n_and_measured_cost(): void
    {
        $payload = app(FixedNCapabilityDollarSeriesGateService::class)->evaluate([
            'fixture' => 'flat-trend',
        ]);

        $this->assertFalse($payload['certified']);
        $this->assertContains('capability_per_dollar_trend_not_positive', $payload['blockers']);
        $this->assertSame('flat', data_get($payload, 'assessment.trend_direction'));
    }

    public function test_missing_cost_blocks_even_with_monthly_window(): void
    {
        $payload = app(FixedNCapabilityDollarSeriesGateService::class)->evaluate([
            'fixture' => 'missing-cost',
        ]);

        $this->assertFalse($payload['certified']);
        $this->assertContains('measured_cost_day_count_below_floor', $payload['blockers']);
        $this->assertContains('capability_per_dollar_missing', $payload['blockers']);
    }

    public function test_command_writes_receipt_without_minting_prices(): void
    {
        $receipt = storage_path('framework/testing/fixed-n-capability-dollar-gate.json');
        File::delete($receipt);

        $exit = Artisan::call('atlas:compounding:fixed-n-capability-dollar-gate', [
            '--fixture' => 'mature',
            '--receipt' => $receipt,
            '--write-receipt' => true,
            '--json' => true,
            '--strict' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertFileExists($receipt);
        $this->assertSame($receipt, $payload['receipt_path']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.provider_calls_made'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.price_inference_performed'));
    }

    public function test_schedule_contains_daily_fixed_n_gate(): void
    {
        $exit = Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('atlas:compounding:fixed-n-capability-dollar-gate --write-snapshot --write-receipt --json', $output);
    }

    /**
     * Load-bearing auto-green proof: the gate certifies a REAL on-disk monthly
     * series read through the production `--series` JSONL path (not the synthetic
     * fixture branch). This is the exact mechanism the daily schedule feeds via
     * --write-snapshot, so it proves the gate auto-greens the instant a real,
     * fixed-N, cost-measured, positively-trending month exists — no mint, no
     * fabricated time, no provider call.
     */
    public function test_live_series_path_auto_greens_on_real_monthly_evidence(): void
    {
        $path = $this->writeRealSeries(31, 'up');

        $exit = Artisan::call('atlas:compounding:fixed-n-capability-dollar-gate', [
            '--fixture' => 'live',
            '--series' => $path,
            '--strict' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, 'real monthly fixed-N series with positive trend must certify');
        $this->assertSame('fixed_n_capability_per_dollar_ready', $payload['status']);
        $this->assertTrue($payload['certified']);
        $this->assertSame([], $payload['blockers']);
        $this->assertSame(31, data_get($payload, 'assessment.series_day_count'));
        $this->assertSame(31, data_get($payload, 'assessment.calendar_span_days'));
        $this->assertSame(31, data_get($payload, 'assessment.measured_cost_day_count'));
        $this->assertSame(1, data_get($payload, 'assessment.fixed_provider_model_count'));
        $this->assertSame('up', data_get($payload, 'assessment.trend_direction'));
        // The gate never estimates N nor infers price even on the live read path.
        $this->assertFalse((bool) data_get($payload, 'claim_policy.external_provider_capability_estimated'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.price_inference_performed'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.provider_calls_made'));
    }

    /**
     * Refusal-to-fabricate proof on the SAME real read path: a genuine 31-day
     * fixed-N, cost-measured series whose capability-per-dollar is FLAT must be
     * blocked. The gate will not invent a positive trend from real data.
     */
    public function test_live_series_path_refuses_flat_real_monthly_series(): void
    {
        $path = $this->writeRealSeries(31, 'flat');

        $exit = Artisan::call('atlas:compounding:fixed-n-capability-dollar-gate', [
            '--fixture' => 'live',
            '--series' => $path,
            '--strict' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit, 'a flat real series must NOT certify');
        $this->assertFalse($payload['certified']);
        $this->assertContains('capability_per_dollar_trend_not_positive', $payload['blockers']);
        $this->assertSame('flat', data_get($payload, 'assessment.trend_direction'));
        // It must NOT block for any of the volume reasons — the window/cost/N are real.
        $this->assertNotContains('series_day_count_below_floor', $payload['blockers']);
        $this->assertNotContains('measured_cost_day_count_below_floor', $payload['blockers']);
        $this->assertNotContains('fixed_n_not_constant', $payload['blockers']);
    }

    /**
     * Duplicate-date HARDEN: the measured-cost floor counts unique calendar DAYS,
     * never duplicate rows. A real read path series with 31 measured rows crammed
     * onto fewer than 30 distinct dates must NOT certify — proving the floor cannot
     * be gamed by row inflation.
     */
    public function test_live_series_path_does_not_count_duplicate_date_rows_as_days(): void
    {
        $rows = [];
        // 31 measured rows, but only 3 distinct dates (14th, 15th, and a far 31-days-out date).
        for ($i = 0; $i < 30; $i++) {
            $date = $i % 2 === 0 ? '2026-05-14' : '2026-05-15';
            $rows[] = $this->realRow($date, 8.0 + ($i * 0.02), 0.82, 0.12, 100.0);
        }
        $rows[] = $this->realRow('2026-06-13', 9.0, 0.9, 0.12, 100.0); // far date for calendar span

        $path = storage_path('framework/testing/fixed-n-dup-date-series.jsonl');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, implode("\n", array_map(
            static fn (array $r): string => json_encode($r, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $rows,
        ))."\n");

        $payload = app(FixedNCapabilityDollarSeriesGateService::class)->evaluate([
            'fixture' => 'live',
            'series_path' => $path,
        ]);

        File::delete($path);

        $this->assertFalse($payload['certified'], 'duplicate-date rows must not satisfy the day floor');
        $this->assertContains('measured_cost_day_count_below_floor', $payload['blockers']);
        $this->assertContains('series_day_count_below_floor', $payload['blockers']);
        // Only 3 distinct measured dates, regardless of 31 rows.
        $this->assertSame(3, data_get($payload, 'assessment.measured_cost_day_count'));
    }

    private function writeRealSeries(int $days, string $shape): string
    {
        $rows = [];
        $start = strtotime('2026-05-14');
        for ($i = 0; $i < $days; $i++) {
            $ratio = $days > 1 ? $i / ($days - 1) : 0.0;
            $score = $shape === 'flat' ? 8.5 : 8.0 + $ratio;
            $m = $shape === 'flat' ? 0.88 : 0.82 + (0.08 * $ratio);
            $date = date('Y-m-d', $start + ($i * 86400));
            $rows[] = $this->realRow($date, $score, $m, 0.12, 100.0);
        }

        $path = storage_path('framework/testing/fixed-n-real-'.$shape.'-series.jsonl');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, implode("\n", array_map(
            static fn (array $r): string => json_encode($r, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $rows,
        ))."\n");

        return $path;
    }

    /**
     * @return array<string,mixed>
     */
    private function realRow(string $date, float $score, float $m, float $cost, float $coverage): array
    {
        $capability = round(($score / 10.0) * $m, 6);

        return [
            'date' => $date,
            'recorded_at' => $date.'T00:00:00+00:00',
            'fixed_n' => ['provider' => 'codex', 'model' => 'gpt-5.5', 'source' => 'config(atlas.compounding.fixed_n_capability_dollar_gate)'],
            'metrics' => [
                'scorecard_overall' => round($score, 3),
                'wrapper_multiplier_m' => round($m, 4),
                'capability_index' => $capability,
                'total_cost_usd' => $cost,
                'cost_coverage_pct' => $coverage,
                'measured_event_count' => 4,
                'event_count' => 4,
                'capability_per_dollar' => $cost > 0 ? round($capability / $cost, 6) : null,
            ],
        ];
    }
}
