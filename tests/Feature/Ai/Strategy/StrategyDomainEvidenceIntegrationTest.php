<?php

namespace Tests\Feature\Ai\Strategy;

use App\Models\AiCertification;
use App\Models\AiEvidencePack;
use App\Services\Ai\Evidence\CertificationRuntimeService;
use App\Services\Ai\Strategy\ExperimentPlanService;
use App\Services\Ai\Strategy\GTMPlanService;
use App\Services\Ai\Strategy\OpportunityRadarService;
use App\Services\Ai\Strategy\StrategyMemoService;
use App\Services\Ai\Strategy\StrategyRuntimeService;
use Tests\Concerns\CreatesEvidenceRuntimeTables;
use Tests\Concerns\CreatesStrategyRuntimeTables;
use Tests\TestCase;

class StrategyDomainEvidenceIntegrationTest extends TestCase
{
    use CreatesEvidenceRuntimeTables;
    use CreatesStrategyRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createEvidenceRuntimeTables();
        $this->createStrategyRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropStrategyRuntimeTables();
        $this->dropEvidenceRuntimeTables();
        parent::tearDown();
    }

    public function test_drive_opportunity_to_decision_attaches_evidence_pack_and_passes_certification(): void
    {
        /** @var StrategyRuntimeService $svc */
        $svc = app(StrategyRuntimeService::class);

        $result = $svc->driveOpportunityToDecision([
            'opportunity' => [
                'title' => 'Atlas-native onboarding accelerator',
                'problem' => 'Onboarding takes 90s; operators churn.',
                'icp' => 'Solo Atlas operators.',
                'pain' => 'Lose daily-driver flow before first command.',
                'urgency' => OpportunityRadarService::URGENCY_HIGH,
                'market' => ['size' => 'mid'],
                'competitors' => [['name' => 'manual install']],
                'risks' => ['adoption'],
            ],
            'venture_blueprint' => [
                'title' => 'Onboarding accelerator',
                'product' => ['name' => 'atlas-bootstrap-fast'],
                'gtm' => [
                    'positioning' => 'Fast Atlas bootstrap',
                    'icp' => 'Solo operators',
                    'channels' => ['cli'],
                    'pricing' => ['model' => 'bundled'],
                    'motion' => GTMPlanService::MOTION_PRODUCT_LED,
                ],
                'unit_economics' => ['cac' => 0, 'ltv' => 0],
                'hiring_plan' => ['phase_1' => ['atlas-ai']],
                'operations' => ['runbook' => 'atlas:bootstrap'],
            ],
            'experiment_plan' => [
                'hypothesis' => 'Bootstrap accelerator cuts time-to-first-command to <30s.',
                'hypothesis_kind' => ExperimentPlanService::KIND_FEASIBILITY,
                'success_metric' => ['name' => 'time_to_first_command_seconds', 'target' => '<30'],
                'design' => ['type' => 'cli_smoke', 'steps' => ['run', 'measure']],
            ],
            'experiment_result' => ['outcome' => 'positive', 'observed' => 'pass'],
            'experiment_decision' => [
                'kind' => ExperimentPlanService::DECISION_SCALE,
                'rationale' => 'feasibility proven',
            ],
            'strategy_memo' => [
                'title' => 'Decision: ship accelerator',
                'memo_kind' => StrategyMemoService::KIND_GO_NO_GO,
                'decision' => 'go',
                'rationale' => ['feasibility_proven', 'no_blockers'],
                'next_actions' => [['action' => 'ship_v1', 'owner' => 'atlas-ai']],
                'status' => StrategyMemoService::STATUS_DECIDED,
            ],
        ]);

        $this->assertTrue($result['evidence']['attached']);
        $this->assertSame(
            CertificationRuntimeService::STATUS_PASSED,
            $result['evidence']['certification_status'],
        );

        $pack = AiEvidencePack::query()->find($result['evidence']['evidence_pack_id']);
        $this->assertNotNull($pack);
        $this->assertNotEmpty($pack->evidence_hash);

        $cert = AiCertification::query()->find($result['evidence']['certification_id']);
        $this->assertNotNull($cert);
        $this->assertSame('domain_delivery', $cert->target_type);
        $this->assertNotEmpty($cert->evidence_refs);
    }

    public function test_drive_opportunity_skips_evidence_when_evidence_tables_missing(): void
    {
        // Tear down only Evidence tables; strategy tables remain.
        $this->dropEvidenceRuntimeTables();

        /** @var StrategyRuntimeService $svc */
        $svc = app(StrategyRuntimeService::class);

        $result = $svc->driveOpportunityToDecision([
            'opportunity' => [
                'title' => 'Tolerance smoke',
                'problem' => 'p', 'icp' => 'i', 'pain' => 'x',
                'urgency' => OpportunityRadarService::URGENCY_LOW,
                'market' => ['size' => 'small'],
                'competitors' => [['name' => 'n']],
                'risks' => ['r'],
            ],
            'venture_blueprint' => [
                'title' => 'b',
                'product' => ['name' => 'x'],
                'gtm' => [
                    'positioning' => 'p',
                    'icp' => 'i',
                    'channels' => ['cli'],
                    'pricing' => ['model' => 'bundled'],
                    'motion' => GTMPlanService::MOTION_PRODUCT_LED,
                ],
                'unit_economics' => ['cac' => 0, 'ltv' => 0],
                'hiring_plan' => ['phase_1' => ['a']],
                'operations' => ['runbook' => 'r'],
            ],
            'experiment_plan' => [
                'hypothesis' => 'h',
                'success_metric' => ['name' => 'm', 'target' => 't'],
                'design' => ['type' => 'ab_test'],
            ],
            'experiment_result' => ['outcome' => 'positive'],
            'experiment_decision' => ['kind' => ExperimentPlanService::DECISION_CONTINUE],
            'strategy_memo' => [
                'title' => 't',
                'memo_kind' => StrategyMemoService::KIND_OPPORTUNITY,
                'decision' => 'continue',
                'rationale' => ['x'],
                'next_actions' => [['action' => 'iterate']],
                'status' => StrategyMemoService::STATUS_DECIDED,
            ],
        ]);

        $this->assertFalse($result['evidence']['attached']);
        $this->assertStringContainsString('Evidence Runtime', $result['evidence']['detail']);

        // Restore evidence tables so tearDown's drop doesn't error.
        $this->createEvidenceRuntimeTables();
    }
}
