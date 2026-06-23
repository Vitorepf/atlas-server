<?php

namespace Tests\Feature\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\EconomicsLedgerService;
use Tests\Concerns\CreatesMarketingDomainTables;
use Tests\TestCase;

class MarketingEconomicsLedgerTest extends TestCase
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

    public function test_records_outcomes_and_serves_niche_priors(): void
    {
        $svc = app(EconomicsLedgerService::class);

        $svc->recordOutcome('OT169', 'weight_loss', ['cvr_actual' => 0.02, 'roas_actual' => 1.5, 'margin_actual' => 0.30, 'max_cpa_achieved' => 120]);
        $svc->recordOutcome('OT170', 'weight_loss', ['cvr_actual' => 0.03, 'roas_actual' => 1.7, 'margin_actual' => 0.40, 'max_cpa_achieved' => 140]);
        $svc->recordOutcome('OT171', 'weight_loss', ['cvr_actual' => 0.04, 'roas_actual' => 2.1, 'margin_actual' => 0.50, 'max_cpa_achieved' => 160]);

        $priors = $svc->getFloorsByNiche('weight_loss');

        $this->assertTrue($priors['has_priors']);
        $this->assertSame(3, $priors['sample_size']);
        $this->assertSame(0.03, $priors['median_cvr']);   // median of 0.02/0.03/0.04
        $this->assertSame(1.7, $priors['median_roas']);
        $this->assertSame(140.0, $priors['avg_max_cpa']); // (120+140+160)/3
    }

    public function test_unknown_niche_has_no_priors(): void
    {
        $priors = app(EconomicsLedgerService::class)->getFloorsByNiche('nonexistent');
        $this->assertFalse($priors['has_priors']);
        $this->assertSame(0, $priors['sample_size']);
    }
}
