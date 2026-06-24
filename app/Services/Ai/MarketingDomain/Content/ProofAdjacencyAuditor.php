<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * ProofAdjacencyAuditor — believability as structural truth (the masters' unanimous lever).
 *
 * Bencivenga ("believability is the lead domino") and Ogilvy ("make your claims verifiable") converge: a
 * sale dies on DISBELIEF, and the fix is not "is there proof somewhere on the page" — it is "does every
 * bold CLAIM have EXTERNAL proof ADJACENT to it, at the point of the assertion". A strong claim sitting
 * with no verifiable proof beside it is an orphan claim — a conversion liability.
 *
 * This finds each bold claim and checks the window around it (the claim sentence + the next) for an
 * EXTERNAL proof anchor — named/credentialed authority, a count of people studied, a ratio/percent, a
 * demonstration, an income receipt — reusing ProofSubstanceAuditor as the oracle. It deliberately does NOT
 * count the claim's own result number as its proof ("you will lose 34 lbs" is the claim, not evidence of
 * it). Structural FACT (claim↔external-proof proximity), not a vocabulary score. Provider-free, no brake.
 */
class ProofAdjacencyAuditor
{
    /** Proof anchor types that are EXTERNAL/verifiable — they can back a claim (vs the claim restating itself). */
    private const EXTERNAL = ['credentialed_authority', 'study_count', 'ratio_result', 'demonstration', 'income_receipt'];

    public function __construct(private readonly ProofSubstanceAuditor $proof = new ProofSubstanceAuditor) {}

    /**
     * @return array{orphan_claims:array<int,string>,backed_claims:array<int,string>,has_orphan_claim:bool,note:string}
     */
    public function audit(string $copy): array
    {
        // Protect abbreviation/decimal dots so the claim sentence and its proof stay in the same window.
        $protected = (string) preg_replace('/\b(dr|mr|mrs|ms|prof)\.\s*/iu', '$1 ', $copy);
        $sentences = array_values(array_filter(array_map('trim', preg_split('/(?<![0-9])[.!?]+(?![0-9])|\n+/u', $protected) ?: []), static fn ($s) => $s !== ''));

        $orphan = [];
        $backed = [];
        foreach ($sentences as $i => $s) {
            if (! $this->isClaim($s)) {
                continue;
            }
            $window = $s.' '.($sentences[$i + 1] ?? '');
            if (array_intersect($this->proof->audit($window)['concrete'], self::EXTERNAL) !== []) {
                $backed[] = $s;
            } else {
                $orphan[] = $s;
            }
        }

        $note = match (true) {
            $orphan === [] && $backed === [] => 'Nenhum claim forte detectado — a página pode estar fraca em promessa (ou é topo de funil).',
            $orphan === [] => 'Todo claim forte tem prova externa adjacente — believability ok.',
            default => count($orphan).' claim(s) ÓRFÃO(s) (promessa sem prova externa ao lado) — a venda morre na descrença. Encaixar autoridade/número-de-gente/ratio/demo JUNTO de cada um.',
        };

        return [
            'orphan_claims' => $orphan,
            'backed_claims' => $backed,
            'has_orphan_claim' => $orphan !== [],
            'note' => $note,
        ];
    }

    /** A sentence asserts a bold benefit/result: a promise framing, a result+number, or an absolute/superlative. */
    private function isClaim(string $s): bool
    {
        $t = mb_strtolower($s);

        // Promise framing aimed at the reader's future.
        if (preg_match('/\b(you (?:will|can|could|\x27ll)|you are going to|finally|guaranteed to|watch as|imagine)\b/u', $t)) {
            return true;
        }
        // A result/transformation verb tied to a number.
        if (preg_match('/\b(?:lose|lost|drop|dropped|melt|burn|make|earn|made|pull|gain|cut|lower|double|triple|add)\b[^.?!]*?\d/u', $t)) {
            return true;
        }
        // Absolute / superlative promise.
        if (preg_match('/\b(the only|the #?1|number one|fastest|easiest|the secret to|never again|once and for all|melts? away|skyrocket|wipe out|in (?:just )?\d+ days?)\b/u', $t)) {
            return true;
        }

        return false;
    }
}
