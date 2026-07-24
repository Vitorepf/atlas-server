<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingVslAsset;

/**
 * MechanismNameForge — ORIGINATES the named, proprietary mechanism (Eixo 2 / Schwartz level 4-5).
 *
 * In a saturated market the same promise dies; what reopens it is a NEW NAMED MECHANISM — a
 * proprietary-sounding name for HOW it works ("The 3-Hormone Reset", "The Allocation Rule", "The Hero
 * Instinct"). This forges candidate names from the asset's real ammunition.
 *
 * v2 (after a brutal cross-niche panel proved v1 shipped headline garbage): it now (a) extracts the real
 * mechanism CORE noun — dropping modifier/prefix junk (at-home, hidden, natural, japanese) and the
 * method word itself — instead of the first token; (b) reuses the input's OWN method word when present
 * ("reset"/"rule") instead of stamping a random one; (c) only uses a number that genuinely enumerates
 * the mechanism (from the mechanism field, never leaked from the result promise) and OMITS it otherwise;
 * (d) speaks PT-BR (no "The 7-Regra Protocol" Frankenstein); (e) guards method==core and preserves an
 * already-elite input. Deterministic, provider-free.
 */
class MechanismNameForge
{
    use ContentInputNormalization;

    private const METHOD_EN = ['Protocol', 'Method', 'Ritual', 'Switch', 'Formula', 'System', 'Blueprint', 'Sequence', 'Reset', 'Loop', 'Code', 'Shortcut'];

    private const METHOD_PT = ['Protocolo', 'Método', 'Ritual', 'Fórmula', 'Sistema', 'Ciclo', 'Gatilho', 'Atalho', 'Sequência'];

    /** Lowercased method words (EN+PT) to DETECT the input's own method word and to exclude from the core. */
    private const METHOD_DETECT = ['protocol', 'method', 'ritual', 'switch', 'formula', 'system', 'blueprint',
        'sequence', 'reset', 'loop', 'code', 'shortcut', 'rule', 'trick', 'hack', 'plan', 'drops', 'fix',
        'protocolo', 'método', 'metodo', 'fórmula', 'formula', 'sistema', 'ciclo', 'gatilho', 'atalho',
        'sequência', 'sequencia', 'regra', 'truque', 'plano', 'reinício', 'reinicio'];

    /** Title-case map for an input method word so we reuse it verbatim ("rule" → "Rule", "regra" → "Regra"). */
    private const METHOD_TITLE = ['rule' => 'Rule', 'trick' => 'Trick', 'hack' => 'Hack', 'plan' => 'Plan',
        'drops' => 'Drops', 'fix' => 'Fix', 'regra' => 'Regra', 'truque' => 'Truque', 'plano' => 'Plano'];

    /** Modifier/prefix/adjective junk that is NEVER the mechanism core. */
    private const MODIFIER = ['at-home', 'home', 'hidden', 'natural', 'simple', 'secret', 'new', 'ancient',
        'weird', 'little', 'known', 'real', 'only', 'best', 'ultimate', 'easy', 'quick', 'fast', 'figure',
        'amazing', 'powerful', 'proven', 'caseiro', 'simples', 'secreto', 'novo', 'escondido', 'poderoso', 'comprovado'];

    private const STOP = ['the', 'a', 'an', 'of', 'to', 'in', 'for', 'and', 'or', 'with', 'your', 'you', 'that',
        'this', 'how', 'why', 'is', 'it', 'use', 'used', 'uses', 'they', 'o', 'a', 'os', 'as', 'de', 'da', 'do',
        'dos', 'das', 'que', 'para', 'com', 'seu', 'sua', 'uma', 'um', 'e', 'em', 'no', 'na'];

    /**
     * @return array{candidates:array<int,array{name:string,structure:string,score:int}>,best:?string}
     */
    public function forge(AiMarketingVslAsset $asset): array
    {
        $pt = $this->isPortuguese($asset);
        // Core comes from the MECHANISM fields only — never from core_promise (that leaks the result number).
        $src = $this->firstNonEmpty([(string) $asset->mechanism_name, (string) $asset->solution_mechanism, (string) $asset->big_idea]);
        $tokens = $this->tokens($src);

        // Already an elite "The N-Core Method" / "The Core Method" — preserve, don't degrade it.
        if ($this->isAlreadyElite($src)) {
            $clean = trim((string) preg_replace('/^the\s+/i', '', trim($src)));

            return ['candidates' => [['name' => 'The '.$this->title($clean), 'structure' => 'preserved', 'score' => 100]], 'best' => 'The '.$this->title($clean)];
        }

        $inputMethod = $this->detectMethod($tokens);                 // e.g. 'Rule' / 'Reset' / 'Regra' / ''
        $coreTokens = $this->coreTokens($tokens);                    // concrete nouns, modifiers/method/stop removed
        $core = $this->title(implode(' ', array_slice($coreTokens, 0, 2)));
        $num = $this->mechanismNumber($src);                         // '' unless the mechanism genuinely enumerates
        $methods = $pt ? self::METHOD_PT : self::METHOD_EN;
        $article = $pt ? 'O' : 'The';

        $cands = [];
        if ($core === '') {
            // No usable core → niche-anchored fallback, no fake number.
            $core = $this->title((string) $asset->niche) ?: ($pt ? 'Método' : 'Method');
        }
        $coreIsMultiWord = str_contains($core, ' ');

        // Pick method words: the input's own first (if any), then a couple of fresh ones that ≠ core.
        $methodPool = array_values(array_unique(array_filter(array_merge($inputMethod !== '' ? [$inputMethod] : [], $methods),
            fn (string $m): bool => mb_strtolower($m) !== mb_strtolower($core))));

        // Structure A: number-anchored (only when the mechanism genuinely enumerates).
        if ($num !== '' && ! $coreIsMultiWord) {
            foreach (array_slice($methodPool, 0, 3) as $mw) {
                $name = $pt
                    ? $this->ptArticle($mw)." {$mw} dos {$num} {$core}"   // "A Regra dos 7 Envelopes" / "O Método dos 3 Hormônios"
                    : "{$article} {$num}-{$core} {$mw}";                   // "The 3-Hormone Reset"
                $cands[] = $this->cand($name, 'number+core+method');
            }
        }
        // Structure B: a multi-word core often IS the mechanism name — let it stand alone.
        if ($coreIsMultiWord) {
            $cands[] = $this->cand($pt ? $core : "{$article} {$core}", 'core_only');
        }
        // Structure C: core + method word. PT uses "{Method} {Core}" (e.g. "Protocolo Metabólico") to
        // dodge article-gender errors; EN uses "The {Core} {Method}".
        foreach (array_slice($methodPool, 0, 3) as $mw) {
            $cands[] = $this->cand($pt ? "{$mw} {$core}" : "{$article} {$core} {$mw}", 'core+method');
        }

        // Dedup + rank.
        $seen = [];
        $unique = [];
        foreach ($cands as $c) {
            if ($c['name'] !== '' && ! isset($seen[$c['name']])) {
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
        $name = trim((string) preg_replace('/\s+/', ' ', $name));
        $words = str_word_count($name);
        $score = 50
            + (preg_match('/\d/', $name) ? 18 : 0)            // a real number is stronger
            + ($words >= 3 && $words <= 5 ? 15 : 0)           // tight, brandable
            + (str_contains($structure, 'core') ? 12 : 0)
            + ($structure === 'preserved' ? 50 : 0);

        return ['name' => $name, 'structure' => $structure, 'score' => min(100, $score)];
    }

    /** The concrete mechanism nouns: drop stopwords, modifiers, method words and bare numbers. */
    private function coreTokens(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $t) {
            if ($t === '' || is_numeric($t) || mb_strlen($t) < 4) {
                continue;
            }
            if (in_array($t, self::STOP, true) || in_array($t, self::MODIFIER, true) || in_array($t, self::METHOD_DETECT, true)) {
                continue;
            }
            $out[] = $t;
        }
        // Prefer the most concrete (longest) cores; keep input order among equals for readability.
        usort($out, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return array_slice($out, 0, 2);
    }

    private function detectMethod(array $tokens): string
    {
        foreach ($tokens as $t) {
            if (in_array($t, self::METHOD_DETECT, true)) {
                return self::METHOD_TITLE[$t] ?? ucfirst($t);
            }
        }

        return '';
    }

    /** A small enumerating count [2-9] from the MECHANISM text only (never from the result promise). */
    private function mechanismNumber(string $src): string
    {
        $s = mb_strtolower($src);
        if (preg_match('/\b([2-9])\b/', $s, $m)) {
            return $m[1];
        }
        foreach (['three' => '3', 'two' => '2', 'four' => '4', 'five' => '5', 'seven' => '7', 'três' => '3', 'dois' => '2', 'sete' => '7'] as $w => $d) {
            if (str_contains($s, $w)) {
                return $d;
            }
        }

        return '';
    }

    /** PT article by the method word's grammatical gender (Fórmula/Sequência/Regra are feminine). */
    private function ptArticle(string $methodWord): string
    {
        return in_array(mb_strtolower($methodWord), ['fórmula', 'formula', 'sequência', 'sequencia', 'regra'], true) ? 'A' : 'O';
    }

    private function isAlreadyElite(string $src): bool
    {
        return (bool) preg_match('/^the\s+\d-\w+\s+\w+$/i', trim($src));
    }

    private function isPortuguese(AiMarketingVslAsset $asset): bool
    {
        $lang = mb_strtolower((string) $asset->language);
        if (str_contains($lang, 'pt') || str_contains($lang, 'por')) {
            return true;
        }
        $hay = mb_strtolower((string) $asset->mechanism_name.' '.(string) $asset->big_idea);

        return (bool) preg_match('/[áàâãéêíóôõúç]| dos | das | método|protocolo|gatilho/u', $hay);
    }

    /**
     * @return array<int,string>
     */
    private function tokens(string $s): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($s))) ?: [];

        return array_values(array_filter($parts, static fn (string $t): bool => $t !== ''));
    }

    private function title(string $s): string
    {
        return trim(implode(' ', array_map(static fn (string $w): string => $w === '' ? '' : mb_strtoupper(mb_substr($w, 0, 1)).mb_substr($w, 1), explode(' ', trim($s)))));
    }

    /**
     * @param  array<int,string>  $candidates
     */
}
