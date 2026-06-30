<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Autonomy;

use App\Services\Ai\SelfConstruction\Autonomy\AtlasSelfConstructionAutonomyDutyCyclePlanner;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionAutonomyDutyCyclePlannerTest extends TestCase
{
    private function planner(): AtlasSelfConstructionAutonomyDutyCyclePlanner
    {
        return new AtlasSelfConstructionAutonomyDutyCyclePlanner;
    }

    private function plan(array $input = []): array
    {
        return $this->planner()->plan($input);
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $r = $this->plan();

        $this->assertSame(AtlasSelfConstructionAutonomyDutyCyclePlanner::SCHEMA, $r['schema']);
    }

    public function test_output_keys_present(): void
    {
        $r = $this->plan();

        foreach (['schema', 'recommended_action', 'rationale', 'ready_to_run', 'servable_depth'] as $k) {
            $this->assertArrayHasKey($k, $r);
        }
    }

    public function test_servable_depth_echoed(): void
    {
        $r = $this->plan(['servable_depth' => 42]);

        $this->assertSame(42, $r['servable_depth']);
    }

    // ── AC2: low risk → originate_or_expand ──────────────────────────────────

    public function test_low_risk_chooses_originate_or_expand(): void
    {
        $r = $this->plan([
            'servable_depth'  => 2,
            'malformed_rate'  => 0.05,
            'give_back_rate'  => 0.05,
            'congestion_score' => 0.10,
        ]);

        $this->assertSame(AtlasSelfConstructionAutonomyDutyCyclePlanner::ACTION_ORIGINATE_OR_EXPAND, $r['recommended_action']);
        $this->assertTrue($r['ready_to_run']);
        $this->assertNotEmpty($r['rationale']);
    }

    public function test_default_empty_input_chooses_originate_or_expand(): void
    {
        $r = $this->plan();

        $this->assertSame(AtlasSelfConstructionAutonomyDutyCyclePlanner::ACTION_ORIGINATE_OR_EXPAND, $r['recommended_action']);
        $this->assertTrue($r['ready_to_run']);
    }

    // ── AC3: high malformed_rate → self_heal_queue ────────────────────────────

    public function test_high_malformed_rate_chooses_self_heal_queue(): void
    {
        $r = $this->plan(['malformed_rate' => 0.50]);

        $this->assertSame(AtlasSelfConstructionAutonomyDutyCyclePlanner::ACTION_SELF_HEAL_QUEUE, $r['recommended_action']);
        $this->assertTrue($r['ready_to_run']);
        $this->assertStringContainsString('malformed_rate', $r['rationale']);
    }

    // ── AC3: high give_back_rate → self_heal_queue ────────────────────────────

    public function test_high_give_back_rate_chooses_self_heal_queue(): void
    {
        $r = $this->plan(['give_back_rate' => 0.50]);

        $this->assertSame(AtlasSelfConstructionAutonomyDutyCyclePlanner::ACTION_SELF_HEAL_QUEUE, $r['recommended_action']);
        $this->assertTrue($r['ready_to_run']);
        $this->assertStringContainsString('give_back_rate', $r['rationale']);
    }

    // ── AC3: high congestion → drain_or_pause ────────────────────────────────

    public function test_high_congestion_chooses_drain_or_pause(): void
    {
        $r = $this->plan(['congestion_score' => 0.90]);

        $this->assertSame(AtlasSelfConstructionAutonomyDutyCyclePlanner::ACTION_DRAIN_OR_PAUSE, $r['recommended_action']);
        $this->assertFalse($r['ready_to_run']);
        $this->assertStringContainsString('congestion_score', $r['rationale']);
    }

    // ── decision priority ─────────────────────────────────────────────────────

    public function test_malformed_beats_congestion(): void
    {
        $r = $this->plan(['malformed_rate' => 0.50, 'congestion_score' => 0.90]);

        $this->assertSame(AtlasSelfConstructionAutonomyDutyCyclePlanner::ACTION_SELF_HEAL_QUEUE, $r['recommended_action']);
    }

    public function test_give_back_beats_congestion(): void
    {
        $r = $this->plan(['give_back_rate' => 0.50, 'congestion_score' => 0.90]);

        $this->assertSame(AtlasSelfConstructionAutonomyDutyCyclePlanner::ACTION_SELF_HEAL_QUEUE, $r['recommended_action']);
    }

    // ── boundary: at-threshold is not triggered ───────────────────────────────

    public function test_malformed_at_threshold_not_triggered(): void
    {
        $r = $this->plan(['malformed_rate' => 0.30]); // exactly at default 0.30 → not >

        $this->assertSame(AtlasSelfConstructionAutonomyDutyCyclePlanner::ACTION_ORIGINATE_OR_EXPAND, $r['recommended_action']);
    }

    public function test_congestion_at_threshold_not_triggered(): void
    {
        $r = $this->plan(['congestion_score' => 0.70]); // exactly at default 0.70 → not >

        $this->assertSame(AtlasSelfConstructionAutonomyDutyCyclePlanner::ACTION_ORIGINATE_OR_EXPAND, $r['recommended_action']);
    }

    // ── custom thresholds ─────────────────────────────────────────────────────

    public function test_custom_thresholds_respected(): void
    {
        // With tight threshold 0.10, malformed_rate=0.15 triggers heal
        $r = $this->plan(['malformed_rate' => 0.15, 'malformed_threshold' => 0.10]);

        $this->assertSame(AtlasSelfConstructionAutonomyDutyCyclePlanner::ACTION_SELF_HEAL_QUEUE, $r['recommended_action']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_deterministic(): void
    {
        $input = ['malformed_rate' => 0.12, 'give_back_rate' => 0.08, 'congestion_score' => 0.55];

        $this->assertSame($this->plan($input), $this->plan($input));
    }
}
