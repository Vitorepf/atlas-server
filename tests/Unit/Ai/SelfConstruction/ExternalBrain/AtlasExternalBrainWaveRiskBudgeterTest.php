<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainWaveRiskBudgeter;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainWaveRiskBudgeterTest extends TestCase
{
    private function budgeter(): AtlasExternalBrainWaveRiskBudgeter
    {
        return new AtlasExternalBrainWaveRiskBudgeter;
    }

    private function safeWave(array $overrides = []): array
    {
        return array_merge([
            'blast_radius'        => 0.10,
            'worker_pressure'     => 0.20,
            'has_runnable_tests'  => true,
            'rollback_ready'      => true,
            'high_value'          => false,
        ], $overrides);
    }

    public function test_schema_present(): void
    {
        $result = $this->budgeter()->evaluate($this->safeWave());

        $this->assertSame(AtlasExternalBrainWaveRiskBudgeter::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->budgeter()->evaluate($this->safeWave());

        foreach (['schema', 'decision', 'reasons', 'blast_radius', 'worker_pressure', 'prework_actions'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    // ── approve_case ─────────────────────────────────────────────────────────

    public function test_low_blast_radius_low_pressure_with_tests_and_rollback_is_approved(): void
    {
        $result = $this->budgeter()->evaluate($this->safeWave());

        $this->assertSame(AtlasExternalBrainWaveRiskBudgeter::DECISION_APPROVE, $result['decision']);
        $this->assertSame([], $result['prework_actions']);
    }

    public function test_approve_reports_blast_radius_and_worker_pressure_values(): void
    {
        $result = $this->budgeter()->evaluate($this->safeWave(['blast_radius' => 0.25, 'worker_pressure' => 0.30]));

        $this->assertSame(0.25, $result['blast_radius']);
        $this->assertSame(0.30, $result['worker_pressure']);
    }

    // ── split_wave_case ──────────────────────────────────────────────────────

    public function test_high_value_high_blast_radius_candidate_is_split(): void
    {
        $result = $this->budgeter()->evaluate($this->safeWave([
            'blast_radius' => 0.75,
            'high_value'   => true,
        ]));

        $this->assertSame(AtlasExternalBrainWaveRiskBudgeter::DECISION_SPLIT_WAVE, $result['decision']);
        $this->assertNotEmpty($result['prework_actions']);
        $this->assertContains('high_value_candidate_requires_prework', $result['reasons']);
    }

    public function test_split_wave_prework_actions_scope_a_smaller_slice(): void
    {
        $result = $this->budgeter()->evaluate($this->safeWave([
            'blast_radius' => 0.90,
            'high_value'   => true,
        ]));

        $this->assertStringContainsString('prework', implode(' ', $result['prework_actions']));
    }

    // ── hold_case ────────────────────────────────────────────────────────────

    public function test_missing_rollback_readiness_holds(): void
    {
        $result = $this->budgeter()->evaluate($this->safeWave(['rollback_ready' => false]));

        $this->assertSame(AtlasExternalBrainWaveRiskBudgeter::DECISION_HOLD, $result['decision']);
        $this->assertContains('rollback_not_ready', $result['reasons']);
        $this->assertSame([], $result['prework_actions']);
    }

    public function test_missing_runnable_tests_holds(): void
    {
        $result = $this->budgeter()->evaluate($this->safeWave(['has_runnable_tests' => false]));

        $this->assertSame(AtlasExternalBrainWaveRiskBudgeter::DECISION_HOLD, $result['decision']);
        $this->assertContains('missing_runnable_test_proof', $result['reasons']);
    }

    public function test_high_worker_pressure_holds_even_with_low_blast_radius(): void
    {
        $result = $this->budgeter()->evaluate($this->safeWave(['worker_pressure' => 0.95]));

        $this->assertSame(AtlasExternalBrainWaveRiskBudgeter::DECISION_HOLD, $result['decision']);
        $this->assertStringContainsString('worker_pressure', implode(',', $result['reasons']));
    }

    public function test_high_blast_radius_without_high_value_holds_not_split(): void
    {
        $result = $this->budgeter()->evaluate($this->safeWave([
            'blast_radius' => 0.80,
            'high_value'   => false,
        ]));

        $this->assertSame(AtlasExternalBrainWaveRiskBudgeter::DECISION_HOLD, $result['decision']);
        $this->assertContains('no_proven_value_to_justify_risk', $result['reasons']);
        $this->assertSame([], $result['prework_actions']);
    }

    public function test_rollback_and_test_failures_take_priority_over_blast_radius_split(): void
    {
        $result = $this->budgeter()->evaluate($this->safeWave([
            'blast_radius'       => 0.90,
            'high_value'         => true,
            'rollback_ready'     => false,
            'has_runnable_tests' => false,
        ]));

        $this->assertSame(AtlasExternalBrainWaveRiskBudgeter::DECISION_HOLD, $result['decision']);
        $this->assertContains('rollback_not_ready', $result['reasons']);
        $this->assertContains('missing_runnable_test_proof', $result['reasons']);
    }

    // ── threshold overrides + determinism ───────────────────────────────────

    public function test_custom_blast_radius_threshold_is_honored(): void
    {
        $result = $this->budgeter()->evaluate($this->safeWave([
            'blast_radius' => 0.15,
            'high_value'   => true,
            'blast_radius_threshold' => 0.10,
        ]));

        $this->assertSame(AtlasExternalBrainWaveRiskBudgeter::DECISION_SPLIT_WAVE, $result['decision']);
    }

    public function test_result_is_deterministic_for_identical_input(): void
    {
        $budgeter = $this->budgeter();
        $wave = $this->safeWave(['blast_radius' => 0.6, 'high_value' => true]);

        $this->assertSame($budgeter->evaluate($wave), $budgeter->evaluate($wave));
    }

    public function test_blast_radius_and_worker_pressure_are_clamped_to_0_1(): void
    {
        $result = $this->budgeter()->evaluate($this->safeWave(['blast_radius' => 5.0, 'worker_pressure' => -2.0]));

        $this->assertSame(1.0, $result['blast_radius']);
        $this->assertSame(0.0, $result['worker_pressure']);
    }
}
