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
        // Eixo 2: name the mechanism. If the asset has no named mechanism, FORGE one (Schwartz level-4
        // proprietary mechanism that reopens a saturated market) instead of a flat "the method".
        $mechanism = $this->firstNonEmpty([
            (string) $asset->mechanism_name,
            (string) $asset->solution_mechanism,
            (string) ((new MechanismNameForge)->forge($asset)['best'] ?? ''),
            'the method',
        ]);
        $avatar = $this->avatarCallout($asset);
        $hero = $this->heroClaim($asset);                 // e.g. "30 lbs" or "" if none
        $heroLine = $hero !== '' ? " — {$hero}" : '';
        $price = $this->price($asset);                     // e.g. "$97" or ""
        $guarantee = $this->guarantee($asset);
        // Default = MAXIMUM aggression (operator: sem freio). The market's raw wound/dream is woven in
        // niche-flavored, so the scaffold ships aggressive by construction (not generic).
        $wound = (new \App\Services\Ai\MarketingDomain\Knowledge\AggressiveConversionTacticsLibrary)->nicheWound((string) $asset->niche);
        // Value Equation (Eixo 5): only CLAIM the time/effort levers when the ASSET gives real substance.
        // A brutal panel proved fixed filler ("starting today", "simple, without…") just gamed the auditor.
        // No substance → leave an HONEST gap for the producer to fill, never plant generic filler.
        $timeframe = $this->timeframe($asset);
        $timeLine = $timeframe !== '' ? "The first changes can show in {$timeframe}." : '';
        $easeLine = $this->easeClaim($asset);
        // Eixo 7: plant REAL concrete proof from the asset when it has any; else keep the producer slot.
        $proof = $this->proof($asset);
        $proofLine = $proof !== '' ? $proof : '[PROOF SLOT: o caso/depoimento/estudo mais forte da oferta]';
        // The LEAD is the biggest conversion multiplier (1→25). Open with an elite, awareness-routed lead
        // forged from the asset's real wound/dream/enemy/promise — it grips and opens a curiosity loop
        // while HOLDING the mechanism (no premature reveal). Falls back to a curiosity hook if empty.
        $leadForge = new BigIdeaLeadForge;
        $pageHook = $leadForge->forge($asset)['best']
            ?? "{$avatar} have you wondered why {$promise} stays out of reach{$heroLine}?";
        // Short scroll-stopper that SHARES the lead's scene+enemy vocabulary → ad/bridge stay congruent
        // with the page (message-match) and the top of funnel is elite too.
        $hook = $leadForge->hook($asset);

        // ── AD: elite scroll-stopper (shares the page lead's scene+enemy → congruent) + hero promise
        // (carries hero number) + soft CTA (free CONTENT, not the product).
        $ad = trim("{$hook} {$promise}{$heroLine} — watch the free presentation before it comes down.");

        // ── BRIDGE: echoes the ad's scene+enemy (congruent hop) + curiosity, NO reveal, soft forward CTA.
        $bridge = trim("{$hook} What they never explain is the one thing that changes {$promise}{$heroLine}. "
            .'Keep reading — it gets clearer in a moment, and then you will see exactly how.');

        // ── PAGE: carries hero, builds before the reveal (mechanism named LATE), single CTA at the end.
        $page = trim(implode(' ', array_filter([
            $pageHook,                                                                     // hook (sophistication-aware)
            'Most advice has it backwards, and it is not your fault.',                     // build
            "Every day you wait is another day {$wound['pain']}.",                         // fear (niche wound)
            'For a long time the real cause stayed hidden in plain sight.',                // build
            'It gets clearer once you see what is actually happening.',                    // forward pull
            'But first, understand what everyone else got wrong about this.',              // forward pull (mid)
            "Here is how {$mechanism} finally makes {$promise}{$heroLine} work.",          // REVEAL (late) — carries the hero claim
            $proofLine,                                                                     // proof ADJACENT to the claim (believability: a claim must be backed at the point of assertion)
            "Imagine {$wound['dream']}.",                                                  // future pacing (niche dream / Value Eq: dream outcome)
            $timeLine,                                                                      // Value Eq: time delay ↓ — ONLY if the asset gives a real timeframe
            $easeLine,                                                                      // Value Eq: effort ↓ — ONLY if the asset names a removed effort
            'Watch the free presentation now — spots are limited.',                         // single CTA (end) + scarcity
        ])));

        // ── CHECKOUT: a GRAND-SLAM stacked offer (Eixo 5). Reuses the existing GrandSlamBuilder for the
        // intelligence (bonuses mapped 1:1 to objections + value anchoring) instead of a thin one-liner —
        // the offer is a top 1→25 lever. Rendered in EN from the builder's DATA (no language leak); the
        // guarantee is only stated when the offer really has one (no fabricated risk-reversal).
        $gs = (new \App\Services\Ai\MarketingDomain\Decision\GrandSlamBuilder)->build($asset);
        $bonusN = is_array($gs['bonus_stack'] ?? null) ? count($gs['bonus_stack']) : 0;
        $anchored = (string) ($gs['total_anchored_value'] ?? '');
        $valueLine = ($anchored !== '' && $anchored !== '$0' && $price !== '')
            ? "Everything here is worth {$anchored} — today it is yours for {$price}."
            : ($price !== '' ? "Today only: {$price}." : '');
        $checkout = trim(implode(' ', array_filter([
            // Recap the value (mechanism + dream) before the price — a real DR close move that also keeps
            // the page→checkout hop congruent (the reader sees the same anchors they just agitated on).
            "You have seen why nothing worked — {$mechanism} is how you finally reach {$wound['dream']}{$heroLine}.",
            "Get the complete {$mechanism} system for {$promise}.",
            $bonusN > 0 ? "Plus {$bonusN} bonuses — each one removes a reason people hesitate." : '',
            $valueLine,
            $guarantee !== '' ? rtrim($guarantee, '.').'.' : '',
            'Order now to get instant access — before this window closes.',
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
        // Eixo 2: name the mechanism. If the asset has no named mechanism, FORGE one (Schwartz level-4
        // proprietary mechanism that reopens a saturated market) instead of a flat "the method".
        $mechanism = $this->firstNonEmpty([
            (string) $asset->mechanism_name,
            (string) $asset->solution_mechanism,
            (string) ((new MechanismNameForge)->forge($asset)['best'] ?? ''),
            'the method',
        ]);
        $avatar = $this->avatarCallout($asset);
        $hero = $this->heroClaim($asset);
        $heroLine = $hero !== '' ? " — {$hero}" : '';
        $wound = (new \App\Services\Ai\MarketingDomain\Knowledge\AggressiveConversionTacticsLibrary)->nicheWound((string) $asset->niche);
        $timeframe = $this->timeframe($asset);
        $meansBody = trim("Imagine {$wound['dream']}."
            .($timeframe !== '' ? " The first changes can show in {$timeframe}." : '')
            .($this->easeClaim($asset) !== '' ? ' '.$this->easeClaim($asset) : ''));
        $proof = $this->proof($asset);
        $proofBody = $proof !== '' ? $proof : '[PROOF SLOT: o caso/depoimento/estudo mais forte da oferta]';

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
                // After the reveal: dream outcome (always) + time/effort levers ONLY when the asset gives
                // real substance (no fixed filler — a brutal panel proved filler just games the auditor).
                ['heading' => 'What it means for you', 'body' => $meansBody],
                ['heading' => 'The proof', 'body' => $proofBody],
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
        // No DEFAULT guarantee — a brutal panel showed a hard-coded "60-day money-back guarantee." was
        // auto-satisfying the proof lever on every page. Only state the guarantee the offer actually has.
        $offer = is_array($asset->offer) ? $asset->offer : [];

        return trim((string) ($offer['guarantee'] ?? ''));
    }

    /** A real timeframe from the asset (e.g. "21 days"), for the time-delay lever — '' if none. */
    private function timeframe(AiMarketingVslAsset $asset): string
    {
        $metrics = is_array($asset->metrics) ? $asset->metrics : [];
        $pool = [(string) $asset->core_promise, (string) $asset->big_idea];
        foreach (['result_claims', 'headline_numbers', 'claims', 'timeframe'] as $k) {
            foreach ((array) ($metrics[$k] ?? []) as $c) {
                if (is_scalar($c)) {
                    $pool[] = (string) $c;
                }
            }
        }
        $offer = is_array($asset->offer) ? $asset->offer : [];
        foreach (['timeframe', 'time_to_result'] as $k) {
            if (is_scalar($offer[$k] ?? null)) {
                $pool[] = (string) $offer[$k];
            }
        }
        foreach ($pool as $s) {
            if (preg_match('/\b\d+\s*(?:days?|weeks?|months?|hours?|minutes?|dias?|semanas?|meses|m[eê]s)\b/iu', $s, $m)) {
                return trim($m[0]);
            }
        }

        return '';
    }

    /**
     * The strongest CONCRETE proof the asset actually carries (Eixo 7), or '' if none. Reuses the
     * ProofSubstanceAuditor as the oracle so the composer plants only proof the auditor would certify as
     * concrete — never fabricated, never a vague tell. '' → the page keeps the producer PROOF SLOT (honest gap).
     */
    private function proof(AiMarketingVslAsset $asset): string
    {
        $oracle = new ProofSubstanceAuditor;
        $metrics = is_array($asset->metrics) ? $asset->metrics : [];
        $offer = is_array($asset->offer) ? $asset->offer : [];
        $candidates = [];
        foreach ((array) $asset->claims as $c) {
            if (is_scalar($c)) {
                $candidates[] = (string) $c;
            }
        }
        foreach (['result_claims', 'proof', 'testimonials', 'authority'] as $k) {
            foreach ((array) ($metrics[$k] ?? []) as $c) {
                if (is_scalar($c)) {
                    $candidates[] = (string) $c;
                }
            }
        }
        foreach (['proof', 'authority', 'guarantee'] as $k) {
            if (is_scalar($offer[$k] ?? null)) {
                $candidates[] = (string) $offer[$k];
            }
        }
        // Plant the STRONGEST proof (transformation/receipt/credential > count > ratio/mechanism), not the
        // first that matches — a brutal panel caught the ordinal pick planting a guarantee over a real receipt.
        $best = '';
        $bestStrength = 0;
        foreach ($candidates as $c) {
            $c = trim($c);
            if ($c === '') {
                continue;
            }
            $s = $oracle->audit($c)['strength'];
            if ($s > $bestStrength) {
                $bestStrength = $s;
                $best = rtrim($c, '.').'.';
            }
        }

        return $best;
    }

    /** A real ease/effort-removal claim the offer carries (e.g. "no gym, no calorie counting") — '' if none. */
    private function easeClaim(AiMarketingVslAsset $asset): string
    {
        $offer = is_array($asset->offer) ? $asset->offer : [];
        foreach (['ease', 'effort', 'removes', 'no_more'] as $k) {
            $v = $offer[$k] ?? null;
            if (is_array($v)) {
                $v = implode(', ', array_filter(array_map(static fn ($x) => is_scalar($x) ? (string) $x : '', $v)));
            }
            $v = trim((string) $v);
            if ($v !== '') {
                return rtrim($v, '.').'.';
            }
        }

        return '';
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
