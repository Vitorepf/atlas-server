<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiScenarioSimulationHarnessService;
use Tests\TestCase;

final class AtlasAiScenarioSimulationHarnessTest extends TestCase
{
    private AtlasAiScenarioSimulationHarnessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasAiScenarioSimulationHarnessService();
    }

    /**
     * "Sem criterio de sucesso ou plano de observacao, a simulacao vira
     * exploration, nao prediction-grade."
     */
    public function testSeedPackWithoutSuccessCriteriaOrObservationPlanIsExploration(): void
    {
        // Everything filled EXCEPT the two prediction gates.
        $partial = [
            'question' => 'How will the audience react to the new price?',
            'business_context' => 'blackink',
            'consumer_domain' => 'marketing',
            'documents' => ['brief.md'],
            'fixed_facts' => ['price = 49'],
            'assumptions' => ['audience is price sensitive'],
            'variables' => ['discount'],
            'time_window' => '30d',
            'privacy' => 'no sensitive profiling',
            'provider_policy' => 'local-first',
        ];
        $graded = $this->service->gradeSeedPack($partial);
        $this->assertSame(AtlasAiScenarioSimulationHarnessService::GRADE_EXPLORATION, $graded['grade']);
        $this->assertFalse($graded['is_prediction_grade']);
        $this->assertSame(
            ['success_criteria', 'observation_plan'],
            $graded['missing_prediction_gates']
        );

        // Adding the two gates flips it to prediction grade.
        $complete = $partial + [
            'success_criteria' => 'CTR uplift >= 10%',
            'observation_plan' => 'analytics tracker for 30 days',
        ];
        $this->assertSame(
            AtlasAiScenarioSimulationHarnessService::GRADE_PREDICTION,
            $this->service->gradeSeedPack($complete)['grade']
        );
    }

    /**
     * "Rodar pelo menos: baseline, optimistic, pessimistic, contrarian/rival e
     * stress." A run set missing an archetype is not complete; a single-narrative
     * run set does not show the required dispersion.
     */
    public function testRunCoverageRequiresAllArchetypesAndDispersion(): void
    {
        $incomplete = $this->service->runCoverage(['baseline', 'optimistic', 'pessimistic']);
        $this->assertFalse($incomplete['complete']);
        $this->assertSame(['contrarian', 'stress'], $incomplete['missing']);

        $single = $this->service->runCoverage(['baseline']);
        $this->assertFalse($single['shows_dispersion']);

        $full = $this->service->runCoverage(['baseline', 'optimistic', 'pessimistic', 'contrarian', 'stress']);
        $this->assertTrue($full['complete']);
        $this->assertTrue($full['shows_dispersion']);
        $this->assertSame([], $full['missing']);
    }

    /**
     * Output Contract: every mandatory field present AND no certainty language
     * ("Nao usar linguagem de certeza como 'vai acontecer'").
     */
    public function testReportRejectsCertaintyLanguageAndMissingFields(): void
    {
        $fields = AtlasAiScenarioSimulationHarnessService::REPORT_FIELDS;

        // Build a fully-populated report.
        $report = [];
        foreach ($fields as $field) {
            $report[$field] = 'filled';
        }
        $this->assertTrue($this->service->gradeReport($report)['compliant']);

        // Inject forbidden certainty phrase -> non-compliant with violation.
        $report['executive_summary'] = 'This vai acontecer no matter what.';
        $bad = $this->service->gradeReport($report);
        $this->assertFalse($bad['compliant']);
        $this->assertContains('vai acontecer', $bad['certainty_violations']);

        // Remove a required field -> non-compliant with that field missing.
        $report['executive_summary'] = 'simulacao sugere um cenario plausivel';
        unset($report['falsification_criteria']);
        $missing = $this->service->gradeReport($report);
        $this->assertFalse($missing['compliant']);
        $this->assertContains('falsification_criteria', $missing['missing']);
        $this->assertSame([], $missing['certainty_violations']);
    }

    /**
     * Outcome Tracking Plan: missing any of the seven fields downgrades to
     * `storytelling_only` ("Sem esses campos, o Atlas nao aprende").
     */
    public function testOutcomeTrackingPlanNeedsAllSevenFields(): void
    {
        $plan = [
            'outcome_window' => '7d',
            'observable_metrics' => ['CTR', 'CPA'],
            'expected_ranges' => ['CTR' => '2-4%'],
            'leading_indicators' => ['comments'],
            'ground_truth_sources' => ['analytics'],
            'review_date' => '2026-07-01',
            // owner intentionally missing
        ];
        $graded = $this->service->gradeOutcomeTrackingPlan($plan);
        $this->assertSame(AtlasAiScenarioSimulationHarnessService::PLAN_STORYTELLING, $graded['grade']);
        $this->assertSame(['owner'], $graded['missing']);

        $plan['owner'] = 'marketing-curator';
        $this->assertSame(
            AtlasAiScenarioSimulationHarnessService::PLAN_PREDICTION,
            $this->service->gradeOutcomeTrackingPlan($plan)['grade']
        );
    }

    /**
     * Safety: "Simulacao nunca autoriza ordem, transacao, deploy, gasto
     * publicitario ou mudanca operacional sem Decision Receipt ... e approval."
     * Real-world actions are never authorized and require the full gate.
     * Evidence is required only at medium/high risk.
     */
    public function testRealWorldActionsAreNeverAuthorizedAndEvidenceTracksRisk(): void
    {
        foreach (['place_order', 'execute_transaction', 'deploy', 'spend_ad_budget', 'operational_change'] as $action) {
            $gate = $this->service->actionGate($action);
            $this->assertTrue($gate['is_real_world'], "$action should be real-world");
            $this->assertFalse($gate['authorized'], "$action must never be authorized by simulation");
            $this->assertContains('decision_receipt', $gate['requires']);
            $this->assertContains('human_approval', $gate['requires']);
        }

        // Analysis-only output is still never autonomously authorized.
        $analysis = $this->service->actionGate('summarize_findings');
        $this->assertFalse($analysis['is_real_world']);
        $this->assertFalse($analysis['authorized']);

        // Evidence Ledger required only for medium/high risk.
        $this->assertTrue($this->service->mustWriteEvidence('medium')['must_write_evidence']);
        $this->assertTrue($this->service->mustWriteEvidence('high')['must_write_evidence']);
        $this->assertFalse($this->service->mustWriteEvidence('low')['must_write_evidence']);
    }

    /**
     * Error Analysis taxonomy is a closed set: a documented category is accepted,
     * an unknown one is refused ("nao deve 'forcar' a historia").
     */
    public function testErrorTaxonomyIsClosedSet(): void
    {
        $known = $this->service->classifyError('overfit_narrative');
        $this->assertTrue($known['known']);
        $this->assertSame('overfit_narrative', $known['category']);

        $unknown = $this->service->classifyError('it_was_basically_right');
        $this->assertFalse($unknown['known']);
        $this->assertNull($unknown['category']);
    }

    /**
     * Definition-Of-Done rollup: an empty request is `exploration` and lists
     * every blocker; a fully-formed prediction-grade request passes.
     */
    public function testAssessRollupGatesPredictionGrade(): void
    {
        $empty = $this->service->assess([], [], [], [], 'medium');
        $this->assertSame(AtlasAiScenarioSimulationHarnessService::GRADE_EXPLORATION, $empty['grade']);
        $this->assertFalse($empty['is_prediction_grade']);
        $this->assertEqualsCanonicalizing(
            [
                'seed_pack_not_prediction_grade',
                'run_coverage_incomplete',
                'report_not_output_contract_compliant',
                'outcome_tracking_plan_incomplete',
            ],
            $empty['blockers']
        );

        $seed = [];
        foreach (AtlasAiScenarioSimulationHarnessService::SEED_PACK_FIELDS as $f) {
            $seed[$f] = 'x';
        }
        $report = [];
        foreach (AtlasAiScenarioSimulationHarnessService::REPORT_FIELDS as $f) {
            $report[$f] = 'simulacao sugere';
        }
        $plan = [];
        foreach (AtlasAiScenarioSimulationHarnessService::OUTCOME_TRACKING_FIELDS as $f) {
            $plan[$f] = 'x';
        }
        $runs = ['baseline', 'optimistic', 'pessimistic', 'contrarian', 'stress'];

        $full = $this->service->assess($seed, $runs, $report, $plan, 'high');
        $this->assertSame(AtlasAiScenarioSimulationHarnessService::GRADE_PREDICTION, $full['grade']);
        $this->assertTrue($full['is_prediction_grade']);
        $this->assertSame([], $full['blockers']);
        $this->assertTrue($full['evidence']['must_write_evidence']);
    }
}
