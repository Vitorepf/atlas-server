<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure triage engine. Classifies locally-captured research frontier rows into four groups.
 *
 * Priority order (first match wins):
 *   hype_rejected      — hype_signals >= HYPE_SIGNAL_MIN AND evidence_strength < HYPE_EVIDENCE_CAP (AC2).
 *   ungrounded_rejected — no code AND no benchmark AND evidence_strength < UNGROUNDED_CAP (AC2).
 *   promising           — evidence_strength >= PROMISING_MIN AND (has_code OR has_benchmark).
 *   exploratory         — all rows that pass rejection but lack the bar for promising.
 *
 * Thresholds:
 *   HYPE_SIGNAL_MIN    = 2  (>=2 hype signals triggers hype check)
 *   HYPE_EVIDENCE_CAP  = 0.40 (below this AND has hype → hype_rejected)
 *   UNGROUNDED_CAP     = 0.30 (evidence < 0.30, no code, no benchmark → ungrounded)
 *   PROMISING_MIN      = 0.70
 *
 * Pure, deterministic, no providers, no I/O.
 */
final class AtlasExternalBrainLocalResearchFrontierTriageEngine
{
    public const SCHEMA = 'atlas.external_brain.local_research_frontier_triage_engine.v1';

    private const HYPE_SIGNAL_MIN   = 2;
    private const HYPE_EVIDENCE_CAP = 0.40;
    private const UNGROUNDED_CAP    = 0.30;
    private const PROMISING_MIN     = 0.70;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function triage(array $facts): array
    {
        $rows = is_array($facts['frontier_rows'] ?? null) ? $facts['frontier_rows'] : [];

        $promising          = [];
        $exploratory        = [];
        $hypeRejected       = [];
        $ungroundedRejected = [];

        foreach ($rows as $row) {
            $id              = (string)  ($row['id']              ?? '');
            $title           = (string)  ($row['title']           ?? '');
            $evidenceStrength = max(0.0, min(1.0, (float) ($row['evidence_strength'] ?? 0.0)));
            $hasCode         = (bool)    ($row['has_code']         ?? false);
            $hasBenchmark    = (bool)    ($row['has_benchmark']    ?? false);
            $hypeSignals     = array_values((array) ($row['hype_signals'] ?? []));

            $entry = ['id' => $id, 'title' => $title, 'evidence_strength' => $evidenceStrength];

            // 1. Hype-only rejection (AC2).
            if (count($hypeSignals) >= self::HYPE_SIGNAL_MIN && $evidenceStrength < self::HYPE_EVIDENCE_CAP) {
                $hypeRejected[] = array_merge($entry, ['reason' => 'hype_signals_with_low_evidence', 'hype_signal_count' => count($hypeSignals)]);
                continue;
            }

            // 2. Ungrounded rejection (AC2).
            if (! $hasCode && ! $hasBenchmark && $evidenceStrength < self::UNGROUNDED_CAP) {
                $ungroundedRejected[] = array_merge($entry, ['reason' => 'no_code_no_benchmark_low_evidence']);
                continue;
            }

            // 3. Promising.
            if ($evidenceStrength >= self::PROMISING_MIN && ($hasCode || $hasBenchmark)) {
                $promising[] = array_merge($entry, ['has_code' => $hasCode, 'has_benchmark' => $hasBenchmark]);
                continue;
            }

            // 4. Exploratory.
            $exploratory[] = array_merge($entry, ['has_code' => $hasCode, 'has_benchmark' => $hasBenchmark]);
        }

        return [
            'schema_version'       => self::SCHEMA,
            'promising'            => $promising,
            'exploratory'          => $exploratory,
            'hype_rejected'        => $hypeRejected,
            'ungrounded_rejected'  => $ungroundedRejected,
            'promising_count'      => count($promising),
            'exploratory_count'    => count($exploratory),
            'hype_rejected_count'  => count($hypeRejected),
            'ungrounded_rejected_count' => count($ungroundedRejected),
        ];
    }
}
