<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure decay monitor. Detects when queued tasks have lost value and emits
 * provider-safe recommendations — never cancels tasks directly.
 *
 * Input facts:
 *   tasks             — list of {id, queued_at_days_ago, prerequisites_changed,
 *                        landscape_shifted, has_value_proof, blocking_count?}.
 *   max_age_days      — maximum queue age before age-decay triggers (default 30).
 *   stale_age_days    — age threshold where context-shift is worrying (default 14).
 *   min_blocking_keep — blocking_count >= this value forces 'keep' (default 3).
 *
 * AC2 — Recommendations (ONLY recommendations, never direct cancellation):
 *   Priority (first match wins):
 *   1. keep          — blocking_count >= min_blocking_keep (task is load-bearing).
 *   2. retire        — queued_at_days_ago > max_age_days AND !has_value_proof.
 *   3. retire        — prerequisites_changed AND landscape_shifted AND !has_value_proof.
 *   4. respec        — prerequisites_changed OR landscape_shifted.
 *   5. keep          — default (stable, proven, uncontested).
 *
 * decay_signals per task: list of detected signals (age_decay, prerequisite_drift,
 *   landscape_drift, no_value_proof).
 *
 * AC4 outputs: recommendations, retire_candidates, respec_candidates, keep_tasks,
 *   monitor_summary.
 *
 * Pure, deterministic, no providers, no I/O.
 */
final class AtlasExternalBrainValueDecayMonitor
{
    public const SCHEMA = 'atlas.external_brain.value_decay_monitor.v1';

    private const DEFAULT_MAX_AGE            = 30;
    private const DEFAULT_STALE_AGE          = 14;
    private const DEFAULT_MIN_BLOCKING_KEEP  = 3;
    private const VALUE_WORTHY_THRESHOLD     = 0.6;
    private const PREREQ_DRIFT_HIGH          = 0.5;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function monitor(array $facts): array
    {
        $rawTasks        = is_array($facts['tasks'] ?? null) ? $facts['tasks'] : [];
        $maxAge          = max(1, (int) ($facts['max_age_days']       ?? self::DEFAULT_MAX_AGE));
        $staleAge        = max(1, (int) ($facts['stale_age_days']     ?? self::DEFAULT_STALE_AGE));
        $minBlockingKeep = max(1, (int) ($facts['min_blocking_keep']  ?? self::DEFAULT_MIN_BLOCKING_KEEP));

        $recommendations   = [];
        $retireCandidates  = [];
        $respecCandidates  = [];
        $keepTasks         = [];

        foreach ($rawTasks as $raw) {
            $id                   = (string) ($raw['id']                       ?? '');
            $ageDays              = max(0,   (int)   ($raw['queued_at_days_ago']    ?? 0));
            $prerequisitesChanged = (bool)   ($raw['prerequisites_changed']         ?? false);
            $landscapeShifted     = (bool)   ($raw['landscape_shifted']             ?? false);
            $hasValueProof        = (bool)   ($raw['has_value_proof']               ?? false);
            $blockingCount        = max(0,   (int)   ($raw['blocking_count']        ?? 0));
            $staleEvidenceAge     = max(0,   (int)   ($raw['stale_evidence_age']    ?? 0));
            $changedAllowedFiles  = (bool)   ($raw['changed_allowed_files']         ?? false);
            $prerequisiteDrift    = max(0.0, min(1.0, (float) ($raw['prerequisite_drift'] ?? 0.0)));
            $blockedDependency    = (bool)   ($raw['blocked_dependency']            ?? false);
            $currentValueScore    = max(0.0, min(1.0, (float) ($raw['current_value_score'] ?? 0.0)));

            // Collect active decay signals.
            $decaySignals = [];
            if ($ageDays > $staleAge) {
                $decaySignals[] = 'age_decay';
            }
            if ($prerequisitesChanged) {
                $decaySignals[] = 'prerequisite_drift';
            }
            if ($landscapeShifted) {
                $decaySignals[] = 'landscape_drift';
            }
            if (! $hasValueProof) {
                $decaySignals[] = 'no_value_proof';
            }
            if ($staleEvidenceAge > $staleAge) {
                $decaySignals[] = 'stale_evidence';
            }
            if ($changedAllowedFiles) {
                $decaySignals[] = 'changed_scope';
            }
            if ($blockedDependency) {
                $decaySignals[] = 'blocked_dependency';
            }
            if ($prerequisiteDrift > self::PREREQ_DRIFT_HIGH) {
                $decaySignals[] = 'prerequisite_drift_high';
            }

            // AC2: recommendation (never cancel, only recommend).
            [$rec, $reason] = $this->recommend(
                $ageDays, $maxAge, $prerequisitesChanged, $landscapeShifted,
                $hasValueProof, $blockingCount, $minBlockingKeep,
                $currentValueScore, $changedAllowedFiles, $blockedDependency,
            );

            $recommendations[] = [
                'task_id'      => $id,
                'recommendation' => $rec,
                'decay_signals' => $decaySignals,
                'reason'       => $reason,
            ];

            match ($rec) {
                'retire' => $retireCandidates[] = $id,
                'respec' => $respecCandidates[] = $id,
                default  => $keepTasks[]        = $id,
            };
        }

        return [
            'schema_version'    => self::SCHEMA,
            'recommendations'   => $recommendations,
            'retire_candidates' => $retireCandidates,
            'respec_candidates' => $respecCandidates,
            'keep_tasks'        => $keepTasks,
            'monitor_summary'   => [
                'total'  => count($rawTasks),
                'retire' => count($retireCandidates),
                'respec' => count($respecCandidates),
                'keep'   => count($keepTasks),
            ],
        ];
    }

    private function recommend(
        int   $ageDays, int $maxAge,
        bool  $prereqChanged, bool $landscapeShifted,
        bool  $hasValueProof, int $blockingCount, int $minBlockingKeep,
        float $currentValueScore = 0.0,
        bool  $changedAllowedFiles = false,
        bool  $blockedDependency = false,
    ): array {
        // Priority 1: load-bearing tasks always kept.
        if ($blockingCount >= $minBlockingKeep) {
            return ['keep', 'high_blocking_count_load_bearing'];
        }

        // Priority 2: age-decayed with no value proof.
        if ($ageDays > $maxAge && ! $hasValueProof) {
            return ['retire', 'age_decay_no_value_proof'];
        }

        // Priority 3a (AC2): both context signals + no proof BUT still valuable → respec, not retire.
        if ($prereqChanged && $landscapeShifted && ! $hasValueProof && $currentValueScore >= self::VALUE_WORTHY_THRESHOLD) {
            return ['respec', 'valuable_capability_scope_drifted_respec_preferred'];
        }

        // Priority 3b: fully obsolete (both context signals + no proof).
        if ($prereqChanged && $landscapeShifted && ! $hasValueProof) {
            return ['retire', 'prerequisites_and_landscape_both_shifted_no_proof'];
        }

        // Priority 3.5: changed scope but capability still valuable → respec.
        if ($changedAllowedFiles && $currentValueScore >= self::VALUE_WORTHY_THRESHOLD) {
            return ['respec', 'scope_changed_capability_still_valuable'];
        }

        // Priority 3.6: blocked dependency → respec to unblock.
        if ($blockedDependency) {
            return ['respec', 'blocked_dependency_requires_rethink'];
        }

        // Priority 4: context shifted — task needs rethinking.
        if ($prereqChanged || $landscapeShifted) {
            return ['respec', 'context_shift_requires_rethink'];
        }

        // Priority 5: default — keep as-is.
        return ['keep', 'no_decay_signals'];
    }
}
