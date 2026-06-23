<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingVslAsset;

/**
 * CopyQualityGate — the "does not read like a dumb AI wrote it" gate, fail-closed. Two failures kill a
 * draft and force a re-generation:
 *
 *  1) META-MARKETING LEAK: the copy talks ABOUT marketing instead of selling — "message-match",
 *     "cold visitor", "audience", "future pacing", "open loop", "this page/funnel", "the click",
 *     "conversion". A real advertorial never names its own machinery; it talks to the woman about her
 *     life. Any such term = instant fail.
 *
 *  2) GENERIC / UNGROUNDED: the copy uses almost none of the VSL's concrete ammunition — the named
 *     authorities (Melania, Dr. Attia, FDA), the common enemy (big pharma), the social-proof names,
 *     the mechanism specifics, the avatar's visceral pains. Abstract "hidden hormone slowdown" mush
 *     with no concrete VSL hooks = fail (it could be any offer; it does not warm THIS lead).
 *
 * Deterministic; no LLM. The thresholds are conservative.
 */
class CopyQualityGate
{
    /** Minimum distinct concrete VSL hooks (names/enemy/mechanism/pain) the copy must weave in. */
    public const MIN_CONCRETE_HOOKS = 4;

    /** Marketing/process vocabulary that must NEVER appear in the reader-facing copy. */
    private const META_MARKETING = [
        'message-match', 'message match', 'cold visitor', 'cold traffic', 'cold lead', 'the audience',
        'this audience', 'engaged viewer', 'future pacing', 'open loop', 'open-loop', 'the click',
        'the watch', 'watch-through', 'conversion', 'convert', 'funnel', 'bridge page', 'this page',
        'this report does', 'this article does', 'presell', 'pre-sell', 'advertorial', 'the copy',
        'the headline', 'qualify the lead', 'qualified lead', 'call to action', 'cta', 'sales page',
        'lead magnet', 'click-through', 'skepticism is not a barrier', 'smart copy', 'the pitch',
        'opt-in', 'landing page', 'the offer page', 'the reader', 'as a reader', 'curiosity gap',
    ];

    /**
     * @param  array<string,mixed>  $bridge
     * @return array<string,mixed>
     */
    public function assess(array $bridge, AiMarketingVslAsset $asset): array
    {
        $copy = mb_strtolower($this->copyText($bridge));

        // 1) META-MARKETING leak ------------------------------------------------------------
        $leaks = array_values(array_filter(self::META_MARKETING, static fn (string $t): bool => str_contains($copy, $t)));

        // 2) CONCRETE VSL ammunition --------------------------------------------------------
        $hooks = $this->concreteHooks($asset);
        $usedHooks = [];
        foreach ($hooks as $hook) {
            if ($hook !== '' && str_contains($copy, mb_strtolower($hook))) {
                $usedHooks[] = $hook;
            }
        }
        $usedHooks = array_values(array_unique($usedHooks));

        $verdict = $leaks !== [] ? 'meta' : (count($usedHooks) < self::MIN_CONCRETE_HOOKS ? 'generic' : 'ok');

        return [
            'verdict' => $verdict,
            'passed' => $verdict === 'ok',
            'meta_leaks' => $leaks,
            'concrete_hooks_used' => $usedHooks,
            'concrete_hooks_count' => count($usedHooks),
            'concrete_hooks_available' => array_slice($hooks, 0, 30),
            'note' => match ($verdict) {
                'meta' => 'A copy FALA SOBRE marketing ('.implode(', ', array_slice($leaks, 0, 4)).') — parece IA. Reescreva falando com a leitora, não sobre a técnica.',
                'generic' => 'Copy genérica: usa só '.count($usedHooks).'/'.self::MIN_CONCRETE_HOOKS.' ganchos concretos da VSL. Precisa dos nomes/inimigo/mecanismo/dores reais.',
                default => 'Copy concreta e sem vazamento meta — fala com a leitora usando a munição da VSL.',
            },
        ];
    }

    /**
     * The concrete, nameable hooks from the VSL the copy should weave in: authority names, the common
     * enemy, social-proof names, mechanism/ingredient terms, branded names, vivid avatar pains.
     *
     * @return array<int,string>
     */
    public function concreteHooks(AiMarketingVslAsset $asset): array
    {
        $hooks = [];
        $devices = is_array($asset->persuasion_devices) ? $asset->persuasion_devices : [];

        // authority + social proof = proper names (≥2 words or a known single brand) → strong hooks
        foreach (['authority', 'social_proof'] as $k) {
            foreach ((array) ($devices[$k] ?? []) as $v) {
                $name = is_array($v) ? (string) ($v['name'] ?? reset($v)) : (string) $v;
                $name = trim($this->firstClause($name));
                if ($name !== '' && mb_strlen($name) >= 4 && str_word_count($name) <= 5) {
                    $hooks[] = $name;
                }
            }
        }
        // common enemy
        if (! empty($devices['conspiracy'])) {
            $hooks[] = 'big pharma';
        }
        // mechanism + branded names + trick ingredients
        foreach ([$asset->mechanism_name, $asset->sub_niche] as $m) {
            $m = trim((string) $m);
            if ($m !== '') {
                $hooks[] = $m;
            }
        }
        foreach ($this->ingredientHints($asset) as $ing) {
            $hooks[] = $ing;
        }
        // vivid avatar pains (short noun phrases)
        $avatar = is_array($asset->avatar) ? $asset->avatar : [];
        foreach ((array) ($avatar['dores'] ?? $avatar['pains'] ?? []) as $pain) {
            $p = trim((string) $pain);
            if ($p !== '' && mb_strlen($p) <= 60) {
                $hooks[] = $p;
            }
        }

        // de-dup, drop empties
        return array_values(array_unique(array_filter(array_map('trim', $hooks))));
    }

    /**
     * @return array<int,string>
     */
    private function ingredientHints(AiMarketingVslAsset $asset): array
    {
        $blob = mb_strtolower((string) $asset->trick.' '.(string) $asset->solution_mechanism.' '.(string) $asset->big_idea);
        $known = ['retatrutide', 'turmeric', 'curcumin', 'piperine', 'green tea', 'quercetin', 'berberine', 'resveratrol', 'glp-1', 'gip', 'glucagon', 'lipo bliss', 'ozempic', 'mounjaro', 'wegovy'];

        return array_values(array_filter($known, static fn (string $t): bool => str_contains($blob, $t)));
    }

    private function firstClause(string $s): string
    {
        // "Dr. Peter Attia (citado como...)" → "Dr. Peter Attia"
        $s = (string) preg_replace('/\s*[\(\[].*$/u', '', $s);
        $s = (string) preg_replace('/\s*[:\-—].*$/u', '', $s);

        return trim($s);
    }

    /**
     * @param  array<string,mixed>  $bridge
     */
    private function copyText(array $bridge): string
    {
        $parts = [
            (string) ($bridge['headline'] ?? ''),
            (string) ($bridge['subheadline'] ?? ''),
            (string) ($bridge['lead_paragraph'] ?? ''),
            (string) ($bridge['mechanism_tease'] ?? ''),
            (string) ($bridge['ps'] ?? ''),
        ];
        foreach ((array) ($bridge['body_sections'] ?? []) as $s) {
            if (is_array($s)) {
                $parts[] = (string) ($s['heading'] ?? '');
                $parts[] = (string) ($s['body'] ?? '');
                $parts[] = (string) ($s['open_loop'] ?? '');
            }
        }
        foreach ((array) ($bridge['objection_flips'] ?? []) as $o) {
            if (is_array($o)) {
                $parts[] = (string) ($o['objection'] ?? '');
                $parts[] = (string) ($o['flip'] ?? '');
            }
        }

        return implode(' ', array_filter($parts));
    }
}
