<?php

namespace Tests\Feature\Ai\FinanceDomain;

use App\Services\Ai\Finance\Kernel\FinanceDomainException;
use App\Services\Ai\Finance\Kernel\FinanceResearchDeskService;
use App\Services\Ai\Mission\MissionFactoryService;
use Tests\Concerns\CreatesFinanceDomainTables;
use Tests\TestCase;

class FinanceDomainResearchDeskTest extends TestCase
{
    use CreatesFinanceDomainTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFinanceDomainTables();
    }

    protected function tearDown(): void
    {
        $this->dropFinanceDomainTables();
        parent::tearDown();
    }

    public function test_research_note_includes_sources_and_blocks_live_trade(): void
    {
        $mission = app(MissionFactoryService::class)->create('research AAPL', ['primary_domain' => 'finance']);
        $note = app(FinanceResearchDeskService::class)->research($mission, 'AAPL', [
            'sources' => ['internal:demo:source-1', 'browser:cache:demo'],
        ]);

        $this->assertSame('research_note', $note['kind']);
        $this->assertNotEmpty($note['sources']);
        $this->assertTrue($note['live_trade_blocked']);
        $this->assertSame(64, strlen((string) $note['receipt_hash']));
    }

    public function test_research_requires_at_least_one_source(): void
    {
        $mission = app(MissionFactoryService::class)->create('research AAPL', ['primary_domain' => 'finance']);

        $this->expectException(FinanceDomainException::class);
        $this->expectExceptionMessageMatches('/at least one source_ref is required/');
        app(FinanceResearchDeskService::class)->research($mission, 'AAPL', ['sources' => []]);
    }
}
