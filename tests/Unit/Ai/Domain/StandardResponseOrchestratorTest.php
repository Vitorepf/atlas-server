<?php

namespace Tests\Unit\Ai\Domain;

use App\Services\Ai\Domain\StandardResponseOrchestrator;
use Tests\TestCase;

class StandardResponseOrchestratorTest extends TestCase
{
    public function test_general_orchestrator_is_implemented_and_triage_only(): void
    {
        $orchestrator = app(StandardResponseOrchestrator::class);

        $this->assertSame('StandardResponseOrchestrator', $orchestrator->orchestratorId());
        $this->assertSame(['general'], $orchestrator->supportedDomains());
        $this->assertSame('implemented', $orchestrator->maturity());
        $this->assertSame(['general.answer'], $orchestrator->supportedFlows());

        $plan = $orchestrator->plan('general.answer', [
            'question' => 'Como encontro o dominio correto para campanha de marketing?',
        ]);

        $this->assertSame('planned', $plan['status']);
        $this->assertSame('general_answer', $plan['mode']);
        $this->assertSame('marketing', data_get($plan, 'packet.brief.suspected_domain'));
        $this->assertTrue(data_get($plan, 'packet.general_contract.must_handoff_specialized_requests'));
    }

    public function test_general_execute_emits_dry_run_receipt_and_evidence(): void
    {
        $orchestrator = app(StandardResponseOrchestrator::class);
        $plan = $orchestrator->plan('general.answer', [
            'question' => 'O que e o Atlas AI?',
            'desired_output' => 'short answer',
        ]);

        $result = $orchestrator->execute($plan, [
            'tenant_id' => 'test',
            'operator_id' => 'vitor',
            'surface_id' => 'phpunit',
        ]);

        $this->assertSame('succeeded', $result['status']);
        $this->assertSame('general.answer', $result['flow']);
        $this->assertTrue($result['answer_or_triage_only']);
        $this->assertTrue(data_get($result, 'result.receipt.dry_run'));
        $this->assertSame('atlas.general.packet.v1', data_get($result, 'result.receipt.signed_by'));
        $this->assertContains('DECISION_ISSUED', data_get($result, 'result.ledger.events'));
    }
}
