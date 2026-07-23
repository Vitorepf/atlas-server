<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Runtime for the Atlas Self-Construction "Quality Bar And Metrics" specification.
 *
 * Turns the doc's measurable quality bar into deterministic, pure decision logic:
 *
 *  - Score Bands: maps a 0..N maturity score to its documented band label
 *    (0-3 ad hoc, 4-5 documented/weak, 6-7 governed/testable,
 *     8-9 agent-executable/strong evidence, 9+ self-improving/drift-aware).
 *  - The hard rule "No score above 9 is valid without repeated autonomous runs
 *    and low drift" is enforced as a real gate: a score above 9 is INVALID and
 *    clamped to the effective ceiling of 9 unless BOTH proofs are supplied.
 *  - Definition Of Done: an 8-item checklist where a block is "done" only when
 *    EVERY documented condition holds; otherwise it reports exactly which are missing.
 *  - The Metrics catalog (9 dimensions) and Quality Gates (10 ordered gates) are
 *    exposed as the canonical enumerable contract.
 *
 * Pure: no database, no IO, no clock. Same input -> same output.
 *
 * @see docs/engineering-knowledge-base/self-construction/quality-bar-and-metrics.md
 */
final class AtlasQualityBarAndMetricsService
{
    private const SCHEMA_VERSION = 'atlas.aaeos.quality_bar_and_metrics.v1';

    /**
     * The maximum score that can be claimed WITHOUT the two extra proofs.
     * Doc: "No score above 9 is valid without repeated autonomous runs and low drift."
     */
    private const STRICT_CEILING = 9;

    /**
     * Score Bands (doc -> "Score Bands" table). Each band carries the inclusive
     * lower bound that opens it; bands are evaluated from highest to lowest.
     * The "9+" band is special: it opens at 9 but only via the validated path.
     *
     * @var array<int, array{min: int, key: string, meaning: string}>
     */
    private const SCORE_BANDS = [
        ['min' => 9, 'key' => 'self_improving', 'meaning' => 'self-improving, drift-aware and strategically prioritized'],
        ['min' => 8, 'key' => 'agent_executable', 'meaning' => 'agent-executable with strong evidence'],
        ['min' => 6, 'key' => 'governed_testable', 'meaning' => 'governed and testable'],
        ['min' => 4, 'key' => 'documented_weak', 'meaning' => 'documented but weakly validated'],
        ['min' => 0, 'key' => 'ad_hoc', 'meaning' => 'ad hoc coding'],
    ];

    /**
     * Definition Of Done (doc -> "Definition Of Done"). A self-construction block
     * is done when ALL of these hold. Maps the documented clause to the caller flag.
     *
     * @var array<string, string>
     */
    private const DONE_CONDITIONS = [
        'docs_ap_spec_exist' => 'docs/AP/spec exist',
        'code_matches_spec' => 'code change, if any, matches spec',
        'focused_tests_pass' => 'focused tests pass',
        'architecture_doc_gates_pass' => 'architecture/doc gates pass',
        'evidence_append_only' => 'evidence is append-only',
        'drift_checked' => 'drift is checked',
        'maturity_delta_stated' => 'maturity delta is stated',
        'residual_risk_explicit' => 'residual risk is explicit',
    ];

    /**
     * Self-construction Quality Gates, in documented order (doc -> "Quality Gates").
     *
     * @var array<int, string>
     */
    private const QUALITY_GATES = [
        'docs-health',
        'architecture-validate',
        'knowledge sync',
        'code index',
        'focused tests',
        'static scans',
        'receipt scope check',
        'traceability check',
        'drift check',
        'diff check',
    ];

    /**
     * Metrics catalog (doc -> "Metrics" table), dimension => list of metrics.
     *
     * @var array<string, array<int, string>>
     */
    private const METRICS = [
        'context' => ['relevant context precision', 'stale context rate', 'missing-doc rate'],
        'sdd' => ['spec completeness', 'assumption quality', 'acceptance coverage'],
        'execution' => ['gate pass rate', 'repair count', 'rollback readiness'],
        'evidence' => ['requirements with proof', 'citation/evidence health'],
        'drift' => ['spec/code/test mismatch count'],
        'continuity' => ['handoff success', 'repeated decision rate', 'long-session degradation'],
        'safety' => ['blocked unsafe actions', 'forbidden-scope attempts'],
        'priority' => ['high-leverage task selection accuracy'],
        'learning' => ['proposals accepted', 'proposals rejected', 'unsafe proposal rate'],
    ];

    /**
     * Classify a raw score into its documented band, enforcing the hard rule that
     * a score above 9 is only valid when repeated autonomous runs AND low drift
     * are both proven; otherwise the effective score is clamped to 9.
     *
     * @param int|float $score raw maturity score
     * @param array{repeated_autonomous_runs?: bool, low_drift?: bool} $proofs
     * @return array{
     *     schema_version: string,
     *     raw_score: float,
     *     effective_score: float,
     *     band_key: string,
     *     band_meaning: string,
     *     above_nine_claimed: bool,
     *     above_nine_valid: bool,
     *     clamped: bool,
     *     missing_proofs: array<int, string>
     * }
     */
    public function scoreBand(int|float $score, array $proofs = []): array
    {
        $raw = (float) $score;
        $aboveNineClaimed = $raw > self::STRICT_CEILING;

        $repeatedRuns = (bool) ($proofs['repeated_autonomous_runs'] ?? false);
        $lowDrift = (bool) ($proofs['low_drift'] ?? false);

        $missingProofs = [];
        if (! $repeatedRuns) {
            $missingProofs[] = 'repeated autonomous runs';
        }
        if (! $lowDrift) {
            $missingProofs[] = 'low drift';
        }

        $aboveNineValid = $aboveNineClaimed && $repeatedRuns && $lowDrift;

        // Enforce the doc rule: an unvalidated above-9 claim is clamped to 9.
        $effective = ($aboveNineClaimed && ! $aboveNineValid)
            ? (float) self::STRICT_CEILING
            : $raw;

        $band = $this->bandForScore($effective);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'raw_score' => $raw,
            'effective_score' => $effective,
            'band_key' => $band['key'],
            'band_meaning' => $band['meaning'],
            'above_nine_claimed' => $aboveNineClaimed,
            'above_nine_valid' => $aboveNineValid,
            'clamped' => $aboveNineClaimed && ! $aboveNineValid,
            'missing_proofs' => $aboveNineClaimed ? array_values($missingProofs) : [],
        ];
    }

    /**
     * Evaluate the Definition Of Done. A block is done only when every one of the
     * 8 documented conditions is true; otherwise report exactly which are missing.
     *
     * @param array<string, bool> $flags
     * @return array{
     *     schema_version: string,
     *     done: bool,
     *     total_conditions: int,
     *     satisfied_count: int,
     *     missing: array<int, string>,
     *     satisfied: array<int, string>
     * }
     */
    public function definitionOfDone(array $flags): array
    {
        $missing = [];
        $satisfied = [];

        foreach (self::DONE_CONDITIONS as $key => $label) {
            if ((bool) ($flags[$key] ?? false)) {
                $satisfied[] = $label;
            } else {
                $missing[] = $label;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'done' => $missing === [],
            'total_conditions' => count(self::DONE_CONDITIONS),
            'satisfied_count' => count($satisfied),
            'missing' => $missing,
            'satisfied' => $satisfied,
        ];
    }

    /**
     * The full canonical quality contract: metrics catalog, ordered gates and the
     * score-band ladder. Used as the default command projection.
     *
     * @return array{
     *     schema_version: string,
     *     metrics: array<string, array<int, string>>,
     *     metric_dimension_count: int,
     *     quality_gates: array<int, string>,
     *     quality_gate_count: int,
     *     score_bands: array<int, array{min: int, key: string, meaning: string}>,
     *     strict_ceiling: int,
     *     definition_of_done: array<int, string>
     * }
     */
    public function contract(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'metrics' => self::METRICS,
            'metric_dimension_count' => count(self::METRICS),
            'quality_gates' => self::QUALITY_GATES,
            'quality_gate_count' => count(self::QUALITY_GATES),
            'score_bands' => self::SCORE_BANDS,
            'strict_ceiling' => self::STRICT_CEILING,
            'definition_of_done' => array_values(self::DONE_CONDITIONS),
        ];
    }

    /**
     * Resolve the band whose inclusive lower bound the score reaches.
     *
     * @return array{min: int, key: string, meaning: string}
     */
    private function bandForScore(float $score): array
    {
        foreach (self::SCORE_BANDS as $band) {
            if ($score >= $band['min']) {
                return $band;
            }
        }

        // Scores below 0 fall back to the ad hoc floor band.
        return self::SCORE_BANDS[array_key_last(self::SCORE_BANDS)];
    }
}
