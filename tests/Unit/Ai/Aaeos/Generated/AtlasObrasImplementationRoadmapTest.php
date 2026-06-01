<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasObrasImplementationRoadmapService;
use Tests\TestCase;

final class AtlasObrasImplementationRoadmapTest extends TestCase
{
    private AtlasObrasImplementationRoadmapService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasObrasImplementationRoadmapService();
    }

    public function testPhaseLadderIsTheSixDocumentedPhasesMappedToL0ToL5(): void
    {
        $keys = array_column(AtlasObrasImplementationRoadmapService::PHASES, 'key');
        $this->assertSame([
            'foundation',
            'workspace_vivo',
            'enterprise_core',
            'obraos',
            'foundry',
            'sovereign_os',
        ], $keys);

        // Doc maps Phase A..F to patamares L0..L5 in order.
        $letters = array_column(AtlasObrasImplementationRoadmapService::PHASES, 'letter');
        $patamares = array_column(AtlasObrasImplementationRoadmapService::PHASES, 'patamar');
        $this->assertSame(['A', 'B', 'C', 'D', 'E', 'F'], $letters);
        $this->assertSame(['L0', 'L1', 'L2', 'L3', 'L4', 'L5'], $patamares);
    }

    public function testSkippingAPhaseIsForbidden(): void
    {
        // Phase A -> Phase C skips Workspace Vivo (Phase B).
        $verdict = $this->service->evaluateAdvancement('foundation', 'enterprise_core');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame('rejected', $verdict['verdict']);
        $this->assertSame('forbidden_phase_skip', $verdict['reason']);

        // A backwards move is not an advance either.
        $back = $this->service->evaluateAdvancement('enterprise_core', 'foundation');
        $this->assertFalse($back['allowed']);
        $this->assertSame('not_a_forward_advancement', $back['reason']);
    }

    public function testObraOsCannotStartUntilL0ToL2AreSolid(): void
    {
        // Decision: "L0-L2 must be solid before ObraOS execution." Enterprise Core
        // (C) not complete -> starting ObraOS (D) is blocked with the L0-L2 reason.
        $blocked = $this->service->evaluateAdvancement(
            'enterprise_core',
            'obraos',
            ['foundation', 'workspace_vivo'], // C missing.
        );
        $this->assertFalse($blocked['allowed']);
        $this->assertSame('blocked', $blocked['verdict']);
        $this->assertContains('obraos_requires_l0_l2_solid:enterprise_core', $blocked['blockers']);
        $this->assertContains('prior_phase_incomplete:enterprise_core', $blocked['blockers']);

        // A, B and C complete -> the single-step advance into ObraOS is allowed.
        $allowed = $this->service->evaluateAdvancement(
            'enterprise_core',
            'obraos',
            ['foundation', 'workspace_vivo', 'enterprise_core'],
        );
        $this->assertTrue($allowed['allowed']);
        $this->assertSame('allowed', $allowed['verdict']);
        $this->assertSame([], $allowed['blockers']);
    }

    public function testSovereignOsCannotStartBeforeSystemProducesAndGovernsRealAssets(): void
    {
        // Decision: "L5 must not be implemented before the system can produce and
        // govern real assets." Foundry (E, the asset-governing phase) not done ->
        // starting Sovereign OS (F) is blocked with the real-assets reason.
        $blocked = $this->service->evaluateAdvancement(
            'foundry',
            'sovereign_os',
            ['foundation', 'workspace_vivo', 'enterprise_core', 'obraos'], // E missing.
        );
        $this->assertFalse($blocked['allowed']);
        $this->assertContains('sovereign_requires_real_assets_produced_and_governed:foundry', $blocked['blockers']);

        // All asset-producing (C, D) and asset-governing (E) phases complete ->
        // the advance into Sovereign OS is allowed.
        $allowed = $this->service->evaluateAdvancement(
            'foundry',
            'sovereign_os',
            ['foundation', 'workspace_vivo', 'enterprise_core', 'obraos', 'foundry'],
        );
        $this->assertTrue($allowed['allowed']);
        $this->assertSame([], $allowed['blockers']);
    }

    public function testMvpForbiddenListIsAHardGate(): void
    {
        $fullHeadroom = [];
        foreach (AtlasObrasImplementationRoadmapService::REQUIRED_MODEL_HEADROOM as $room) {
            $fullHeadroom[$room] = true;
        }

        // A TCC-only, markdown-only MVP that omits the next step is rejected with
        // each documented "may not" violation.
        $rejected = $this->service->validateMvp([
            'uses_tcc_only_model' => true,
            'stores_obra_only_as_markdown' => true,
            'omits_next_step' => true,
            'ai_sessions_without_obra_id' => true,
            'model_headroom' => $fullHeadroom,
        ]);
        $this->assertFalse($rejected['valid']);
        $this->assertContains('mvp_may_not_use_tcc_only_model', $rejected['violations']);
        $this->assertContains('mvp_may_not_store_obra_only_as_markdown', $rejected['violations']);
        $this->assertContains('mvp_may_not_omit_next_step', $rejected['violations']);
        $this->assertContains('mvp_may_not_create_ai_sessions_without_obra_id', $rejected['violations']);

        // A compliant MVP that still lacks data-model headroom for assets/portfolio
        // is invalid because the model must already leave room for them.
        $noHeadroom = $this->service->validateMvp([]);
        $this->assertFalse($noHeadroom['valid']);
        $this->assertContains('assets', $noHeadroom['missing_model_headroom']);
        $this->assertContains('portfolio', $noHeadroom['missing_model_headroom']);

        // A compliant MVP with full headroom is valid.
        $valid = $this->service->validateMvp(['model_headroom' => $fullHeadroom]);
        $this->assertTrue($valid['valid']);
        $this->assertSame([], $valid['violations']);
        $this->assertSame([], $valid['missing_model_headroom']);
    }

    public function testFirstPilotIsRecommendedOnlyWhenEveryDocumentedReasonHolds(): void
    {
        $this->assertSame(
            'Atlas Self-Construction OS',
            AtlasObrasImplementationRoadmapService::RECOMMENDED_PILOT,
        );

        // Missing one documented reason -> not recommended; the gap is reported.
        $notRecommended = $this->service->evaluatePilot([
            'has_docs_aps_tests_gates_outputs' => true,
            'complex_enough_to_prove_value' => true,
            'directly_improves_atlas_construction' => true,
            'avoids_narrowing_obras_to_tcc' => false,
        ]);
        $this->assertFalse($notRecommended['recommended']);
        $this->assertContains('avoids_narrowing_obras_to_tcc', $notRecommended['missing_reasons']);

        // Every documented reason holds -> recommended.
        $recommended = $this->service->evaluatePilot([
            'has_docs_aps_tests_gates_outputs' => true,
            'complex_enough_to_prove_value' => true,
            'directly_improves_atlas_construction' => true,
            'avoids_narrowing_obras_to_tcc' => true,
        ]);
        $this->assertTrue($recommended['recommended']);
        $this->assertSame([], $recommended['missing_reasons']);
    }

    public function testReadinessClaimRefusedWithoutEvidenceAndGreenGates(): void
    {
        // frontmatter forbidden_changes: no runtime/maturity/promotion claim
        // without verifiable evidence AND green gates.
        $refused = $this->service->claimGuard(['evidence_ref' => '', 'gate_status' => 'unknown']);
        $this->assertFalse($refused['claim_allowed']);
        $this->assertContains('no_readiness_claim_without_verifiable_evidence', $refused['blockers']);
        $this->assertContains('no_readiness_claim_without_green_gates', $refused['blockers']);

        // Evidence cited AND gate green -> allowed.
        $allowed = $this->service->claimGuard(['evidence_ref' => 'docs-health.json', 'gate_status' => 'ok']);
        $this->assertTrue($allowed['claim_allowed']);
        $this->assertSame([], $allowed['blockers']);
    }
}
