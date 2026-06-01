<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasLifecycleRunbookService;
use Tests\TestCase;

/**
 * Pins the executable contracts from the lifecycle runbook doc: the ordered
 * 11-step Product Lifecycle (strictly sequential, evidence-gated, current step =
 * earliest not-done), the 5-item AI Start Checklist, and the Failure Rule
 * (missing contract / stale blueprint / absent evidence / blocking gate =>
 * pause or repair, never claim completion). Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/engineering-blueprint/lifecycle-runbook.md
 */
class AtlasLifecycleRunbookTest extends TestCase
{
    private function service(): AtlasLifecycleRunbookService
    {
        return new AtlasLifecycleRunbookService;
    }

    /** Helper: mark a list of steps done WITH evidence (the only valid proof). */
    private function stepsDone(array $keys): array
    {
        $steps = [];
        foreach ($keys as $k) {
            $steps[$k] = ['done' => true, 'evidence' => ['receipt://' . $k]];
        }

        return $steps;
    }

    public function test_lifecycle_has_exactly_11_ordered_steps_prepare_to_memory_delta(): void
    {
        // Doc "Product Lifecycle" table: 11 rows, prepare blueprint .. memory delta.
        $keys = array_keys(AtlasLifecycleRunbookService::LIFECYCLE_STEPS);

        $this->assertCount(11, $keys);
        $this->assertSame('prepare_blueprint', $keys[0]);
        $this->assertSame('memory_delta', $keys[10]);
        // Mid-pipeline order is load-bearing (freeze before generate_tasks, etc).
        $this->assertSame('freeze_blueprint', $keys[3]);
        $this->assertSame('postgres_review', $keys[8]);
    }

    public function test_pipeline_is_sequential_current_step_is_the_earliest_not_done(): void
    {
        // First three steps proven; the lifecycle should point at the 4th
        // (freeze_blueprint) as the current/blocking step, not jump ahead.
        $r = $this->service()->evaluateLifecycle([
            'steps' => $this->stepsDone(['prepare_blueprint', 'create_blueprint', 'validate_coverage']),
        ]);

        $this->assertSame('in_progress', $r['verdict']);
        $this->assertFalse($r['is_complete']);
        $this->assertSame(3, $r['done_count']);
        $this->assertSame('freeze_blueprint', $r['current_step']);
        $this->assertFalse($r['may_promote_memory_delta']);
    }

    public function test_bare_boolean_true_is_not_evidence_so_step_is_not_done(): void
    {
        // Evidence gate (forbidden_changes): no readiness claim without
        // verifiable evidence. A plain `true` carries none.
        $bare = $this->service()->evaluateLifecycle([
            'steps' => ['prepare_blueprint' => true],
        ]);
        $this->assertSame(0, $bare['done_count']);
        $this->assertSame('prepare_blueprint', $bare['current_step']);

        // The same step WITH an evidence ref does count as done.
        $withEvidence = $this->service()->evaluateLifecycle([
            'steps' => ['prepare_blueprint' => ['done' => true, 'evidence' => 'docs/x.md']],
        ]);
        $this->assertSame(1, $withEvidence['done_count']);
        $this->assertSame('create_blueprint', $withEvidence['current_step']);
    }

    public function test_only_a_fully_proven_pipeline_completes_and_may_promote_memory_delta(): void
    {
        // All 11 steps done with evidence => complete + may promote memory delta.
        $all = $this->service()->evaluateLifecycle([
            'steps' => $this->stepsDone(array_keys(AtlasLifecycleRunbookService::LIFECYCLE_STEPS)),
        ]);

        $this->assertSame('complete', $all['verdict']);
        $this->assertTrue($all['is_complete']);
        $this->assertSame(11, $all['done_count']);
        $this->assertNull($all['current_step']);
        $this->assertTrue($all['may_promote_memory_delta']);
    }

    public function test_failure_rule_structural_conditions_force_pause_and_block_completion(): void
    {
        // Doc "Failure Rule": missing contract / stale blueprint / blocking gate
        // => pause, NOT claim completion.
        foreach (['missing_contract', 'stale_blueprint', 'blocking_gate'] as $cond) {
            $r = $this->service()->applyFailureRule([$cond => true]);

            $this->assertSame('pause', $r['verdict'], "condition {$cond} must pause");
            $this->assertFalse($r['may_claim_completion'], "condition {$cond} must block completion");
            $this->assertTrue($r['has_structural_failure']);
            $this->assertContains('pause:' . $cond, $r['reasons']);
        }
    }

    public function test_failure_rule_absent_evidence_only_forces_repair_not_pause(): void
    {
        // Doc "Failure Rule": absent evidence => repair (collect/attach it),
        // still NOT claim completion. No structural failure here.
        $r = $this->service()->applyFailureRule(['absent_evidence' => true]);

        $this->assertSame('repair', $r['verdict']);
        $this->assertFalse($r['may_claim_completion']);
        $this->assertFalse($r['has_structural_failure']);

        // No failure condition at all => the operation may complete.
        $clean = $this->service()->applyFailureRule([]);
        $this->assertSame('complete', $clean['verdict']);
        $this->assertTrue($clean['may_claim_completion']);
    }

    public function test_assess_reports_done_only_when_checklist_ready_lifecycle_complete_and_no_failure(): void
    {
        $allSteps = $this->stepsDone(array_keys(AtlasLifecycleRunbookService::LIFECYCLE_STEPS));
        $allChecklist = array_fill_keys(array_keys(AtlasLifecycleRunbookService::START_CHECKLIST), true);

        // Everything green => work_done true.
        $done = $this->service()->assess([
            'steps' => $allSteps,
            'start_checklist' => $allChecklist,
            'failure_signals' => [],
        ]);
        $this->assertTrue($done['work_done']);
        $this->assertSame('complete', $done['overall_state']);
        $this->assertTrue($done['may_claim_completion']);

        // Same proven pipeline, but a blocking gate appears => the Failure Rule
        // overrides: not done, overall_state falls back to the pause verdict.
        $blocked = $this->service()->assess([
            'steps' => $allSteps,
            'start_checklist' => $allChecklist,
            'failure_signals' => ['blocking_gate' => true],
        ]);
        $this->assertFalse($blocked['work_done']);
        $this->assertFalse($blocked['may_claim_completion']);
        $this->assertSame('pause', $blocked['overall_state']);
    }
}
