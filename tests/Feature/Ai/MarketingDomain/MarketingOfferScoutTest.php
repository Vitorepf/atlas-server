<?php

namespace Tests\Feature\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Models\AiMarketingWinningPattern;
use App\Services\Ai\MarketingDomain\ICPPositioningService;
use App\Services\Ai\MarketingDomain\Offer\OfferEpcCalculator;
use App\Services\Ai\MarketingDomain\Offer\OfferScoutScorer;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesMarketingDomainTables;
use Tests\TestCase;

class MarketingOfferScoutTest extends TestCase
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

    private function pattern(): AiMarketingWinningPattern
    {
        return AiMarketingWinningPattern::query()->create([
            'id' => (string) Str::uuid(),
            'niche' => 'weight_loss',
            'real_cvr' => 0.0209,
            'sales_total' => 1200,
            'clicks_total' => 57000,
            'converting_keywords' => [['term' => 'jello diet', 'conversions' => 1083]],
        ]);
    }

    public function test_epc_is_grounded_in_real_cvr_and_network_refund(): void
    {
        $epc = (new OfferEpcCalculator)->epcProjection(['payout' => 200, 'network' => 'clickbank'], $this->pattern());

        // 0.0209 * 200 * (1 - 0.12) = 3.6784
        $this->assertSame(3.6784, $epc['projected_epc']);
        $this->assertSame('high', $epc['confidence']);
        $this->assertFalse($epc['refund_risk_flag']);
    }

    public function test_scout_verdict_is_hot_for_strong_aligned_offer(): void
    {
        $vsl = new AiMarketingVslAsset(['mechanism_name' => 'Jello Protocol', 'solution_mechanism' => 'pink gelatin']);
        $s = (new OfferScoutScorer)->score(['payout' => 200, 'network' => 'clickbank'], $vsl, $this->pattern());

        $this->assertSame('hot', $s['verdict']);
        $this->assertTrue($s['keyword_mechanism_alignment']['aligned']);
        $this->assertSame(123.2, $s['max_cpa']); // 200 * (1-0.12 clickbank refund) * 0.7 margin
    }

    public function test_icp_ranks_offers_by_epc(): void
    {
        $pattern = $this->pattern();
        $vsl = new AiMarketingVslAsset(['mechanism_name' => 'Jello Protocol']);

        $diag = app(ICPPositioningService::class)->offerQualityDiagnosis(
            [['payout' => 50, 'network' => 'clickbank'], ['payout' => 200, 'network' => 'clickbank']],
            $vsl,
            $pattern,
        );

        // higher payout → higher EPC → ranked first; nothing is refused
        $this->assertSame(200, $diag['best']['offer']['payout']);
        $this->assertCount(2, $diag['ranked']);
    }
}
