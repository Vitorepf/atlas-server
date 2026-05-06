<?php

namespace Tests\Unit\Ai\Domain;

use App\Services\Ai\Domain\AtlasMarketingOrchestrator;
use Tests\TestCase;

class AtlasMarketingOrchestratorTest extends TestCase
{
    public function test_marketing_orchestrator_is_implemented_and_draft_only(): void
    {
        $orchestrator = app(AtlasMarketingOrchestrator::class);

        $this->assertSame('AtlasMarketingOrchestrator', $orchestrator->orchestratorId());
        $this->assertSame(['marketing'], $orchestrator->supportedDomains());
        $this->assertSame('implemented', $orchestrator->maturity());
        $this->assertContains('marketing.forge', $orchestrator->supportedFlows());

        $plan = $orchestrator->plan('marketing.campaign', [
            'business_context' => 'blackink',
            'goal' => 'Criar campanha para oferta principal',
            'offer' => 'Produto X com garantia',
            'audience' => 'compradores frios interessados em performance',
            'brand_voice' => 'direto, premium e baseado em prova',
            'claims' => ['reduz tempo de setup com evidência interna'],
        ]);

        $this->assertSame('planned', $plan['status']);
        $this->assertSame('draft_and_review', $plan['mode']);
        $this->assertSame('marketing.campaign', $plan['flow']);
        $this->assertFalse(data_get($plan, 'packet.rules.external_publish_allowed'));
        $this->assertContains('auto_publish', data_get($plan, 'forbidden_actions'));
    }

    public function test_marketing_execute_emits_dry_run_receipt_and_evidence(): void
    {
        $orchestrator = app(AtlasMarketingOrchestrator::class);
        $plan = $orchestrator->plan('marketing.copywriting', [
            'goal' => 'Criar variações de headline',
            'offer' => 'Produto X com garantia',
            'audience' => 'compradores frios interessados em performance',
            'brand_voice' => 'direto, premium e baseado em prova',
            'claims' => ['reduz tempo de setup com evidência interna'],
        ]);

        $result = $orchestrator->execute($plan, [
            'tenant_id' => 'test',
            'operator_id' => 'vitor',
        ]);

        $this->assertSame('succeeded', $result['status']);
        $this->assertSame('draft_and_review', $result['mode']);
        $this->assertFalse($result['external_publish_allowed']);
        $this->assertSame('marketing', data_get($result, 'result.receipt.domain'));
        $this->assertSame('marketing.copywriting', data_get($result, 'result.receipt.flow'));
        $this->assertSame('atlas.marketing.draft.v1', data_get($result, 'result.receipt.signed_by'));
        $this->assertContains('DECISION_ISSUED', data_get($result, 'result.ledger.events'));
    }
}
