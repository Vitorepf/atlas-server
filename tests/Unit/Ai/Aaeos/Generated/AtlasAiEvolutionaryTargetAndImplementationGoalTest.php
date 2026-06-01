<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiEvolutionaryTargetAndImplementationGoalService;
use Tests\TestCase;

final class AtlasAiEvolutionaryTargetAndImplementationGoalTest extends TestCase
{
    private AtlasAiEvolutionaryTargetAndImplementationGoalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasAiEvolutionaryTargetAndImplementationGoalService();
    }

    public function testExistentialClaimsAreNeverAllowedEvenWithEvidencePack(): void
    {
        // Doc "Claims proibidas sem evidence pack": AGI/ASI are existential and
        // cannot be unlocked by presenting an evidence pack at this stage.
        $agi = $this->service->evaluateClaim('is_agi', evidencePack: true);
        $this->assertTrue($agi['known_forbidden']);
        $this->assertFalse($agi['allowed']);
        $this->assertSame('existential_claim_never_allowed', $agi['reason']);

        $asi = $this->service->evaluateClaim('is_asi', evidencePack: true);
        $this->assertFalse($asi['allowed']);
    }

    public function testOperationalClaimIsBlockedWithoutEvidenceButUnlockedWithIt(): void
    {
        // Doc: "Atlas esta completo" / "ja substitui os providers em todos os
        // casos" are forbidden WITHOUT an evidence pack, allowed WITH one.
        $blocked = $this->service->evaluateClaim('is_complete', evidencePack: false);
        $this->assertTrue($blocked['known_forbidden']);
        $this->assertFalse($blocked['allowed']);
        $this->assertSame('forbidden_without_evidence_pack', $blocked['reason']);

        $unlocked = $this->service->evaluateClaim('is_complete', evidencePack: true);
        $this->assertTrue($unlocked['allowed']);
        $this->assertSame('unlocked_by_evidence_pack', $unlocked['reason']);

        // A claim outside the forbidden set is always allowed.
        $free = $this->service->evaluateClaim('atlas_is_in_transition', evidencePack: false);
        $this->assertFalse($free['known_forbidden']);
        $this->assertTrue($free['allowed']);

        // All six documented forbidden claims are present.
        $this->assertCount(6, AtlasAiEvolutionaryTargetAndImplementationGoalService::FORBIDDEN_CLAIMS);
    }

    public function testPromotionChainReportsFirstBrokenRungAndMarksHigherRungsUnreachable(): void
    {
        // Doc "Promocao proibida": runtime exists but no caller -> the break is
        // at 'caller', and test/standard_flow/control_plane/real_use are
        // unreachable EVEN IF their own flags are set true.
        $result = $this->service->gatePromotionChain([
            'doc' => true,
            'runtime' => true,
            'caller' => false,
            'test' => true,            // set true but must be ignored (unreachable)
            'standard_flow' => true,
            'control_plane' => true,
            'real_use_evidence' => true,
        ]);

        $this->assertFalse($result['promote']);
        $this->assertSame('caller', $result['first_broken']);
        $this->assertSame('runtime existe, mas nao ha caller', $result['first_broken_reason']);
        $this->assertSame(['doc', 'runtime'], $result['satisfied']);
        $this->assertSame(
            ['test', 'standard_flow', 'control_plane', 'real_use_evidence'],
            $result['unreachable']
        );
    }

    public function testPromotionChainPromotesOnlyWhenEveryRungHolds(): void
    {
        $full = $this->service->gatePromotionChain([
            'doc' => true,
            'runtime' => true,
            'caller' => true,
            'test' => true,
            'standard_flow' => true,
            'control_plane' => true,
            'real_use_evidence' => true,
        ]);

        $this->assertTrue($full['promote']);
        $this->assertNull($full['first_broken']);
        $this->assertSame([], $full['unreachable']);
        $this->assertCount(7, $full['satisfied']);
    }

    public function testArtifactTaxonomyTreatsDocOnlyAsScaffoldAndFullChainAsPatamar(): void
    {
        // Doc "Contratos": a doc/class with no real use is scaffold, never runtime.
        $scaffold = $this->service->classifyArtifact([
            'executable' => false,
            'tested' => false,
            'has_usage_path' => false,
        ]);
        $this->assertSame('scaffold', $scaffold['kind']);
        $this->assertFalse($scaffold['is_real_runtime']);

        // Executable + tested + usage path but no main flow -> runtime_real.
        $runtimeReal = $this->service->classifyArtifact([
            'executable' => true,
            'tested' => true,
            'has_usage_path' => true,
            'consumed_by_main_flow' => false,
        ]);
        $this->assertSame('runtime_real', $runtimeReal['kind']);

        // Operational change of nature, in a main flow, emitting evidence -> patamar.
        $patamar = $this->service->classifyArtifact([
            'executable' => true,
            'tested' => true,
            'has_usage_path' => true,
            'consumed_by_main_flow' => true,
            'emits_evidence' => true,
            'changes_operational_nature' => true,
        ]);
        $this->assertSame('patamar', $patamar['kind']);
        $this->assertTrue($patamar['is_real_runtime']);

        // In a main flow with evidence but NOT a nature change -> operational_evidence.
        $opEvidence = $this->service->classifyArtifact([
            'executable' => true,
            'tested' => true,
            'has_usage_path' => true,
            'consumed_by_main_flow' => true,
            'emits_evidence' => true,
            'changes_operational_nature' => false,
        ]);
        $this->assertSame('operational_evidence', $opEvidence['kind']);
    }

    public function testNextOperationalActionFollowsTheDocumentedOrder(): void
    {
        // Doc "Proximas Acoes": ordered 1..7. With steps 1-2 done the next is
        // step 3 (close ASEIF), and five remain.
        $next = $this->service->nextOperationalAction([
            'close_context_layers',
            'close_aemor_outcomes',
        ]);
        $this->assertFalse($next['all_done']);
        $this->assertSame(3, $next['next_step']);
        $this->assertSame('close_aseif_capability_loop', $next['next_key']);
        $this->assertSame(5, $next['remaining']);

        // Step 5 (Swarm) is NOT reachable as "next" while earlier steps are open,
        // even though it appears in the done list out of order.
        $outOfOrder = $this->service->nextOperationalAction(['implement_swarm_company']);
        $this->assertSame(1, $outOfOrder['next_step']);
        $this->assertSame('close_context_layers', $outOfOrder['next_key']);
    }

    public function testGoalDoneRequiresAllChecksAndEnforcesExternalSafetyInvariant(): void
    {
        $allKeys = array_column(
            AtlasAiEvolutionaryTargetAndImplementationGoalService::DEFINITION_OF_DONE,
            'key'
        );
        $allTrue = array_fill_keys($allKeys, true);

        $done = $this->service->evaluateGoalDone($allTrue);
        $this->assertTrue($done['done']);
        $this->assertFalse($done['safety_violation']);
        $this->assertSame([], $done['missing']);

        // Doc DoD: "External execution continua bloqueada" — flipping that single
        // safety invariant to false blocks done AND flags a safety violation.
        $openExternal = $allTrue;
        $openExternal['external_execution_blocked'] = false;
        $violated = $this->service->evaluateGoalDone($openExternal);
        $this->assertFalse($violated['done']);
        $this->assertTrue($violated['safety_violation']);
        $this->assertSame('blocked_safety_invariant_open', $violated['reason']);
        $this->assertSame(['external_execution_blocked'], $violated['missing']);
    }
}
