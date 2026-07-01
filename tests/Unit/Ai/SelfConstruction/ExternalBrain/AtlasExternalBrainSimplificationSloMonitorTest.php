<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSimplificationSloMonitor;
use Tests\TestCase;

final class AtlasExternalBrainSimplificationSloMonitorTest extends TestCase
{
    private function monitor(): AtlasExternalBrainSimplificationSloMonitor
    {
        return new AtlasExternalBrainSimplificationSloMonitor;
    }

    private function healthyFacts(array $overrides = []): array
    {
        return array_merge([
            'green_rate' => 0.9,
            'green_rate_target' => 0.8,
            'line_reduction' => 100,
            'line_reduction_target' => 100,
            'proof_debt' => 0,
            'proof_debt_target' => 2,
            'regression_rate' => 0.0,
            'regression_rate_target' => 0.05,
        ], $overrides);
    }

    public function test_schema_present(): void
    {
        $r = $this->monitor()->evaluate($this->healthyFacts());
        $this->assertSame(AtlasExternalBrainSimplificationSloMonitor::SCHEMA, $r['schema']);
    }

    // ── AC: missing SLO targets produce repair, never normal batch creation ──

    public function test_missing_slo_targets_produces_repair(): void
    {
        $r = $this->monitor()->evaluate(['green_rate' => 0.9]);

        $this->assertSame(AtlasExternalBrainSimplificationSloMonitor::MODE_REPAIR, $r['mode']);
        $this->assertStringContainsString('missing_slo_targets', $r['reasons'][0]);
    }

    public function test_partially_missing_slo_targets_still_produces_repair(): void
    {
        $facts = $this->healthyFacts();
        unset($facts['proof_debt_target']);

        $r = $this->monitor()->evaluate($facts);

        $this->assertSame(AtlasExternalBrainSimplificationSloMonitor::MODE_REPAIR, $r['mode']);
        $this->assertStringContainsString('proof_debt_target', $r['reasons'][0]);
    }

    // ── AC: repair_missed_slo_case — green rate or proof debt miss → repair ──

    public function test_repair_missed_slo_case_low_green_rate(): void
    {
        $r = $this->monitor()->evaluate($this->healthyFacts(['green_rate' => 0.3]));

        $this->assertSame(AtlasExternalBrainSimplificationSloMonitor::MODE_REPAIR, $r['mode']);
        $this->assertFalse($r['slo_checks']['green_rate_ok']);
    }

    public function test_repair_missed_slo_case_high_proof_debt(): void
    {
        $r = $this->monitor()->evaluate($this->healthyFacts(['proof_debt' => 10]));

        $this->assertSame(AtlasExternalBrainSimplificationSloMonitor::MODE_REPAIR, $r['mode']);
        $this->assertFalse($r['slo_checks']['proof_debt_ok']);
    }

    // ── regression above target stops, even when everything else is fine ────

    public function test_regression_above_target_stops(): void
    {
        $r = $this->monitor()->evaluate($this->healthyFacts(['regression_rate' => 0.5]));

        $this->assertSame(AtlasExternalBrainSimplificationSloMonitor::MODE_STOP, $r['mode']);
    }

    public function test_repair_takes_priority_over_stop_when_both_missed(): void
    {
        $r = $this->monitor()->evaluate($this->healthyFacts([
            'green_rate' => 0.1,
            'regression_rate' => 0.9,
        ]));

        $this->assertSame(AtlasExternalBrainSimplificationSloMonitor::MODE_REPAIR, $r['mode']);
    }

    // ── AC: accelerate_case ────────────────────────────────────────────────────

    public function test_accelerate_case(): void
    {
        $r = $this->monitor()->evaluate($this->healthyFacts([
            'line_reduction' => 200, // 2x target=100, above 1.5x multiplier
        ]));

        $this->assertSame(AtlasExternalBrainSimplificationSloMonitor::MODE_ACCELERATE, $r['mode']);
    }

    public function test_all_slos_met_but_line_reduction_not_ahead_is_steady(): void
    {
        $r = $this->monitor()->evaluate($this->healthyFacts(['line_reduction' => 100]));

        $this->assertSame(AtlasExternalBrainSimplificationSloMonitor::MODE_STEADY, $r['mode']);
    }

    // ── slo_checks breakdown always present when targets are configured ──────

    public function test_slo_checks_breakdown_present_for_all_four_indicators(): void
    {
        $r = $this->monitor()->evaluate($this->healthyFacts());

        foreach (['green_rate_ok', 'line_reduction_ok', 'proof_debt_ok', 'regression_rate_ok'] as $key) {
            $this->assertArrayHasKey($key, $r['slo_checks'], "Missing slo check: {$key}");
        }
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_evaluate_is_deterministic(): void
    {
        $facts = $this->healthyFacts();

        $this->assertSame(
            json_encode($this->monitor()->evaluate($facts)),
            json_encode($this->monitor()->evaluate($facts)),
        );
    }
}
