<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\ConversionLeverageDiagnostic;
use PHPUnit\Framework\TestCase;

/**
 * ConversionLeverageDiagnostic — the 1→25 roadmap. Names the single highest-leverage weak lever for a
 * funnel so the next build targets the 25x, not a polish. Composes the honest substance auditors.
 */
class ConversionLeverageDiagnosticTest extends TestCase
{
    public function test_a_commodity_page_bottlenecks_on_the_named_mechanism(): void
    {
        $d = new ConversionLeverageDiagnostic;
        $r = $d->diagnose(
            new AiMarketingVslAsset(['niche' => 'weight loss']),
            'Lose weight fast. It works great. Studies show it helps. Buy now, satisfaction guaranteed.'
        );
        $this->assertNotNull($r['bottleneck']);
        // Named mechanism is tier 5 — the highest-leverage weak lever wins.
        $this->assertSame('named_mechanism', $r['bottleneck']['key']);
        $this->assertStringContainsString('GARGALO', $r['summary']);
        // The roadmap is ordered by leverage and lists the weak levers only.
        $this->assertNotContains('watch_through', $r['roadmap']); // a single hookless line has no leak
    }

    public function test_proof_outranks_offer_when_both_are_weak(): void
    {
        // Has a named mechanism (strong) but no concrete proof and an open offer → proof (tier 5) wins.
        $d = new ConversionLeverageDiagnostic;
        $r = $d->diagnose(
            new AiMarketingVslAsset(['niche' => 'finance', 'mechanism_name' => 'The Allocation Rule']),
            'Here is how The Allocation Rule works to grow your money. Experts agree it is great.'
        );
        $this->assertSame('proof', $r['bottleneck']['key']);
    }

    public function test_believability_lever_catches_an_orphan_claim(): void
    {
        // Named mechanism present, but a bold promise with NO external proof beside it = believability hole.
        $d = new ConversionLeverageDiagnostic;
        $r = $d->diagnose(
            new AiMarketingVslAsset(['niche' => 'finance', 'mechanism_name' => 'The Allocation Rule']),
            'Here is how The Allocation Rule works. You will double your money in 30 days. Order now.'
        );
        $believability = collect($r['levers'])->firstWhere('key', 'believability');
        $this->assertNotNull($believability, 'the diagnostic must include the believability lever');
        $this->assertSame('weak', $believability['status']);
    }

    public function test_a_strong_page_has_no_high_leverage_bottleneck(): void
    {
        $d = new ConversionLeverageDiagnostic;
        // Mechanism named, concrete proof, offer levers, reveal held to the end, single CTA.
        $copy = 'Have you wondered why the weight stays? It is not your fault. '
            .'Imagine your body back. You will see results in 21 days, with no gym. '
            .'Dr. Aronson ran this on 312 women; 9 out of 10 dropped a dress size in 6 weeks. '
            .'Here is how The 3-Hormone Reset finally makes it work. '
            .'Get instant access, no credit card, cancel anytime. Watch the free presentation now.';
        $r = $d->diagnose(new AiMarketingVslAsset(['niche' => 'weight loss', 'mechanism_name' => 'The 3-Hormone Reset']), $copy);
        $this->assertNull($r['bottleneck'], 'a funnel strong on every high-leverage lever has no bottleneck');
        $this->assertSame([], $r['roadmap']);
    }
}
