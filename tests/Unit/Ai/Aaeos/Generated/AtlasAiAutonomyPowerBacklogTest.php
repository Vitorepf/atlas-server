<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiAutonomyPowerBacklogService;
use Tests\TestCase;

final class AtlasAiAutonomyPowerBacklogTest extends TestCase
{
    private AtlasAiAutonomyPowerBacklogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasAiAutonomyPowerBacklogService();
    }

    public function testCentralPermissionRuleMapsEachStageToItsDocumentedPosture(): void
    {
        // "Detectar sozinho: permitido em shadow mode."
        $detect = $this->service->classifyAction('detect');
        $this->assertSame(AtlasAiAutonomyPowerBacklogService::POSTURE_SHADOW, $detect['posture']);
        $this->assertTrue($detect['may_run_autonomously']);
        $this->assertFalse($detect['requires_approval']);

        // "Planejar sozinho: permitido com evidence."
        $plan = $this->service->classifyAction('plan');
        $this->assertSame(AtlasAiAutonomyPowerBacklogService::POSTURE_EVIDENCE, $plan['posture']);
        $this->assertTrue($plan['requires_evidence']);
        $this->assertTrue($plan['may_run_autonomously']);

        // "Testar em sandbox: permitido com gates."
        $sandbox = $this->service->classifyAction('sandbox_test');
        $this->assertSame(AtlasAiAutonomyPowerBacklogService::POSTURE_GATED_SANDBOX, $sandbox['posture']);
        $this->assertTrue($sandbox['requires_gates']);
        $this->assertTrue($sandbox['may_run_autonomously']);
    }

    public function testMutatingActionsAreNeverAutonomousAndRequireDecisionReceipt(): void
    {
        // "Alterar producao, dinheiro, privacidade ou sistema critico:
        //  somente com Decision Receipt + approval."
        foreach (['mutate_production', 'spend_money', 'access_privacy', 'mutate_critical_system'] as $action) {
            $decision = $this->service->classifyAction($action);
            $this->assertSame(
                AtlasAiAutonomyPowerBacklogService::POSTURE_APPROVAL,
                $decision['posture'],
                "{$action} must require approval"
            );
            $this->assertFalse($decision['may_run_autonomously'], "{$action} must not run autonomously");
            $this->assertTrue($decision['requires_decision_receipt'], "{$action} must require a Decision Receipt");
            $this->assertTrue($decision['requires_approval']);
        }
    }

    public function testUnknownActionDefaultsToTheMostRestrictivePosture(): void
    {
        // A novel/unnamed power must not leak past the gate.
        $unknown = $this->service->classifyAction('teleport-the-datacenter');
        $this->assertFalse($unknown['action_known']);
        $this->assertSame(AtlasAiAutonomyPowerBacklogService::POSTURE_APPROVAL, $unknown['posture']);
        $this->assertFalse($unknown['may_run_autonomously']);
        $this->assertSame('unknown_action_defaults_to_approval', $unknown['reason']);
    }

    public function testBacklogIsOrderedAndCarriesPerItemGates(): void
    {
        $backlog = $this->service->backlog();
        $this->assertSame(7, $backlog['count']);

        $ids = array_column($backlog['items'], 'id');
        // Documented order from the "Backlog Ordenado" table.
        $this->assertSame([
            'dynamic_compute_market',
            'tool_synthesis',
            'zero_click_shadow_mode',
            'real_world_feedback_loop',
            'scenario_simulation_harness',
            'multimodal_continuous_context',
            'cross_domain_heuristic_transfer',
        ], $ids);

        // Item 1 carries its exact documented gate.
        $first = $backlog['items'][0];
        $this->assertSame(1, $first['order']);
        $this->assertSame('candidate_high', $first['status']);
        $this->assertSame('AP-99 populado com dados confiaveis', $first['gate']);
    }

    public function testItemStaysCandidateUntilItsGateIsSatisfied(): void
    {
        $blocked = $this->service->itemReadyToImplement('tool_synthesis', false);
        $this->assertTrue($blocked['known']);
        $this->assertFalse($blocked['ready']);
        $this->assertSame('gate_not_satisfied_remains_candidate', $blocked['reason']);

        $ready = $this->service->itemReadyToImplement('tool_synthesis', true);
        $this->assertTrue($ready['ready']);
        $this->assertSame('gate_satisfied_may_implement', $ready['reason']);
        $this->assertSame('Super Tool Runtime registry + sandbox + security gate', $ready['gate']);

        $unknown = $this->service->itemReadyToImplement('mind-upload', true);
        $this->assertFalse($unknown['known']);
        $this->assertFalse($unknown['ready']);
        $this->assertSame('unknown_backlog_item', $unknown['reason']);
    }

    public function testComputeMarketHonoursManualOverrideAndNeverSwitchesProvider(): void
    {
        // "sem trocar provider quando usuario passou modelo manual"
        $manual = $this->service->computeMarketDecision('claude-opus', 'gemini-pro', 'claude-sonnet');
        $this->assertSame('claude-opus', $manual['recommended_model']);
        $this->assertSame(AtlasAiAutonomyPowerBacklogService::MARKET_MANUAL_OVERRIDE, $manual['reason']);
        $this->assertFalse($manual['switched_provider']);
        $this->assertTrue($manual['read_only']);

        // Policy/budget restriction binds -> recommend the best ALLOWED.
        $allowed = $this->service->computeMarketDecision(null, 'gemini-pro', 'claude-sonnet');
        $this->assertSame('claude-sonnet', $allowed['recommended_model']);
        $this->assertSame(AtlasAiAutonomyPowerBacklogService::MARKET_BEST_ALLOWED, $allowed['reason']);
        $this->assertTrue($allowed['switched_provider']);

        // No restriction -> recommend the best AVAILABLE.
        $available = $this->service->computeMarketDecision(null, 'gemini-pro', null);
        $this->assertSame('gemini-pro', $available['recommended_model']);
        $this->assertSame(AtlasAiAutonomyPowerBacklogService::MARKET_BEST_AVAILABLE, $available['reason']);
        $this->assertFalse($available['switched_provider']);
    }

    public function testPromotionGateRequiresAllSevenRequirements(): void
    {
        // A half-filled set is blocked and reports exactly what is missing.
        $partial = $this->service->evaluatePromotion([
            'dedicated_ap_or_adr' => true,
            'owner' => true,
            'safety_boundary' => true,
        ]);
        $this->assertFalse($partial['promotable']);
        $this->assertSame('promotion_gate_requires_all_requirements', $partial['reason']);
        $this->assertSame([
            'expected_evidence_ledger_events',
            'tests_or_scanner',
            'rollback',
            'canonical_doc_updated',
        ], $partial['missing']);

        // All seven present -> promotable with nothing missing.
        $full = $this->service->evaluatePromotion([
            'dedicated_ap_or_adr' => true,
            'owner' => true,
            'safety_boundary' => true,
            'expected_evidence_ledger_events' => true,
            'tests_or_scanner' => true,
            'rollback' => true,
            'canonical_doc_updated' => true,
        ]);
        $this->assertTrue($full['promotable']);
        $this->assertSame([], $full['missing']);
        $this->assertNull($full['reason']);
        $this->assertCount(7, $full['satisfied']);
    }
}
