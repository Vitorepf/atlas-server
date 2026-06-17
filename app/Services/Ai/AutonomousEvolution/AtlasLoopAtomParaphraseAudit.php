<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * ACDE F8 (honest, non-Goodhart form) — paraphrase audit over the HUMAN-FROZEN verification atoms.
 *
 * The LITERAL F8 — "sample-N over paraphrases, abstain on INFERRED atoms" — is forbidden by the Atlas canon:
 * the loop must NEVER infer, author, weaken, or grade its own acceptance atoms (that is the Goodhart path for
 * which P1 spec-consensus and V2 sub-requirement-fan-out were correctly KILLED). So this does NOT generate or
 * judge any bar. It applies the SAME Jaccard mechanism U3 uses — but to the atoms the HUMAN already froze — to
 * surface a possible AUTHORING issue: two atoms that read as near-paraphrases of each other (very high word
 * overlap) are likely a redundant/duplicated criterion the operator may want to merge or disambiguate. The
 * output is a read-only ADVISORY routed to the operator; it never removes an atom, never changes the bar, and
 * never blocks a feature. Pure + deterministic.
 */
final class AtlasLoopAtomParaphraseAudit
{
    public function __construct(private readonly ?AtlasLoopObjectiveDivergence $jaccard = null) {}

    /**
     * Pairs of human atoms whose descriptions are near-paraphrases (pairwise Jaccard similarity >= threshold).
     * The atom TEXT is read from common shape keys (description / text / id) — never inferred. Advisory only.
     *
     * @param  list<array<string,mixed>>  $atoms  the human-frozen verification_atoms
     * @return list<array{a:int, b:int, similarity:float, a_text:string, b_text:string}>
     */
    public function nearDuplicatePairs(array $atoms, float $threshold = 0.85): array
    {
        if ($threshold <= 0.0) {
            return [];
        }
        $jaccard = $this->jaccard ?? new AtlasLoopObjectiveDivergence;

        $texts = [];
        foreach (array_values($atoms) as $i => $atom) {
            $texts[$i] = $this->atomText(is_array($atom) ? $atom : []);
        }

        $pairs = [];
        $n = count($texts);
        for ($i = 0; $i < $n; $i++) {
            if (trim($texts[$i]) === '') {
                continue;
            }
            for ($j = $i + 1; $j < $n; $j++) {
                if (trim($texts[$j]) === '') {
                    continue;
                }
                $sim = $jaccard->jaccardSimilarity($texts[$i], $texts[$j]);
                if ($sim >= $threshold) {
                    $pairs[] = [
                        'a' => $i,
                        'b' => $j,
                        'similarity' => $sim,
                        'a_text' => $texts[$i],
                        'b_text' => $texts[$j],
                    ];
                }
            }
        }

        return $pairs;
    }

    /**
     * @param  array<string,mixed>  $atom
     */
    private function atomText(array $atom): string
    {
        foreach (['description', 'text', 'criterion', 'id'] as $key) {
            $v = $atom[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return '';
    }
}
