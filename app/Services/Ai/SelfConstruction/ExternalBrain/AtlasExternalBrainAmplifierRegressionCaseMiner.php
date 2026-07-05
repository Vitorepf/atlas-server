<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure miner: converts failed amplified proposals into held-out regression cases.
 *
 * Promotion criteria (OR):
 *   - repeated  : same (failure_type × trigger_shape) appears >= REPEAT_THRESHOLD times
 *   - severe    : severity in SEVERE_LEVELS ('critical', 'high'), regardless of count
 *
 * Blocking (before promotion check, first match wins):
 *   - has_provider_trace = true  → promotion_blockers (never enters promoted or rejected)
 *
 * Below-threshold: not repeated AND not severe → rejected_candidates.
 *
 * Dedup: only the first occurrence of each (failure_type × trigger_shape) key
 *   is added to promoted_cases; subsequent occurrences are silently dropped.
 *
 * Supported failure types: give_back, poison, false_green, overfit,
 *   duplicate_target, template_farm, low_compounding, provider_dependency.
 *
 * Output: promoted_cases, rejected_candidates, heldout_suite_updates,
 *   promotion_blockers.
 *
 * Each promoted case includes: failure_id, failure_type, trigger_shape,
 *   expected_rejection, heldout_reason.
 *
 * Pure: no I/O, no side effects, deterministic.
 */
final class AtlasExternalBrainAmplifierRegressionCaseMiner
{
    public const SCHEMA = 'atlas.external_brain.amplifier_regression_case_miner.v1';

    public const SUPPORTED_FAILURE_TYPES = [
        'give_back', 'poison', 'false_green', 'overfit',
        'duplicate_target', 'template_farm', 'low_compounding', 'provider_dependency',
        'proxy_win', 'no_delta', 'negative_lift',
    ];

    private const REPEAT_THRESHOLD = 2;
    private const SEVERE_LEVELS    = ['critical', 'high'];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function mine(array $input): array
    {
        $failures = is_array($input['failure_records'] ?? null) ? $input['failure_records'] : [];

        // Pass 1 — count occurrences per (failure_type × trigger_shape).
        $keyCounts = [];
        foreach ($failures as $f) {
            $key = (string) ($f['failure_type'] ?? '') . '::' . (string) ($f['trigger_shape'] ?? '');
            $keyCounts[$key] = ($keyCounts[$key] ?? 0) + 1;
        }

        $promotedCases     = [];
        $rejectedCandidates = [];
        $promotionBlockers = [];
        $promotedKeys      = [];

        // Pass 2 — classify each failure.
        foreach ($failures as $f) {
            $id               = (string) ($f['failure_id']          ?? 'unknown');
            $type             = (string) ($f['failure_type']        ?? '');
            $trigger          = (string) ($f['trigger_shape']       ?? '');
            $expectedRejection = (string) ($f['expected_rejection'] ?? '');
            $heldoutReason    = trim((string) ($f['heldout_reason'] ?? ''));
            $severity         = strtolower(trim((string) ($f['severity'] ?? 'low')));
            $hasProviderTrace = (bool) ($f['has_provider_trace']    ?? false);
            $taskFamily       = (string) ($f['task_family']         ?? '');
            $isReproducible   = (bool) ($f['is_reproducible']       ?? false);
            $sampleEvidence   = (string) ($f['sample_evidence']     ?? '');

            if ($hasProviderTrace) {
                $promotionBlockers[] = ['failure_id' => $id, 'blocker' => 'contains_provider_trace'];
                continue;
            }

            // Enforce the supported-failure-types whitelist.
            if ($type === '' || ! in_array($type, self::SUPPORTED_FAILURE_TYPES, true)) {
                $rejectedCandidates[] = ['failure_id' => $id, 'rejection_reason' => 'unsupported_failure_type'];
                continue;
            }

            $key        = $type . '::' . $trigger;
            $isRepeated = ($keyCounts[$key] ?? 0) >= self::REPEAT_THRESHOLD;
            $isSevere   = in_array($severity, self::SEVERE_LEVELS, true);

            if (! $isRepeated && ! $isSevere) {
                $rejectedCandidates[] = ['failure_id' => $id, 'rejection_reason' => 'below_threshold'];
                continue;
            }

            // Dedup: only first occurrence of each key is promoted.
            if (isset($promotedKeys[$key])) {
                continue;
            }
            $promotedKeys[$key] = true;

            $promotedCases[] = [
                'failure_id'         => $id,
                'failure_type'       => $type,
                'failure_mode'       => $type,
                'trigger_shape'      => $trigger,
                'task_family'        => $taskFamily,
                'expected_rejection' => $expectedRejection,
                'expected_guard'     => $expectedRejection,
                'heldout_reason'     => $heldoutReason !== ''
                    ? $heldoutReason
                    : ($isRepeated ? 'repeated_failure' : 'severe_singleton'),
                'sample_evidence'    => $sampleEvidence,
                'is_reproducible'    => $isReproducible,
                'required_replay'    => true,
            ];
        }

        $uniqueTypes = array_values(array_unique(array_column($promotedCases, 'failure_type')));

        // AC3: only reproducible promoted cases are concrete enough to become benchmark_case
        // candidates — a non-reproducible failure cannot be turned into a runnable test.
        $benchmarkCases = array_values(array_filter(
            $promotedCases,
            static fn (array $c): bool => $c['is_reproducible'] && $c['trigger_shape'] !== '',
        ));

        return [
            'schema_version'       => self::SCHEMA,
            'promoted_cases'       => $promotedCases,
            'rejected_candidates'  => $rejectedCandidates,
            'benchmark_case_candidates' => $benchmarkCases,
            'heldout_suite_updates' => [
                'promoted_count'       => count($promotedCases),
                'rejected_count'       => count($rejectedCandidates),
                'unique_failure_types' => $uniqueTypes,
                'benchmark_case_count' => count($benchmarkCases),
            ],
            'promotion_blockers'   => $promotionBlockers,
        ];
    }
}
