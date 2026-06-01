<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSddImplementationRoadmapService;
use Tests\TestCase;

final class AtlasSddImplementationRoadmapTest extends TestCase
{
    private AtlasSddImplementationRoadmapService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasSddImplementationRoadmapService();
    }

    public function testPhaseLadderIsTheFourDocumentedPhasesInOrder(): void
    {
        $keys = array_column(AtlasSddImplementationRoadmapService::PHASES, 'key');
        $this->assertSame([
            'foundation',
            'auto_spec',
            'controlled_execution',
            'state_of_art',
        ], $keys);

        $orders = array_column(AtlasSddImplementationRoadmapService::PHASES, 'order');
        $this->assertSame([1, 2, 3, 4], $orders);

        // The doc's Phase 1 (Foundation) and Phase 2 (Auto-Spec) are pre-execution;
        // Controlled Execution and State Of Art are the executing phases.
        $byKey = [];
        foreach (AtlasSddImplementationRoadmapService::PHASES as $phase) {
            $byKey[$phase['key']] = $phase['executes'];
        }
        $this->assertFalse($byKey['foundation']);
        $this->assertFalse($byKey['auto_spec']);
        $this->assertTrue($byKey['controlled_execution']);
        $this->assertTrue($byKey['state_of_art']);
    }

    public function testSkippingAndBackwardsAdvancementAreForbidden(): void
    {
        // Foundation -> Controlled Execution skips Auto-Spec (Phase 2).
        $skip = $this->service->evaluateAdvancement('foundation', 'controlled_execution');
        $this->assertFalse($skip['allowed']);
        $this->assertSame('rejected', $skip['verdict']);
        $this->assertSame('forbidden_phase_skip', $skip['reason']);

        // A backwards move is not a forward advance.
        $back = $this->service->evaluateAdvancement('controlled_execution', 'foundation');
        $this->assertFalse($back['allowed']);
        $this->assertSame('not_a_forward_advancement', $back['reason']);
    }

    public function testSingleStepAdvanceRequiresAllPriorPhasesComplete(): void
    {
        // Auto-Spec -> Controlled Execution with Foundation NOT complete is blocked.
        $blocked = $this->service->evaluateAdvancement(
            'auto_spec',
            'controlled_execution',
            ['auto_spec'],
        );
        $this->assertFalse($blocked['allowed']);
        $this->assertSame('blocked', $blocked['verdict']);
        $this->assertContains('prior_phase_incomplete:foundation', $blocked['blockers']);

        // Same step with every prior phase complete is allowed.
        $ok = $this->service->evaluateAdvancement(
            'auto_spec',
            'controlled_execution',
            ['foundation', 'auto_spec'],
        );
        $this->assertTrue($ok['allowed']);
        $this->assertSame('allowed', $ok['verdict']);
        $this->assertSame([], $ok['blockers']);
    }

    public function testDeliverableCannotBeBuiltBeforeItsPhasePrerequisites(): void
    {
        // Plan Compiler belongs to Phase 3 (Controlled Execution); building it with
        // only Foundation done is blocked because Auto-Spec (Phase 2) is missing.
        $blocked = $this->service->evaluateDeliverable('plan_compiler', ['foundation']);
        $this->assertSame('controlled_execution', $blocked['owning_phase']);
        $this->assertSame(3, $blocked['owning_phase_order']);
        $this->assertFalse($blocked['buildable']);
        $this->assertContains('auto_spec', $blocked['missing_prerequisite_phases']);

        // Spec Compiler belongs to Phase 2; with Foundation complete it is buildable.
        $ok = $this->service->evaluateDeliverable('spec_compiler', ['foundation']);
        $this->assertSame('auto_spec', $ok['owning_phase']);
        $this->assertTrue($ok['buildable']);
        $this->assertSame([], $ok['missing_prerequisite_phases']);

        // An undocumented deliverable is rejected, not silently buildable.
        $unknown = $this->service->evaluateDeliverable('teleport_compiler', ['foundation', 'auto_spec']);
        $this->assertFalse($unknown['buildable']);
        $this->assertSame('unknown_deliverable_outside_documented_roadmap', $unknown['reason']);
    }

    public function testFirstSafeBlockEnforcesReadOnlyPipelineEndingInNoCode(): void
    {
        // The exact documented sequence, in order, with no code emitted, is valid.
        $valid = $this->service->validateSafeBlock(
            AtlasSddImplementationRoadmapService::SAFE_BLOCK_STAGES,
        );
        $this->assertTrue($valid['valid']);
        $this->assertSame('allowed', $valid['verdict']);
        $this->assertTrue($valid['terminal_is_no_code']);

        // Reordering the stages (assumptions before draft spec) is rejected.
        $outOfOrder = $this->service->validateSafeBlock([
            'raw_intent', 'context_summary', 'assumptions', 'draft_spec', 'questions', 'no_code',
        ]);
        $this->assertFalse($outOfOrder['valid']);
        $this->assertContains('safe_block_stage_sequence_violation', $outOfOrder['violations']);

        // The load-bearing "no code" invariant: emitting code breaks the gate even
        // when the stage order is correct.
        $emitsCode = $this->service->validateSafeBlock(
            AtlasSddImplementationRoadmapService::SAFE_BLOCK_STAGES,
            ['emits_code' => true],
        );
        $this->assertFalse($emitsCode['valid']);
        $this->assertContains('safe_block_must_not_emit_code', $emitsCode['violations']);

        // Claiming execution authority gives the runtime new autonomy -> rejected.
        $grantsAutonomy = $this->service->validateSafeBlock(
            AtlasSddImplementationRoadmapService::SAFE_BLOCK_STAGES,
            ['claims_execution_authority' => true],
        );
        $this->assertFalse($grantsAutonomy['valid']);
        $this->assertTrue($grantsAutonomy['grants_runtime_autonomy']);
        $this->assertContains('safe_block_must_not_grant_runtime_autonomy', $grantsAutonomy['violations']);
    }

    public function testReadinessClaimRequiresEvidenceAndGreenGates(): void
    {
        // No evidence + non-green gate -> both blockers, claim refused.
        $refused = $this->service->claimGuard(['evidence_ref' => '', 'gate_status' => 'unknown']);
        $this->assertFalse($refused['claim_allowed']);
        $this->assertContains('no_readiness_claim_without_verifiable_evidence', $refused['blockers']);
        $this->assertContains('no_readiness_claim_without_green_gates', $refused['blockers']);

        // Evidence cited + green gate -> claim allowed.
        $allowed = $this->service->claimGuard([
            'evidence_ref' => 'docs/engineering-knowledge-base/spec-operating-system/implementation-roadmap.md',
            'gate_status' => 'ok',
        ]);
        $this->assertTrue($allowed['claim_allowed']);
        $this->assertSame([], $allowed['blockers']);
    }
}
