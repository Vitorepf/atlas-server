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

    public function test_compose_bridge_is_structurally_sound_and_survives_polish(): void
    {
        $composer = new StructuredFunnelComposer;
        $leaks = new \App\Services\Ai\MarketingDomain\Content\WatchThroughLeakDetector;
        $decision = new \App\Services\Ai\MarketingDomain\Content\DecisionClarityAuditor;
        $asset = $this->asset(['niche' => 'weight loss', 'core_promise' => 'lose belly fat',
            'mechanism_name' => 'the morning ritual', 'metrics' => ['result_claims' => ['lost 30 lbs']]]);

        $bridge = $composer->composeBridge($asset);
        $flat = trim(implode("\n", array_filter([
            (string) $bridge['headline'], (string) $bridge['kicker'], (string) $bridge['subheadline'],
            (string) $bridge['lead_paragraph'],
            implode(' ', array_map(fn ($s) => $s['heading'].' '.$s['body'], $bridge['body_sections'])),
            implode(' ', array_map(fn ($c) => $c['label'].' '.$c['sub'], $bridge['cta_blocks'])),
        ])));

        // Generated scaffold is structurally clean: reveal held late, single CTA, no premature leak.
        $this->assertSame([], $leaks->detect($flat)['flaws']);
        $this->assertSame([], $decision->audit($flat)['flaws']);

        // And the structure-safe amplifier polishes it WITHOUT introducing a structural defect.
        $out = (new \App\Services\Ai\MarketingDomain\Content\AggressionAmplifier)
            ->amplify($bridge, $asset, ['until' => 'killer', 'max_iterations' => 3]);
        $this->assertSame(0, $out['structural_defects']);
    }

    public function test_generated_funnel_closes_levers_only_with_real_asset_substance(): void
    {
        // A brutal panel proved v1 closed the levers with fixed FILLER + an empty PROOF SLOT (Goodhart).
        // Honest contract now: when the asset carries real substance (timeframe, proof/guarantee, named
        // effort removal), the page covers the levers; when it does NOT, the page leaves an HONEST gap.
        $composer = new StructuredFunnelComposer;
        $ve = new \App\Services\Ai\MarketingDomain\Content\ValueEquationAuditor;

        foreach ([
            ['niche' => 'weight loss', 'core_promise' => 'lose the weight', 'sophistication_level' => 3],
            ['niche' => 'finance', 'core_promise' => 'grow your money', 'sophistication_level' => 4],
            ['niche' => 'relationship', 'core_promise' => 'win them back', 'sophistication_level' => 2],
        ] as $base) {
            $rich = $composer->compose($this->asset($base + [
                'metrics' => ['result_claims' => ['real results in 21 days'], 'timeframe' => ['21 days']],
                'offer' => ['guarantee' => '60-day money-back guarantee', 'ease' => ['no gym', 'just 10 minutes a day']],
            ]));
            $this->assertSame([], $ve->audit($rich['page'].' '.$rich['checkout'])['gaps'],
                "rich {$base['niche']} asset should close every lever with real substance");
        }

        // Thin asset: no proof, no timeframe, no effort claim → the proof lever stays an honest gap
        // (the empty PROOF SLOT must NOT auto-cover it).
        $thin = $composer->compose($this->asset(['niche' => 'weight loss', 'core_promise' => 'lose the weight']));
        $gapKeys = array_column($ve->audit($thin['page'].' '.$thin['checkout'])['gaps'], 'key');
        $this->assertContains('perceived_likelihood', $gapKeys, 'an empty proof slot must NOT count as proof');
    }
}
