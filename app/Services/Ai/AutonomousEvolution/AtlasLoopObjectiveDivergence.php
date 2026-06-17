<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * ACDE U3 — sample-N objective DIVERGENCE (Jaccard). When U1 draws K independent readings of a file, the
 * SPREAD of the objectives those readings propose is a free ambiguity signal: if K honest reads agree on
 * what to improve, the file is legible and the loop should proceed; if they propose wildly different
 * objectives (high mean pairwise Jaccard DISTANCE over their word sets), the file is genuinely ambiguous and
 * the calibrated move is to ASK the operator rather than commit to one arbitrary reading.
 *
 * Pure + deterministic (objective texts in -> a distance in [0,1] out). It measures DISAGREEMENT between the
 * human-frozen-to-be objectives the engine proposed — it never judges which is "correct" (that rides the
 * model); a high spread only routes to a human question, never fabricates or picks a bar. < 2 readings => 0
 * (nothing to disagree about).
 */
final class AtlasLoopObjectiveDivergence
{
    /**
     * Mean pairwise Jaccard DISTANCE (1 - intersection/union of word sets) over the objective texts, in
     * [0,1]. 0 = every reading proposed the same words (full agreement); 1 = the readings share no words.
     *
     * @param  list<string>  $objectives
     */
    public function meanPairwiseJaccardDistance(array $objectives): float
    {
        $sets = [];
        foreach ($objectives as $text) {
            $set = $this->wordSet((string) $text);
            if ($set !== []) {
                $sets[] = $set;
            }
        }
        $n = count($sets);
        if ($n < 2) {
            return 0.0; // nothing to disagree about
        }

        $sum = 0.0;
        $pairs = 0;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $sum += 1.0 - $this->jaccard($sets[$i], $sets[$j]);
                $pairs++;
            }
        }

        return $pairs === 0 ? 0.0 : round($sum / $pairs, 4);
    }

    /**
     * Do the K readings DIVERGE beyond the threshold? False for < 2 readings (no disagreement possible) and
     * for a non-positive threshold (disabled).
     *
     * @param  list<string>  $objectives
     */
    public function diverges(array $objectives, float $threshold): bool
    {
        if ($threshold <= 0.0) {
            return false;
        }

        return $this->meanPairwiseJaccardDistance($objectives) >= $threshold;
    }

    /**
     * Public Jaccard SIMILARITY (intersection/union of word sets) in [0,1] between two texts — the complement
     * of the distance used above. Reused by the F8 human-atom paraphrase audit. 1 = identical word sets.
     */
    public function jaccardSimilarity(string $a, string $b): float
    {
        return round($this->jaccard($this->wordSet($a), $this->wordSet($b)), 4);
    }

    /**
     * @return list<string>
     */
    private function wordSet(string $text): array
    {
        $tokens = preg_split('/[^a-z0-9]+/', mb_strtolower(trim($text)), -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($tokens)) {
            return [];
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function jaccard(array $a, array $b): float
    {
        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        return $union === 0 ? 1.0 : $intersection / $union;
    }
}
