<?php

namespace Tests\Unit\Ai\Domain;

use App\Services\Ai\Domain\AtlasResearchOrchestrator;
use Tests\TestCase;

class AtlasResearchOrchestratorTest extends TestCase
{
    public function test_research_orchestrator_is_implemented_and_source_grounded(): void
    {
        $orchestrator = app(AtlasResearchOrchestrator::class);

        $this->assertSame('AtlasResearchOrchestrator', $orchestrator->orchestratorId());
        $this->assertSame(['research'], $orchestrator->supportedDomains());
        $this->assertSame('implemented', $orchestrator->maturity());
        $this->assertSame(['research.quick', 'research.super'], $orchestrator->supportedFlows());

        $plan = $orchestrator->plan('research.quick', [
            'question' => 'O que falta no Context Builder?',
            'sources' => ['docs/engineering-knowledge-base/atlas-ai-pipeline.md'],
        ]);

        $this->assertSame('planned', $plan['status']);
        $this->assertSame('quick_research_plan', $plan['mode']);
        $this->assertSame('research.quick', $plan['flow']);
        $this->assertTrue(data_get($plan, 'packet.rules.source_grounded'));
        $this->assertContains('hide_uncertainty', data_get($plan, 'forbidden_actions'));
    }

    public function test_research_execute_emits_dry_run_receipt_and_evidence(): void
    {
        $orchestrator = app(AtlasResearchOrchestrator::class);
        $plan = $orchestrator->plan('research.super', [
            'question' => 'Quais padrões de Graph RAG valem para o Atlas?',
            'sources' => [
                ['title' => 'Paper A', 'url' => 'https://example.com/a'],
                ['title' => 'Docs B', 'url' => 'https://example.com/b'],
                ['title' => 'Case C', 'url' => 'https://example.com/c'],
            ],
        ]);

        $result = $orchestrator->execute($plan, [
            'tenant_id' => 'test',
            'operator_id' => 'vitor',
        ]);

        $this->assertSame('succeeded', $result['status']);
        $this->assertSame('deep_research_plan', $result['mode']);
        $this->assertSame('proposal_only', $result['memory_promotion']);
        $this->assertSame('research', data_get($result, 'result.receipt.domain'));
        $this->assertSame('research.super', data_get($result, 'result.receipt.flow'));
        $this->assertSame('atlas.research.packet.v1', data_get($result, 'result.receipt.signed_by'));
        $this->assertContains('DECISION_ISSUED', data_get($result, 'result.ledger.events'));
    }
}
