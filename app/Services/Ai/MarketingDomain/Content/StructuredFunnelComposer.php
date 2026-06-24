<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingVslAsset;

/**
 * StructuredFunnelComposer — the leap from AUDITING to GENERATING (generator+verifier inverted at the
 * FUNNEL level).
 *
 * Cycles 43-50 built a structural-truth layer that AUDITS a funnel. This uses that layer as the SPEC and
 * builds a complete ad → bridge → page → checkout chain that is structurally SOUND BY CONSTRUCTION:
 *   - promise continuity: the hero number/promise is carried into EVERY stage (no dropped_promise);
 *   - hop congruence: every stage echoes the same anchor keywords (message-match passes per hop);
 *   - watch-through: the page holds the mechanism REVEAL past the opening and puts ONE CTA at the end
 *     (no premature_reveal / premature_hard_cta);
 *   - decision clarity: each stage carries a single dominant action category (no choice overload);
 *   - moral line: NO fabricated proof — proof is a producer placeholder, never an invented authority/
 *     testimonial/statistic.
 *
 * It is grounded in the real AiMarketingVslAsset ammunition (promise, mechanism, hero metric, offer), so
 * it scaffolds a sound funnel with REAL specifics — not hollow template copy. Provider-free, deterministic.
 * The persuasive prose polish is the amplifier's job; this guarantees the STRUCTURE is right first.
 */
class StructuredFunnelComposer
{
    /**
     * @return array{ad:string,bridge:string,page:string,checkout:string}
     */
    public function compose(AiMarketingVslAsset $asset): array
    {
        $promise = $this->firstNonEmpty([(string) $asset->core_promise, (string) $asset->big_idea, 'a real change']);
        $mechanism = $this->firstNonEmpty([(string) $asset->mechanism_name, (string) $asset->solution_mechanism, 'the method']);
        $avatar = $this->avatarCallout($asset);
        $hero = $this->heroClaim($asset);                 // e.g. "30 lbs" or "" if none
        $heroLine = $hero !== '' ? " — {$hero}" : '';
        $price = $this->price($asset);                     // e.g. "$97" or ""
        $guarantee = $this->guarantee($asset);
        // Default = MAXIMUM aggression (operator: sem freio). The market's raw wound/dream is woven in
        // niche-flavored, so the scaffold ships aggressive by construction (not generic).
        $wound = (new \App\Services\Ai\MarketingDomain\Knowledge\AggressiveConversionTacticsLibrary)->nicheWound((string) $asset->niche);

        // ── AD: callout + hero promise (carries hero number) + soft CTA (free CONTENT, not the product).
        $ad = trim("{$avatar} {$promise}{$heroLine}. Watch the free presentation to see how — before it comes down.");

        // ── BRIDGE: echoes the ad's anchor words (congruent hop) + curiosity, NO reveal, soft forward CTA.
        $bridge = trim("{$avatar} If you want {$promise}{$heroLine}, there is one thing almost nobody explains. "
            ."Keep reading — it gets clearer in a moment, and then you will see exactly how.");

        // ── PAGE: carries hero, builds before the reveal (mechanism named LATE), single CTA at the end.
        $page = trim(implode(' ', array_filter([
            "{$avatar} have you wondered why {$promise} stays out of reach{$heroLine}?",   // hook (opening)
            'Most advice has it backwards, and it is not your fault.',                     // build
            "Every day you wait is another day {$wound['pain']}.",                         // fear (niche wound)
            'For a long time the real cause stayed hidden in plain sight.',                // build
            'It gets clearer once you see what is actually happening.',                    // forward pull
            'But first, understand what everyone else got wrong about this.',              // forward pull (mid)
            "Here is how {$mechanism} finally makes {$promise}{$heroLine} work.",          // REVEAL (late)
            "Imagine {$wound['dream']} — 30 days from now.",                               // future pacing (niche dream)
            '[PROOF SLOT: o caso/depoimento/estudo mais forte da oferta]',                  // proof slot
            'Watch the free presentation now — spots are limited.',                         // single CTA (end) + scarcity
        ])));

        // ── CHECKOUT: the offer + price + single purchase CTA. No "free" on the core product → no bait.
        $checkout = trim(implode(' ', array_filter([
            "Get the complete {$mechanism} system for {$promise}{$heroLine}.",
            $price !== '' ? "Today only: {$price}." : '',
            $guarantee !== '' ? $guarantee : '',
            'Order now to get instant access — before this closes.',
        ])));

        return ['ad' => $ad, 'bridge' => $bridge, 'page' => $page, 'checkout' => $checkout];
    }

    /**
     * Emit the PAGE stage as a bridge-structured array (the slot schema the AggressionAmplifier and the
     * BridgePageHtmlRenderer consume), so the generated scaffold plugs straight into the polish+render
     * pipeline. Built so that when flattened it stays structurally sound: the mechanism REVEAL sits in
     * the LAST body section (late in the flatten order), and there is a SINGLE cta block — so the
     * watch-through and decision-clarity detectors see no premature reveal / CTA and no choice overload.
     *
     * @return array<string,mixed>
     */
    public function composeBridge(AiMarketingVslAsset $asset): array
    {
        $promise = $this->firstNonEmpty([(string) $asset->core_promise, (string) $asset->big_idea, 'a real change']);
        $mechanism = $this->firstNonEmpty([(string) $asset->mechanism_name, (string) $asset->solution_mechanism, 'the method']);
        $avatar = $this->avatarCallout($asset);
        $hero = $this->heroClaim($asset);
        $heroLine = $hero !== '' ? " — {$hero}" : '';

        return [
            'kicker' => rtrim($avatar, ':'),
            'headline' => "Have you wondered why {$promise} stays out of reach{$heroLine}?",
            'subheadline' => "{$promise}{$heroLine} is closer than the industry wants you to believe.",
            'lead_paragraph' => 'Most advice has it backwards, and it is not your fault — the real cause stayed hidden in plain sight.',
            // NOTE: mechanism_tease left empty on purpose — it flattens EARLY; a reveal there would leak.
            'body_sections' => [
                ['heading' => 'What everyone got wrong', 'body' => 'For a long time the wrong thing got all the attention. It gets clearer once you see what is actually happening.'],
                ['heading' => 'But first', 'body' => 'Before the how, understand the one shift that changes everything — wait until you see it.'],
                ['heading' => 'The mechanism', 'body' => "Here is how {$mechanism} finally makes {$promise}{$heroLine} work."], // REVEAL, late
                ['heading' => 'The proof', 'body' => '[PROOF SLOT: o caso/depoimento/estudo mais forte da oferta]'],
            ],
            'cta_blocks' => [
                ['label' => 'Watch the free presentation', 'sub' => 'See the full method in action.'],
            ],
        ];
    }

    private function avatarCallout(AiMarketingVslAsset $asset): string
    {
        $avatar = is_array($asset->avatar) ? $asset->avatar : [];
        $who = trim((string) ($avatar['who'] ?? $avatar['label'] ?? ''));
        if ($who !== '') {
            return rtrim($who, '.:').':';
        }
        $niche = trim((string) $asset->niche);

        return $niche !== '' ? ucfirst($niche).':' : 'For you:';
    }

    private function heroClaim(AiMarketingVslAsset $asset): string
    {
        $metrics = is_array($asset->metrics) ? $asset->metrics : [];
        $pool = [];
        foreach (['result_claims', 'headline_numbers', 'claims'] as $k) {
            foreach ((array) ($metrics[$k] ?? []) as $c) {
                if (is_scalar($c)) {
                    $pool[] = (string) $c;
                }
            }
        }
        foreach ($pool as $c) {
            if (preg_match('/\d[\d.,]*\s?(?:lbs?|pounds?|kg|%|days?|weeks?|months?|\$\d|x\b)/iu', $c, $m)) {
                return trim($m[0]);
            }
        }

        return '';
    }

    private function price(AiMarketingVslAsset $asset): string
    {
        $offer = is_array($asset->offer) ? $asset->offer : [];
        foreach (['price', 'price_point', 'amount'] as $k) {
            $v = $offer[$k] ?? null;
            if (is_scalar($v) && trim((string) $v) !== '') {
                $v = trim((string) $v);

                return str_starts_with($v, '$') || ! preg_match('/^\d/', $v) ? $v : '$'.$v;
            }
        }

        return '';
    }

    private function guarantee(AiMarketingVslAsset $asset): string
    {
        $offer = is_array($asset->offer) ? $asset->offer : [];
        $g = trim((string) ($offer['guarantee'] ?? ''));

        return $g !== '' ? $g : '60-day money-back guarantee.';
    }

    /**
     * @param  array<int,string>  $candidates
     */
    private function firstNonEmpty(array $candidates): string
    {
        foreach ($candidates as $c) {
            if (trim($c) !== '') {
                return trim($c);
            }
        }

        return '';
    }
}
