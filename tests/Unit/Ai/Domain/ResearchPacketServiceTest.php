<?php

namespace Tests\Unit\Ai\Domain;

use App\Services\Ai\Domain\ResearchPacketService;
use Tests\TestCase;

class ResearchPacketServiceTest extends TestCase
{
    public function test_research_packet_requires_sources_citations_and_uncertainty(): void
    {
        $packet = app(ResearchPacketService::class)->packet('research.super', [
            'question' => 'Quais padrões de Graph RAG valem para o Atlas?',
            'purpose' => 'Atualizar arquitetura de contexto',
            'sources' => [
                ['title' => 'Paper A', 'url' => 'https://example.com/a', 'type' => 'paper'],
                ['title' => 'Docs B', 'url' => 'https://example.com/b', 'type' => 'docs'],
                ['title' => 'Case C', 'url' => 'https://example.com/c', 'type' => 'case'],
            ],
        ]);

        $this->assertSame(ResearchPacketService::SCHEMA_VERSION, $packet['schema_version']);
        $this->assertSame('deep_research_plan', $packet['mode']);
        $this->assertSame('research.super', $packet['flow']);
        $this->assertTrue(data_get($packet, 'research_plan.contradiction_check_required'));
        $this->assertTrue(data_get($packet, 'output_contract.uncertainty'));
        $this->assertSame('proposal_only', data_get($packet, 'output_contract.memory_promotion'));
        $this->assertContains('promote_to_memory_without_review', $packet['forbidden_actions']);
        $this->assertSame('passed', collect($packet['gates'])->firstWhere('id', 'contradiction_check')['status']);
    }
}
