<?php

namespace Tests\Feature\Ai\MarketingDomain;

use App\Models\AiMarketingWinningPattern;
use App\Services\Ai\MarketingDomain\Campaign\SmartBiddingReadinessDiagnostic;
use App\Services\Ai\MarketingDomain\Campaign\TrackingStackDecider;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesMarketingDomainTables;
use Tests\TestCase;

class MarketingSmartBiddingReadinessTest extends TestCase
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

    private function pattern(int $clicks = 2_000_000, string $niche = 'weight_loss'): AiMarketingWinningPattern
    {
        return AiMarketingWinningPattern::query()->create([
            'id' => (string) Str::uuid(),
            'niche' => $niche,
            'real_cvr' => 0.0209,
            'clicks_total' => $clicks,
            'sales_total' => 1200,
        ]);
    }

    public function test_readiness_says_when_troas_unlocks(): void
    {
        $d = (new SmartBiddingReadinessDiagnostic)->assess($this->pattern(), [
            'daily_conversions' => 2.0,
            'conversions_last_30d' => 9, // below 15
        ]);

        $this->assertFalse($d['per_strategy']['target_roas']['ready']);
        // (15 - 9) / 2 = 3 days
        $this->assertSame(3, $d['per_strategy']['target_roas']['days_to_ready']);
        $this->assertTrue($d['per_strategy']['maximize_conversions']['ready']);
    }

    public function test_broken_loop_blocks_migration(): void
    {
        $d = (new SmartBiddingReadinessDiagnostic)->assess($this->pattern(), [
            'conversions_last_30d' => 50,
            'conversion_loop_complete' => false,
        ]);

        $this->assertTrue($d['data_quality_block']);
        $this->assertFalse($d['per_strategy']['target_roas']['ready']); // blocked despite volume
        $this->assertStringContainsString('loop de conversão', strtolower($d['migration_roadmap'][0]));
    }

    public function test_tracking_decider_picks_tier_by_throughput(): void
    {
        $hi = (new TrackingStackDecider)->decide($this->pattern(2_000_000, 'hi_vol'));
        $this->assertStringContainsString('Binom', $hi['recommended_tracker']);

        $lo = (new TrackingStackDecider)->decide($this->pattern(5_000, 'lo_vol'));
        $this->assertStringContainsString('ClickMagick', $lo['recommended_tracker']);
    }
}
