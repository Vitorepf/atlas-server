<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAdvancedCapabilitiesBacklogService;
use Tests\TestCase;

final class AtlasAdvancedCapabilitiesBacklogTest extends TestCase
{
    private AtlasAdvancedCapabilitiesBacklogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasAdvancedCapabilitiesBacklogService();
    }

    public function testBacklogExposesTheEightDocumentedFamiliesWithTargets(): void
    {
        $families = $this->service->families();

        // The "Backlog Families" table has exactly eight rows.
        $this->assertSame(8, $families['count']);
        $this->assertCount(8, $families['families']);

        $ids = array_column($families['families'], 'id');
        $this->assertContains('zero_click_operations', $ids);
        $this->assertContains('tool_synthesis', $ids);
        $this->assertContains('swarms_councils', $ids);
        $this->assertContains('local_models', $ids);

        // Targets are carried verbatim from the doc.
        $byId = array_column($families['families'], 'target', 'id');
        $this->assertSame('optimize provider/model/cost per task', $byId['dynamic_compute_market']);
    }

    public function testAutonomyLadderIsOrderedLeastToMostPowerWithDocumentedBehavior(): void
    {
        $ladder = $this->service->ladder();

        // Five documented levels in increasing-power order.
        $this->assertSame(5, $ladder['count']);
        $this->assertSame(
            ['shadow', 'proposal', 'assisted', 'governed', 'critical'],
            array_column($ladder['levels'], 'level')
        );

        // Shadow may "observe and emit evidence only".
        $this->assertSame('observe and emit evidence only', $ladder['levels'][0]['allowed']);
        // Critical is "never automatic without explicit policy and human approval".
        $this->assertSame(
            'never automatic without explicit policy and human approval',
            $ladder['levels'][4]['allowed']
        );
    }

    public function testCriticalLevelNeverRunsAutomaticallyEvenWhenNotMutating(): void
    {
        // The doc: critical is "never automatic without explicit policy and human
        // approval" — so even a non-mutating critical action cannot auto-run.
        $critical = $this->service->classifyLevel('critical', false);

        $this->assertFalse($critical['may_run_automatically']);
        $this->assertTrue($critical['requires_human_approval']);
        $this->assertSame(
            'critical_never_automatic_requires_policy_and_human_approval',
            $critical['reason']
        );
    }

    public function testReversibleLevelsRunButMutationNeedsGovernedReceiptAndGates(): void
    {
        // Shadow/proposal/assisted are observational-or-reversible -> may run.
        $assisted = $this->service->classifyLevel('assisted', false);
        $this->assertTrue($assisted['may_run_automatically']);
        $this->assertFalse($assisted['requires_receipt_and_gates']);

        // A mutating action below the governed rank must NOT run and needs
        // governance (receipt + gates).
        $assistedMutating = $this->service->classifyLevel('assisted', true);
        $this->assertFalse($assistedMutating['may_run_automatically']);
        $this->assertTrue($assistedMutating['requires_human_approval']);
        $this->assertSame(
            'mutating_action_requires_governed_receipt_and_gates',
            $assistedMutating['reason']
        );

        // Governed executes "bounded tasks with receipt and gates".
        $governedMutating = $this->service->classifyLevel('governed', true);
        $this->assertTrue($governedMutating['requires_receipt_and_gates']);
        $this->assertFalse($governedMutating['requires_human_approval']);
    }

    public function testUnknownLevelDefaultsToCriticalMostRestrictive(): void
    {
        // A novel/unnamed capability must not leak past the ladder.
        $unknown = $this->service->classifyLevel('superpower', false);

        $this->assertFalse($unknown['known']);
        $this->assertSame(AtlasAdvancedCapabilitiesBacklogService::LEVEL_CRITICAL, $unknown['level']);
        $this->assertFalse($unknown['may_run_automatically']);
        $this->assertSame(
            'unknown_level_defaults_to_critical_human_approval',
            $unknown['reason']
        );
    }

    public function testToolSynthesisGateRequiresAllSevenRequirements(): void
    {
        // Six of seven present -> still blocked, missing one reported.
        $partial = $this->service->evaluateToolSynthesis([
            'purpose_and_owner' => true,
            'sandbox_execution' => true,
            'tests' => true,
            'security_scan' => true,
            'registry_entry' => true,
            'evidence_event' => true,
            // 'rollback_or_delete_path' missing
        ]);

        $this->assertSame(7, $partial['total_required']);
        $this->assertFalse($partial['promotable']);
        $this->assertSame(['rollback_or_delete_path'], $partial['missing']);
        $this->assertSame(
            'tool_synthesis_gate_requires_all_seven_requirements',
            $partial['reason']
        );

        // All seven present -> promotable.
        $full = $this->service->evaluateToolSynthesis([
            'purpose_and_owner' => true,
            'sandbox_execution' => true,
            'tests' => true,
            'security_scan' => true,
            'registry_entry' => true,
            'evidence_event' => true,
            'rollback_or_delete_path' => true,
        ]);

        $this->assertTrue($full['promotable']);
        $this->assertSame([], $full['missing']);
    }

    public function testSimulationWithoutCalibrationIsFiction(): void
    {
        // "Simulation without calibration becomes fiction."
        $uncalibrated = $this->service->evaluateSimulation(true, false);
        $this->assertTrue($uncalibrated['is_fiction']);
        $this->assertFalse($uncalibrated['trustworthy']);
        $this->assertSame('simulation_without_calibration_becomes_fiction', $uncalibrated['reason']);

        // Must "close the loop with real outcomes".
        $unclosed = $this->service->evaluateSimulation(false, true);
        $this->assertTrue($unclosed['is_fiction']);
        $this->assertSame('simulation_must_close_loop_with_real_outcomes', $unclosed['reason']);

        // Closed against real outcomes AND calibrated -> trustworthy.
        $good = $this->service->evaluateSimulation(true, true);
        $this->assertFalse($good['is_fiction']);
        $this->assertTrue($good['trustworthy']);
    }
}
