<?php

namespace Tests\Unit\Ai\Domain;

use App\Services\Ai\Domain\AtlasStrategicDecisionOrchestrator;
use Tests\TestCase;

class AtlasStrategicDecisionOrchestratorTest extends TestCase
{
    public function test_strategic_decision_orchestrator_is_implemented_and_review_only(): void
    {
        $orchestrator = app(AtlasStrategicDecisionOrchestrator::class);

        $this->assertSame('AtlasStrategicDecisionOrchestrator', $orchestrator->orchestratorId());
        $this->assertSame(['strategic_decision'], $orchestrator->supportedDomains());
        $this->assertSame('implemented', $orchestrator->maturity());
        $this->assertContains('strategic_decision.counterargument', $orchestrator->supportedFlows());

        $plan = $orchestrator->plan('strategic_decision.review', [
            'title' => 'Escolher direcao do Atlas',
            'decision' => 'Priorizar arquitetura-mae antes de features novas',
            'options' => ['arquitetura-mae', 'features novas'],
            'values' => ['qualidade', 'clareza'],
            'impact' => 'high',
        ]);

        $this->assertSame('planned', $plan['status']);
        $this->assertSame('review_only', $plan['mode']);
        $this->assertSame('strategic_decision.review', $plan['flow']);
        $this->assertSame('plan_only', data_get($plan, 'packet.mode'));
        $this->assertTrue(data_get($plan, 'packet.rules.preserve_operator_agency'));
        $this->assertContains('auto_life_decision', data_get($plan, 'forbidden_actions'));
    }

    public function test_strategic_decision_execute_emits_review_only_audit_result(): void
    {
        $orchestrator = app(AtlasStrategicDecisionOrchestrator::class);
        $plan = $orchestrator->plan('strategic_decision.review', [
            'title' => 'Escolher direcao do Atlas',
            'decision' => 'Priorizar arquitetura-mae antes de features novas',
            'options' => ['arquitetura-mae', 'features novas'],
            'values' => ['qualidade', 'clareza'],
        ]);

        $result = $orchestrator->execute($plan, [
            'tenant_id' => 'test',
            'operator_id' => 'vitor',
        ]);

        $this->assertSame('succeeded', $result['status']);
        $this->assertSame('review_only', $result['mode']);
        $this->assertTrue($result['no_external_side_effects']);
        $this->assertSame('strategic_decision', data_get($result, 'result.receipt.domain'));
        $this->assertSame('strategic_decision.review', data_get($result, 'result.receipt.flow'));
        $this->assertSame('atlas.strategic_decision.review.v1', data_get($result, 'result.receipt.signed_by'));
        $this->assertContains('DECISION_ISSUED', data_get($result, 'result.ledger.events'));
    }
}
