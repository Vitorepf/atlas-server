<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Completion;

use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionFinalAutonomyVerdict;
use Tests\TestCase;

final class AtlasSelfConstructionFinalAutonomyVerdictTest extends TestCase
{
    public function test_complete_when_audit_native_no_untransitioned_and_ready(): void
    {
        $verdict = (new AtlasSelfConstructionFinalAutonomyVerdict)->compose(
            ['atlas_native' => true, 'blockers' => []],
            ['replacements' => [], 'untransitioned' => []],
            ['state' => 'ready', 'blockers' => []],
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
}
