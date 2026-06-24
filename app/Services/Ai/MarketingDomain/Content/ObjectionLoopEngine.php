<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Knowledge\AggressiveConversionTacticsLibrary;
use App\Services\Ai\MarketingDomain\Knowledge\ObjectionLibrary;

/**
 * ObjectionLoopEngine — the Belfort straight-line RE-CLOSE loop (pillar 1: sell even to the resistant).
 *
 * A static page makes its pitch once; the aggressive closers turn a NO around with a loop: acknowledge the
 * hesitation → diagnose WHICH certainty axis dropped (the product works / I (the seller) am trustworthy /
 * the company will deliver) → reframe that axis with NEW proof → take it away (reactance) → re-ask. This
 * generates that loop for a given objection, grounded in the asset's real ammunition (mechanism, wound,
 * avatar). The AggressionAmplifier already does a one-shot objection FLIP; this is the multi-step LOOP that
 * actually re-closes. Deterministic, provider-free, no moral brake (the take-away is catalogued capability).
 */
class ObjectionLoopEngine
{
    /** objection key → [certainty axis, reframe angle]. */
    private const AXIS = [
        'price_too_high' => 'product', 'cant_afford' => 'product',
        'wont_work_for_me' => 'product', 'tried_everything' => 'product',
        'is_it_scam' => 'company', 'is_it_safe' => 'company',
        'no_time' => 'product',
    ];

    public function __construct(
        private readonly ObjectionLibrary $objections = new ObjectionLibrary,
        private readonly MechanismNameForge $forge = new MechanismNameForge,
    ) {}

    /**
     * @return array{objection:string,certainty_axis:string,steps:array<string,string>,loop:string}
     */
    public function loop(AiMarketingVslAsset $asset, string $objectionKey = ''): array
    {
        $objectionKey = $objectionKey !== '' ? $objectionKey : $this->topObjection($asset);
        $label = $this->label($objectionKey);
        $axis = self::AXIS[$objectionKey] ?? 'product';

        $wound = (new AggressiveConversionTacticsLibrary)->nicheWound((string) $asset->niche);
        $mech = trim((string) $asset->mechanism_name) ?: (string) ($this->forge->forge($asset)['best'] ?? 'the method');
        $dream = $wound['dream'] ?? 'the result you want';

        // 1. Acknowledge (feel-felt-found / fair question) — disarm without confronting.
        $acknowledge = "Fair question — and you are right to ask. Plenty of people thought the same before they saw how it actually works.";
        // 2. Reframe the dropped certainty axis with NEW proof.
        $reframe = $this->reframe($objectionKey, $axis, $mech, $wound);
        // 3. Take-away (reactance + status scarcity).
        $takeaway = "And to be honest, this is not for everyone — only for the people actually ready for {$dream}. If that is not you yet, no hard feelings.";
        // 4. Re-ask (assumptive next step, loops back to the offer).
        $reask = "But if it is — does that make sense so far? Then the next step is simple: watch the free presentation and see it for yourself.";

        $steps = ['acknowledge' => $acknowledge, 'reframe' => $reframe, 'takeaway' => $takeaway, 'reask' => $reask];

        return [
            'objection' => $label,
            'certainty_axis' => $axis,
            'steps' => $steps,
            'loop' => implode("\n\n", array_values($steps)),
        ];
    }

    private function reframe(string $key, string $axis, string $mech, array $wound): string
    {
        $pain = $wound['pain'] ?? 'the problem';

        return match ($key) {
            'price_too_high', 'cant_afford' => "Think about the real cost of doing nothing — another year of {$pain}, and the bill only grows. {$mech} costs less than what you already waste on what does not work. The expensive choice is staying where you are.",
            'wont_work_for_me' => "That is exactly why it works for you — everything you tried before fixed the wrong thing. {$mech} targets the real cause, and it was built for people in exactly your situation.",
            'tried_everything' => "Of course you have — and that is the point. You tried everything except the one thing that addresses the real cause. {$mech} is not another version of what already failed you.",
            'is_it_scam' => "Smart to be skeptical — most things out there are noise. So do not take my word: watch the presentation, see the proof for yourself, and you are covered by a full money-back guarantee. The risk is entirely on us.",
            'is_it_safe' => "Your safety comes first — that is why {$mech} works WITH your body, not against it, and you are protected by a full money-back guarantee if it is ever not right for you.",
            'no_time' => "If time is the worry, this is built for exactly that — it fits into minutes a day, no overhaul of your life. Doing nothing costs you far more time than this ever will.",
            default => "Here is what changes it: {$mech} addresses the real reason {$pain} — and you are covered by a full money-back guarantee, so the risk is on us, not you.",
        };
    }

    private function topObjection(AiMarketingVslAsset $asset): string
    {
        // Highest-weight objection the library knows, as a sane default when none is specified.
        $all = $this->objections->all();
        usort($all, static fn ($a, $b) => $b['weight'] <=> $a['weight']);

        return $all[0]['key'] ?? 'wont_work_for_me';
    }

    private function label(string $key): string
    {
        foreach ($this->objections->all() as $o) {
            if ($o['key'] === $key) {
                return (string) $o['name'];
            }
        }

        return $key;
    }
}
