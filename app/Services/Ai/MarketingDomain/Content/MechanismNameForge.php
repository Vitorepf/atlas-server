<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingVslAsset;

/**
 * MechanismNameForge — ORIGINATES the named, proprietary mechanism (Eixo 2 / Schwartz level 4-5).
 *
 * In a saturated market, the same promise stops converting; what reopens it is a NEW NAMED MECHANISM —
 * a proprietary-sounding name for HOW it works ("The 3-Hormone Reset", "The Allocation Rule"). The OS
 * could DETECT a named mechanism, but nothing CREATED one. This forges candidate names from the asset's
 * real ammunition: a number + a concrete core noun + a method word, in the structures elite copy uses.
 * Deterministic, provider-free, niche-agnostic. The engine now invents the level-4 mechanism, not just
 * scores it; the operator/amplifier picks the winner.
 */
class MechanismNameForge
{
    private const METHOD_WORDS = ['Protocol', 'Method', 'Ritual', 'Switch', 'Formula', 'System', 'Blueprint', 'Sequence', 'Reset', 'Shortcut', 'Loop', 'Code'];

    private const STOP = ['the', 'a', 'an', 'of', 'to', 'in', 'for', 'and', 'or', 'with', 'your', 'you', 'that',
        'this', 'how', 'why', 'new', 'best', 'get', 'o', 'a', 'de', 'da', 'do', 'que', 'para', 'com', 'seu', 'uma', 'um'];

    /**
     * @return array{candidates:array<int,array{name:string,structure:string,score:int}>,best:?string}
     */
    public function forge(AiMarketingVslAsset $asset): array
    {
        $core = $this->coreNoun($asset);
        $num = $this->number($asset);
        $cands = [];

        // Structure A: "The N-Core Method" — number-anchored proprietary mechanism (strongest in saturated markets).
        if ($num !== '' && $core !== '') {
            foreach (['Protocol', 'Method', 'Reset', 'Formula'] as $mw) {
                $cands[] = $this->cand("The {$num}-{$core} {$mw}", 'number+core+method');
            }
        }
        // Structure B: "The Core Switch/Loop" — single-mechanism name.
        if ($core !== '') {
            foreach (['Switch', 'Loop', 'Protocol', 'Shortcut'] as $mw) {
                $cands[] = $this->cand("The {$core} {$mw}", 'core+method');
            }
        }
        // Structure C: number + method only (when no clean core noun).
        if ($cands === [] && $num !== '') {
            foreach (['Method', 'Protocol', 'Formula'] as $mw) {
                $cands[] = $this->cand("The {$num}-Step {$mw}", 'number+method');
            }
        }
        // Fallback so the forge always yields something usable.
        if ($cands === []) {
            $cands[] = $this->cand('The '.($core !== '' ? $core.' ' : '').'Method', 'fallback');
        }

        // Dedup + rank (number + concrete core + brevity = stronger).
        $seen = [];
        $unique = [];
        foreach ($cands as $c) {
            if (! isset($seen[$c['name']])) {
                $seen[$c['name']] = true;
                $unique[] = $c;
            }
        }
        usort($unique, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return ['candidates' => array_slice($unique, 0, 6), 'best' => $unique[0]['name'] ?? null];
    }

    /**
     * @return array{name:string,structure:string,score:int}
     */
    private function cand(string $name, string $structure): array
    {
        $words = str_word_count($name);
        $hasNum = (bool) preg_match('/\d/', $name);
        $score = 50 + ($hasNum ? 25 : 0) + ($words <= 4 ? 15 : 0) + (str_contains($structure, 'core') ? 10 : 0);

        return ['name' => $name, 'structure' => $structure, 'score' => min(100, $score)];
    }

    /** A concrete, title-cased core noun from the asset's mechanism / big idea / niche. */
    private function coreNoun(AiMarketingVslAsset $asset): string
    {
        $sources = [(string) $asset->mechanism_name, (string) $asset->big_idea, (string) $asset->niche];
        foreach ($sources as $s) {
            foreach (preg_split('/[^\p{L}]+/u', mb_strtolower($s)) ?: [] as $w) {
                if (mb_strlen($w) >= 4 && ! in_array($w, self::STOP, true)) {
                    return ucfirst($w);
                }
            }
        }

        return '';
    }

    /** A small mechanism-count number (3 hormones, 7 foods…) — defaults to 3 (the canonical rule of three). */
    private function number(AiMarketingVslAsset $asset): string
    {
        $hay = mb_strtolower((string) $asset->mechanism_name.' '.(string) $asset->big_idea.' '.(string) $asset->core_promise);
        if (preg_match('/\b([2-9])\b/', $hay, $m)) {
            return $m[1];
        }
        foreach (['three' => '3', 'two' => '2', 'four' => '4', 'five' => '5', 'seven' => '7', 'três' => '3', 'dois' => '2', 'sete' => '7'] as $word => $digit) {
            if (str_contains($hay, $word)) {
                return $digit;
            }
        }

        return '3';
    }
}
