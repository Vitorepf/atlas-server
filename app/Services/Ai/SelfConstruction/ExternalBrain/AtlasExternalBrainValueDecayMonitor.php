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
 *   monitor_summary, per_task, batch_decay_summary.
 *
 * Muscle-outcome decay signals (NEW):
 *   - superseded_target / duplicate_family_saturation → retire (highest priority after load-bearing).
 *   - stale_evidence + repeated_give_back → refresh (or consolidate when muscle_success_rate is also low),
 *     never retain.
 *   per_task entries surface value_status (fresh|stale|decaying|expired), decay_score [0..1],
 *   reasons (the decay_signals), and recommended_action (retain|refresh|consolidate|retire).
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
    private const GIVE_BACK_THRESHOLD        = 3;
    private const DUPLICATE_FAMILY_THRESHOLD = 5;
    private const LOW_SUCCESS_THRESHOLD      = 0.3;

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
        $perTask           = [];
        $actionCounts      = ['retain' => 0, 'refresh' => 0, 'consolidate' => 0, 'retire' => 0];

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
            $supersededTarget     = (bool)   ($raw['superseded_target']             ?? false);
            $duplicateFamilyCount = max(0,   (int)   ($raw['duplicate_family_count'] ?? 0));
            $giveBackCount        = max(0,   (int)   ($raw['give_back_count']       ?? 0));
            $muscleSuccessRate    = isset($raw['muscle_success_rate']) ? max(0.0, min(1.0, (float) $raw['muscle_success_rate'])) : null;

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
            if ($supersededTarget) {
                $decaySignals[] = 'superseded_target';
            }
            if ($duplicateFamilyCount >= self::DUPLICATE_FAMILY_THRESHOLD) {
                $decaySignals[] = 'duplicate_family_saturation';
            }
            if ($giveBackCount >= self::GIVE_BACK_THRESHOLD) {
                $decaySignals[] = 'repeated_give_back';
            }
            if ($muscleSuccessRate !== null && $muscleSuccessRate < self::LOW_SUCCESS_THRESHOLD) {
                $decaySignals[] = 'low_muscle_success';
            }

            // AC2: recommendation (never cancel, only recommend).
            [$rec, $reason] = $this->recommend(
                $ageDays, $maxAge, $prerequisitesChanged, $landscapeShifted,
                $hasValueProof, $blockingCount, $minBlockingKeep,
                $currentValueScore, $changedAllowedFiles, $blockedDependency,
                $supersededTarget, $duplicateFamilyCount, $staleEvidenceAge, $staleAge,
                $giveBackCount, $muscleSuccessRate,
            );

            $recommendations[] = [
                'task_id'      => $id,
                'recommendation' => $rec,
                'decay_signals' => $decaySignals,
                'reason'       => $reason,
            ];

            // 'respec', 'refresh' and 'consolidate' are all "needs change" buckets.
            match ($rec) {
                'retire' => $retireCandidates[] = $id,
                'keep'   => $keepTasks[]        = $id,
                default  => $respecCandidates[] = $id,
            };

            $recommendedAction = match ($rec) {
                'retire'      => 'retire',
                'refresh'     => 'refresh',
                'consolidate' => 'consolidate',
                'respec'      => 'refresh',
                default       => 'retain',
            };
            $actionCounts[$recommendedAction]++;

            $valueStatus = match (true) {
                $rec === 'retire'           => 'expired',
                in_array($rec, ['respec', 'refresh', 'consolidate'], true) => 'decaying',
                $decaySignals !== []        => 'stale',
                default                     => 'fresh',
            };

            $perTask[] = [
                'task_id'            => $id,
                'value_status'       => $valueStatus,
                'decay_score'        => round(min(1.0, count($decaySignals) * 0.15), 2),
                'reasons'            => $decaySignals,
                'recommended_action' => $recommendedAction,
            ];
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
            'per_task'             => $perTask,
            'batch_decay_summary'  => array_merge(['total' => count($rawTasks)], $actionCounts),
        ];
    }

    private function recommend(
        int   $ageDays, int $maxAge,
        bool  $prereqChanged, bool $landscapeShifted,
        bool  $hasValueProof, int $blockingCount, int $minBlockingKeep,
        float $currentValueScore = 0.0,
        bool  $changedAllowedFiles = false,
        bool  $blockedDependency = false,
        bool  $supersededTarget = false,
        int   $duplicateFamilyCount = 0,
        int   $staleEvidenceAge = 0,
        int   $staleAge = self::DEFAULT_STALE_AGE,
        int   $giveBackCount = 0,
        ?float $muscleSuccessRate = null,
    ): array {
        // Priority 1: load-bearing tasks always kept.
        if ($blockingCount >= $minBlockingKeep) {
            return ['keep', 'high_blocking_count_load_bearing'];
        }

        // Priority 1.5: superseded target or duplicate-family saturation — retire,
        // isolated per task and never affects unrelated fresh/high-value tasks.
        if ($supersededTarget) {
            return ['retire', 'superseded_target'];
        }
        if ($duplicateFamilyCount >= self::DUPLICATE_FAMILY_THRESHOLD) {
            return ['retire', 'duplicate_family_saturation'];
        }

        // Priority 2: age-decayed with no value proof.
        if ($ageDays > $maxAge && ! $hasValueProof) {
            return ['retire', 'age_decay_no_value_proof'];
        }

        // Priority 2.5: stale evidence + repeated give_back — needs refresh or
        // consolidate, never a bare retain. Consolidate when muscle success is
        // also low (the family itself is underperforming); refresh otherwise.
        if ($staleEvidenceAge > $staleAge && $giveBackCount >= self::GIVE_BACK_THRESHOLD) {
            if ($muscleSuccessRate !== null && $muscleSuccessRate < self::LOW_SUCCESS_THRESHOLD) {
                return ['consolidate', 'stale_evidence_repeated_give_back_low_muscle_success'];
            }

            return ['refresh', 'stale_evidence_repeated_give_back'];
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
