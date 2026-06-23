<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Campaign\BidStrategyDecider;
use App\Services\Ai\MarketingDomain\Campaign\CampaignEconomicsCalculator;
use App\Services\Ai\MarketingDomain\CampaignPlanService;
use App\Services\Ai\MarketingDomain\CopyBriefService;
use App\Services\Ai\MarketingDomain\Decision\MarketingVslDossierService;
use App\Services\Ai\MarketingDomain\Decision\OfferDoctorScorer;
use App\Services\Ai\MarketingDomain\Decision\VslAwarenessAlignmentAuditor;
use App\Services\Ai\MarketingDomain\Decision\VslPersuasionAuditService;
use App\Services\Ai\MarketingDomain\Decision\VslStructuralDiagnosisOrchestrator;
use App\Services\Ai\MarketingDomain\FunnelPlanService;
use App\Services\Ai\MarketingDomain\ICPPositioningService;
use PHPUnit\Framework\TestCase;

class MarketingVslDossierServiceTest extends TestCase
{
    private function dossier(): MarketingVslDossierService
    {
        return new MarketingVslDossierService(
            new CampaignEconomicsCalculator,
            new BidStrategyDecider,
            new VslPersuasionAuditService,
            new VslStructuralDiagnosisOrchestrator(
                new VslPersuasionAuditService,
                new VslAwarenessAlignmentAuditor,
                new OfferDoctorScorer,
            ),
            new CopyBriefService,
            new FunnelPlanService,
            new ICPPositioningService,
            new CampaignPlanService,
        );
    }

    private function strongVsl(): AiMarketingVslAsset
    {
        $transcript = strtolower(
            'What if I told you a shocking secret? You are tired of the struggle and pain. '.
            "It's not your fault — the real reason is hidden. The only breakthrough method, our mechanism, ".
            'is backed by a clinical study, university research, proven results, a doctor and scientist endorse it. '.
            'Order the package today, a free bonus gift. 60-day money-back guarantee, risk-free. '.
            'Limited supplies, hurry, deadline expires. Click the button below to order now. '.
            'P.S. you might be thinking it is too good — thousands of customers just like you joined us together.'
        );

        return new AiMarketingVslAsset([
            'campaign_ref' => 'OT169',
            'niche' => 'weight_loss',
            'transcript' => $transcript,
            'awareness_level' => 'problem_aware',
            'big_idea' => 'the hidden cause',
            'mechanism_name' => 'Method X',
            'problem_mechanism' => 'hidden cause',
            'solution_mechanism' => 'unique protocol',
            'offer' => ['price' => 49],
            'claims' => ['clinically proven'],
            'cta' => ['text' => 'order now'],
            'objection_rebuttals' => ['too good' => 'no'],
            'power_phrases' => ['shocking secret'],
            'persuasion' => ['authority' => true],
        ]);
    }

    public function test_dossier_composes_all_capabilities(): void
    {
        $d = $this->dossier()->compile($this->strongVsl(), ['payout' => 200.0]);

        foreach (['vsl', 'economics', 'bid_plan', 'vsl_audit', 'structural_diagnosis', 'execution_briefs', 'readiness', 'recommended_first_move'] as $key) {
            $this->assertArrayHasKey($key, $d);
        }
        $this->assertArrayHasKey('structural_strength', $d['structural_diagnosis']);
        $this->assertSame(126.0, $d['economics']['max_cpa']);
        $this->assertArrayHasKey('copy', $d['execution_briefs']);
        $this->assertArrayHasKey('traffic', $d['execution_briefs']);
        $this->assertGreaterThanOrEqual(85, $d['vsl_audit']['score']);
    }

    public function test_strong_vsl_with_economics_is_launchable_and_recommends_launch(): void
    {
        $d = $this->dossier()->compile($this->strongVsl(), ['payout' => 200.0]);

        $this->assertTrue($d['readiness']['launchable']);
        $this->assertSame([], $d['readiness']['blockers']);
        $this->assertSame('launch_first_test', $d['recommended_first_move']['move']);
    }

    public function test_missing_economics_blocks_launch_and_asks_for_payout(): void
    {
        $d = $this->dossier()->compile($this->strongVsl(), []); // no payout

        $this->assertFalse($d['readiness']['launchable']);
        $this->assertNull($d['bid_plan']);
        $this->assertSame('set_economics', $d['recommended_first_move']['move']);
    }

    public function test_unextracted_vsl_is_not_launchable(): void
    {
        $bare = new AiMarketingVslAsset(['campaign_ref' => 'X', 'transcript' => '']);
        $d = $this->dossier()->compile($bare, ['payout' => 200.0]);

        $this->assertFalse($d['readiness']['launchable']);
        $this->assertNotEmpty($d['readiness']['blockers']);
    }
}
