<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiEvolutionRoadmapService;
use Tests\TestCase;

final class AtlasAiEvolutionRoadmapTest extends TestCase
{
    private AtlasAiEvolutionRoadmapService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasAiEvolutionRoadmapService();
    }

    public function testFrameIsTheFiveNonNegotiableInvariantsInOrder(): void
    {
        $keys = array_column(AtlasAiEvolutionRoadmapService::FRAME, 'key');

        $this->assertSame([
            'provider_launch_is_input',
            'capabilities_through_contracts',
            'repeated_patterns_to_core',
            'outcomes_become_evidence',
            'curator_proposes_not_self_applies',
        ], $keys);
    }

    public function testSpineReflectsDocumentedPhaseStatuses(): void
    {
        $rows = [];
        foreach ($this->service->spine()['spine'] as $row) {
            $rows[$row['id']] = $row;
        }

        // AP-100 is implemented; AP-101 is the next increment; Phase 2+ is planned.
        $this->assertSame('implemented', $rows['ap_100_context_pack_manifest']['status']);
        $this->assertSame('next', $rows['ap_101_retrieval_router']['status']);
        $this->assertSame('planned', $rows['graph_rag']['status']);

        // AP-99 is the read-model path and must carry an evidence requirement.
        $this->assertSame('implemented_read_model', $rows['ap_99_provider_performance']['status']);
        $this->assertTrue($rows['ap_99_provider_performance']['requires_evidence']);

        // A merely-planned row makes no runtime claim, so no evidence is required.
        $this->assertFalse($rows['graph_rag']['requires_evidence']);
    }

    public function testAuthorityRoutesTopicsToOwnerDocsAndFlagsUnknown(): void
    {
        $this->assertSame(
            'evolution/provider-performance-roadmap.md',
            $this->service->resolveAuthority('provider')['owner_doc']
        );
        $this->assertSame(
            'atlas-ai-kernel-architecture.md',
            $this->service->resolveAuthority('kernel')['owner_doc']
        );
        $this->assertSame(
            'evolution/context-builder-roadmap.md',
            $this->service->resolveAuthority('rag')['owner_doc']
        );

        $unknown = $this->service->resolveAuthority('quantum-teleportation');
        $this->assertFalse($unknown['resolved']);
        $this->assertSame('no_authority_owner_place_via_canonical_index', $unknown['reason']);
    }

    public function testRuntimeStatusClaimRequiresEvidenceAndGreenGates(): void
    {
        // forbidden_changes: cannot declare runtime without evidence + green gates.
        $bare = $this->service->gateStatusClaim('implemented', false, false);
        $this->assertFalse($bare['accepted']);
        $this->assertSame('planned', $bare['effective_status']);
        $this->assertSame('runtime_claim_requires_evidence_and_green_gates', $bare['reason']);

        // Evidence present but gates red is still rejected.
        $halfway = $this->service->gateStatusClaim('implemented', true, false);
        $this->assertFalse($halfway['accepted']);
        $this->assertSame('planned', $halfway['effective_status']);

        // Both present: the claim is accepted and the status stands.
        $proven = $this->service->gateStatusClaim('implemented', true, true);
        $this->assertTrue($proven['accepted']);
        $this->assertSame('implemented', $proven['effective_status']);

        // A "planned" status makes no runtime claim, so it is accepted with no evidence.
        $planned = $this->service->gateStatusClaim('planned', false, false);
        $this->assertTrue($planned['accepted']);
        $this->assertSame('planned', $planned['effective_status']);
    }

    public function testImplementationRuleRejectsNewSubsystemForExistingContract(): void
    {
        // Creating a NEW subsystem when a contract already exists -> extend it.
        $duplicate = $this->service->evaluateProposal([
            'creates_new_subsystem' => true,
            'existing_contract' => 'AP-99 Provider Performance Contract',
            'goes_through_contracts' => true,
        ]);
        $this->assertFalse($duplicate['accepted']);
        $this->assertSame('extend_existing', $duplicate['verdict']);
        $this->assertSame('AP-99 Provider Performance Contract', $duplicate['extend_target']);
        $this->assertContains('implementation_rule_extend_existing_do_not_duplicate', $duplicate['violations']);

        // Extending the existing contract (no new subsystem) is accepted.
        $extend = $this->service->evaluateProposal([
            'creates_new_subsystem' => false,
            'existing_contract' => 'AP-99 Provider Performance Contract',
            'goes_through_contracts' => true,
        ]);
        $this->assertTrue($extend['accepted']);
        $this->assertSame('accepted_auto', $extend['verdict']);
    }

    public function testCuratorCannotSelfApplyCriticalBehavior(): void
    {
        // Frame #5: critical behavior is proposal-only; the Curator may not self-apply.
        $overreach = $this->service->evaluateProposal([
            'creates_new_subsystem' => false,
            'goes_through_contracts' => true,
            'is_critical_behavior' => true,
            'curator_self_apply' => true,
        ]);
        $this->assertFalse($overreach['accepted']);
        $this->assertSame('rejected', $overreach['verdict']);
        $this->assertContains('frame_curator_must_not_self_apply_critical_behavior', $overreach['violations']);
        $this->assertFalse($overreach['auto_apply_allowed']);

        // The same critical change as a proposal (not self-applying) is accepted but
        // never auto-applied — it is held for authorization.
        $proposalOnly = $this->service->evaluateProposal([
            'creates_new_subsystem' => false,
            'goes_through_contracts' => true,
            'is_critical_behavior' => true,
            'curator_self_apply' => false,
        ]);
        $this->assertTrue($proposalOnly['accepted']);
        $this->assertSame('accepted_proposal_only', $proposalOnly['verdict']);
        $this->assertFalse($proposalOnly['auto_apply_allowed']);
    }

    public function testProposalBypassingContractsIsRejected(): void
    {
        // Frame #2: capabilities must go through Kernel + Domain contracts.
        $bypass = $this->service->evaluateProposal([
            'creates_new_subsystem' => false,
            'goes_through_contracts' => false,
        ]);
        $this->assertFalse($bypass['accepted']);
        $this->assertSame('rejected', $bypass['verdict']);
        $this->assertContains('frame_capabilities_must_go_through_contracts', $bypass['violations']);
    }
}
