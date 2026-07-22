<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SymbolicAnchoring;

/**
 * Read-only reporter that computes anchor-coverage metrics over a window of FACTs and emits its
 * own FACT marked ANCHOR_COVERAGE_FACT.
 *
 * Anti-Goodhart guards:
 *   - coverage_pct is computed ONLY over FACTs marked anchor_required=true; narrative/operator FACTs
 *     are reported as `excluded_narrative` but do not deflate the headline number.
 *   - The emitted FACT carries NO `score|grade|rating` key — it is a fact, not a measurement that
 *     decision/grading services may consume blindly.
 */
final class AtlasLoopFactAnchorCoverageReporter
{
    public const FACT_TYPE = 'ANCHOR_COVERAGE_FACT';

    public function __construct(private readonly AtlasLoopFactAnchorExtractor $extractor) {}

    /**
     * @param  iterable<array<string,mixed>>  $factWindow  each row: {text:string, anchor_required:bool, source?:string, metadata?:array}
     * @return array<string,mixed>
     */
    public function report(iterable $factWindow): array
    {
        $total = 0;
        $criticalAnchored = 0;
        $criticalTotal = 0;
        $excludedNarrative = 0;
        $unresolvedPhantomFacts = 0;
        $perSource = [];
        $histogram = [0, 0, 0, 0]; // buckets 0,1,2,3+

        foreach ($factWindow as $row) {
            if (! is_array($row)) {
                continue;
            }
            $total++;
            $text = (string) ($row['text'] ?? '');
            $required = (bool) ($row['anchor_required'] ?? false);
            $source = (string) ($row['source'] ?? 'unknown');
            $meta = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
            $anchors = $this->extractor->extract($text, $meta);
            $resolved = $anchors->resolvedCount();
            $unresolved = $anchors->unresolvedCount();

            $perSource[$source] ??= ['total' => 0, 'anchored' => 0, 'phantom_only' => 0];
            $perSource[$source]['total']++;
            if ($resolved > 0) {
                $perSource[$source]['anchored']++;
            } elseif ($unresolved > 0) {
                $perSource[$source]['phantom_only']++;
                $unresolvedPhantomFacts++;
            }

            $bucket = $resolved >= 3 ? 3 : $resolved;
            $histogram[$bucket]++;

            if ($required) {
                $criticalTotal++;
                if ($resolved > 0) {
                    $criticalAnchored++;
                }
            } else {
                $excludedNarrative++;
            }
        }

        $coveragePct = $criticalTotal > 0 ? round(($criticalAnchored / $criticalTotal) * 100, 2) : 0.0;
        ksort($perSource);

        return [
            'type' => self::FACT_TYPE,
            'total_facts' => $total,
            'anchored_facts_in_critical' => $criticalAnchored,
            'critical_facts_total' => $criticalTotal,
            'coverage_pct' => $coveragePct,
            'excluded_narrative' => $excludedNarrative,
            'unresolved_phantom_facts' => $unresolvedPhantomFacts,
            'per_source' => $perSource,
            'anchor_density_histogram' => [
                '0' => $histogram[0],
                '1' => $histogram[1],
                '2' => $histogram[2],
                '3+' => $histogram[3],
            ],
        ];
    }
}
