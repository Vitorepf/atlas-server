<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainModelAmplifierOperatingLoop;
use Tests\TestCase;

final class AtlasExternalBrainModelAmplifierOperatingLoopTest extends TestCase
{
    private function svc(): AtlasExternalBrainModelAmplifierOperatingLoop
    {
        return new AtlasExternalBrainModelAmplifierOperatingLoop;
    }

    private function decide(array $overrides = []): array
    {
        $base = [
            'proxy_leak_detected' => false,
            'benchmark_score' => 0.85,
            'frontier_available' => false,
            'escalation_budget_remaining' => false,
            'scaffold_available' => false,
            'scaffold_evidence' => ['lift_score' => 0.0, 'retire_signal' => false, 'repair_signal' => false],
        ];

        return $this->svc()->decide(array_merge($base, $overrides));
    }

    // ── priority 1: proxy leak → repair_scaffold ──────────────────────────────

    public function test_proxy_leak_triggers_repair_scaffold(): void
    {
        $r = $this->decide(['proxy_leak_detected' => true]);
        $this->assertSame('repair_scaffold', $r['decision']);
    }

    public function test_proxy_leak_overrides_retire_signal(): void
    {
        $r = $this->decide([
            'proxy_leak_detected' => true,
            'scaffold_evidence' => ['lift_score' => 0.0, 'retire_signal' => true, 'repair_signal' => false],
        ]);
        $this->assertSame('repair_scaffold', $r['decision']);
        $this->assertSame('proxy_leak_detected', $r['rationale']);
    }

    // ── priority 2: retire signal → retire_scaffold ───────────────────────────

    public function test_retire_signal_triggers_retire_scaffold(): void
    {
        $r = $this->decide([
            'scaffold_evidence' => ['lift_score' => 0.5, 'retire_signal' => true, 'repair_signal' => false],
        ]);
        $this->assertSame('retire_scaffold', $r['decision']);
    }

    // ── priority 3: repair signal → repair_scaffold ───────────────────────────

    public function test_repair_signal_triggers_repair_scaffold(): void
    {
        $r = $this->decide([
            'scaffold_evidence' => ['lift_score' => 0.3, 'retire_signal' => false, 'repair_signal' => true],
        ]);
        $this->assertSame('repair_scaffold', $r['decision']);
        $this->assertSame('scaffold_repair_signal', $r['rationale']);
    }

    // ── priority 4: escalate_frontier ─────────────────────────────────────────

    public function test_frontier_escalation_when_all_conditions_met(): void
    {
        $r = $this->decide([
            'benchmark_score' => 0.60,  // below ESCALATION_SCORE_THRESHOLD(0.70)
            'frontier_available' => true,
            'escalation_budget_remaining' => true,
        ]);
        $this->assertSame('escalate_frontier', $r['decision']);
    }

    public function test_no_escalation_when_frontier_unavailable(): void
    {
        $r = $this->decide([
            'benchmark_score' => 0.50,
            'frontier_available' => false,
            'escalation_budget_remaining' => true,
        ]);
        $this->assertNotSame('escalate_frontier', $r['decision']);
    }

    public function test_no_escalation_when_budget_exhausted(): void
    {
        $r = $this->decide([
            'benchmark_score' => 0.50,
            'frontier_available' => true,
            'escalation_budget_remaining' => false,
        ]);
        $this->assertNotSame('escalate_frontier', $r['decision']);
    }

    public function test_no_escalation_when_benchmark_already_high(): void
    {
        // benchmark >= ESCALATION_SCORE_THRESHOLD → no need to escalate
        $r = $this->decide([
            'benchmark_score' => 0.80,
            'frontier_available' => true,
            'escalation_budget_remaining' => true,
        ]);
        $this->assertNotSame('escalate_frontier', $r['decision']);
    }

    // ── priority 5: run_scaffolded ────────────────────────────────────────────

    public function test_run_scaffolded_when_scaffold_available_with_positive_lift(): void
    {
        $r = $this->decide([
            'scaffold_available' => true,
            'scaffold_evidence' => ['lift_score' => 0.3, 'retire_signal' => false, 'repair_signal' => false],
        ]);
        $this->assertSame('run_scaffolded', $r['decision']);
    }

    public function test_no_scaffold_run_when_lift_is_zero_or_negative(): void
    {
        $r = $this->decide([
            'scaffold_available' => true,
            'scaffold_evidence' => ['lift_score' => 0.0, 'retire_signal' => false, 'repair_signal' => false],
        ]);
        $this->assertSame('run_small', $r['decision']);
    }

    // ── default: run_small (steady-state, no frontier required) ──────────────

    public function test_default_decision_is_run_small(): void
    {
        $r = $this->decide();
        $this->assertSame('run_small', $r['decision']);
        $this->assertSame('default_steady_state', $r['rationale']);
    }

    public function test_frontier_required_always_false(): void
    {
        foreach (['run_small', 'run_scaffolded', 'escalate_frontier'] as $case) {
            $input = match ($case) {
                'run_small' => [],
                'run_scaffolded' => [
                    'scaffold_available' => true,
                    'scaffold_evidence' => ['lift_score' => 0.5, 'retire_signal' => false, 'repair_signal' => false],
                ],
                'escalate_frontier' => [
                    'benchmark_score' => 0.50,
                    'frontier_available' => true,
                    'escalation_budget_remaining' => true,
                ],
            };
            $r = $this->decide($input);
            $this->assertFalse($r['frontier_required'], "frontier_required must be false for decision {$r['decision']}");
        }
    }

    // ── receipt ───────────────────────────────────────────────────────────────

    public function test_receipt_includes_key_inputs(): void
    {
        $r = $this->decide(['benchmark_score' => 0.75, 'frontier_available' => true]);
        $this->assertArrayHasKey('proxy_leak_detected', $r['receipt']);
        $this->assertArrayHasKey('frontier_available', $r['receipt']);
        $this->assertArrayHasKey('benchmark_score', $r['receipt']);
        $this->assertSame(0.75, $r['receipt']['benchmark_score']);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->decide([]);
        $this->assertSame(AtlasExternalBrainModelAmplifierOperatingLoop::SCHEMA, $r['schema_version']);
    }
}
