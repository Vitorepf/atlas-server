<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiEvolutionaryMaturityModelService;
use Tests\TestCase;

final class AtlasAiEvolutionaryMaturityModelTest extends TestCase
{
    private AtlasAiEvolutionaryMaturityModelService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasAiEvolutionaryMaturityModelService();
    }

    public function testLineageExposesElevenOrderedCanonicalLevelsWithNamesAndDeps(): void
    {
        $lineage = $this->service->lineage();

        // Doc "Fluxo": eleven levels, Nivel 0..10.
        $this->assertSame(11, $lineage['count']);
        $this->assertSame(0, $lineage['min']);
        $this->assertSame(10, $lineage['max']);

        $names = array_column($lineage['levels'], 'name', 'level');
        $this->assertSame('LLM Wrapper', $names[0]);
        $this->assertSame('Atlas Outcome-Learning OS', $names[5]);
        $this->assertSame('Atlas Intelligence Factory OS', $names[6]);
        $this->assertSame('Atlas Swarm Company Runtime', $names[7]);
        $this->assertSame('Atlas Civilization Intelligence Engine', $names[10]);

        // Doc "Dependencias" table is carried verbatim.
        $deps = array_column($lineage['levels'], 'depends_on', 'level');
        $this->assertSame('AEMOR, evidence, outcome tracking', $deps[5]);
        $this->assertSame('all previous levels plus ecosystem governance', $deps[10]);

        // Nivel 7 is flagged as the next macro patamar.
        $byLevel = array_column($lineage['levels'], null, 'level');
        $this->assertTrue($byLevel[7]['is_next_macro']);
        $this->assertFalse($byLevel[6]['is_next_macro']);
    }

    public function testCurrentClassificationIsConservativeAndNotAgiOrAsi(): void
    {
        $current = $this->service->currentClassification();

        // Doc "Evidencias": Nivel 4.5 a 5, com inicio forte de Nivel 6.
        $this->assertSame(4.5, $current['floor']);
        $this->assertSame(5, $current['ceiling']);
        $this->assertSame(6, $current['emerging_level']);

        // Doc claim contract: not AGI, not ASI, not yet an autonomous company.
        $this->assertFalse($current['is_agi']);
        $this->assertFalse($current['is_asi']);
        $this->assertFalse($current['is_autonomous_company']);

        // Next macro patamar is Nivel 7 = Swarm Company Runtime.
        $this->assertSame(7, $current['next_macro_level']);
        $this->assertSame('Atlas Swarm Company Runtime', $current['next_macro_name']);
    }

    public function testPromotionRequiresAllEightProofsAndNamesTheMissingOnes(): void
    {
        // Doc "Definition of Done": all eight proofs required. Doc/scaffold alone
        // must not promote. With only the canonical doc present, promotion fails
        // and the seven runtime proofs are reported missing.
        $docOnly = $this->service->gatePromotion(7, ['canonical_doc' => true]);
        $this->assertFalse($docOnly['promote']);
        $this->assertSame('missing_promotion_proofs', $docOnly['reason']);
        $this->assertContains('runtime_in_standard_flow', $docOnly['missing']);
        $this->assertContains('real_use_in_a_main_flow', $docOnly['missing']);
        $this->assertCount(7, $docOnly['missing']);

        // All eight proofs present -> promotion allowed.
        $allProofs = [
            'canonical_doc' => true,
            'runtime_in_standard_flow' => true,
            'persistence_or_versioned_contract' => true,
            'cli_api_control_plane' => true,
            'focused_tests_and_regression' => true,
            'readiness_certification' => true,
            'verifiable_evidence_refs' => true,
            'real_use_in_a_main_flow' => true,
        ];
        $full = $this->service->gatePromotion(7, $allProofs);
        $this->assertTrue($full['promote']);
        $this->assertSame('all_proofs_present', $full['reason']);
        $this->assertSame([], $full['missing']);

        // A falsey (not strict-true) proof never counts: one proof set to a
        // truthy-but-not-true value (1) must still block promotion.
        $almost = $allProofs;
        $almost['real_use_in_a_main_flow'] = false;
        $blocked = $this->service->gatePromotion(7, $almost);
        $this->assertFalse($blocked['promote']);
        $this->assertSame(['real_use_in_a_main_flow'], $blocked['missing']);
    }

    public function testClaimContractRejectsAgiAndAsiButAllowsOsFraming(): void
    {
        // Doc "Regras para IA" 1 + "Contrato de claim".
        $this->assertFalse($this->service->evaluateClaim('AGI')['allowed']);
        $this->assertFalse($this->service->evaluateClaim('asi')['allowed']);
        $this->assertSame('forbidden_claim_atlas_is_not_agi', $this->service->evaluateClaim('AGI')['reason']);

        $os = $this->service->evaluateClaim('AI Operating System');
        $this->assertTrue($os['allowed']);
    }

    public function testChangeOnlyPromotesWhenOperationalUnitChangesWiredAndEvidenced(): void
    {
        // Doc "Fluxo": isolated feature without wiring/evidence -> no movement.
        $isolated = $this->service->classifyChange([
            'changes_operational_unit' => false,
            'wired_to_standard_flow' => false,
            'has_evidence' => false,
        ]);
        $this->assertFalse($isolated['promotes_level']);
        $this->assertFalse($isolated['changes_within_level']);
        $this->assertSame('no_movement', $isolated['verdict']);

        // Wiring an existing flow + evidence deepens maturity within the level.
        $within = $this->service->classifyChange([
            'changes_operational_unit' => false,
            'wired_to_standard_flow' => true,
            'has_evidence' => true,
        ]);
        $this->assertFalse($within['promotes_level']);
        $this->assertTrue($within['changes_within_level']);
        $this->assertSame('within_level', $within['verdict']);

        // New operational unit, wired and evidenced -> real level move.
        $move = $this->service->classifyChange([
            'changes_operational_unit' => true,
            'wired_to_standard_flow' => true,
            'has_evidence' => true,
        ]);
        $this->assertTrue($move['promotes_level']);
        $this->assertSame('level_move', $move['verdict']);

        // Operational unit changes but with no evidence -> still no promotion
        // (doc forbids promotion without evidence).
        $unevidenced = $this->service->classifyChange([
            'changes_operational_unit' => true,
            'wired_to_standard_flow' => false,
            'has_evidence' => false,
        ]);
        $this->assertFalse($unevidenced['promotes_level']);
    }

    public function testScenarioOracleMatchesDocumentedExamples(): void
    {
        // Doc "Exemplos": chat answer = Nivel 1.
        $chat = $this->service->classifyScenario('simple_chat_answer');
        $this->assertTrue($chat['known']);
        $this->assertSame(1, $chat['level']);
        $this->assertSame('Atlas Copilot', $chat['level_name']);

        // Patch that learns from failure = Nivel 5.
        $this->assertSame(5, $this->service->classifyScenario('patch_learns_from_failure')['level']);

        // Five agents with handoff = Nivel 7.
        $swarm = $this->service->classifyScenario('five_agents_with_handoff');
        $this->assertSame(7, $swarm['level']);
        $this->assertSame('Atlas Swarm Company Runtime', $swarm['level_name']);

        // Unknown scenario -> level -1.
        $this->assertSame(-1, $this->service->classifyScenario('totally_unknown')['level']);
    }
}
