<?php

namespace Tests\Feature\Ai\MarketingDomain;

use App\Models\AiMarketingWinningPattern;
use App\Services\Ai\MarketingDomain\Decision\MarketingDecisionAdvisor;
use App\Services\Ai\MarketingDomain\Decision\MarketingDecisionLedger;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesMarketingDomainTables;
use Tests\TestCase;

class MarketingDecisionAdvisorTest extends TestCase
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

    /** Pure: winners' funnel rates (pct) become stage floors (fraction × GROUND_FRACTION). */
    public function test_floors_are_derived_from_winning_pattern_funnel(): void
    {
        $floors = MarketingDecisionAdvisor::floorsFromFunnelProfile([
            'vsl_completion_rate_pct' => 40.0,   // → 0.40 * 0.5 = 0.20
            'checkout_conversion_pct' => 19.0,   // → 0.19 * 0.5 = 0.095
            'vsl_play_rate_pct' => 99.0,         // not mapped to a stage floor
        ]);

        $this->assertSame(0.20, $floors['vsl_watch_through']);
        $this->assertSame(0.095, $floors['checkout_rate']);
        $this->assertArrayNotHasKey('ad_ctr', $floors);
    }

    public function test_advise_grounds_cvr_and_floors_in_real_pattern(): void
    {
        AiMarketingWinningPattern::query()->create([
            'id' => (string) Str::uuid(),
            'niche' => 'weight_loss',
            'real_cvr' => 0.0209,
            'funnel_profile' => ['vsl_completion_rate_pct' => 40.0, 'checkout_conversion_pct' => 19.0],
        ]);

        $advisor = app(MarketingDecisionAdvisor::class);

        // watch-through 0.10 is below the GROUNDED floor (0.20) → weak hook, not a generic call.
        $d = $advisor->advise(
            ['vsl_watch_through' => 0.10, 'sales' => 4, 'spend' => 500.0],
            ['payout' => 200.0, 'niche' => 'weight_loss'],
        );

        $this->assertTrue($d['grounding']['pattern_found']);
        $this->assertSame('nivor_winning_pattern:weight_loss', $d['grounding']['cvr_source']);
        $this->assertSame(0.20, $d['grounding']['grounded_floors']['vsl_watch_through']);
        $this->assertSame('edit_hook', $d['primary']['action']);
        // history annotation present (no prior decisions yet)
        $this->assertSame(0, $d['primary']['history']['prior_decisions']);
    }

    public function test_advise_compounds_ledger_history_into_the_recommendation(): void
    {
        AiMarketingWinningPattern::query()->create([
            'id' => (string) Str::uuid(),
            'niche' => 'weight_loss',
            'real_cvr' => 0.0209,
            'funnel_profile' => ['checkout_conversion_pct' => 19.0],
        ]);

        $ledger = app(MarketingDecisionLedger::class);
        // Three prior 'edit_bridge_headline' decisions on ad_ctr: 1 improved, 2 worse → win_rate 0.333 (losing).
        foreach (['improved', 'worse', 'worse'] as $outcome) {
            $e = $ledger->record(
                ['stage' => 'ad_ctr', 'action' => 'edit_bridge_headline', 'symptom' => 's', 'lever' => 'l'],
                ['niche' => 'weight_loss'],
            );
            $ledger->attachResult($e->id, $outcome);
        }

        $advisor = app(MarketingDecisionAdvisor::class);
        $d = $advisor->advise(
            ['ad_ctr' => 0.01, 'sales' => 3, 'spend' => 400.0],
            ['payout' => 200.0, 'niche' => 'weight_loss'],
        );

        $this->assertSame('edit_bridge_headline', $d['primary']['action']);
        $this->assertSame(3, $d['primary']['history']['prior_decisions']);
        $this->assertSame(0.333, $d['primary']['history']['win_rate']);
        // a losing lever nudges toward the alternative action (close_audience)
        $this->assertStringContainsString('considerar a alternativa', $d['primary']['history']['note']);
    }
}
