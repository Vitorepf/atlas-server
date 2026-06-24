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

    public function test_ad_is_an_elite_hook_congruent_with_the_page_lead(): void
    {
        // The ad (top of funnel) must share the page lead's scene + enemy so ad→bridge→page is congruent
        // (Eixo 6 message-match) and the scroll-stopper is grounded, not "Finance: grow your money".
        $composer = new StructuredFunnelComposer;
        $cong = new \App\Services\Ai\MarketingDomain\Content\FunnelCongruenceAuditor;
        $funnel = $composer->compose($this->asset([
            'niche' => 'finance', 'awareness_level' => 'problem_aware', 'core_promise' => 'grow your money',
            'mechanism_name' => 'The Allocation Rule',
        ]));
        $this->assertStringContainsString('Wall Street', $funnel['ad']);          // real enemy, grounded
        $this->assertStringContainsString('card gets declined', $funnel['ad']);   // concrete scene
        $hops = collect($cong->audit($funnel)['hops'])->keyBy(fn ($h) => $h['from'].'>'.$h['to']);
        $this->assertGreaterThanOrEqual(60, $hops['ad>bridge']['congruence'], 'ad must echo the bridge');
    }

    public function test_checkout_stacks_a_grand_slam_offer_not_a_thin_line(): void
    {
        // Eixo 5: the checkout must STACK the offer (bonuses mapped to objections + value anchoring),
        // reusing GrandSlamBuilder — not just "today only: $97".
        $checkout = (new StructuredFunnelComposer)->compose($this->asset([
            'niche' => 'weight loss', 'core_promise' => 'lose the weight',
            'offer' => ['price' => '97', 'guarantee' => '60-day money-back guarantee'],
        ]))['checkout'];
        $this->assertStringContainsString('bonuses', $checkout);
        $this->assertStringContainsString('worth $', $checkout);   // value anchor
        $this->assertStringContainsString('$97', $checkout);
        $this->assertStringContainsString('guarantee.', $checkout); // punctuation healed
    }

    public function test_a_rich_generated_funnel_passes_the_1_to_25_brain(): void
    {
        // Autonomy (pillar 2): given a rich asset, the Atlas's own generated funnel must pass its OWN
        // ConversionLeverageDiagnostic — no high-leverage bottleneck (mechanism/proof/offer/watch/friction/
        // believability all strong). The analysis→generation loop is closed and self-consistent.
        $funnel = (new StructuredFunnelComposer)->compose($this->asset([
            'niche' => 'weight loss', 'awareness_level' => 'problem_aware', 'sophistication_level' => 4,
            'core_promise' => 'lose the weight', 'mechanism_name' => 'The 3-Hormone Reset',
            'claims' => ['Dr. Lee tracked 312 women; 9 out of 10 dropped a size in 6 weeks'],
            'metrics' => ['result_claims' => ['results in 21 days']],
            'offer' => ['price' => '97', 'guarantee' => '60-day money-back guarantee', 'ease' => ['no gym', 'just 10 minutes a day']],
        ]));
        $diag = (new \App\Services\Ai\MarketingDomain\Content\ConversionLeverageDiagnostic)
            ->diagnose($this->asset(['niche' => 'weight loss', 'mechanism_name' => 'The 3-Hormone Reset']), $funnel['page'].' '.$funnel['checkout']);
        $this->assertNull($diag['bottleneck'], 'the generated funnel should have no high-leverage bottleneck');
    }

    public function test_proof_lever_is_planted_only_from_real_asset_proof(): void
    {
        // Eixo 7 end-to-end: a concrete claim on the asset → the page carries CONCRETE proof; a thin
        // asset → the page carries only the producer slot (no fabricated proof), which is NOT concrete.
        $composer = new StructuredFunnelComposer;
        $proof = new \App\Services\Ai\MarketingDomain\Content\ProofSubstanceAuditor;

        $rich = $composer->compose($this->asset([
            'niche' => 'weight loss', 'core_promise' => 'lose the weight',
            'claims' => ['Dr. Lee tracked 312 women; 9 out of 10 dropped a size in 6 weeks'],
        ]));
        $this->assertTrue($proof->audit($rich['page'])['has_concrete'], 'real asset proof must reach the page');

        $thin = $composer->compose($this->asset(['niche' => 'weight loss', 'core_promise' => 'lose the weight']));
        $this->assertFalse($proof->audit($thin['page'])['has_concrete'], 'no asset proof → no fabricated proof, only the slot');
    }
}
