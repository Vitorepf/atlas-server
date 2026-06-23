<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * CopySubstanceProbe — the substance gate the brutal cross-niche panel forced into existence.
 *
 * The persona panels detect WHICH conversion markers a page contains. But a skeptic is not moved by
 * the WORDS "verified, audited, track record" — those are advertiser self-labels any scammer types in
 * ten minutes. He is moved by SUBSTANCE: concrete specifics, a real story, a named event, a number he
 * can check. And a keyword salad ("track record verified audited as seen on...") fires every marker
 * while persuading no one.
 *
 * This probe scores 0..1 how SUBSTANTIVE a page is, independent of which markers it hits, so the
 * audience score can be gated by it — a vacuous page cannot out-score real craft just by stacking the
 * right vocabulary. Two deterministic failure modes are caught:
 *   1. SALAD — a run of noun-phrases with almost no grammatical glue (low function-word ratio + almost
 *      no sentence punctuation). Real prose has connective tissue; a marker list does not.
 *   2. BARE-LABEL STUFFING — many self-declared authority/proof LABELS with zero credibility-specifics
 *      (no year, no named duration, no first-person story, no checkable number). The dressed scam.
 *
 * It does NOT claim to detect a sophisticated, grammatical, specific-but-false page — that needs the
 * LLM verifier (off the conversion-critical path by rule) or the calibrated outcome ledger (dormant).
 * It is an honest deterministic prior, not a lie detector. Provider-free.
 */
class CopySubstanceProbe
{
    /** Self-declared authority/proof LABELS — credible only when backed by specifics. */
    private const LABEL_CLAIMS = [
        'verified', 'audited', 'track record', 'as seen on', 'proven', 'certified', 'trusted',
        'official', 'guaranteed', 'world-class', 'industry-leading', 'award-winning',
        'comprovado', 'auditado', 'certificado', 'oficial', 'garantido', 'premiado',
    ];

    /** Common function words (EN + PT) — the connective tissue of real prose. */
    private const FUNCTION_WORDS = [
        'the', 'a', 'an', 'of', 'to', 'in', 'is', 'are', 'was', 'were', 'be', 'been', 'you', 'your',
        'i', 'my', 'me', 'we', 'our', 'it', 'its', 'that', 'this', 'and', 'but', 'for', 'with', 'on',
        'at', 'as', 'so', 'if', 'when', 'who', 'what', 'how', 'not', 'no', 'they', 'them', 'he', 'she',
        'o', 'os', 'as', 'um', 'uma', 'de', 'do', 'da', 'dos', 'das', 'que', 'voce', 'você', 'eu',
        'meu', 'minha', 'nao', 'não', 'com', 'em', 'por', 'pra', 'para', 'se', 'quando', 'quem', 'e',
        'mas', 'ele', 'ela', 'isso', 'foi', 'era', 'sou',
    ];

    /**
     * @return array{substance:float,prose_ratio:float,label_claims:int,credibility_specifics:int,grade:string}
     */
    public function analyze(string $copy): array
    {
        $text = mb_strtolower(trim($copy));
        $words = preg_split('/\s+/u', $text) ?: [];
        $wc = count(array_filter($words, static fn ($w) => $w !== ''));

        $funcHits = 0;
        $fn = array_flip(self::FUNCTION_WORDS);
        foreach ($words as $w) {
            $clean = preg_replace('/[^\p{L}\p{N}]/u', '', $w) ?? '';
            if ($clean !== '' && isset($fn[$clean])) {
                $funcHits++;
            }
        }
        $proseRatio = $wc > 0 ? $funcHits / $wc : 0.0;
        $sentencePunct = preg_match_all('/[.!?](?:\s|$)/u', $text);

        $labelClaims = 0;
        foreach (self::LABEL_CLAIMS as $l) {
            $labelClaims += substr_count($text, $l);
        }

        $credSpecifics = $this->credibilitySpecifics($text);

        $substance = 1.0;

        // 1. SALAD: a marker list pretending to be copy. Two shapes, both caught:
        //    (a) a wall of 14+ words with ZERO sentence punctuation — real copy always has sentences;
        //        a marker dump does not (this catches the relationship salad whose marker-phrases like
        //        "not your fault" inflate the function-word ratio enough to look like prose);
        //    (b) low grammatical glue AND almost no sentences.
        //    Terse-but-real copy ("He left. You stayed.") survives both — it has sentence punctuation.
        $isWallOfWords = $wc >= 14 && $sentencePunct === 0;
        $isGlueless = $wc >= 12 && $proseRatio < 0.24 && $sentencePunct <= max(1, (int) floor($wc / 25));
        if ($isWallOfWords || $isGlueless) {
            $substance *= 0.22;
        }

        // 2. BARE-LABEL STUFFING: stacks self-declared credibility labels with nothing concrete to back
        //    them. The dressed scam. A page with even ONE real specific escapes (it's earning the claim).
        if ($labelClaims >= 2 && $credSpecifics === 0) {
            $substance *= ($labelClaims >= 3 ? 0.32 : 0.45);
        }

        return [
            'substance' => round($substance, 3),
            'prose_ratio' => round($proseRatio, 3),
            'label_claims' => $labelClaims,
            'credibility_specifics' => $credSpecifics,
            'grade' => $substance >= 0.8 ? 'substantive' : ($substance >= 0.45 ? 'thin' : 'hollow'),
        ];
    }

    public function substance(string $copy): float
    {
        return $this->analyze($copy)['substance'];
    }

    /**
     * Count distinct TYPES of credibility-specifics present. These are the anchors a skeptic actually
     * checks — explicitly NOT generic offer terms ($ price, "60-day guarantee"), which any scam states.
     */
    private function credibilitySpecifics(string $text): int
    {
        $types = 0;
        // Named year / event ("2008", "in 2022")
        if (preg_match('/\b(?:19|20)\d{2}\b/u', $text)) {
            $types++;
        }
        // Track-record duration in years (digit or spelled), e.g. "17 years", "eleven years", "17 anos"
        if (preg_match('/\b(?:\d+|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|fifteen|seventeen|twenty)\s+(?:years?|anos?)\b/u', $text)) {
            $types++;
        }
        // Percentage in context (a drawdown / rate — "11%", "drawdown of 11")
        if (preg_match('/\b\d{1,3}\s?%/u', $text) || preg_match('/\bdrawdown\b/u', $text)) {
            $types++;
        }
        // First-person lived narrative (the story lead — "my father", "I lost", "I spent", "eu perdi")
        if (preg_match('/\b(?:my father|my mother|my wife|my husband|i lost|i spent|i swore|i built|i watched|meu pai|minha mãe|eu perdi|eu gastei|eu jurei|eu construí)\b/u', $text)) {
            $types++;
        }
        // Verifiable invitation ("read the statements yourself", "see the books", "screen recorded")
        if (preg_match('/\b(?:read the (?:brokerage )?statements|see the books|screen[- ]record|leia os (?:extratos|dezessete)|extratos)\b/u', $text)) {
            $types++;
        }

        return $types;
    }
}
