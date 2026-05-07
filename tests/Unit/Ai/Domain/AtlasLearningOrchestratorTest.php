<?php

namespace Tests\Unit\Ai\Domain;

use App\Services\Ai\Domain\AtlasLearningOrchestrator;
use Tests\TestCase;

class AtlasLearningOrchestratorTest extends TestCase
{
    public function test_learning_orchestrator_is_implemented_and_plan_only(): void
    {
        $orchestrator = app(AtlasLearningOrchestrator::class);

        $this->assertSame('AtlasLearningOrchestrator', $orchestrator->orchestratorId());
        $this->assertSame(['learning'], $orchestrator->supportedDomains());
        $this->assertSame('implemented', $orchestrator->maturity());
        $this->assertSame(['learning.plan', 'learning.practice', 'learning.review', 'learning.spaced_review', 'learning.worked_example'], $orchestrator->supportedFlows());

        $plan = $orchestrator->plan('learning.practice', [
            'objective' => 'Treinar arquitetura do Atlas por exercicios.',
            'topic' => 'Domain onboarding',
            'target_level' => 'implements_ready_domain',
        ]);

        $this->assertSame('planned', $plan['status']);
        $this->assertSame('deliberate_practice_plan', $plan['mode']);
        $this->assertTrue(data_get($plan, 'packet.rules.plan_only_until_operator_acceptance'));
        $this->assertTrue(data_get($plan, 'packet.rules.learning_domain_is_not_core_learning_plane'));
    }

    public function test_learning_execute_emits_dry_run_receipt_and_evidence(): void
    {
        $orchestrator = app(AtlasLearningOrchestrator::class);
        $plan = $orchestrator->plan('learning.plan', [
            'objective' => 'Entender o fluxo visual do Atlas AI.',
            'topic' => 'Atlas AI Flow v3',
            'target_level' => 'can_explain_and_update_docs',
        ]);

        $result = $orchestrator->execute($plan, [
            'tenant_id' => 'test',
            'operator_id' => 'vitor',
            'surface_id' => 'phpunit',
        ]);

        $this->assertSame('succeeded', $result['status']);
        $this->assertSame('learning.plan', $result['flow']);
        $this->assertTrue($result['plan_only_until_operator_acceptance']);
        $this->assertTrue(data_get($result, 'result.receipt.dry_run'));
        $this->assertSame('atlas.learning.packet.v1', data_get($result, 'result.receipt.signed_by'));
        $this->assertSame('atlas.decide.extension.dreyfus.v1', data_get($result, 'result.receipt.metadata.cognitive_decision.schema_version'));
        $this->assertSame(1, data_get($result, 'result.receipt.metadata.cognitive_decision.dreyfus_stage_resolved'));
        $this->assertContains('DECISION_ISSUED', data_get($result, 'result.ledger.events'));
    }
}
