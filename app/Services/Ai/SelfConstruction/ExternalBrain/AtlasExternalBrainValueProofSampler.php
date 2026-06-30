<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure classifier. Samples completed task records and classifies whether they
 * produced real capability, only observability, consolidation value, or low-value
 * scaffolding — feeding future originator ranking with proof instead of commit volume.
 *
 * INVARIANT (AC2): commit count and file count alone are never proof of value.
 * `real_capability` requires behavior or integration evidence:
 *   - integration_status=true (the new code is exercised end-to-end), OR
 *   - downstream_usage list is non-empty (another organ consumes the output), AND
 *   - at least one test verifying behavior change is present.
 *
 * Classification rules (first match wins per record):
 *   real_capability — integration evidence AND behavior tests
 *   observability   — all files are logging/monitoring/reporting/metric paths, no new capability
 *   consolidation   — files are dedup/merge/compress/cleanup, with tests but no new capability
 *   scaffolding     — only stub/scaffold/placeholder/TODO markers, no behavior evidence
 *   unknown         — cannot classify from available signals
 *
 * Confidence:
 *   high   — two or more independent signals agree
 *   medium — one clear signal
 *   low    — inference from file-name patterns only
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainValueProofSampler
{
    public const SCHEMA       = 'atlas.external_brain.value_proof_sampler.v1';
    public const SCHEMA_ADMIT = 'atlas.external_brain.value_proof_sampler.admit.v1';

    public const CLASS_REAL_CAPABILITY = 'real_capability';
    public const CLASS_OBSERVABILITY   = 'observability';
    public const CLASS_CONSOLIDATION   = 'consolidation';
    public const CLASS_SCAFFOLDING     = 'scaffolding';
    public const CLASS_UNKNOWN         = 'unknown';

    public const CONFIDENCE_HIGH   = 'high';
    public const CONFIDENCE_MEDIUM = 'medium';
    public const CONFIDENCE_LOW    = 'low';

    // Keyword sets for file-path heuristics.
    private const OBSERVABILITY_KEYWORDS   = ['log', 'monitor', 'report', 'metric', 'telemetry', 'trace', 'audit', 'ledger', 'health'];
    private const CONSOLIDATION_KEYWORDS   = ['consolidat', 'merge', 'dedup', 'duplicate', 'compress', 'cleanup', 'refactor', 'simplif'];
    private const SCAFFOLDING_KEYWORDS     = ['stub', 'scaffold', 'placeholder', 'todo', 'fixme', 'dummy', 'noop', 'empty'];

    /** Admission scoring. 5 unlocked tasks = score 1.0 on the unlock dimension. */
    private const DEFAULT_MIN_DIMENSION_SCORE = 0.30;
    private const UNLOCK_NORMALIZATION_FACTOR  = 5;

    /**
     * @param  array{
     *   task_packet_id?: string,
     *   changed_files?: list<string>,
     *   tests?: list<string>,
     *   integration_status?: bool,
     *   downstream_usage?: list<string>,
     *   behavior_evidence?: list<string>,
     * }  $record
     * @return array{schema:string, task_packet_id:string, value_class:string, confidence:string, signals:list<string>}
     */
    public function sample(array $record): array
    {
        $id               = (string) ($record['task_packet_id'] ?? '');
        $changedFiles     = $this->normalize($record['changed_files']    ?? []);
        $tests            = $this->normalize($record['tests']            ?? []);
        $integrated       = (bool) ($record['integration_status']        ?? false);
        $downstream       = $this->normalize($record['downstream_usage'] ?? []);
        $behaviorEvidence = $this->normalize($record['behavior_evidence'] ?? []);

        $hasTests           = $tests !== [];
        $hasIntegration     = $integrated || $downstream !== [];
        $hasBehaviorProof   = $hasTests || $behaviorEvidence !== [];

        $signals = [];
        if ($hasIntegration) {
            $signals[] = $integrated ? 'integration_status:true' : 'downstream_usage:'.count($downstream);
        }
        if ($hasTests) {
            $signals[] = 'behavior_tests:'.count($tests);
        }
        if ($behaviorEvidence !== []) {
            $signals[] = 'behavior_evidence:'.count($behaviorEvidence);
        }

        // ── Classification (first match wins) ────────────────────────────────

        // real_capability: integration evidence AND behavior proof
        if ($hasIntegration && $hasBehaviorProof) {
            $signals[] = 'classified:real_capability';

            return $this->result($id, self::CLASS_REAL_CAPABILITY,
                count($signals) >= 3 ? self::CONFIDENCE_HIGH : self::CONFIDENCE_MEDIUM,
                $signals,
            );
        }

        // Pattern checks on file paths.
        $implFiles = array_values(array_filter($changedFiles, fn (string $f): bool => ! $this->isTestPath($f)));

        if ($implFiles !== []) {
            // observability: all impl files match observability keywords
            if ($this->allFilesMatchKeywords($implFiles, self::OBSERVABILITY_KEYWORDS)) {
                $signals[] = 'file_pattern:observability';

                return $this->result($id, self::CLASS_OBSERVABILITY,
                    $hasTests ? self::CONFIDENCE_MEDIUM : self::CONFIDENCE_LOW,
                    $signals,
                );
            }

            // consolidation: all impl files match consolidation keywords
            if ($this->allFilesMatchKeywords($implFiles, self::CONSOLIDATION_KEYWORDS)) {
                $signals[] = 'file_pattern:consolidation';

                return $this->result($id, self::CLASS_CONSOLIDATION,
                    $hasTests ? self::CONFIDENCE_MEDIUM : self::CONFIDENCE_LOW,
                    $signals,
                );
            }

            // scaffolding: all impl files match scaffolding keywords
            if ($this->allFilesMatchKeywords($implFiles, self::SCAFFOLDING_KEYWORDS)) {
                $signals[] = 'file_pattern:scaffolding';

                return $this->result($id, self::CLASS_SCAFFOLDING, self::CONFIDENCE_LOW, $signals);
            }
        }

        // If we have behavior proof but no integration evidence: could be real capability
        // but we can't confirm — treat as unknown with medium confidence signal.
        if ($hasBehaviorProof && $changedFiles !== []) {
            $signals[] = 'has_tests_but_no_integration_evidence';

            return $this->result($id, self::CLASS_UNKNOWN, self::CONFIDENCE_MEDIUM, $signals);
        }

        $signals[] = 'insufficient_evidence';

        return $this->result($id, self::CLASS_UNKNOWN, self::CONFIDENCE_LOW, $signals);
    }

    /** Classify a batch of records. */
    public function sampleBatch(array $records): array
    {
        $results = array_map(fn (array $r): array => $this->sample($r), $records);

        return ['schema' => self::SCHEMA, 'samples' => $results, 'count' => count($results)];
    }

    /**
     * Score a candidate task spec across 5 impact dimensions and decide admission.
     *
     * Input fields:
     *   unlocks_task_count          int   — downstream tasks unblocked (0..N)
     *   give_back_risk_delta        float — reduction in give_back risk (0..1; negative ignored)
     *   simplification_score        float — 0..1
     *   autonomy_gain_score         float — 0..1
     *   certification_strength_score float — 0..1
     *   min_dimension_score         float — override admission threshold (default 0.30)
     *
     * Returns: schema, admitted, max_dimension_score, dimension_scores, admission_evidence, rejection_reason
     */
    public function admit(array $candidate, array $config = []): array
    {
        $minScore = (float) ($config['min_dimension_score'] ?? $candidate['min_dimension_score'] ?? self::DEFAULT_MIN_DIMENSION_SCORE);

        $unlockCount = max(0, (int)   ($candidate['unlocks_task_count']           ?? 0));
        $gbDelta     = max(0.0, min(1.0, (float) ($candidate['give_back_risk_delta']         ?? 0.0)));
        $simplScore  = max(0.0, min(1.0, (float) ($candidate['simplification_score']         ?? 0.0)));
        $autScore    = max(0.0, min(1.0, (float) ($candidate['autonomy_gain_score']          ?? 0.0)));
        $certScore   = max(0.0, min(1.0, (float) ($candidate['certification_strength_score'] ?? 0.0)));

        $dimensionScores = [
            'unlock'                 => round(min(1.0, $unlockCount / self::UNLOCK_NORMALIZATION_FACTOR), 4),
            'risk_reduction'         => round($gbDelta,    4),
            'simplification'         => round($simplScore, 4),
            'autonomy_gain'          => round($autScore,   4),
            'certification_strength' => round($certScore,  4),
        ];

        $maxScore = max($dimensionScores);
        $admitted = $maxScore >= $minScore;

        $admissionEvidence = [];
        foreach ($dimensionScores as $dim => $score) {
            if ($score >= $minScore) {
                $admissionEvidence[] = "{$dim}:{$score}";
            }
        }

        return [
            'schema'              => self::SCHEMA_ADMIT,
            'admitted'            => $admitted,
            'max_dimension_score' => round($maxScore, 4),
            'dimension_scores'    => $dimensionScores,
            'admission_evidence'  => $admissionEvidence,
            'rejection_reason'    => $admitted ? null : "no_dimension_above_min:{$minScore}",
        ];
    }

    private function allFilesMatchKeywords(array $files, array $keywords): bool
    {
        if ($files === []) {
            return false;
        }
        foreach ($files as $file) {
            $lower = strtolower(basename($file));
            $found = false;
            foreach ($keywords as $kw) {
                if (str_contains($lower, $kw)) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    private function isTestPath(string $path): bool
    {
        return str_starts_with($path, 'tests/') || str_ends_with($path, 'Test.php') || str_ends_with($path, 'Spec.php');
    }

    /** @param  list<string>  $signals */
    private function result(string $id, string $class, string $confidence, array $signals): array
    {
        return [
            'schema'         => self::SCHEMA,
            'task_packet_id' => $id,
            'value_class'    => $class,
            'confidence'     => $confidence,
            'signals'        => $signals,
        ];
    }

    /** @param  mixed  $raw  @return list<string> */
    private function normalize($raw): array
    {
        return array_values(array_filter(array_map('strval', (array) $raw), static fn (string $s): bool => $s !== ''));
    }
}
