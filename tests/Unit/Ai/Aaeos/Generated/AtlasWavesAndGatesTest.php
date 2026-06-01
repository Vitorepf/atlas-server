<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasWavesAndGatesService;
use Tests\TestCase;

/**
 * Pins the documented Legacy Cleanup Waves And Gates rules: the eight ordered
 * waves (0 → 1 → 2 → 2.5 → 3 → 4 → 5), each wave's named Gate, the two
 * frontmatter invariants (runtime-out-of-scope-unless-declared, reversible),
 * sequential advancement and the strict four-step Rollback order.
 *
 * @see docs/engineering-knowledge-base/legacy-cleanup/waves-and-gates.md
 */
class AtlasWavesAndGatesTest extends TestCase
{
    private function service(): AtlasWavesAndGatesService
    {
        return new AtlasWavesAndGatesService();
    }

    /**
     * Wave 0 with its gate evidence satisfied passes and advances to Wave 1.
     * Doc gate: "the session can answer which doc wins in conflict?".
     */
    public function test_wave_0_gate_satisfied_advances_to_wave_1(): void
    {
        $r = $this->service()->evaluateGate([
            'wave' => '0',
            'conflict_winner_decided' => true,
        ]);

        $this->assertSame('pass', $r['verdict']);
        $this->assertTrue($r['may_advance']);
        $this->assertSame('1', $r['next_wave']);
        $this->assertFalse($r['pipeline_complete']);
        $this->assertSame('advance_to_next_wave', $r['required_next_action']);
        $this->assertSame([], $r['blockers']);
    }

    /**
     * A wave whose gate evidence is missing is blocked and stays on the same
     * wave — it may NOT advance.
     */
    public function test_wave_blocked_when_gate_evidence_missing(): void
    {
        $r = $this->service()->evaluateGate([
            'wave' => '2',
            'no_new_master_flow' => false,
        ]);

        $this->assertSame('blocked', $r['verdict']);
        $this->assertFalse($r['may_advance']);
        $this->assertSame('2', $r['next_wave']);
        $this->assertContains('gate_evidence_missing:no_new_master_flow', $r['blockers']);
        $this->assertSame('stay_and_satisfy_gate', $r['required_next_action']);
    }

    /**
     * Frontmatter invariant: "Runtime changes are outside cleanup scope unless
     * explicitly declared." A wave that touched runtime without declaring it is
     * blocked even though its wave-specific gate evidence is otherwise satisfied.
     */
    public function test_undeclared_runtime_change_blocks_gate(): void
    {
        $r = $this->service()->evaluateGate([
            'wave' => '1',
            'within_line_limits_with_owner' => true,
            'runtime_touched' => true,
            'runtime_declared' => false,
        ]);

        $this->assertSame('blocked', $r['verdict']);
        $this->assertContains('runtime_touched_without_declaration', $r['blockers']);

        // The same wave passes once the runtime change is explicitly declared.
        $ok = $this->service()->evaluateGate([
            'wave' => '1',
            'within_line_limits_with_owner' => true,
            'runtime_touched' => true,
            'runtime_declared' => true,
        ]);
        $this->assertSame('pass', $ok['verdict']);
        $this->assertSame('2', $ok['next_wave']);
    }

    /**
     * Frontmatter invariant "small, reversible and validated": an irreversible
     * wave is blocked regardless of gate evidence.
     */
    public function test_irreversible_wave_is_blocked(): void
    {
        $r = $this->service()->evaluateGate([
            'wave' => '3',
            'redirect_points_to_replacement' => true,
            'reversible' => false,
        ]);

        $this->assertSame('blocked', $r['verdict']);
        $this->assertContains('wave_not_reversible', $r['blockers']);
    }

    /**
     * The fractional Wave 2.5 ("Normalize Domain Docs") is a first-class wave: it
     * gates on the domain staying below Kernel/Master/Pipeline and advances to
     * Wave 3 — NOT to "3" by rounding. The final Wave 5 completes the pipeline.
     */
    public function test_wave_2_5_advances_to_3_and_wave_5_completes_pipeline(): void
    {
        $half = $this->service()->evaluateGate([
            'wave' => '2.5',
            'domain_below_kernel_master_pipeline' => true,
        ]);
        $this->assertSame('pass', $half['verdict']);
        $this->assertSame('3', $half['next_wave']);
        $this->assertSame('Normalize Domain Docs', $half['wave_goal']);

        $final = $this->service()->evaluateGate([
            'wave' => '5',
            'provider_safe_explicit' => true,
        ]);
        $this->assertSame('pass', $final['verdict']);
        $this->assertNull($final['next_wave']);
        $this->assertTrue($final['pipeline_complete']);
        $this->assertFalse($final['may_advance']);
        $this->assertSame('cleanup_pipeline_complete', $final['required_next_action']);
    }

    /**
     * Transitions must be sequential: 0 -> 1 is legal; skipping 0 -> 2 is not,
     * and an unknown wave id is rejected.
     */
    public function test_transition_must_be_sequential(): void
    {
        $this->assertTrue($this->service()->validateTransition('0', '1')['legal']);
        $this->assertTrue($this->service()->validateTransition('2', '2.5')['legal']);

        $skip = $this->service()->validateTransition('0', '2');
        $this->assertFalse($skip['legal']);
        $this->assertSame('1', $skip['expected_next']);

        $this->assertFalse($this->service()->validateTransition('5', '6')['legal']);
        $this->assertFalse($this->service()->evaluateGate(['wave' => '9'])['gate_satisfied']);
    }

    /**
     * Rollback is a strict, ordered four-step sequence preserving history.
     * Doc order: revert cleanup patch, restore redirect/header, keep archived
     * source, record cause before retry.
     */
    public function test_rollback_plan_is_ordered_four_steps(): void
    {
        $plan = $this->service()->rollbackPlan(['cause' => 'authority confusion']);

        $this->assertSame(4, $plan['total_steps']);
        $this->assertTrue($plan['preserves_history']);
        $this->assertSame('revert_only_the_cleanup_patch', $plan['steps'][0]['step']);
        $this->assertSame(1, $plan['steps'][0]['order']);
        $this->assertSame('record_cause_before_retry', $plan['steps'][3]['step']);
        $this->assertSame('authority confusion', $plan['cause']);
    }
}
