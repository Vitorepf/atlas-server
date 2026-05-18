<?php

namespace Tests\Feature\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\ICPPositioningService;
use App\Services\Ai\MarketingDomain\MarketingDomainCanon;
use App\Services\Ai\MarketingDomain\MarketingRuntimeService;
use InvalidArgumentException;
use Tests\Concerns\CreatesMarketingDomainTables;
use Tests\TestCase;

class MarketingICPPositioningTest extends TestCase
{
    use CreatesMarketingDomainTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMarketingDomainTables();
    }

    protected function tearDown(): void
    {
        $this->dropMarketingDomainTables();
        parent::tearDown();
    }

    public function test_icp_requires_core_fields(): void
    {
        $run = app(MarketingRuntimeService::class)->open('Atlas Vault', 'objective');
        $this->expectException(InvalidArgumentException::class);
        app(ICPPositioningService::class)->defineICP($run, ['segment' => 'X']);
    }

    public function test_positioning_requires_proof_points(): void
    {
        $run = app(MarketingRuntimeService::class)->open('Atlas Vault', 'objective');
        $this->expectException(InvalidArgumentException::class);
        app(ICPPositioningService::class)->definePositioning($run, [
            'promise' => 'A', 'differentiation' => 'B', 'proof_points' => [],
        ]);
    }

    public function test_icp_and_positioning_persist_as_artifacts(): void
    {
        $run = app(MarketingRuntimeService::class)->open('Atlas Vault', 'objective');
        $icp = app(ICPPositioningService::class)->defineICP($run, [
            'segment' => 'X', 'pains' => ['a'], 'jobs_to_be_done' => ['b'], 'gains' => ['c'], 'channels' => ['d'],
        ]);
        $pos = app(ICPPositioningService::class)->definePositioning($run, [
            'promise' => 'p', 'differentiation' => 'd', 'proof_points' => ['x'],
        ]);
        $this->assertSame(MarketingDomainCanon::ARTIFACT_ICP, $icp->artifact_type);
        $this->assertSame(MarketingDomainCanon::ARTIFACT_POSITIONING, $pos->artifact_type);
        $this->assertSame(64, strlen((string) $icp->artifact_hash));
    }
}
