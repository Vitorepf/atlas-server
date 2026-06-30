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

            if ($hasProviderTrace) {
                $promotionBlockers[] = ['failure_id' => $id, 'blocker' => 'contains_provider_trace'];
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
                'trigger_shape'      => $trigger,
                'expected_rejection' => $expectedRejection,
                'heldout_reason'     => $heldoutReason !== ''
                    ? $heldoutReason
                    : ($isRepeated ? 'repeated_failure' : 'severe_singleton'),
            ];
        }

        $uniqueTypes = array_values(array_unique(array_column($promotedCases, 'failure_type')));

        return [
            'schema_version'       => self::SCHEMA,
            'promoted_cases'       => $promotedCases,
            'rejected_candidates'  => $rejectedCandidates,
            'heldout_suite_updates' => [
                'promoted_count'       => count($promotedCases),
                'rejected_count'       => count($rejectedCandidates),
                'unique_failure_types' => $uniqueTypes,
            ],
            'promotion_blockers'   => $promotionBlockers,
        ];
    }
}
