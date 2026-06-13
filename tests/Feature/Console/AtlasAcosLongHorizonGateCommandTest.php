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
}
