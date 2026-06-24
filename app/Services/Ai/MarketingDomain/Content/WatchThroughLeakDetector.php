<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * WatchThroughLeakDetector — the HONEST sliver salvaged from a failed cycle.
 *
 * The loop first tried a 0-100 NarrativeTensionScorer to grade watch-through. A brutal cross-niche
 * panel killed it: a deterministic lexicon-positional score is a VOCABULARY PROXY — empty filler with
 * the right marker phrases parked in the right thirds scored "gripping", while real elite copy without
 * the cliché phrases scored "flat". Scoring copy QUALITY deterministically inverts on adversarial copy
 * (same lesson the Ciclo-43 audience_score taught). So the quality score was reverted.
 *
 * What survives is the ONE thing that is TRUE regardless of vocabulary: a literal reveal/hard-CTA that
 * appears in the OPENING is a real structural leak — you cannot retain a reader past a payoff you
 * already gave them, and a buy-button before desire is built is a dead-end exit. This detector emits
 * ONLY those true-positive WARNINGS. It produces NO positive quality score — so there is nothing to
 * game: empty copy gets no flaws AND no praise; it makes a claim only when a real leak is present.
 *
 * Conservative by design: it under-fires (a paraphrased reveal it can't see is a missed warning, not a
 * false alarm) rather than over-claiming. Real watch-through GRADING needs the outcome ledger (retention
 * data, dormant) or the LLM verifier (off the conversion-critical path by rule) — not a phrase list.
 * Provider-free, deterministic, niche-agnostic.
 */
class WatchThroughLeakDetector
{
    /** The reveal/payoff named explicitly — leaking this early kills the pull. Fuzzy (optional adverbs). */
    private const REVEAL = [
        '/\bhere\s+(?:is|are|\'s)\s+(?:exactly\s+|precisely\s+|finally\s+)?how\b/u',
        '/\bthe\s+(?:real\s+|actual\s+|whole\s+)?secret\s+is\b/u',
        '/\bthe\s+(?:real\s+)?answer\s+is\b/u',
        '/\bit(?:\'s| is)\s+called\b/u',
        '/\bthe\s+mechanism\s+is\b/u',
        '/\bthe\s+(?:one\s+)?(?:thing|trick|fix)\s+(?:is|that works)\b/u',
        '/\bveja\s+(?:exatamente\s+)?como\b/u',
        '/\bo\s+segredo\s+(?:real\s+)?é\b/u',
        '/\ba\s+resposta\s+é\b/u',
        '/\b(?:se\s+chama|chama-se)\b/u',
        '/\bo\s+mecanismo\s+é\b/u',
    ];

    /** Hard purchase CTAs — a dead-end exit if offered before desire is built. Excludes soft CTAs (watch). */
    private const HARD_CTA = [
        '/\bbuy\s+now\b/u', '/\border\s+now\b/u', '/\badd\s+to\s+cart\b/u', '/\bcheckout\b/u',
        '/\bget\s+instant\s+access\b/u', '/\bclaim\s+your\s+(?:spot|copy|order)\b/u',
        '/\bcompre\s+agora\b/u', '/\bfinalizar\s+(?:a\s+)?compra\b/u', '/\badicione\s+ao\s+carrinho\b/u',
    ];

    /** Fraction of the document considered "the opening" — a payoff/CTA here is premature. */
    private const OPENING = 0.30;

    /**
     * @return array{flaws:array<int,array{key:string,name:string,detail:string,position:int}>,assessed:bool,sentences:int}
     */
    public function detect(string $copy): array
    {
        $sentences = $this->sentences(mb_strtolower(trim($copy)));
        $n = count($sentences);

        // Too short to have an opening-vs-body structure — make no claim either way.
        if ($n < 5) {
            return ['flaws' => [], 'assessed' => false, 'sentences' => $n];
        }

        $flaws = [];
        $revealAt = $this->earliest($sentences, self::REVEAL);
        if ($revealAt !== null && $revealAt < self::OPENING) {
            $flaws[] = [
                'key' => 'premature_reveal',
                'name' => 'Revelação vazada no topo',
                'detail' => 'o mecanismo/segredo é nomeado a ~'.(int) round($revealAt * 100).'% da página — '
                    .'não dá pra reter quem já recebeu o payoff. Segure a revelação até depois de construir o desejo (>50%).',
                'position' => (int) round($revealAt * 100),
            ];
        }
        $ctaAt = $this->earliest($sentences, self::HARD_CTA);
        if ($ctaAt !== null && $ctaAt < self::OPENING) {
            $flaws[] = [
                'key' => 'premature_hard_cta',
                'name' => 'CTA de compra prematuro',
                'detail' => 'um CTA de compra duro aparece a ~'.(int) round($ctaAt * 100).'% — uma saída dead-end '
                    .'antes do desejo estar construído. Use micro-commit/CTA soft cedo; deixe o "compre" pro fim.',
                'position' => (int) round($ctaAt * 100),
            ];
        }

        return ['flaws' => $flaws, 'assessed' => true, 'sentences' => $n];
    }

    /**
     * @param  array<int,string>  $sentences
     * @param  array<int,string>  $patterns
     */
    private function earliest(array $sentences, array $patterns): ?float
    {
        $n = count($sentences);
        foreach ($sentences as $i => $s) {
            foreach ($patterns as $re) {
                if (@preg_match($re, $s) === 1) {
                    return $n > 1 ? $i / ($n - 1) : 0.0;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function sentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?])\s+|\n+/u', $text) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }

        return $out;
    }
}
