<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainRunPolicyCompiler;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainRunPolicyCompilerTest extends TestCase
{
    private function compiler(): AtlasExternalBrainRunPolicyCompiler
    {
        return new AtlasExternalBrainRunPolicyCompiler;
    }

    private function defaultPolicy(): array
    {
        return $this->compiler()->compile(['target_quota' => 10]);
    }

    /** Clean run state: quota met, no fatigue, no violations. */
    private function cleanState(array $overrides = []): array
    {
        return array_merge([
            'verified_count'             => 10,
            'stalled'                    => false,
            'breakthrough_actions_taken' => false,
            'honest_exhausted'           => false,
            'padding_detected'           => false,
            'same_template_fill_detected' => false,
            'human_dependent_steady_state' => false,
            'value_score'                => 0.9,
            'learning_actions_remaining' => 0,
            'self_heal_actions_remaining' => 0,
            'queue_pressure'             => 0.0,
            'simplification_debt'        => 0.0,
        ], $overrides);
    }

    // ── AC1: compile() emits all autonomy action thresholds ──────────────────

    public function test_compile_emits_autonomy_action_thresholds_key(): void
    {
        $p = $this->compiler()->compile([]);

        $this->assertArrayHasKey('autonomy_action_thresholds', $p);
    }

    public function test_compile_thresholds_include_create(): void
    {
        $p = $this->compiler()->compile([]);

        $this->assertArrayHasKey('create_min_queue_depth', $p['autonomy_action_thresholds']);
    }

    public function test_compile_thresholds_include_stop(): void
    {
        $p = $this->compiler()->compile([]);

        $this->assertArrayHasKey('stop_requires_no_pending_learning', $p['autonomy_action_thresholds']);
    }

    public function test_compile_thresholds_include_consolidate(): void
    {
        $p = $this->compiler()->compile([]);

        $this->assertArrayHasKey('consolidate_queue_pressure_floor',      $p['autonomy_action_thresholds']);
        $this->assertArrayHasKey('consolidate_simplification_debt_floor', $p['autonomy_action_thresholds']);
    }

    public function test_compile_thresholds_include_self_heal(): void
    {
        $p = $this->compiler()->compile([]);

        $this->assertArrayHasKey('self_heal_error_rate_ceiling', $p['autonomy_action_thresholds']);
    }

    public function test_compile_thresholds_include_research(): void
    {
        $p = $this->compiler()->compile([]);

        $this->assertArrayHasKey('research_novelty_score_floor', $p['autonomy_action_thresholds']);
    }

    public function test_compile_thresholds_include_ambition_escalation(): void
    {
        $p = $this->compiler()->compile([]);

        $this->assertArrayHasKey('ambition_escalation_stagnation_floor', $p['autonomy_action_thresholds']);
    }

    // ── AC2: evaluate blocks stopping when learning/self-heal actions remain ──

    public function test_clean_state_quota_met_can_stop(): void
    {
        $r = $this->compiler()->evaluate($this->defaultPolicy(), $this->cleanState());

        $this->assertTrue($r['can_stop']);
        $this->assertSame([], $r['violations']);
    }

    public function test_blocks_stop_when_learning_actions_remain(): void
    {
        $r = $this->compiler()->evaluate(
            $this->defaultPolicy(),
            $this->cleanState(['learning_actions_remaining' => 3]),
        );

        $this->assertFalse($r['can_stop']);
        $violations = implode('|', $r['violations']);
        $this->assertStringContainsString('pending_learning_actions_block_stop', $violations);
        $this->assertContains('complete_learning_actions_before_stopping', $r['required_actions']);
    }

    public function test_blocks_stop_when_self_heal_actions_remain(): void
    {
        $r = $this->compiler()->evaluate(
            $this->defaultPolicy(),
            $this->cleanState(['self_heal_actions_remaining' => 2]),
        );

        $this->assertFalse($r['can_stop']);
        $violations = implode('|', $r['violations']);
        $this->assertStringContainsString('pending_self_heal_actions_block_stop', $violations);
        $this->assertContains('complete_self_heal_actions_before_stopping', $r['required_actions']);
    }

    public function test_quota_only_not_enough_to_stop_when_learning_remains(): void
    {
        // quota met + honest_exhausted, but learning still pending → must not stop
        $r = $this->compiler()->evaluate(
            $this->defaultPolicy(),
            $this->cleanState([
                'honest_exhausted'           => true,
                'learning_actions_remaining' => 1,
            ]),
        );

        $this->assertFalse($r['can_stop']);
    }

    // ── AC3: evaluate requires consolidation when thresholds exceeded ─────────

    public function test_high_queue_pressure_requires_consolidation(): void
    {
        $r = $this->compiler()->evaluate(
            $this->defaultPolicy(),
            $this->cleanState(['queue_pressure' => 0.85]),
        );

        $this->assertFalse($r['can_stop']);
        $violations = implode('|', $r['violations']);
        $this->assertStringContainsString('queue_pressure_exceeds_consolidation_threshold', $violations);
        $this->assertContains('consolidate_queue_before_creating_new_tasks', $r['required_actions']);
    }

    public function test_high_simplification_debt_requires_consolidation(): void
    {
        $r = $this->compiler()->evaluate(
            $this->defaultPolicy(),
            $this->cleanState(['simplification_debt' => 0.75]),
        );

        $this->assertFalse($r['can_stop']);
        $violations = implode('|', $r['violations']);
        $this->assertStringContainsString('simplification_debt_exceeds_consolidation_threshold', $violations);
        $this->assertContains('consolidate_simplification_debt_before_continuing', $r['required_actions']);
    }

    public function test_queue_pressure_below_threshold_does_not_require_consolidation(): void
    {
        $r = $this->compiler()->evaluate(
            $this->defaultPolicy(),
            $this->cleanState(['queue_pressure' => 0.50]),
        );

        $violations = implode('|', $r['violations']);
        $this->assertStringNotContainsString('queue_pressure_exceeds_consolidation_threshold', $violations);
    }

    // ── AC4: deterministic ────────────────────────────────────────────────────

    public function test_compile_is_deterministic(): void
    {
        $cfg = ['target_quota' => 5, 'min_value_score' => 0.7];

        $this->assertSame(json_encode($this->compiler()->compile($cfg)), json_encode($this->compiler()->compile($cfg)));
    }

    public function test_evaluate_is_deterministic(): void
    {
        $p = $this->defaultPolicy();
        $s = $this->cleanState(['learning_actions_remaining' => 2, 'queue_pressure' => 0.80]);

        $this->assertSame(json_encode($this->compiler()->evaluate($p, $s)), json_encode($this->compiler()->evaluate($p, $s)));
    }
}
