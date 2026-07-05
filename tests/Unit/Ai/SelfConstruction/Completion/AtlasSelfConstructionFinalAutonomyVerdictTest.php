<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Completion;

use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionFinalAutonomyVerdict;
use Tests\TestCase;

final class AtlasSelfConstructionFinalAutonomyVerdictTest extends TestCase
{
    private function freshSoak(): array
    {
        return ['status' => 'pass', 'age_seconds' => 60, 'soak_run_hash' => 'abc123def'];
    }

    public function test_compose_does_not_mutate_its_input_arrays(): void
    {
        $audit = ['atlas_native' => true, 'blockers' => []];
        $transitionMap = ['replacements' => [], 'untransitioned' => []];
        $readinessPolicy = ['state' => 'ready', 'blockers' => []];
        $soak = $this->freshSoak();
        $auditBefore = $audit;
        $transitionMapBefore = $transitionMap;
        $readinessPolicyBefore = $readinessPolicy;
        $soakBefore = $soak;

        (new AtlasSelfConstructionFinalAutonomyVerdict)->compose($audit, $transitionMap, $readinessPolicy, soakEvidence: $soak);

        $this->assertSame($auditBefore, $audit);
        $this->assertSame($transitionMapBefore, $transitionMap);
        $this->assertSame($readinessPolicyBefore, $readinessPolicy);
        $this->assertSame($soakBefore, $soak);
    }

    public function test_complete_when_audit_native_no_untransitioned_and_ready(): void
    {
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'ready', 'blockers' => []],
            soakEvidence: $this->freshSoak(),
        );

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_COMPLETE, $verdict['verdict']);
        $this->assertSame([], $verdict['blockers']);
        $this->assertSame([], $verdict['next_atlas_actions']);
        $this->assertFalse($verdict['asks_for_human']);
    }

    public function test_incomplete_when_untransitioned_dependencies_remain(): void
    {
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            [
                'replacements' => [['step_id' => 'verify', 'task_fabric_action' => 'create_task_packets:run_verification']],
                'untransitioned' => [['step_id' => 'mystical_step', 'reason' => 'no_known_atlas_native_replacement']],
            ],
            ['state' => 'ready', 'blockers' => []],
        );

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_INCOMPLETE, $verdict['verdict']);
        $this->assertContains('untransitioned:mystical_step', $verdict['blockers']);
        $this->assertContains('extend_transition_map_for_unknown_steps', $verdict['next_atlas_actions']);
        $this->assertContains('create_task_packets:run_verification', $verdict['next_atlas_actions']);
        $this->assertFalse($verdict['asks_for_human']);
    }

    public function test_incomplete_when_readiness_is_hold(): void
    {
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'hold', 'blockers' => ['context_freshness_stale']],
        );

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_INCOMPLETE, $verdict['verdict']);
        $this->assertContains('readiness:hold', $verdict['blockers']);
        $this->assertContains('readiness_hold:context_freshness_stale', $verdict['blockers']);
    }

    public function test_unsafe_when_audit_not_atlas_native(): void
    {
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => false, 'blockers' => ['steady_state_non_atlas_dependency:verify:operator']],
            ['replacements' => [['step_id' => 'verify', 'task_fabric_action' => 'create_task_packets:run_verification']], 'untransitioned' => []],
            ['state' => 'ready', 'blockers' => []],
        );

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_UNSAFE, $verdict['verdict']);
        $this->assertContains('audit:steady_state_non_atlas_dependency:verify:operator', $verdict['blockers']);
        $this->assertContains('create_task_packets:run_verification', $verdict['next_atlas_actions']);
        $this->assertContains('route_atlas_native_replacement_capabilities', $verdict['next_atlas_actions']);
        $this->assertFalse($verdict['asks_for_human'], 'unsafe verdict MUST NOT request human rescue');
    }

    public function test_unsafe_when_readiness_blocked(): void
    {
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'blocked', 'blockers' => ['admission_failed']],
        );

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_UNSAFE, $verdict['verdict']);
        $this->assertContains('readiness:admission_failed', $verdict['blockers']);
    }

    public function test_incomplete_when_readiness_state_is_absent(): void
    {
        // readiness policy with no 'state' key — defaults to 'unknown', must NOT pass to COMPLETE
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['blockers' => []], // no 'state' key
        );

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_INCOMPLETE, $verdict['verdict']);
        $this->assertContains('readiness:unknown', $verdict['blockers'], 'absent readiness state must produce a blocker');
    }

    public function test_incomplete_when_any_required_capability_lane_is_missing(): void
    {
        $allLanes = array_fill_keys(AtlasSelfConstructionFinalAutonomyVerdict::REQUIRED_CAPABILITY_LANES, true);
        $allLanes['task_repair'] = false;

        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'ready', 'blockers' => []],
            $allLanes,
        );

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_INCOMPLETE, $verdict['verdict']);
        $this->assertContains('missing_capability_lane:task_repair', $verdict['blockers']);
        $this->assertContains('provision_missing_capability_lanes', $verdict['next_atlas_actions']);
        $this->assertLessThan(90, $verdict['score']);
        $this->assertFalse($verdict['asks_for_human']);
    }

    public function test_complete_with_score_at_least_90_when_all_capability_lanes_met(): void
    {
        $allLanes = array_fill_keys(AtlasSelfConstructionFinalAutonomyVerdict::REQUIRED_CAPABILITY_LANES, true);

        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'ready', 'blockers' => []],
            $allLanes,
            soakEvidence: $this->freshSoak(),
        );

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_COMPLETE, $verdict['verdict']);
        $this->assertSame([], $verdict['blockers']);
        $this->assertGreaterThanOrEqual(90, $verdict['score']);
    }

    public function test_score_below_90_when_partial_capability_lanes_met(): void
    {
        $lanes = array_fill_keys(AtlasSelfConstructionFinalAutonomyVerdict::REQUIRED_CAPABILITY_LANES, true);
        $lastLane = array_key_last($lanes);
        $lanes[$lastLane] = false; // 5/6 → floor(5/6*100) = 83

        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'ready', 'blockers' => []],
            $lanes,
        );

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_INCOMPLETE, $verdict['verdict']);
        $this->assertSame(83, $verdict['score']);
    }

    public function test_asks_for_human_is_always_false(): void
    {
        $svc = new AtlasSelfConstructionFinalAutonomyVerdict;
        foreach ([
            $svc->compose(['atlas_native' => true, 'blockers' => []], ['replacements' => [], 'untransitioned' => []], ['state' => 'ready']),
            $svc->compose(['atlas_native' => true, 'blockers' => []], ['replacements' => [], 'untransitioned' => [['step_id' => 'x']]], ['state' => 'ready']),
            $svc->compose(['atlas_native' => false, 'blockers' => ['b']], ['replacements' => [], 'untransitioned' => []], ['state' => 'blocked']),
        ] as $verdict) {
            $this->assertFalse($verdict['asks_for_human']);
        }
    }

    // ── evidence_refs + readiness_95_blockers ─────────────────────────────────

    private function allTrue(): array
    {
        return array_fill_keys(AtlasSelfConstructionFinalAutonomyVerdict::REQUIRED_CAPABILITY_LANES, true);
    }

    private function allEvidence(): array
    {
        return array_fill_keys(AtlasSelfConstructionFinalAutonomyVerdict::REQUIRED_CAPABILITY_LANES, ['evidence-ref-1']);
    }

    public function test_true_boolean_without_evidence_refs_is_insufficient_for_complete(): void
    {
        $evidence = $this->allEvidence();
        $evidence['self_recovery'] = [];

        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'ready', 'blockers' => []],
            $this->allTrue(),
            $evidence,
        );

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_INCOMPLETE, $verdict['verdict']);
        $this->assertContains('missing_evidence_for_lane:self_recovery', $verdict['blockers']);
        $this->assertFalse($verdict['asks_for_human']);
    }

    public function test_complete_requires_all_lanes_with_evidence_when_evidence_tracking_active(): void
    {
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'ready', 'blockers' => []],
            $this->allTrue(),
            $this->allEvidence(),
            soakEvidence: $this->freshSoak(),
        );

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_COMPLETE, $verdict['verdict']);
        $this->assertSame([], $verdict['blockers']);
    }

    public function test_readiness_95_blockers_keyed_by_missing_lane(): void
    {
        $lanes = $this->allTrue();
        $lanes['frontier_import'] = false;

        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'ready', 'blockers' => []],
            $lanes,
        );

        $blocker = $verdict['readiness_95_blockers'][0] ?? [];
        $this->assertSame('frontier_import', $blocker['lane']);
        $this->assertSame('missing_lane', $blocker['type']);
        $this->assertStringContainsString('frontier_import', $blocker['next_action']);
    }

    public function test_readiness_95_blockers_keyed_by_missing_evidence(): void
    {
        $evidence = $this->allEvidence();
        $evidence['compounding'] = [];

        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'ready', 'blockers' => []],
            $this->allTrue(),
            $evidence,
        );

        $byLane = array_column($verdict['readiness_95_blockers'], null, 'lane');
        $this->assertArrayHasKey('compounding', $byLane);
        $this->assertSame('missing_evidence', $byLane['compounding']['type']);
        $this->assertStringContainsString('compounding', $byLane['compounding']['next_action']);
    }

    public function test_readiness_95_blockers_empty_on_complete_verdict(): void
    {
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'ready', 'blockers' => []],
        );

        $this->assertSame([], $verdict['readiness_95_blockers']);
    }

    public function test_next_action_present_for_each_readiness_95_blocker(): void
    {
        $lanes = $this->allTrue();
        $lanes['task_repair'] = false;
        $lanes['muscle_feedback_learning'] = false;

        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'ready', 'blockers' => []],
            $lanes,
        );

        foreach ($verdict['readiness_95_blockers'] as $b) {
            $this->assertArrayHasKey('next_action', $b);
            $this->assertNotEmpty($b['next_action']);
        }
        $this->assertCount(2, $verdict['readiness_95_blockers']);
    }

    public function test_backward_compat_no_evidence_param_still_admits_complete_with_all_true_lanes(): void
    {
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'ready', 'blockers' => []],
            $this->allTrue(),
            soakEvidence: $this->freshSoak(),
        );

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_COMPLETE, $verdict['verdict']);
    }

    // ── regression_facts gate ─────────────────────────────────────────────────

    public function test_regression_not_passed_prevents_complete(): void
    {
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'ready', 'blockers' => []],
            [],
            [],
            ['status' => 'fail'],
        );

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_INCOMPLETE, $verdict['verdict']);
        $this->assertContains('regression_not_passed:fail', $verdict['blockers']);
        $this->assertContains('resolve_regression_failures_before_final_ready', $verdict['next_atlas_actions']);
        $this->assertFalse($verdict['asks_for_human']);
    }

    public function test_regression_pending_prevents_complete(): void
    {
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'ready', 'blockers' => []],
            [],
            [],
            ['status' => 'pending'],
        );

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_INCOMPLETE, $verdict['verdict']);
        $this->assertContains('regression_not_passed:pending', $verdict['blockers']);
    }

    public function test_regression_pass_allows_complete_verdict(): void
    {
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'ready', 'blockers' => []],
            [],
            [],
            ['status' => 'pass'],
            soakEvidence: $this->freshSoak(),
        );

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_COMPLETE, $verdict['verdict']);
        $this->assertSame([], $verdict['blockers']);
    }

    public function test_omitting_regression_facts_does_not_block_complete(): void
    {
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'ready', 'blockers' => []],
            soakEvidence: $this->freshSoak(),
        );

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_COMPLETE, $verdict['verdict']);
    }

    // ── next_evidence_demands ─────────────────────────────────────────────────

    public function test_next_evidence_demands_present_in_output(): void
    {
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'ready', 'blockers' => []],
        );

        $this->assertArrayHasKey('next_evidence_demands', $verdict);
        $this->assertIsArray($verdict['next_evidence_demands']);
    }

    public function test_regression_failure_surfaces_in_next_evidence_demands(): void
    {
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'ready', 'blockers' => []],
            [],
            [],
            ['status' => 'fail'],
        );

        $this->assertContains('provide_regression_test_results_with_status_pass', $verdict['next_evidence_demands']);
    }

    public function test_missing_lane_surfaces_in_next_evidence_demands(): void
    {
        $lanes = $this->allTrue();
        $lanes['compounding'] = false;

        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'ready', 'blockers' => []],
            $lanes,
        );

        $this->assertContains('provision_capability_lane:compounding', $verdict['next_evidence_demands']);
    }

    // ── worker-feed continuity evidence ──────────────────────────────────────

    private function readyArgs(): array
    {
        return [
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'ready', 'blockers' => []],
        ];
    }

    public function test_refuses_final_autonomy_when_worker_feed_evidence_missing_age(): void
    {
        [$audit, $transition, $readiness] = $this->readyArgs();
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            $audit, $transition, $readiness, [], [], [],
            ['claimable_per_active_worker' => 10.0, 'worker_feed_floor' => 2.0],
            $this->freshSoak(),
        );

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_INCOMPLETE, $verdict['verdict']);
        $this->assertContains('worker_feed_evidence:missing_age', $verdict['blockers']);
    }

    public function test_refuses_final_autonomy_when_worker_feed_evidence_stale(): void
    {
        [$audit, $transition, $readiness] = $this->readyArgs();
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            $audit, $transition, $readiness, [], [], [],
            ['age_seconds' => 9999, 'claimable_per_active_worker' => 10.0, 'worker_feed_floor' => 2.0],
            $this->freshSoak(),
        );

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_INCOMPLETE, $verdict['verdict']);
        $this->assertContains('worker_feed_evidence:stale', $verdict['blockers']);
    }

    public function test_refuses_final_autonomy_when_worker_feed_below_floor_and_not_repaired(): void
    {
        [$audit, $transition, $readiness] = $this->readyArgs();
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            $audit, $transition, $readiness, [], [], [],
            ['age_seconds' => 60, 'claimable_per_active_worker' => 1.0, 'worker_feed_floor' => 2.0],
            $this->freshSoak(),
        );

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_INCOMPLETE, $verdict['verdict']);
        $this->assertContains('worker_feed_evidence:below_floor', $verdict['blockers']);
        $this->assertContains('refresh_worker_feed_continuity_evidence', $verdict['next_atlas_actions']);
    }

    public function test_accepts_final_autonomy_with_fresh_healthy_floor_metrics(): void
    {
        [$audit, $transition, $readiness] = $this->readyArgs();
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            $audit, $transition, $readiness, [], [], [],
            ['age_seconds' => 60, 'claimable_per_active_worker' => 10.0, 'worker_feed_floor' => 2.0],
            $this->freshSoak(),
        );

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_COMPLETE, $verdict['verdict']);
    }

    public function test_accepts_final_autonomy_with_repaired_no_claimable_task_receipt_despite_thin_floor(): void
    {
        [$audit, $transition, $readiness] = $this->readyArgs();
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            $audit, $transition, $readiness, [], [], [],
            ['age_seconds' => 60, 'claimable_per_active_worker' => 0.5, 'worker_feed_floor' => 2.0, 'no_claimable_task_repaired' => true],
            $this->freshSoak(),
        );

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_COMPLETE, $verdict['verdict']);
    }

    public function test_omitting_worker_feed_evidence_does_not_block_complete(): void
    {
        [$audit, $transition, $readiness] = $this->readyArgs();
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose($audit, $transition, $readiness, soakEvidence: $this->freshSoak());

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_COMPLETE, $verdict['verdict']);
    }

    // ── AC: soak evidence is mandatory ────────────────────────────────────────

    public function test_ready_readiness_with_no_soak_evidence_returns_incomplete_with_missing_soak_evidence(): void
    {
        [$audit, $transition, $readiness] = $this->readyArgs();
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose($audit, $transition, $readiness);

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_INCOMPLETE, $verdict['verdict']);
        $this->assertContains('soak_evidence:missing_soak_evidence', $verdict['blockers']);
    }

    public function test_stale_soak_evidence_returns_incomplete_and_emits_refresh_action(): void
    {
        [$audit, $transition, $readiness] = $this->readyArgs();
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            $audit, $transition, $readiness,
            soakEvidence: ['status' => 'pass', 'age_seconds' => 999999],
        );

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_INCOMPLETE, $verdict['verdict']);
        $this->assertContains('soak_evidence:stale_soak_evidence', $verdict['blockers']);
        $this->assertContains('refresh_soak_test_evidence', $verdict['next_atlas_actions']);
    }

    public function test_failed_regression_evidence_returns_incomplete_and_emits_refresh_action(): void
    {
        [$audit, $transition, $readiness] = $this->readyArgs();
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            $audit, $transition, $readiness, [], [],
            ['status' => 'fail'],
        );

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_INCOMPLETE, $verdict['verdict']);
        $this->assertContains('regression_not_passed:fail', $verdict['blockers']);
        $this->assertContains('resolve_regression_failures_before_final_ready', $verdict['next_atlas_actions']);
    }

    public function test_complete_requires_audit_transition_readiness_capability_regression_soak_and_worker_feed_all_passing(): void
    {
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'ready', 'blockers' => []],
            $this->allTrue(),
            $this->allEvidence(),
            ['status' => 'pass'],
            ['age_seconds' => 60, 'claimable_per_active_worker' => 10.0, 'worker_feed_floor' => 2.0],
            $this->freshSoak(),
        );

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_COMPLETE, $verdict['verdict']);
        $this->assertSame([], $verdict['blockers']);
    }

    public function test_missing_soak_run_hash_blocks_complete_even_with_pass_status(): void
    {
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'ready', 'blockers' => []],
            $this->allTrue(),
            $this->allEvidence(),
            ['status' => 'pass'],
            ['age_seconds' => 60, 'claimable_per_active_worker' => 10.0, 'worker_feed_floor' => 2.0],
            ['status' => 'pass', 'age_seconds' => 60], // no soak_run_hash
        );

        $this->assertNotSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_COMPLETE, $verdict['verdict']);
        $this->assertContains('soak_evidence:soak_evidence_missing_run_hash', $verdict['blockers']);
    }

    public function test_soak_run_hash_present_does_not_block(): void
    {
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'ready', 'blockers' => []],
            $this->allTrue(),
            $this->allEvidence(),
            ['status' => 'pass'],
            ['age_seconds' => 60, 'claimable_per_active_worker' => 10.0, 'worker_feed_floor' => 2.0],
            ['status' => 'pass', 'age_seconds' => 60, 'soak_run_hash' => 'real-hash'],
        );

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_COMPLETE, $verdict['verdict']);
        $this->assertSame([], $verdict['blockers']);
    }

    // ── AC: stale worker feed evidence prevents verdict=complete ──

    public function test_stale_worker_feed_evidence_prevents_complete_verdict(): void
    {
        [$audit, $transition, $readiness] = $this->readyArgs();
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            $audit, $transition, $readiness, [], [], [],
            ['age_seconds' => 9999, 'claimable_per_active_worker' => 10.0, 'worker_feed_floor' => 2.0],
            $this->freshSoak(),
        );

        $this->assertNotSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_COMPLETE, $verdict['verdict']);
        $this->assertContains('worker_feed_evidence:stale', $verdict['blockers']);
    }

    // ── AC: stale or missing soak evidence prevents verdict=complete ──

    public function test_missing_soak_evidence_prevents_complete_verdict(): void
    {
        [$audit, $transition, $readiness] = $this->readyArgs();
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose($audit, $transition, $readiness);

        $this->assertNotSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_COMPLETE, $verdict['verdict']);
        $this->assertContains('soak_evidence:missing_soak_evidence', $verdict['blockers']);
    }

    public function test_stale_soak_evidence_prevents_complete_verdict(): void
    {
        [$audit, $transition, $readiness] = $this->readyArgs();
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            $audit, $transition, $readiness,
            soakEvidence: ['status' => 'pass', 'age_seconds' => 999999, 'soak_run_hash' => 'abc'],
        );

        $this->assertNotSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_COMPLETE, $verdict['verdict']);
        $this->assertContains('soak_evidence:stale_soak_evidence', $verdict['blockers']);
    }

    // ── AC: verdict=complete requires all required capability lanes and no unsafe regression ──

    public function test_complete_requires_all_capability_lanes(): void
    {
        $lanes = $this->allTrue();
        $lanes['self_recovery'] = false;

        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'ready', 'blockers' => []],
            $lanes,
            soakEvidence: $this->freshSoak(),
        );

        $this->assertNotSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_COMPLETE, $verdict['verdict']);
        $this->assertContains('missing_capability_lane:self_recovery', $verdict['blockers']);
    }

    public function test_complete_prevented_by_unsafe_regression_facts(): void
    {
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'ready', 'blockers' => []],
            $this->allTrue(),
            $this->allEvidence(),
            ['status' => 'fail'],
            soakEvidence: $this->freshSoak(),
        );

        $this->assertNotSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_COMPLETE, $verdict['verdict']);
        $this->assertContains('regression_not_passed:fail', $verdict['blockers']);
    }

    public function test_complete_with_all_lanes_regression_pass_and_fresh_evidence(): void
    {
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'ready', 'blockers' => []],
            $this->allTrue(),
            $this->allEvidence(),
            ['status' => 'pass'],
            ['age_seconds' => 60, 'claimable_per_active_worker' => 10.0, 'worker_feed_floor' => 2.0],
            $this->freshSoak(),
        );

        $this->assertSame(AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_COMPLETE, $verdict['verdict']);
        $this->assertSame([], $verdict['blockers']);
    }
}
