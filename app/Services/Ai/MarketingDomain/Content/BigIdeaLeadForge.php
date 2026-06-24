<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Knowledge\AggressiveConversionTacticsLibrary;

/**
 * BigIdeaLeadForge — ORIGINATES the lead, the single biggest conversion multiplier (Eixo 4 / "a
 * construção é que vende").
 *
 * The first ~10% of a VSL/advertorial — the LEAD — decides watch-through and carries the big idea; it is
 * what takes a funnel from 1% to 25%. A commodity opener ("have you wondered why X?") converts at 1%; an
 * elite lead grips by the throat. This distills the cardinal direct-response lead archetypes (Bencivenga's
 * four + Halbert/Schwartz) into grounded variants built from the asset's REAL ammunition — the niche wound
 * and dream, the common enemy, the promise — and OPENS a curiosity loop while HOLDING the mechanism back
 * (no premature reveal → watch-through stays intact). It routes the best archetype to the reader's
 * awareness stage. Deterministic, provider-free, niche-agnostic, no moral gate. The amplifier/composer
 * consume the chosen lead as the page opener.
 */
class BigIdeaLeadForge
{
    /** Awareness stage → the lead archetypes that fit it, best first. */
    private const ROUTE = [
        'unaware' => ['story', 'secret', 'enemy'],
        'problem_aware' => ['problem_agitate', 'enemy', 'secret'],
        'solution_aware' => ['secret', 'proclamation', 'problem_agitate'],
        'product_aware' => ['proclamation', 'secret'],
        'most_aware' => ['proclamation'],
    ];

    /** Niche → the common enemy a lead can rally against (external villain mobilizes). */
    private const ENEMY = [
        'health' => 'the supplement industry',
        'weight' => 'the diet industry',
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
        $pain = $wound['pain'] ?? 'this keeps holding you back';
        $dream = $wound['dream'] ?? 'the life you want';
        $enemy = $this->enemy((string) $asset->niche, (string) ($wound['family'] ?? ''));
        $avatar = $this->avatar($asset);
        $promise = $this->firstNonEmpty([(string) $asset->core_promise, (string) $asset->big_idea, $dream]);
        $hero = $this->hero($asset);
        $heroTail = $hero !== '' ? " — {$hero}" : '';

        // Every archetype OPENS a loop and HOLDS the mechanism (no reveal here → watch-through intact).
        $built = [
            'secret' => "{$avatar} there is a little-known reason {$pain} — and it is not what you have been told. Once you see it, {$promise}{$heroTail} stops being a fight.",
            'story' => "{$avatar} not long ago I was right where you are: {$pain}. I had tried everything. Then one overlooked thing changed it — and {$dream}. Let me show you what it was.",
            'problem_agitate' => "{$avatar} if {$pain}, understand this: it is not your fault. The real reason is something {$enemy} never made clear — and it changes everything.",
            'proclamation' => "{$avatar} {$promise}{$heroTail} — and far faster and simpler than you have been led to believe. It sounds impossible until you see the one reason it works.",
            'enemy' => "{$avatar} {$enemy} has quietly buried the real reason {$pain}. What they do not want you to find is exactly what finally makes {$dream} possible.",
        ];

        $order = self::ROUTE[$this->normalizeAwareness((string) $asset->awareness_level)] ?? self::ROUTE['problem_aware'];
        $leads = [];
        foreach ($order as $arch) {
            $leads[] = ['archetype' => $arch, 'text' => $this->tidy($built[$arch])];
        }

        return [
            'leads' => $leads,
            'best' => $leads[0]['text'] ?? null,
            'best_archetype' => $leads[0]['archetype'] ?? null,
        ];
    }

    private function enemy(string $niche, string $family): string
    {
        $n = mb_strtolower($niche.' '.$family);
        foreach (self::ENEMY as $key => $enemy) {
            if ($key !== 'generic' && str_contains($n, $key)) {
                return $enemy;
            }
        }

        return self::ENEMY['generic'];
    }

    private function avatar(AiMarketingVslAsset $asset): string
    {
        $avatar = is_array($asset->avatar) ? $asset->avatar : [];
        $who = trim((string) ($avatar['who'] ?? $avatar['label'] ?? ''));
        if ($who !== '') {
            return rtrim($who, '.:').':';
        }
        $niche = trim((string) $asset->niche);

        return $niche !== '' ? ucfirst($niche).':' : 'Listen:';
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
