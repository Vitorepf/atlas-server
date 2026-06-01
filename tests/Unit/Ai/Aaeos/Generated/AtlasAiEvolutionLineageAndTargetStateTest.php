<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiEvolutionLineageAndTargetStateService;
use Tests\TestCase;

final class AtlasAiEvolutionLineageAndTargetStateTest extends TestCase
{
    private AtlasAiEvolutionLineageAndTargetStateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasAiEvolutionLineageAndTargetStateService();
    }

    public function testIdentityIsOperatingSystemNeverAgiOrAsi(): void
    {
        // Doc "Contratos / Identidade": Atlas = AI Operating System; nao = AGI; nao = ASI.
        $identity = $this->service->identity();
        $this->assertTrue($identity['is_ai_operating_system']);
        $this->assertFalse($identity['is_agi']);
        $this->assertFalse($identity['is_asi']);
    }

    public function testLineageHasElevenLevelsWithDocumentedPerLevelStatus(): void
    {
        $lineage = $this->service->lineage();

        // Doc "Fluxo": eleven ordered levels, Nivel 0..10.
        $this->assertSame(11, $lineage['count']);
        $this->assertSame(0, $lineage['min']);
        $this->assertSame(10, $lineage['max']);

        $byLevel = $lineage['levels'];

        // Doc names carried verbatim.
        $this->assertSame('Atlas Router OS', $byLevel[2]['name']);
        $this->assertSame('Atlas Intelligence Factory OS', $byLevel[6]['name']);
        $this->assertSame('Atlas Civilization Intelligence Engine', $byLevel[10]['name']);

        // Doc "Estado atual conservador" per-level status:
        // Nivel 2 exists; Nivel 4/5/6 partial; Nivel 7-9 need runtime; Nivel 10 north-star.
        $this->assertSame('exists', $byLevel[2]['status']);
        $this->assertSame('partial', $byLevel[4]['status']);
        $this->assertSame('partial', $byLevel[6]['status']);
        $this->assertSame('needs_runtime', $byLevel[7]['status']);
        $this->assertSame('north_star', $byLevel[10]['status']);

        // Current band 4..5, emerging 6, next macro 7 (Swarm Company Runtime).
        $this->assertTrue($byLevel[4]['is_current_band']);
        $this->assertTrue($byLevel[5]['is_current_band']);
        $this->assertFalse($byLevel[6]['is_current_band']);
        $this->assertTrue($byLevel[6]['is_emerging']);
        $this->assertTrue($byLevel[7]['is_next_macro']);
        $this->assertSame('Atlas Swarm Company Runtime', $lineage['next_macro_name']);
    }

    public function testCurrentStateIsLevelFourFiveConsolidatingNotAutonomousCompany(): void
    {
        // Doc "Estado atual conservador": Nivel 4/5 em consolidacao, inicio operacional de Nivel 6.
        $state = $this->service->currentState();
        $this->assertSame(4, $state['floor']);
        $this->assertSame(5, $state['ceiling']);
        $this->assertSame(6, $state['emerging_level']);
        $this->assertFalse($state['is_agi']);
        $this->assertFalse($state['is_asi']);
        // Nivel 8 ainda precisa Company OS multi-dominio completo.
        $this->assertFalse($state['is_autonomous_company']);
    }

    public function testEvolutionUnitTableSeparatesCosmeticFromOperationalUnitChange(): void
    {
        // Doc "Unidade de evolucao": cosmetic / unwired changes never count.
        foreach (['new_button', 'new_prompt', 'new_doc', 'new_flow_without_caller'] as $cosmetic) {
            $r = $this->service->classifyEvolutionUnit($cosmetic);
            $this->assertTrue($r['known'], "$cosmetic should be a known change type");
            $this->assertFalse($r['counts_as_patamar'], "$cosmetic must NOT count as patamar");
        }

        // Operational-unit changes DO count.
        $this->assertTrue(
            $this->service->classifyEvolutionUnit('runtime_in_standard_flow_with_evidence')['counts_as_patamar']
        );
        $this->assertTrue(
            $this->service->classifyEvolutionUnit('memory_changes_future_decisions_with_receipts')['counts_as_patamar']
        );
        $this->assertTrue(
            $this->service->classifyEvolutionUnit('coordinated_subagents_handoff_leases')['counts_as_patamar']
        );
        $this->assertTrue(
            $this->service->classifyEvolutionUnit('external_execution_mandate_approval_rollback')['counts_as_patamar']
        );

        // Unknown change type is conservatively NOT a patamar.
        $unknown = $this->service->classifyEvolutionUnit('something_invented');
        $this->assertFalse($unknown['known']);
        $this->assertFalse($unknown['counts_as_patamar']);
    }

    public function testClaimPolicyBlocksForbiddenClaimsWithoutEvidencePack(): void
    {
        // Doc "Claim policy": forbidden without evidence pack.
        $agi = $this->service->evaluateClaim('agi');
        $this->assertFalse($agi['allowed']);
        $this->assertSame('forbidden_claim_blocked_without_evidence_pack', $agi['reason']);

        $this->assertFalse($this->service->evaluateClaim('complete')['allowed']);
        $this->assertFalse($this->service->evaluateClaim('replaces_all_scenarios')['allowed']);
        $this->assertFalse($this->service->evaluateClaim('acts_without_approval')['allowed']);

        // An evidence pack is the only documented gate that unlocks a forbidden claim.
        $this->assertTrue($this->service->evaluateClaim('agi', true)['allowed']);

        // The internal-runtime claim is permitted only when tests/gates prove it.
        $this->assertFalse($this->service->evaluateClaim('internal_runtime')['allowed']);
        $this->assertTrue($this->service->evaluateClaim('internal_runtime', true)['allowed']);
    }

    public function testEvidenceIsSufficientOnlyWhenStandardFlowUsesCapabilityAndAllItemsPresent(): void
    {
        $all = [];
        foreach (AtlasAiEvolutionLineageAndTargetStateService::EVIDENCE_MINIMUM as $item) {
            $all[$item] = true;
        }

        // All twelve present + runtime in standard flow -> sufficient.
        $ok = $this->service->isEvidenceSufficient($all);
        $this->assertTrue($ok['sufficient']);
        $this->assertSame([], $ok['missing']);
        $this->assertTrue($ok['standard_flow_uses_capability']);

        // Drop the overriding rule: "Promocao so vale quando o fluxo padrao usa a capacidade".
        $noFlow = $all;
        $noFlow['runtime_in_standard_flow'] = false;
        $blocked = $this->service->isEvidenceSufficient($noFlow);
        $this->assertFalse($blocked['sufficient']);
        $this->assertFalse($blocked['standard_flow_uses_capability']);
        $this->assertSame('standard_flow_does_not_use_capability', $blocked['reason']);

        // "Sinais que nao bastam" are recognised as never-sufficient on their own.
        $this->assertTrue($this->service->isInsufficientSignal('doc_exists'));
        $this->assertTrue($this->service->isInsufficientSignal('migration_exists'));
        $this->assertFalse($this->service->isInsufficientSignal('runtime_in_standard_flow'));
    }

    public function testHorizonMapReturnsDocumentedTargets(): void
    {
        // Doc "Proximas Acoes / Horizonte".
        $sixMonths = $this->service->horizon('6_months');
        $this->assertTrue($sixMonths['known']);
        $this->assertSame('Nivel 7 com Swarm Company Runtime operacional', $sixMonths['target']);

        $this->assertSame(
            'Nivel 8 com Autonomous Company OS multi-dominio',
            $this->service->horizon('1_year')['target']
        );

        // Unknown horizon -> not known.
        $this->assertFalse($this->service->horizon('10_years')['known']);
    }
}
