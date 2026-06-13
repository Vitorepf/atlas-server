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
}
