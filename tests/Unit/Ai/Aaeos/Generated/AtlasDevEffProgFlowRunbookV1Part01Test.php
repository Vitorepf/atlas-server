<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDevEffProgFlowRunbookV1Part01Service;
use Tests\TestCase;

/**
 * Pins the orchestration invariants from Atlas Dev Efficient Programming Flow
 * Runbook v1 · Parte 1 (§1 slice order + activation gate + rollout, §1.1
 * milestones + surface-agnostic core, §3 reuse contract). Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-01.md
 */
class AtlasDevEffProgFlowRunbookV1Part01Test extends TestCase
{
    private function service(): AtlasDevEffProgFlowRunbookV1Part01Service
    {
        return new AtlasDevEffProgFlowRunbookV1Part01Service;
    }

    /**
     * §1 rigid slice order (doc lines 127-135): the build starts at Fatia 0;
     * after Fatia 1 (DoD green) the next slice is the "1.5" half-step; a red DoD
     * blocks advancing ("Fatia nao comeca sem DoD anterior verde"); and after
     * Fatia 5 the team encerra — there is no Fatia 6.
     */
    public function test_slice_order_is_rigid_and_blocks_on_red_dod(): void
    {
        $service = $this->service();

        // No current slice -> start at 0.
        $start = $service->nextSlice(null, false);
        $this->assertSame('0', $start['next_slice']);
        $this->assertTrue($start['can_advance']);

        // 1 (green) -> 1.5, faithfully representing the half-step.
        $afterOne = $service->nextSlice('1', true);
        $this->assertSame('1.5', $afterOne['next_slice']);
        $this->assertFalse($afterOne['done']);

        // 1.5 (green) -> 2.
        $this->assertSame('2', $service->nextSlice('1.5', true)['next_slice']);

        // Red DoD blocks the very next slice; nothing is unlocked.
        $blocked = $service->nextSlice('2', false);
        $this->assertFalse($blocked['can_advance']);
        $this->assertNull($blocked['next_slice']);
        $this->assertSame('previous_dod_not_green', $blocked['reason']);

        // 5 (green) is terminal: done, no Fatia 6 invented.
        $afterFive = $service->nextSlice('5', true);
        $this->assertTrue($afterFive['done']);
        $this->assertNull($afterFive['next_slice']);
        $this->assertSame('fatia_5_complete_team_encerra', $afterFive['reason']);
    }

    /**
     * §1 activation gate (doc line 141): the efficient flow supersedes classic
     * `programming.dev` only when an efficient flag is on AND a workspace is
     * present AND the surface is supported. run implies the full plan→run path;
     * plan-only otherwise. Any missing precondition falls back to the classic
     * flow and names what blocked it.
     */
    public function test_flow_activation_gate_supersedes_classic_only_when_all_conditions_met(): void
    {
        $service = $this->service();

        // plan on, workspace + surface ok -> efficient plan-only, core returns PlanOnlyResult.
        $planOnly = $service->resolveFlow(true, false, true, true);
        $this->assertSame('atlas_dev', $planOnly['flow']);
        $this->assertSame('plan_only', $planOnly['efficient_mode']);
        $this->assertSame('PlanOnlyResult', $planOnly['core_result_type']);

        // run on -> full run path, core returns PatchResult.
        $run = $service->resolveFlow(true, true, true, true);
        $this->assertSame('run', $run['efficient_mode']);
        $this->assertSame('PatchResult', $run['core_result_type']);

        // No workspace -> classic flow, blocked_by names it.
        $noWorkspace = $service->resolveFlow(true, false, false, true);
        $this->assertSame('programming.dev', $noWorkspace['flow']);
        $this->assertNull($noWorkspace['efficient_mode']);
        $this->assertContains('workspace_missing', $noWorkspace['blocked_by']);

        // Both flags off -> classic flow.
        $noFlags = $service->resolveFlow(false, false, true, true);
        $this->assertSame('programming.dev', $noFlags['flow']);
        $this->assertContains('no_efficient_flag_enabled', $noFlags['blocked_by']);
    }

    /**
     * §1 production rollout order (doc line 141): in prod both flags start false;
     * the only legal first move is enabling plan, and run may be enabled only once
     * plan is already on. Enabling run before plan is rejected.
     */
    public function test_rollout_requires_plan_before_run(): void
    {
        $service = $this->service();

        $bothOff = ['plan_enabled' => false, 'run_enabled' => false];

        // Enabling run first is illegal.
        $runFirst = $service->evaluateRolloutToggle($bothOff, 'run_enabled', true);
        $this->assertFalse($runFirst['allowed']);
        $this->assertSame('run_requires_plan_enabled_first', $runFirst['reason']);
        // Rejected toggle does not mutate state.
        $this->assertFalse($runFirst['resulting_state']['run_enabled']);

        // Enabling plan first is legal.
        $planFirst = $service->evaluateRolloutToggle($bothOff, 'plan_enabled', true);
        $this->assertTrue($planFirst['allowed']);
        $this->assertTrue($planFirst['resulting_state']['plan_enabled']);

        // With plan already on, enabling run is now legal.
        $runAfterPlan = $service->evaluateRolloutToggle(
            ['plan_enabled' => true, 'run_enabled' => false],
            'run_enabled',
            true,
        );
        $this->assertTrue($runAfterPlan['allowed']);
        $this->assertTrue($runAfterPlan['resulting_state']['run_enabled']);
    }

    /**
     * §1.1 vertical-delivery milestones (doc lines 147-153): each Marco is
     * reachable only when every cumulative slice it requires is green. Marco 1 =
     * Foundation (0+1+1.5); Marco 2 additionally needs slice 2.
     */
    public function test_milestones_require_their_cumulative_slice_set(): void
    {
        $service = $this->service();

        // Marco 1 reachable with the three foundation slices green.
        $m1 = $service->evaluateMilestone(1, ['0', '1', '1.5']);
        $this->assertTrue($m1['reachable']);
        $this->assertSame([], $m1['missing']);
        $this->assertSame(['0', '1', '1.5'], $m1['required']);

        // Marco 2 not reachable yet: slice 2 is still missing.
        $m2 = $service->evaluateMilestone(2, ['0', '1', '1.5']);
        $this->assertFalse($m2['reachable']);
        $this->assertContains('2', $m2['missing']);

        // Marco 2 reachable once slice 2 is green too.
        $m2ok = $service->evaluateMilestone(2, ['0', '1', '1.5', '2']);
        $this->assertTrue($m2ok['reachable']);

        // Marco 5 requires the full set.
        $this->assertCount(7, $service->evaluateMilestone(5, [])['required']);
    }

    /**
     * §1.1 surface-agnostic core (doc line 155): the core consumes an
     * OperationEnvelope and returns ONLY PlanOnlyResult|PatchResult, never a
     * surface name. coreResultType maps each mode to its single legal type.
     */
    public function test_core_is_surface_agnostic_with_two_result_types(): void
    {
        $service = $this->service();

        $plan = $service->coreResultType('plan_only');
        $this->assertSame('PlanOnlyResult', $plan['result_type']);
        $this->assertTrue($plan['surface_agnostic']);

        $run = $service->coreResultType('run');
        $this->assertSame('PatchResult', $run['result_type']);

        $unknown = $service->coreResultType('something_else');
        $this->assertNull($unknown['result_type']);
        $this->assertSame('unknown_mode', $unknown['reason']);
    }

    /**
     * §3 reuse-mandatory (doc lines 187-191): a proposed new service is rejected
     * when it substitutes a reuse-target, when its name is a version-bump twin
     * (…V2 / …ServiceV2), or when it re-introduces the workflow driver under a
     * different name. A genuine new, non-duplicating helper is allowed.
     */
    public function test_reuse_contract_blocks_duplicates_v2_and_second_drivers(): void
    {
        $service = $this->service();

        // Version-bump twin of the driver: rejected on two counts.
        $v2 = $service->evaluateNewService('AtlasDevWorkflowServiceV2', null, true);
        $this->assertFalse($v2['allowed']);
        $this->assertContains('version_bump_of_existing_service', $v2['violations']);
        $this->assertContains('second_driver_same_function', $v2['violations']);

        // Substituting a reuse-target is rejected.
        $sub = $service->evaluateNewService('NewOrchestrator', 'AtlasProgrammingOrchestrator', false);
        $this->assertFalse($sub['allowed']);
        $this->assertContains('substitutes_reuse_target', $sub['violations']);

        // A second driver under a different name (not a V-bump) is still rejected.
        $secondDriver = $service->evaluateNewService('AtlasDevFlowDriver', null, true);
        $this->assertFalse($secondDriver['allowed']);
        $this->assertContains('second_driver_same_function', $secondDriver['violations']);

        // A genuine, non-duplicating helper is allowed.
        $ok = $service->evaluateNewService('AtlasDevDiscoveryProjection', null, false);
        $this->assertTrue($ok['allowed']);
        $this->assertSame([], $ok['violations']);
    }
}
