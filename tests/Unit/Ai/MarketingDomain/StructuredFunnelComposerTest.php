<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\FunnelCongruenceAuditor;
use App\Services\Ai\MarketingDomain\Content\StructuredFunnelComposer;
use PHPUnit\Framework\TestCase;

/**
 * StructuredFunnelComposer — the AUDIT→GENERATE leap. It builds a funnel chain that is structurally
 * SOUND BY CONSTRUCTION; the proof is that the very auditor that judges funnels rates its output `sound`
 * across niches (generator+verifier inverted at the funnel level).
 */
class StructuredFunnelComposerTest extends TestCase
{
    private function asset(array $attrs): AiMarketingVslAsset
    {
        return new AiMarketingVslAsset($attrs);
    }

    public function test_generated_funnel_is_structurally_sound_cross_niche(): void
    {
        $composer = new StructuredFunnelComposer;
        $auditor = new FunnelCongruenceAuditor;

        $assets = [
            'health' => $this->asset(['niche' => 'weight loss', 'core_promise' => 'lose belly fat',
                'mechanism_name' => 'the morning ritual', 'metrics' => ['result_claims' => ['lost 30 lbs in 90 days']], 'offer' => ['price' => '97']]),
            'finance' => $this->asset(['niche' => 'finance', 'core_promise' => 'grow your savings safely',
                'mechanism_name' => 'the allocation rule', 'metrics' => ['result_claims' => ['11% worst drawdown']], 'offer' => ['price' => '197']]),
            'relationship' => $this->asset(['niche' => 'relationship', 'core_promise' => 'win your ex back',
                'mechanism_name' => 'the reconnection sequence', 'offer' => ['price' => '47']]),
        ];

        foreach ($assets as $niche => $a) {
            $r = $auditor->audit($composer->compose($a));
            $this->assertSame('sound', $r['verdict'], "{$niche} funnel must be structurally sound, got: ".implode(' | ', $r['defects']));
        }
    }

    public function test_hero_number_is_carried_into_every_stage(): void
    {
        $funnel = (new StructuredFunnelComposer)->compose($this->asset([
            'core_promise' => 'lose belly fat', 'mechanism_name' => 'the morning ritual',
            'metrics' => ['result_claims' => ['lost 30 lbs']], 'niche' => 'weight loss', 'offer' => ['price' => '97'],
        ]));
        foreach (['ad', 'bridge', 'page', 'checkout'] as $stage) {
            $this->assertStringContainsString('30', $funnel[$stage], "stage {$stage} must carry the hero number");
        }
    }

    public function test_generated_funnel_fabricates_no_proof(): void
    {
        $composer = new StructuredFunnelComposer;
        $auditor = new FunnelCongruenceAuditor;
        $funnel = $composer->compose($this->asset([
            'core_promise' => 'grow your savings', 'mechanism_name' => 'the rule', 'niche' => 'finance', 'offer' => ['price' => '97'],
        ]));
        // The composer must never invent a named authority / media mention / testimonial / statistic.
        $this->assertSame([], $auditor->audit($funnel)['requires_proof']);
    }
}
