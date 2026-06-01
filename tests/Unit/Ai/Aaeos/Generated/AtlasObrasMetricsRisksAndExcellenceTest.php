<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasObrasMetricsRisksAndExcellenceService;
use Tests\TestCase;

final class AtlasObrasMetricsRisksAndExcellenceTest extends TestCase
{
    private AtlasObrasMetricsRisksAndExcellenceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasObrasMetricsRisksAndExcellenceService();
    }

    /**
     * Doc Quality Claim Rules: "Atlas may not claim an Obra is complete unless"
     * all 8 conditions hold. An empty state must deny the claim and list every
     * missing condition.
     */
    public function testEmptyStateDeniesCompleteClaimWithAllEightConditionsMissing(): void
    {
        $gate = $this->service->evaluateCompleteClaim([]);

        $this->assertFalse($gate['can_claim']);
        $this->assertSame(8, $gate['total_conditions']);
        $this->assertCount(8, $gate['missing']);
        $this->assertSame(0, $gate['satisfied_count']);
    }

    /**
     * The complete gate flips to ALLOWED only when every one of the 8 documented
     * conditions is true. Dropping a single condition must re-deny it.
     */
    public function testCompleteClaimRequiresAllEightConditions(): void
    {
        $full = [
            'output_exists' => true,
            'current_version_identified' => true,
            'required_gates_ran' => true,
            'critical_failures_resolved_or_accepted' => true,
            'key_decisions_recorded' => true,
            'evidence_exists' => true,
            'next_step_or_closure_explicit' => true,
            'learning_captured' => true,
        ];

        $this->assertTrue($this->service->evaluateCompleteClaim($full)['can_claim']);

        // Remove one documented condition -> claim must be denied.
        $missingEvidence = $full;
        $missingEvidence['evidence_exists'] = false;
        $denied = $this->service->evaluateCompleteClaim($missingEvidence);
        $this->assertFalse($denied['can_claim']);
        $this->assertSame(['evidence_exists'], $denied['missing']);
    }

    /**
     * Final Principle: "If the delivery does not become an asset, it has not
     * reached Foundry." Even with all 5 Foundry conditions satisfied, Foundry
     * must stay DENIED while the complete predecessor is not met.
     */
    public function testFoundryClaimDeniedWhenCompletePredecessorUnmet(): void
    {
        $foundryConditionsOnly = [
            'output_became_asset' => true,
            'asset_type_classified' => true,
            'dependency_or_portfolio_relation_exists' => true,
            'opportunity_cost_considered' => true,
            'continue_pause_kill_scale_decision_exists' => true,
        ];

        $gate = $this->service->evaluateFoundryClaim($foundryConditionsOnly);

        // All 5 Foundry conditions are satisfied...
        $this->assertSame([], $gate['missing']);
        // ...but the complete predecessor is not, so the claim is blocked.
        $this->assertFalse($gate['predecessor_satisfied']);
        $this->assertFalse($gate['can_claim']);
    }

    /**
     * Sovereign sits above complete + Foundry. With every documented condition
     * across all three gates satisfied, highest_claimable_maturity must resolve
     * to "sovereign".
     */
    public function testSovereignClaimAllowedWhenAllThreeGatesSatisfied(): void
    {
        $state = [
            // complete (8)
            'output_exists' => true,
            'current_version_identified' => true,
            'required_gates_ran' => true,
            'critical_failures_resolved_or_accepted' => true,
            'key_decisions_recorded' => true,
            'evidence_exists' => true,
            'next_step_or_closure_explicit' => true,
            'learning_captured' => true,
            // foundry (5)
            'output_became_asset' => true,
            'asset_type_classified' => true,
            'dependency_or_portfolio_relation_exists' => true,
            'opportunity_cost_considered' => true,
            'continue_pause_kill_scale_decision_exists' => true,
            // sovereign (5)
            'autonomy_impact_explicit' => true,
            'capital_impact_explicit' => true,
            'health_relationship_integrity_constraints_passed' => true,
            'success_metrics_exist' => true,
            'reversal_or_review_plan_exists' => true,
        ];

        $result = $this->service->evaluate($state);

        $this->assertTrue($result['claims']['sovereign']['can_claim']);
        $this->assertSame('sovereign', $result['highest_claimable_maturity']);
    }

    /**
     * Excellence Criteria: "Obras reaches state of the art when it combines"
     * all 10 criteria. 9/10 is NOT state of the art; 10/10 is, and scores 10.
     */
    public function testExcellenceIsStateOfTheArtOnlyWithAllTenCriteria(): void
    {
        $allCriteria = [
            'persistent_context_per_obra' => true,
            'agentic_execution' => true,
            'sources_and_evidence' => true,
            'traceable_decisions' => true,
            'quality_gates' => true,
            'repair_loops' => true,
            'versioning' => true,
            'real_outputs' => true,
            'strategic_portfolio' => true,
            'operator_autonomy_protection' => true,
        ];

        $full = $this->service->excellence($allCriteria);
        $this->assertTrue($full['state_of_the_art']);
        $this->assertSame(10, $full['score_out_of_10']);
        $this->assertSame(10, $full['total']);

        $nineOfTen = $allCriteria;
        $nineOfTen['operator_autonomy_protection'] = false;
        $partial = $this->service->excellence($nineOfTen);
        $this->assertFalse($partial['state_of_the_art']);
        $this->assertSame(['operator_autonomy_protection'], $partial['missing']);
    }

    /**
     * Main Risks register: the doc enumerates exactly 5 risks, each with at
     * least one mitigation. "Pretty Folder" is the strongest documented risk.
     */
    public function testRiskRegisterHasFiveDocumentedRisksWithMitigations(): void
    {
        $risks = $this->service->risks();

        $this->assertCount(5, $risks);

        $names = array_column($risks, 'name');
        $this->assertContains('Pretty Folder', $names);
        $this->assertContains('Renamed Chat', $names);

        foreach ($risks as $risk) {
            $this->assertNotEmpty($risk['mitigations']);
        }
    }

    /**
     * Metrics By Level: the doc defines L0..L5. Spot-check the documented metric
     * counts (L0 has 5, L5 has 8) and that an unknown level returns empty.
     */
    public function testLevelMetricsMatchDocumentedCounts(): void
    {
        $all = $this->service->levelMetrics();
        $this->assertSame(['L0', 'L1', 'L2', 'L3', 'L4', 'L5'], array_keys($all));

        $this->assertCount(5, $this->service->levelMetrics('L0'));
        $this->assertCount(8, $this->service->levelMetrics('L5'));
        $this->assertSame([], $this->service->levelMetrics('L9'));
    }
}
