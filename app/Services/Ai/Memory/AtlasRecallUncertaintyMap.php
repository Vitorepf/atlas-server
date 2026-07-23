<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory;

/**
 * T4-S4 (Obra #17) — the recall's UNCERTAINTY MAP. A conversational MCP recall must not
 * hand back memories as if they were equally trustworthy: it must say HOW confident the
 * retrieval is, so the reader treats a weak/flat recall as a weak signal (and can ask for
 * more) instead of settled truth. This is a pure, deterministic read over the recall
 * result the hybrid retrieval already produces — it invents no scores.
 *
 * Scale-invariant by design: recall scores are NOT normalised to 0..1 (the composer can
 * emit large blended scores), so the verdict keys on the RELATIVE separation of the top
 * hit from the rest, never an absolute score threshold.
 */
final class AtlasRecallUncertaintyMap
{
    public const SCHEMA = 'atlas.recall_uncertainty.v1';

    /**
     * A clearly-separated single top hit is CONFIDENT; a flat/weak distribution is
     * UNCERTAIN. Relative-margin knob (upgrade path: calibrate against real feedback).
     */
    private const CONFIDENT_RELATIVE_MARGIN = 0.30;

    /**
     * @param  array<string,mixed>  $recall  an AtlasHybridMemoryRetrievalService::recall() result
     * @return array{schema:string, verdict:string, recalled:int, candidates:int, top_score:float|null, margin:float|null, relative_margin:float, coverage:float}
     */
    public function forRecall(array $recall): array
    {
        $items = array_values(array_filter((array) ($recall['recall'] ?? []), 'is_array'));
        $recalled = count($items);

        $summary = (array) ($recall['summary'] ?? []);
        $candidates = (int) ($summary['registry_candidates'] ?? 0)
            + (int) ($summary['verbatim_candidates'] ?? 0)
            + (int) ($summary['semantic_candidates'] ?? 0)
            + (int) ($summary['compounding_candidates'] ?? 0);

        if ($recalled === 0) {
            return [
                'schema' => self::SCHEMA,
                'verdict' => 'no_signal',
                'recalled' => 0,
                'candidates' => $candidates,
                'top_score' => null,
                'margin' => null,
                'relative_margin' => 0.0,
                'coverage' => 0.0,
            ];
        }

        $scores = array_map(static fn (array $item): float => (float) ($item['score'] ?? 0.0), $items);
        rsort($scores);
        $top = $scores[0];
        $second = $scores[1] ?? 0.0;
        $margin = round($top - $second, 3);
        $relativeMargin = $top > 0.0 ? round(($top - $second) / $top, 3) : 0.0;
        $coverage = $candidates > 0 ? round(min(1.0, $recalled / $candidates), 3) : 1.0;

        $verdict = ($recalled === 1 || $relativeMargin >= self::CONFIDENT_RELATIVE_MARGIN)
            ? 'confident'
            : 'uncertain';

        return [
            'schema' => self::SCHEMA,
            'verdict' => $verdict,
            'recalled' => $recalled,
            'candidates' => $candidates,
            'top_score' => round($top, 3),
            'margin' => $margin,
            'relative_margin' => $relativeMargin,
            'coverage' => $coverage,
        ];
    }
}
