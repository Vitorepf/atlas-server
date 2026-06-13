<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Ai\Cognitive\PredictiveFailure\PredictiveCodeIntelligenceCorrelationGateService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AtlasPredictiveCodeIntelligenceGateCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'atlas.cognition.predictive_code_intelligence_gate.enabled' => true,
            'atlas.cognition.predictive_code_intelligence_gate.schedule_enabled' => true,
            'atlas.cognition.predictive_code_intelligence_gate.schedule_time' => '07:25',
            'atlas.cognition.predictive_code_intelligence_gate.domain' => 'learning',
            'atlas.cognition.predictive_code_intelligence_gate.window_days' => 60,
            'atlas.cognition.predictive_code_intelligence_gate.min_outcomes' => 3,
            'atlas.cognition.predictive_code_intelligence_gate.min_failure_signature_outcomes' => 1,
            'atlas.cognition.predictive_code_intelligence_gate.max_avg_calibration_error' => 0.35,
            'atlas.cognition.predictive_code_intelligence_gate.max_brier_score' => 0.25,
            'atlas.cognition.predictive_code_intelligence_gate.auto_refresh' => false,
        ]);
    }

    public function test_mature_fixture_certifies_only_with_code_gate_and_failure_outcomes(): void
    {
        $payload = app(PredictiveCodeIntelligenceCorrelationGateService::class)->evaluate([
            'fixture' => 'mature',
        ]);

        $this->assertSame('predictive_code_intelligence_correlated', $payload['status']);
        $this->assertTrue($payload['certified']);
        $this->assertTrue($payload['completion_claim_allowed']);
        $this->assertSame('ready', data_get($payload, 'assessment.code_gate_status'));
        $this->assertSame(5, data_get($payload, 'assessment.outcomes_recorded'));
        $this->assertSame(3, data_get($payload, 'assessment.failure_signature_outcomes'));
        $this->assertLessThanOrEqual(0.35, data_get($payload, 'assessment.avg_calibration_error'));
        $this->assertLessThanOrEqual(0.25, data_get($payload, 'assessment.brier_score'));
        $this->assertSame([], $payload['blockers']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.does_not_backfill_outcomes'));
    }

    public function test_zero_outcomes_fixture_blocks_strict_claim(): void
    {
        $exit = Artisan::call('atlas:cognition:predictive-code-intelligence-gate', [
            '--fixture' => 'zero-outcomes',
            '--json' => true,
            '--strict' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('insufficient_predictive_failure_correlation', $payload['status']);
        $this->assertFalse($payload['certified']);
        $this->assertContains('predictive_outcomes_below_floor', $payload['blockers']);
        $this->assertContains('failure_signature_outcomes_below_floor', $payload['blockers']);
        $this->assertContains('avg_calibration_error_missing', $payload['blockers']);
        $this->assertContains('brier_score_missing', $payload['blockers']);
    }

    public function test_stale_code_gate_blocks_even_with_mature_prediction_metrics(): void
    {
        $payload = app(PredictiveCodeIntelligenceCorrelationGateService::class)->evaluate([
            'fixture' => 'stale-code',
        ]);

        $this->assertSame('insufficient_predictive_failure_correlation', $payload['status']);
        $this->assertFalse($payload['certified']);
        $this->assertContains('code_intelligence_gate_not_ready', $payload['blockers']);
        $this->assertContains('code_gate:audit_not_fresh', $payload['blockers']);
        $this->assertContains('code_gate:drift_detected', $payload['blockers']);
    }

    public function test_command_writes_receipt_without_mutating_predictions(): void
    {
        $receipt = storage_path('framework/testing/predictive-code-intelligence-gate.json');
        File::delete($receipt);

        $exit = Artisan::call('atlas:cognition:predictive-code-intelligence-gate', [
            '--fixture' => 'mature',
            '--receipt' => $receipt,
            '--write-receipt' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertFileExists($receipt);
        $this->assertSame($receipt, $payload['receipt_path']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.does_not_mint_predictions'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.does_not_backfill_outcomes'));
    }

    public function test_schedule_contains_daily_predictive_code_intelligence_gate(): void
    {
        $exit = Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('atlas:cognition:predictive-code-intelligence-gate --write-receipt --json', $output);
    }
}
