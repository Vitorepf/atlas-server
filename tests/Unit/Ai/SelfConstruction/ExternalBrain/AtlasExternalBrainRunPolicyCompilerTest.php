<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainRunPolicyCompiler;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainRunPolicyCompilerTest extends TestCase
{
    private AtlasExternalBrainRunPolicyCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new AtlasExternalBrainRunPolicyCompiler;
    }

    // ── compile() ─────────────────────────────────────────────────────────────

    public function test_compile_produces_required_policy_keys(): void
    {
        $policy = $this->compiler->compile(['target_quota' => 100]);

        $this->assertSame(AtlasExternalBrainRunPolicyCompiler::SCHEMA_POLICY, $policy['schema']);
        $this->assertSame(100, $policy['target_quota']);

        foreach (['min_value_score', 'stall_threshold', 'forbidden_behaviours',
                  'continuation_rules', 'breakthrough_required_after_stall',
                  'honest_exhausted_criteria'] as $key) {
            $this->assertArrayHasKey($key, $policy, "compiled policy must contain {$key}");
        }
    }

    public function test_compile_always_includes_default_forbidden_behaviours(): void
    {
        $policy = $this->compiler->compile(['target_quota' => 50]);

        $forbidden = $policy['forbidden_behaviours'];
        $this->assertContains(AtlasExternalBrainRunPolicyCompiler::FORBIDDEN_PADDING, $forbidden);
        $this->assertContains(AtlasExternalBrainRunPolicyCompiler::FORBIDDEN_SAME_TEMPLATE_FILL, $forbidden);
        $this->assertContains(AtlasExternalBrainRunPolicyCompiler::FORBIDDEN_HUMAN_DEPENDENT, $forbidden);
    }

    public function test_stall_threshold_scales_with_target_quota(): void
    {
        $policy = $this->compiler->compile(['target_quota' => 100, 'stall_threshold_pct' => 0.5]);
        $this->assertSame(50, $policy['stall_threshold']);
    }

    // ── KEY ACCEPTANCE CRITERION: quota-100 cannot stop at 10 ────────────────

    public function test_quota_100_cannot_stop_at_10_without_breakthrough_or_honest_exhausted(): void
    {
        $policy = $this->compiler->compile(['target_quota' => 100]);

        $verdict = $this->compiler->evaluate($policy, [
            'verified_count' => 10,
            'stalled' => true,
            'breakthrough_actions_taken' => false,
            'honest_exhausted' => false,
        ]);

        $this->assertFalse($verdict['can_stop'], 'quota-100 run must not stop at 10 without breakthrough or honest_exhausted');
        $this->assertNotEmpty($verdict['violations']);
        $this->assertContains('invoke_breakthrough_planner', $verdict['required_actions']);
    }

    public function test_quota_100_can_stop_at_10_with_honest_exhausted(): void
    {
        $policy = $this->compiler->compile(['target_quota' => 100]);

        $verdict = $this->compiler->evaluate($policy, [
            'verified_count' => 10,
            'stalled' => true,
            'breakthrough_actions_taken' => false,
            'honest_exhausted' => true,
        ]);

        $this->assertTrue($verdict['can_stop'], 'honest_exhausted evidence bundle must allow early stop');
        $this->assertSame('honest_exhausted', $verdict['stop_reason']);
        $this->assertSame([], $verdict['violations']);
    }

    public function test_quota_100_can_stop_at_10_stalled_with_breakthrough_taken(): void
    {
        $policy = $this->compiler->compile(['target_quota' => 100]);

        // Stalled + breakthrough taken = legitimate mid-run pause, but still can't stop (quota not reached)
        // unless honest_exhausted follows. breakthrough_actions_taken only prevents the specific violation.
        $verdict = $this->compiler->evaluate($policy, [
            'verified_count' => 10,
            'stalled' => true,
            'breakthrough_actions_taken' => true,
            'honest_exhausted' => false,
        ]);

        // No stall violation (breakthrough was taken), but quota not reached → cannot stop yet.
        $this->assertFalse($verdict['can_stop']);
        $violations = $verdict['violations'];
        $this->assertEmpty(array_filter($violations, fn (string $v): bool => str_contains($v, 'stalled_without_breakthrough')));
    }

    public function test_quota_100_can_stop_when_quota_reached(): void
    {
        $policy = $this->compiler->compile(['target_quota' => 100]);

        $verdict = $this->compiler->evaluate($policy, [
            'verified_count' => 100,
            'stalled' => false,
            'breakthrough_actions_taken' => false,
            'honest_exhausted' => false,
        ]);

        $this->assertTrue($verdict['can_stop']);
        $this->assertSame('quota_reached', $verdict['stop_reason']);
        $this->assertSame([], $verdict['violations']);
    }

    // ── forbidden behaviour enforcement ───────────────────────────────────────

    public function test_padding_detected_violates_policy(): void
    {
        $policy = $this->compiler->compile(['target_quota' => 100]);

        $verdict = $this->compiler->evaluate($policy, [
            'verified_count' => 100,  // quota met
            'padding_detected' => true,
        ]);

        $this->assertFalse($verdict['can_stop']);
        $this->assertContains('forbidden_behaviour:padding_detected', $verdict['violations']);
        $this->assertContains('replace_padding_with_substantive_origination', $verdict['required_actions']);
    }

    public function test_same_template_fill_detected_violates_policy(): void
    {
        $policy = $this->compiler->compile(['target_quota' => 100]);

        $verdict = $this->compiler->evaluate($policy, [
            'verified_count' => 100,
            'same_template_fill_detected' => true,
        ]);

        $this->assertFalse($verdict['can_stop']);
        $this->assertContains('forbidden_behaviour:same_template_quota_fill_detected', $verdict['violations']);
        $this->assertContains('diversify_patterns_before_continuing', $verdict['required_actions']);
    }

    public function test_human_dependent_steady_state_violates_policy(): void
    {
        $policy = $this->compiler->compile(['target_quota' => 100]);

        $verdict = $this->compiler->evaluate($policy, [
            'verified_count' => 100,
            'human_dependent_steady_state' => true,
        ]);

        $this->assertFalse($verdict['can_stop']);
        $this->assertContains('forbidden_behaviour:human_dependent_steady_state', $verdict['violations']);
        $this->assertContains('restore_autonomous_origination', $verdict['required_actions']);
    }

    // ── value score guard ─────────────────────────────────────────────────────

    public function test_value_score_below_minimum_violates_policy(): void
    {
        $policy = $this->compiler->compile(['target_quota' => 5, 'min_value_score' => 0.7]);

        $verdict = $this->compiler->evaluate($policy, [
            'verified_count' => 5,
            'value_score' => 0.3,
        ]);

        $this->assertFalse($verdict['can_stop']);
        $violationStr = implode(' ', $verdict['violations']);
        $this->assertStringContainsString('value_score_below_minimum', $violationStr);
        $this->assertContains('raise_value_bar_before_continuing', $verdict['required_actions']);
    }

    // ── verdict schema ────────────────────────────────────────────────────────

    public function test_evaluate_verdict_has_required_keys(): void
    {
        $policy = $this->compiler->compile(['target_quota' => 10]);
        $verdict = $this->compiler->evaluate($policy, ['verified_count' => 10]);

        foreach (['schema', 'can_stop', 'violations', 'required_actions', 'stop_reason'] as $key) {
            $this->assertArrayHasKey($key, $verdict, "verdict must contain {$key}");
        }
        $this->assertSame(AtlasExternalBrainRunPolicyCompiler::SCHEMA_VERDICT, $verdict['schema']);
    }

    // ── non-stalled quota shortfall ───────────────────────────────────────────

    public function test_non_stalled_quota_shortfall_requires_continue_searching(): void
    {
        $policy = $this->compiler->compile(['target_quota' => 100]);

        $verdict = $this->compiler->evaluate($policy, [
            'verified_count' => 10,
            'stalled' => false,
            'honest_exhausted' => false,
        ]);

        $this->assertFalse($verdict['can_stop']);
        $this->assertContains('continue_searching', $verdict['required_actions']);
    }
}
