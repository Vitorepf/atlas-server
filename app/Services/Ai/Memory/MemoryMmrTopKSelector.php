<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory;

/**
 * MAXB-04 — MMR (Maximal Marginal Relevance) top-K selection.
 *
 * Pure selector: after score-sort, before greedy budget. λ≈0.7 balances
 * relevance vs diversity. Only candidates with a resolvable pairwise similarity
 * participate; others keep stable tail order after the MMR head.
 *
 * Default-OFF at the composer seam — this class never flips live retrieval.
 */
final class MemoryMmrTopKSelector
{
    public const SCHEMA_VERSION = 'atlas.memory.mmr_top_k.v1';

    public const DEFAULT_LAMBDA = 0.7;

    /**
     * @param  list<array<string,mixed>>  $candidates  already score-sorted desc
     * @param  callable(string,string):?float  $pairSimilarity  idA,idB → cosine 0..1 (null = unavailable)
     * @return list<array<string,mixed>>
     */
    public function select(array $candidates, int $limit, float $lambda, callable $pairSimilarity): array
    {
        $limit = max(0, $limit);
        if ($limit === 0 || $candidates === []) {
            return [];
        }

        $lambda = max(0.0, min(1.0, $lambda));

        $embeddable = [];
        $tail = [];
        foreach ($candidates as $index => $candidate) {
            $id = $this->registryId($candidate);
            if ($id === null) {
                $tail[] = $candidate;

                continue;
            }
            $embeddable[] = ['id' => $id, 'index' => $index, 'candidate' => $candidate];
        }

        if (count($embeddable) < 2) {
            return array_slice(array_values($candidates), 0, $limit);
        }

        $rawScores = array_map(static fn (array $row): float => (float) ($row['candidate']['score'] ?? 0.0), $embeddable);
        $minRel = min($rawScores);
        $maxRel = max($rawScores);
        $span = $maxRel - $minRel;
        foreach ($embeddable as $i => $row) {
            $raw = (float) ($row['candidate']['score'] ?? 0.0);
            $embeddable[$i]['rel_norm'] = $span > 1e-9 ? (($raw - $minRel) / $span) : 1.0;
        }

        $selected = [];
        $remaining = $embeddable;

        while (count($selected) < $limit && $remaining !== []) {
            $bestPos = null;
            $bestScore = -INF;

            foreach ($remaining as $pos => $row) {
                $rel = (float) $row['rel_norm'];
                $maxSim = 0.0;
                foreach ($selected as $picked) {
                    $sim = $pairSimilarity($row['id'], $picked['id']);
                    if ($sim === null) {
                        continue;
                    }
                    $maxSim = max($maxSim, max(0.0, min(1.0, $sim)));
                }
                $mmr = ($lambda * $rel) - ((1.0 - $lambda) * $maxSim);
                if ($bestPos === null
                    || $mmr > $bestScore
                    || ($mmr === $bestScore && $row['index'] < $remaining[$bestPos]['index'])
                ) {
                    $bestScore = $mmr;
                    $bestPos = $pos;
                }
            }

            if ($bestPos === null) {
                break;
            }

            $picked = $remaining[$bestPos];
            $explain = is_array($picked['candidate']['explain'] ?? null) ? $picked['candidate']['explain'] : [];
            $explain['mmr'] = [
                'schema_version' => self::SCHEMA_VERSION,
                'lambda' => $lambda,
                'mmr_score' => round($bestScore, 6),
            ];
            $picked['candidate']['explain'] = $explain;
            $selected[] = $picked;
            unset($remaining[$bestPos]);
            $remaining = array_values($remaining);
        }

        $out = array_map(static fn (array $row): array => $row['candidate'], $selected);
        foreach ($remaining as $row) {
            if (count($out) >= $limit) {
                break;
            }
            $out[] = $row['candidate'];
        }
        foreach ($tail as $candidate) {
            if (count($out) >= $limit) {
                break;
            }
            $out[] = $candidate;
        }

        return $out;
    }

    /**
     * Cosine similarity of two dense float vectors (fixture / in-memory path).
     *
     * @param  list<float>|array<int,float>  $a
     * @param  list<float>|array<int,float>  $b
     */
    public static function cosine(array $a, array $b): ?float
    {
        if ($a === [] || $b === [] || count($a) !== count($b)) {
            return null;
        }

        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        foreach ($a as $i => $av) {
            $bv = (float) $b[$i];
            $av = (float) $av;
            $dot += $av * $bv;
            $na += $av * $av;
            $nb += $bv * $bv;
        }
        if ($na <= 0.0 || $nb <= 0.0) {
            return null;
        }

        return max(0.0, min(1.0, $dot / (sqrt($na) * sqrt($nb))));
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function registryId(array $candidate): ?string
    {
        if (($candidate['source_ref_type'] ?? null) !== 'atlas_memory_entry') {
            return null;
        }

        $id = trim((string) ($candidate['source_ref_id'] ?? ''));
        if ($id === '') {
            return null;
        }

        // MMR only runs on candidates with a resolvable embedding signal.
        if (! isset($candidate['embedding_vector']) || ! is_array($candidate['embedding_vector']) || $candidate['embedding_vector'] === []) {
            return null;
        }

        return $id;
    }
}
