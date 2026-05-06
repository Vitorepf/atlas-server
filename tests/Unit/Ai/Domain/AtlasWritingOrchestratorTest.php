<?php

namespace Tests\Unit\Ai\Domain;

use App\Services\Ai\Domain\AtlasWritingOrchestrator;
use Tests\TestCase;

class AtlasWritingOrchestratorTest extends TestCase
{
    public function test_writing_orchestrator_is_implemented_and_review_only_for_publication(): void
    {
        $orchestrator = app(AtlasWritingOrchestrator::class);

        $this->assertSame('implemented', $orchestrator->maturity());
        $this->assertSame(['writing'], $orchestrator->supportedDomains());
        $this->assertContains('writing.publish_review', $orchestrator->supportedFlows());

        $plan = $orchestrator->plan('writing.voice_review', [
            'goal' => 'Revisar voz de uma documentação canônica.',
            'audience' => 'agentes de IA',
            'voice' => 'profissional, claro, sem hype',
        ]);

        $this->assertSame('planned', $plan['status']);
        $this->assertSame('voice_review', $plan['mode']);
        $this->assertFalse(data_get($plan, 'packet.rules.external_publish_allowed'));
    }

    public function test_writing_execute_emits_dry_run_receipt_and_evidence(): void
    {
        $orchestrator = app(AtlasWritingOrchestrator::class);
        $plan = $orchestrator->plan('writing.draft', [
            'goal' => 'Criar um resumo operacional do fluxo Atlas.',
            'audience' => 'humano e IA',
            'voice' => 'direto',
        ]);

        $result = $orchestrator->execute($plan, [
            'tenant_id' => 'test',
            'operator_id' => 'vitor',
            'surface_id' => 'phpunit',
        ]);

        $this->assertSame('succeeded', $result['status']);
        $this->assertSame('writing.draft', $result['flow']);
        $this->assertFalse($result['external_publish_allowed']);
        $this->assertTrue(data_get($result, 'result.receipt.dry_run'));
        $this->assertSame('atlas.writing.packet.v1', data_get($result, 'result.receipt.signed_by'));
        $this->assertContains('DECISION_ISSUED', data_get($result, 'result.ledger.events'));
    }
}
