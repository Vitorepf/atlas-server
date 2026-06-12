<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\ProgrammingRuntime\Telemetry;

use App\Services\Ai\ProgrammingRuntime\Telemetry\ProgrammingRuntimeTelemetryRecorder;
use App\Services\Ai\ProgrammingRuntime\Telemetry\ProviderCostEstimator;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * L3-10 — proves the cost axis of the N×M antifragility equation is MEASURED,
 * not blind and not fabricated:
 *
 *  - tokens present  → measured cost > 0, computed FROM the rate table.
 *  - neither tokens nor runtime → stays null (honest "unknown", never faked).
 *  - local token-free provider with runtime → measured from wall-clock.
 *  - the recorder persists the measured cost end-to-end.
 */
class ProviderCostEstimatorTest extends TestCase
{
    private ProviderCostEstimator $estimator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->estimator = new ProviderCostEstimator();
    }

    public function test_token_usage_yields_measured_cost_from_rate_table(): void
    {
        // claude default rate: in 0.003 / out 0.015 per 1k tokens.
        // 1000 in × 0.003 + 1000 out × 0.015 = 0.003 + 0.015 = 0.018 exactly.
        $cost = $this->estimator->estimate([
            'provider' => 'claude',
            'tokens_in' => 1000,
            'tokens_out' => 1000,
        ]);

        $this->assertNotNull($cost);
        $this->assertGreaterThan(0.0, $cost);
        $this->assertEqualsWithDelta(0.018, $cost, 0.0000001, 'cost must come from the rate table, not a constant');
    }

    public function test_known_token_count_maps_to_known_cost_for_codex(): void
    {
        // codex rate: in 0.0025 / out 0.01 per 1k.
        // 2000 in × 0.0025 + 500 out × 0.01 = 0.005 + 0.005 = 0.010.
        $cost = $this->estimator->estimate([
            'provider' => 'codex',
            'input_tokens' => 2000,
            'output_tokens' => 500,
        ]);

        $this->assertEqualsWithDelta(0.010, $cost, 0.0000001);
    }

    public function test_unknown_provider_uses_default_rate_when_tokens_present(): void
    {
        // default rate: in 0.003 / out 0.015. 1000/0 → 0.003.
        $cost = $this->estimator->estimate([
            'provider' => 'some-future-model',
            'tokens_in' => 1000,
            'tokens_out' => 0,
        ]);

        $this->assertEqualsWithDelta(0.003, $cost, 0.0000001);
    }

    public function test_total_tokens_only_splits_evenly(): void
    {
        // claude, 2000 total → 1000 in / 1000 out → 0.018.
        $cost = $this->estimator->estimate([
            'provider' => 'claude',
            'total_tokens' => 2000,
        ]);

        $this->assertEqualsWithDelta(0.018, $cost, 0.0000001);
    }

    public function test_no_tokens_and_no_runtime_stays_unknown(): void
    {
        $cost = $this->estimator->estimate([
            'provider' => 'claude',
            'execution_status' => 'passed',
        ]);

        $this->assertNull($cost, 'with no real signal the cost must stay honestly unknown, never fabricated');
    }

    public function test_no_signals_at_all_stays_unknown(): void
    {
        $this->assertNull($this->estimator->estimate([]));
    }

    public function test_local_provider_without_tokens_uses_runtime_fallback(): void
    {
        // hermes is token-free; rate per minute default 0.02.
        // 60000 ms = 1 minute → 0.02.
        $cost = $this->estimator->estimate([
            'provider' => 'hermes',
            'duration_ms' => 60000,
        ]);

        $this->assertNotNull($cost);
        $this->assertEqualsWithDelta(0.02, $cost, 0.0000001);
    }

    public function test_local_provider_with_no_runtime_stays_unknown(): void
    {
        // hermes, no tokens, no duration → genuinely unknown (not $0.00 faked).
        $cost = $this->estimator->estimate([
            'provider' => 'minimax',
        ]);

        $this->assertNull($cost);
    }

    public function test_non_local_provider_does_not_use_runtime_fallback(): void
    {
        // claude with runtime but no tokens: runtime fallback is local-only,
        // so this stays unknown rather than inventing a runtime-based number.
        $cost = $this->estimator->estimate([
            'provider' => 'claude',
            'duration_ms' => 60000,
        ]);

        $this->assertNull($cost);
    }

    public function test_explicit_measured_cost_is_trusted(): void
    {
        $cost = $this->estimator->estimate([
            'provider' => 'claude',
            'cost_estimate_usd' => 0.42,
            'tokens_in' => 1000,
            'tokens_out' => 1000,
        ]);

        $this->assertEqualsWithDelta(0.42, $cost, 0.0000001);
    }

    public function test_model_id_substring_resolves_to_rate_key(): void
    {
        // "claude-opus-4-8" → opus rate (in 0.015 / out 0.075). 1000/1000 → 0.09.
        $cost = $this->estimator->estimate([
            'model' => 'claude-opus-4-8',
            'tokens_in' => 1000,
            'tokens_out' => 1000,
        ]);

        $this->assertEqualsWithDelta(0.09, $cost, 0.0000001);
    }

    public function test_recorder_persists_measured_cost_from_tokens(): void
    {
        $this->bootTelemetrySchema();

        $recorder = app(ProgrammingRuntimeTelemetryRecorder::class);
        $event = $recorder->record([
            'event_name' => 'runtime_record_completed',
            'flow' => 'atlas_dev',
            'selected_core' => 'atlas_dev',
            'run_id' => 'run-cost-1',
            'execution_status' => 'passed',
            'provider' => 'claude',
            'tokens_in' => 1000,
            'tokens_out' => 1000,
        ]);

        $this->assertNotNull($event);
        $this->assertNotNull($event->cost_estimate_usd);
        $this->assertGreaterThan(0.0, (float) $event->cost_estimate_usd);
        $this->assertEqualsWithDelta(0.018, (float) $event->cost_estimate_usd, 0.0000001);

        Schema::dropIfExists('ai_programming_runtime_telemetry_events');
    }

    public function test_recorder_keeps_cost_null_with_no_signals(): void
    {
        $this->bootTelemetrySchema();

        $recorder = app(ProgrammingRuntimeTelemetryRecorder::class);
        $event = $recorder->record([
            'event_name' => 'runtime_record_completed',
            'flow' => 'atlas_dev',
            'selected_core' => 'atlas_dev',
            'run_id' => 'run-cost-unknown',
            'execution_status' => 'passed',
        ]);

        $this->assertNotNull($event);
        $this->assertNull($event->cost_estimate_usd, 'no signal → honest unknown, not a fabricated value');

        Schema::dropIfExists('ai_programming_runtime_telemetry_events');
    }

    private function bootTelemetrySchema(): void
    {
        Schema::dropIfExists('ai_programming_runtime_telemetry_events');
        (require database_path('migrations/2026_05_19_040000_create_ai_programming_runtime_telemetry_events_table.php'))->up();
    }
}
