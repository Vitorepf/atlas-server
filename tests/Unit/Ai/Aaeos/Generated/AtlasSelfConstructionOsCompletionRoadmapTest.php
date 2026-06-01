<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSelfConstructionOsCompletionRoadmapService;
use Tests\TestCase;

final class AtlasSelfConstructionOsCompletionRoadmapTest extends TestCase
{
    private AtlasSelfConstructionOsCompletionRoadmapService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasSelfConstructionOsCompletionRoadmapService();
    }

    public function testPhaseLadderIsTheSixDocumentedPhasesInOrder(): void
    {
        $keys = array_column(AtlasSelfConstructionOsCompletionRoadmapService::PHASES, 'key');

        $this->assertSame([
            'contract_complete',
            'certification_complete',
            'dry_run_pilot_complete',
            'runtime_pilot_complete',
            'multi_agent_execution_complete',
            'self_programming_ready',
        ], $keys);

        // Section 9 maturity floor: runtime safety is all-false today, no real runtime.
        $maturity = $this->service->maturity();
        $this->assertTrue($maturity['runtime_safety_all_false']);
        $this->assertFalse($maturity['real_runtime_exists']);
    }

    public function testSkippingAPhaseIsForbidden(): void
    {
        // Contract Complete -> Dry-Run Pilot skips Certification Complete.
        $verdict = $this->service->evaluateAdvancement('contract_complete', 'dry_run_pilot_complete');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame('rejected', $verdict['verdict']);
        $this->assertSame('forbidden_phase_skip', $verdict['reason']);
    }

    public function testLegalNextAdvanceRequiresPriorPhaseComplete(): void
    {
        // Without Contract Complete done, advancing into Certification Complete is blocked.
        $blocked = $this->service->evaluateAdvancement('contract_complete', 'certification_complete', []);
        $this->assertFalse($blocked['allowed']);
        $this->assertSame('blocked', $blocked['verdict']);
        $this->assertSame('prior_phase_incomplete:contract_complete', $blocked['reason']);

        // With Contract Complete done, the same single-step advance is allowed.
        $allowed = $this->service->evaluateAdvancement('contract_complete', 'certification_complete', ['contract_complete']);
        $this->assertTrue($allowed['allowed']);
        $this->assertSame('allowed', $allowed['verdict']);
    }

    public function testPhaseSixIsNotACurrentAdvancementTarget(): void
    {
        // Phase 5 -> Phase 6 is the immediate next step, but Phase 6 is "future, not current".
        $verdict = $this->service->evaluateAdvancement(
            'multi_agent_execution_complete',
            'self_programming_ready',
            ['contract_complete', 'certification_complete', 'dry_run_pilot_complete', 'runtime_pilot_complete', 'multi_agent_execution_complete'],
        );

        $this->assertFalse($verdict['allowed']);
        $this->assertSame('target_phase_not_a_current_target', $verdict['reason']);
    }

    public function testRegressionForcesMandatoryReturnToEarlierPhase(): void
    {
        // Sitting in Phase 3, a Phase 1 regression forces a return to Phase 1.
        $regress = $this->service->applyRegression('dry_run_pilot_complete', 'contract_complete');
        $this->assertTrue($regress['forced_return']);
        $this->assertSame('contract_complete', $regress['resulting_phase']);

        // A "regression" pointing forward is not a regression and does not roll back.
        $noop = $this->service->applyRegression('contract_complete', 'dry_run_pilot_complete');
        $this->assertFalse($noop['forced_return']);
        $this->assertSame('contract_complete', $noop['resulting_phase']);
    }

    public function testPhaseExitBlockedByMissingDossierAndRuntimeSafetyFalse(): void
    {
        // Everything aligned EXCEPT release dossier present, and runtime safety reported false.
        $blocked = $this->service->evaluatePhaseExit('contract_complete', [
            'docs_aligned' => true,
            'code_aligned' => true,
            'tests_aligned' => true,
            'evidence_aligned' => true,
            'drift_checks_aligned' => true,
            'release_dossier_ref_present' => false,
            'runtime_safety_all_false' => false,
        ]);

        $this->assertFalse($blocked['may_exit']);
        $this->assertContains('no_phase_exit_over_missing_release_dossier_reference', $blocked['blockers']);
        $this->assertContains('no_phase_exits_with_runtime_safety_all_false_false', $blocked['blockers']);

        // Fully aligned exit with runtime safety all-false is permitted.
        $ok = $this->service->evaluatePhaseExit('contract_complete', [
            'docs_aligned' => true,
            'code_aligned' => true,
            'tests_aligned' => true,
            'evidence_aligned' => true,
            'drift_checks_aligned' => true,
            'release_dossier_ref_present' => true,
            'runtime_safety_all_false' => true,
        ]);

        $this->assertTrue($ok['may_exit']);
        $this->assertSame([], $ok['blockers']);
    }
}
