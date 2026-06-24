<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Knowledge\AggressiveConversionTacticsLibrary;

/**
 * BigIdeaLeadForge — ORIGINATES the lead, the single biggest conversion multiplier (1→25), GENERALIZED
 * cross-niche.
 *
 * The first ~10% (the lead) decides watch-through and carries the big idea. A sibling, LeadForge, already
 * forges an elite lead but is hard-wired to the weight-loss/GLP-1 asset (OT169). This is the general
 * engine: it routes the cardinal direct-response archetypes (Bencivenga's four + Halbert/Schwartz) to the
 * reader's awareness and builds each from the asset's REAL ammunition — a CONCRETE sensory scene (not an
 * abstract pain), the asset's REAL enemy (Big Pharma when a conspiracy device is present, not a generic
 * "industry"), a proper identity callout ("a woman over 40", never the raw niche name), and an open loop
 * ANCHORED on the named mechanism (closed only by the video) — so it never reveals the mechanism early
 * (watch-through stays intact). For the weight/health family it DELEGATES the headline lead to the proven
 * LeadForge (no duplication of that elite treatment). A brutal panel (cycle: lead-forge) drove these
 * upgrades. Deterministic, provider-free, no moral gate.
 */
class BigIdeaLeadForge
{
    /** Awareness stage → lead archetypes that fit it, best first. */
    private const ROUTE = [
        'unaware' => ['story', 'secret', 'enemy'],
        'problem_aware' => ['problem_agitate', 'enemy', 'secret'],
        'solution_aware' => ['secret', 'proclamation', 'problem_agitate'],
        'product_aware' => ['proclamation', 'secret'],
        'most_aware' => ['proclamation'],
    ];

    /** Niche family → a CONCRETE sensory scene the cold reader sees themselves in (not abstract pain). */
    private const SCENE = [
        'weight' => 'the scale will not move, your clothes keep getting tighter, and every mirror feels like bad news',
        'health' => 'you wake up tired, the symptoms creep back, and the doctor just shrugs',
        'finance' => 'your balance drops the day before payday and the card gets declined at the worst moment',
        'money' => 'your balance drops the day before payday and the card gets declined at the worst moment',
        'relationship' => 'the texts go unanswered, the calls go to voicemail, and the other side of the bed stays cold',
        'generic' => 'the thing you want keeps slipping further away no matter what you try',
    ];

    /** Niche family → the common enemy a lead rallies against. */
    private const ENEMY = [
        'weight' => 'the diet industry',
        'health' => 'the supplement industry',
        'finance' => 'Wall Street',
        'money' => 'the banks',
        'relationship' => 'the dating-advice industry',
        'generic' => 'the industry that profits from your problem',
    ];

    /**
     * @return array{leads:array<int,array{archetype:string,text:string}>,best:?string,best_archetype:?string}
     */
    public function forge(AiMarketingVslAsset $asset): array
    {
        $wound = (new AggressiveConversionTacticsLibrary)->nicheWound((string) $asset->niche);
        $family = (string) ($wound['family'] ?? 'generic');
        $scene = self::SCENE[$family] ?? self::SCENE['generic'];
        $enemy = $this->enemy($asset, $family);
        $who = $this->avatar($asset);
        $dream = $wound['dream'] ?? 'the life you want';
        $promise = $this->firstNonEmpty([(string) $asset->core_promise, (string) $asset->big_idea, $dream]);
        $hero = $this->hero($asset);
        $heroTail = $hero !== '' ? " — {$hero}" : '';
        $close = $this->openLoop($asset);

        $built = [
            'secret' => "If you are {$who}, there is a little-known reason {$scene} — and it is not what you have been told. {$close}",
            'story' => "If you are {$who}, I have been right where you are: {$scene}. I had tried everything. Then one overlooked thing changed it — and {$dream}. {$close}",
            'problem_agitate' => "If you are {$who} and {$scene}, understand this: it is not your fault. The real reason is something {$enemy} never made clear. {$close}",
            'proclamation' => "If you are {$who}, {$promise}{$heroTail} is within reach — far faster and simpler than you have been led to believe. {$close}",
            'enemy' => "If you are {$who}, know this: {$enemy} has every reason to keep you from finding why {$scene}. {$close}",
        ];

        $order = self::ROUTE[$this->normalizeAwareness((string) $asset->awareness_level)] ?? self::ROUTE['problem_aware'];
        $leads = [];
        foreach ($order as $arch) {
            $leads[] = ['archetype' => $arch, 'text' => $this->tidy($built[$arch])];
        }

        // Reuse, not duplicate: for the weight/health family, the proven LeadForge is the elite headline
        // lead. Use it as `best`; keep the routed archetypes as cross-niche variants.
        $best = $leads[0]['text'] ?? null;
        $bestArch = $leads[0]['archetype'] ?? null;
        if (in_array($family, ['weight', 'health'], true)) {
            $elite = trim((new LeadForge)->forge($asset));
            if ($elite !== '') {
                $best = $elite;
                $bestArch = 'leadforge_elite';
            }
        }

        return ['leads' => $leads, 'best' => $best, 'best_archetype' => $bestArch];
    }

    /**
     * A SHORT scroll-stopper for the ad/bridge — shares the lead's scene + enemy vocabulary so the whole
     * ad→bridge→page chain stays congruent (message-match, Eixo 6) and the top of funnel is elite too.
     */
    public function hook(AiMarketingVslAsset $asset): string
    {
        $wound = (new AggressiveConversionTacticsLibrary)->nicheWound((string) $asset->niche);
        $family = (string) ($wound['family'] ?? 'generic');
        $scene = self::SCENE[$family] ?? self::SCENE['generic'];
        $enemy = $this->enemy($asset, $family);

        return $this->tidy("If you are {$this->avatar($asset)} and {$scene}, the real reason is not what {$enemy} told you.");
    }

    /** Open loop anchored on the named mechanism — closed only by the video; never reveals the how. */
    private function openLoop(AiMarketingVslAsset $asset): string
    {
        $mech = trim((string) preg_replace('/\s*\(.*$/u', '', (string) $asset->mechanism_name));
        if ($mech === '') {
            $mech = (string) ((new MechanismNameForge)->forge($asset)['best'] ?? '');
        }

        return $mech !== ''
            ? "It has a name — {$mech} — and the exact reason it works is in the presentation above."
            : 'The exact reason it works is in the presentation above.';
    }

    private function enemy(AiMarketingVslAsset $asset, string $family): string
    {
        // The asset's REAL enemy wins: a conspiracy device means Big-Pharma-grade framing (congruence with
        // the VSL), not a generic family label.
        $devices = (array) ($asset->persuasion_devices ?? []);
        if (! empty($devices['conspiracy'] ?? null)) {
            return in_array($family, ['weight', 'health'], true)
                ? 'the people making billions on $1,000-a-month injections'
                : 'the people who profit while you stay stuck';
        }

        return self::ENEMY[$family] ?? self::ENEMY['generic'];
    }

    /** Identity callout ("a woman over 40"), mirroring LeadForge — NEVER the raw niche name as a greeting. */
    private function avatar(AiMarketingVslAsset $asset): string
    {
        $blob = mb_strtolower(json_encode($asset->avatar, JSON_UNESCAPED_UNICODE).' '.(string) $asset->niche);
        $woman = (bool) preg_match('/\b(women|woman|mulher|female)\b/u', $blob);
        $man = (bool) preg_match('/\b(men|man|homem|male)\b/u', $blob);
        $age = preg_match('/\b([456]0)\b/u', $blob, $m) ? $m[1] : '40';
        $base = $woman ? 'a woman' : ($man ? 'a man' : 'someone');

        return $base === 'someone' ? 'someone who has tried everything' : "{$base} over {$age}";
    }

    private function hero(AiMarketingVslAsset $asset): string
    {
        $metrics = is_array($asset->metrics) ? $asset->metrics : [];
        foreach (['result_claims', 'headline_numbers', 'claims'] as $k) {
            foreach ((array) ($metrics[$k] ?? []) as $c) {
                if (is_scalar($c) && preg_match('/\d[\d.,]*\s?(?:lbs?|pounds?|kg|%|days?|weeks?|months?|\$\d|x\b)/iu', (string) $c, $m)) {
                    return trim($m[0]);
                }
            }
        }

        return '';
    }

    private function normalizeAwareness(string $a): string
    {
        $a = str_replace([' ', '-'], '_', mb_strtolower(trim($a)));

        return array_key_exists($a, self::ROUTE) ? $a : 'problem_aware';
    }

    private function tidy(string $s): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $s));
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
