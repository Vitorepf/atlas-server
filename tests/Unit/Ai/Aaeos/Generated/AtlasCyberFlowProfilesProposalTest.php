<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCyberFlowProfilesProposalService;
use Tests\TestCase;

final class AtlasCyberFlowProfilesProposalTest extends TestCase
{
    private AtlasCyberFlowProfilesProposalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasCyberFlowProfilesProposalService();
    }

    public function testCatalogHoldsTheSevenProposedFlowsAndAllStartAsUnregisteredProposals(): void
    {
        $this->assertSame([
            'programming.security.audit',
            'cyber.recon',
            'cyber.recon.continuous',
            'cyber.bb.full-flow',
            'cyber.bb.triage',
            'cyber.purple.validate',
            'cyber.ir.investigate',
        ], $this->service->flowIds());

        // Documented status: proposed, NOT registered in the domain registry.
        $bb = $this->service->profile('cyber.bb.full-flow');
        $this->assertSame('proposal', $bb['status']);
        $this->assertFalse($bb['registered_in_domain_registry']);
        $this->assertTrue($bb['bb_final']);

        // Triage is interactive: foreground only (background_allowed=false).
        $triage = $this->service->profile('cyber.bb.triage');
        $this->assertFalse($triage['background_allowed']);
        // The continuous recon flow is the only scheduled one.
        $this->assertTrue($this->service->profile('cyber.recon.continuous')['scheduled']);
        $this->assertFalse($this->service->profile('cyber.recon')['scheduled']);
    }

    public function testUnknownFlowIdIsReportedNotGuessed(): void
    {
        $result = $this->service->profile('cyber.exfiltrate.everything');

        $this->assertFalse($result['known']);
        $this->assertSame('unknown_flow_id', $result['reason']);
    }

    public function testEveryCatalogProfileIsValidAndAutonomyIsNeverHigh(): void
    {
        $proposal = $this->service->proposal();

        $this->assertTrue($proposal['all_profiles_valid']);
        $this->assertSame(7, $proposal['flow_count']);

        foreach ($this->service->flowIds() as $flowId) {
            $autonomy = $this->service->profile($flowId)['autonomy_default'];
            $this->assertNotSame('high', $autonomy, "{$flowId} must not default to high autonomy");
        }
    }

    public function testAntiPatternFourRejectsHighAutonomyCyberFlow(): void
    {
        $profile = $this->service->profile('cyber.recon');
        $profile['autonomy_default'] = 'high';

        $result = $this->service->validateProfile($profile);

        $this->assertFalse($result['valid']);
        $this->assertContains('autonomy_high_forbidden_for_cyber_flow', $result['violations']);
    }

    public function testAntiPatternThreeRejectsBbFinalFlowWithNonInboxOutputTarget(): void
    {
        $profile = $this->service->profile('cyber.bb.full-flow');
        $profile['output_target'] = 'auto_submit_to_program';

        $result = $this->service->validateProfile($profile);

        $this->assertFalse($result['valid']);
        $this->assertContains('bb_final_output_target_must_be_human_review_inbox', $result['violations']);
    }

    public function testAntiPatternTwoRejectsFlowWithoutRefusalMatrixReference(): void
    {
        $profile = $this->service->profile('cyber.purple.validate');
        $profile['refusal_matrix_ref'] = '';

        $result = $this->service->validateProfile($profile);

        $this->assertFalse($result['valid']);
        $this->assertContains('missing_refusal_matrix_reference', $result['violations']);
    }

    public function testPromotionIsBlockedByDefaultAndRequiresAllEightStepsPlusCandidateSkill(): void
    {
        // Default: empty checklist + draft skill => never implemented.
        $blocked = $this->service->promote('cyber.bb.full-flow');
        $this->assertFalse($blocked['promotable']);
        $this->assertSame('proposal', $blocked['status']);
        $this->assertContains('primary_skill_below_candidate', $blocked['blockers']);
        $this->assertContains('promotion_steps_incomplete', $blocked['blockers']);
        $this->assertCount(8, $blocked['missing_steps']);

        // All 8 steps satisfied but skill still draft (anti-pattern #1) => blocked.
        $fullChecklist = array_fill_keys(
            AtlasCyberFlowProfilesProposalService::PROMOTION_STEPS,
            true,
        );
        $skillDraft = $this->service->promote('cyber.bb.full-flow', $fullChecklist, 'draft');
        $this->assertFalse($skillDraft['promotable']);
        $this->assertContains('primary_skill_below_candidate', $skillDraft['blockers']);
        $this->assertSame([], $skillDraft['missing_steps']);

        // All 8 steps + candidate skill => promotable, flips to implemented.
        $ready = $this->service->promote('cyber.bb.full-flow', $fullChecklist, 'candidate');
        $this->assertTrue($ready['promotable']);
        $this->assertSame('implemented', $ready['status']);
        $this->assertSame([], $ready['blockers']);
    }

    public function testProposalSnapshotProvesNothingIsPromotableByDefault(): void
    {
        $proposal = $this->service->proposal();

        $this->assertTrue($proposal['none_promotable_by_default']);
        $this->assertSame('proposal', $proposal['status']);
        $this->assertSame('cyber-security/refusal-matrix.md', $proposal['refusal_matrix_ref']);
        $this->assertCount(8, $proposal['promotion_steps']);
    }
}
