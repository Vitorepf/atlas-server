<?php

namespace Tests\Unit\Ai\Domain;

use App\Services\Ai\Domain\AtlasHealthOrchestrator;
use Tests\TestCase;

class AtlasHealthOrchestratorTest extends TestCase
{
    public function test_health_orchestrator_is_implemented_and_non_clinical(): void
    {
        $orchestrator = app(AtlasHealthOrchestrator::class);

        $this->assertSame('AtlasHealthOrchestrator', $orchestrator->orchestratorId());
        $this->assertSame(['health'], $orchestrator->supportedDomains());
        $this->assertSame('implemented', $orchestrator->maturity());
        $this->assertSame(['health.review', 'health.routine_review', 'health.recovery_review', 'health.safety_review'], $orchestrator->supportedFlows());

        $plan = $orchestrator->plan('health.review', [
            'topic' => 'focus and energy routine',
            'goal' => 'create questions for professional review if needed',
            'constraints' => ['non-clinical'],
        ]);

        $this->assertSame('planned', $plan['status']);
        $this->assertSame('wellness_review', $plan['mode']);
        $this->assertTrue(data_get($plan, 'packet.health_contract.non_clinical_review_only'));
        $this->assertTrue(data_get($plan, 'packet.rules.does_not_diagnose'));
    }

    public function test_health_execute_emits_dry_run_receipt_and_evidence(): void
    {
        $orchestrator = app(AtlasHealthOrchestrator::class);
        $plan = $orchestrator->plan('health.recovery_review', [
            'topic' => 'recovery routine after intense work week',
            'goal' => 'non-clinical recovery considerations',
            'signals' => ['fatigue', 'poor sleep'],
            'constraints' => ['no medical claims'],
        ]);

        $result = $orchestrator->execute($plan, [
            'tenant_id' => 'test',
            'operator_id' => 'vitor',
            'surface_id' => 'phpunit',
        ]);

        $this->assertSame('succeeded', $result['status']);
        $this->assertSame('health.recovery_review', $result['flow']);
        $this->assertTrue($result['non_clinical_review_only']);
        $this->assertTrue(data_get($result, 'result.receipt.dry_run'));
        $this->assertSame('atlas.health.packet.v1', data_get($result, 'result.receipt.signed_by'));
        $this->assertContains('DECISION_ISSUED', data_get($result, 'result.ledger.events'));
    }
}
