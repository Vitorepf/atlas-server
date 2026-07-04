<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure decay monitor. Detects when queued tasks have lost value and emits
 * provider-safe recommendations — never cancels tasks directly.
 *
 * Input facts:
 *   tasks             — list of {id, queued_at_days_ago, prerequisites_changed,
 *                        landscape_shifted, has_value_proof, blocking_count?,
 *                        repeated_family_count?, impact_evidence?, autonomy_gain?,
 *                        fresh_unblock_evidence?}.
 *   max_age_days      — maximum queue age before age-decay triggers (default 30).
 *   stale_age_days    — age threshold where context-shift is worrying (default 14).
 *   min_blocking_keep — blocking_count >= this value forces 'keep' (default 3).
 *
 * AC2 — Recommendations (ONLY recommendations, never direct cancellation):
 *   Priority (first match wins):
 *   1. retire        — superseded_target or duplicate_family_saturation (hard signals).
 *   2. keep/refresh/consolidate — blocking_count >= min_blocking_keep (load-bearing).
 *   3. demote        — repeated_family_count >= threshold AND low impact_evidence AND low autonomy_gain.
 *   4. retire        — queued_at_days_ago > max_age_days AND !has_value_proof (unless fresh evidence).
 *   5. refresh/consolidate — stale_evidence + repeated_give_back.
 *   6. respec        — both context shifts + no proof BUT still valuable.
 *   7. retire        — both context shifts + no proof.
 *   8. respec        — changed scope but capability still valuable.
 *   9. respec        — blocked dependency.
 *   10. respec       — single context shift.
 *   11. demote       — stale backlog (age > stale_age AND low impact AND low autonomy).
 *   12. refresh_or_keep — stale evidence without other negative signals.
 *   13. keep         — fresh autonomy/unblock evidence (AC3: not demoted solely for age).
 *   14. keep         — default (stable, proven, uncontested).
 *
 * decay_signals per task: list of detected signals (age_decay, prerequisite_drift,
 *   landscape_drift, no_value_proof, repeated_low_impact_family, fresh_autonomy_evidence).
 *
 * AC4 outputs: recommendations (with evidence_refs), retire_candidates, respec_candidates,
 *   keep_tasks, monitor_summary, per_task (with value_decay), batch_decay_summary.
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
    private const DEMOTE_FAMILY_REPEAT_THRESHOLD = 3;
    private const STRONG_AUTONOMY_THRESHOLD  = 0.7;
    private const LOW_IMPACT_THRESHOLD       = 0.3;

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
        $actionCounts      = ['retain' => 0, 'refresh' => 0, 'consolidate' => 0, 'retire' => 0, 'demote' => 0, 'refresh_or_keep' => 0];

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
            $freshValueProof      = (bool)   ($raw['fresh_value_proof']            ?? false);
            // AC2: new input fields for value_decay computation.
            $repeatedFamilyCount  = max(0,   (int)   ($raw['repeated_family_count'] ?? 0));
            $impactEvidence       = isset($raw['impact_evidence']) ? max(0.0, min(1.0, (float) $raw['impact_evidence'])) : 0.5;
            $autonomyGain         = isset($raw['autonomy_gain']) ? max(0.0, min(1.0, (float) $raw['autonomy_gain'])) : 0.5;
            $freshUnblockEvidence = (bool)   ($raw['fresh_unblock_evidence']        ?? false);

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
            if ($repeatedFamilyCount >= self::DEMOTE_FAMILY_REPEAT_THRESHOLD && $impactEvidence < self::LOW_IMPACT_THRESHOLD) {
                $decaySignals[] = 'repeated_low_impact_family';
            }
            if ($freshUnblockEvidence || $autonomyGain >= self::STRONG_AUTONOMY_THRESHOLD) {
                $decaySignals[] = 'fresh_autonomy_evidence';
            }

            // AC2: compute value_decay from age, repeated_family_count, impact_evidence, autonomy_gain.
            $ageComponent      = min(1.0, $ageDays / max(1, $maxAge));
            $familyComponent   = min(1.0, $repeatedFamilyCount / max(1, self::DEMOTE_FAMILY_REPEAT_THRESHOLD));
            $impactComponent   = 1.0 - $impactEvidence;
            $autonomyComponent = 1.0 - $autonomyGain;
            $valueDecay        = round(min(1.0, $ageComponent * 0.3 + $familyComponent * 0.3 + $impactComponent * 0.2 + $autonomyComponent * 0.2), 2);

            // AC2: recommendation (never cancel, only recommend).
            [$rec, $reason] = $this->recommend(
                $ageDays, $maxAge, $prerequisitesChanged, $landscapeShifted,
                $hasValueProof, $blockingCount, $minBlockingKeep,
                $currentValueScore, $changedAllowedFiles, $blockedDependency,
                $supersededTarget, $duplicateFamilyCount, $staleEvidenceAge, $staleAge,
                $giveBackCount, $muscleSuccessRate, $freshValueProof,
                $repeatedFamilyCount, $impactEvidence, $autonomyGain, $freshUnblockEvidence,
            );

            $evidenceRefs = $this->evidenceRefsFor($reason, $decaySignals);

            $recommendations[] = [
                'task_id'        => $id,
                'recommendation' => $rec,
                'decay_signals'  => $decaySignals,
                'reason'         => $reason,
                'evidence_refs'  => $evidenceRefs,
            ];

            // 'respec', 'refresh', 'consolidate', 'demote', 'refresh_or_keep' are all "needs change" buckets.
            match ($rec) {
                'retire' => $retireCandidates[] = $id,
                'keep'   => $keepTasks[]        = $id,
                default  => $respecCandidates[] = $id,
            };

            $recommendedAction = match ($rec) {
                'retire'          => 'retire',
                'refresh'         => 'refresh',
                'consolidate'     => 'consolidate',
                'respec'          => 'refresh',
                'demote'          => 'demote',
                'refresh_or_keep' => 'refresh_or_keep',
                default           => 'retain',
            };
            $actionCounts[$recommendedAction]++;

            $valueStatus = match (true) {
                $rec === 'retire'           => 'expired',
                in_array($rec, ['respec', 'refresh', 'consolidate', 'demote'], true) => 'decaying',
                $rec === 'refresh_or_keep'  => 'stale',
                $decaySignals !== []        => 'stale',
                default                     => 'fresh',
            };

            $nextEvidenceNeeded = $this->nextEvidenceNeeded($rec, $reason);

            // AC1/AC2: refresh/consolidate/respec/demote/refresh_or_keep are never safe to act on until their evidence
            // gap is closed. retire is safe only for a non-load-bearing task with no fresh proof
            // and no blocking dependency — retiring a load-bearing or blocked task outright would
            // silently strip capability instead of repairing it.
            $safeToAct = match ($rec) {
                'retire' => $blockingCount < $minBlockingKeep && ! $freshValueProof && ! $blockedDependency,
                'keep' => true,
                default => false,
            };

            $valueRecoveryPath = match ($rec) {
                'refresh'         => 'refresh_evidence',
                'consolidate'     => 'consolidate_family',
                'respec'          => 'respec_scope',
                'retire'          => 'retire_cleanly',
                'demote'          => 'demote_to_lower_priority',
                'refresh_or_keep' => 'refresh_evidence_or_keep',
                default           => null,
            };

            $perTask[] = [
                'task_id'              => $id,
                'value_status'         => $valueStatus,
                'decay_score'          => round(min(1.0, count($decaySignals) * 0.15), 2),
                'value_decay'          => $valueDecay,
                'reasons'              => $decaySignals,
                'recommended_action'   => $recommendedAction,
                'next_evidence_needed' => $nextEvidenceNeeded,
                'safe_to_act'          => $safeToAct,
                'value_recovery_path'  => $valueRecoveryPath,
            ];
        }

        return [
            'schema_version'    => self::SCHEMA,
            'recommendations'   => $recommendations,
            'retire_candidates' => $retireCandidates,
            'respec_candidates' => $respecCandidates,
            'keep_tasks'        => $keepTasks,
            'monitor_summary'   => [
                'total'           => count($rawTasks),
                'retire'          => count($retireCandidates),
                'respec'          => count($respecCandidates),
                'keep'            => count($keepTasks),
                'demote'          => $actionCounts['demote'],
                'refresh_or_keep' => $actionCounts['refresh_or_keep'],
            ],
            'per_task'             => $perTask,
            'batch_decay_summary'  => array_merge(['total' => count($rawTasks)], $actionCounts),
        ];
    }

    /**
     * Certain decisions (retire, keep) need no further evidence — the decay
     * signals already justify them. Uncertain "needs rethink" decisions
     * (respec/refresh/consolidate/demote/refresh_or_keep) name the exact evidence that would resolve
     * the uncertainty, so the next task authored against this recommendation
     * is targeted instead of another blind respec.
     */
    private function nextEvidenceNeeded(string $recommendation, string $reason): ?string
    {
        return match ($recommendation) {
            'retire', 'keep' => null,
            'demote' => 'fresh_impact_evidence_or_autonomy_gain',
            'refresh_or_keep' => 'refreshed_evidence_ref',
            'consolidate' => $reason === 'high_blocking_count_stale_proof_low_muscle_success'
                ? 'fresh_value_proof_for_load_bearing_task_after_family_consolidation'
                : 'muscle_success_rate_after_family_consolidation',
            'refresh' => $reason === 'high_blocking_count_stale_or_missing_value_proof'
                ? 'fresh_value_proof_for_load_bearing_task'
                : 'refreshed_evidence_ref_after_stale_evidence_repair',
            'respec' => match ($reason) {
                'valuable_capability_scope_drifted_respec_preferred' => 'updated_current_value_score_and_scope_after_respec',
                'scope_changed_capability_still_valuable' => 'confirmed_allowed_files_and_value_score_after_scope_change',
                'blocked_dependency_requires_rethink' => 'blocking_dependency_resolution_status',
                default => 'updated_prerequisite_and_landscape_state',
            },
            default => null,
        };
    }

    /**
     * Returns the evidence field references that informed a specific recommendation,
     * so downstream consumers can trace WHY a demote/keep/refresh_or_keep was emitted.
     *
     * @param  list<string>  $decaySignals
     * @return list<string>
     */
    private function evidenceRefsFor(string $reason, array $decaySignals): array
    {
        return match ($reason) {
            'repeated_low_impact_family_demotion' => ['repeated_family_count', 'impact_evidence', 'autonomy_gain'],
            'stale_backlog_demotion'              => ['age_decay', 'impact_evidence', 'autonomy_gain'],
            'fresh_autonomy_or_unblock_evidence'  => ['fresh_unblock_evidence', 'autonomy_gain'],
            'stale_evidence_refresh_or_keep'      => ['stale_evidence'],
            default                                => $decaySignals,
        };
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
        bool  $freshValueProof = false,
        int   $repeatedFamilyCount = 0,
        float $impactEvidence = 0.5,
        float $autonomyGain = 0.5,
        bool  $freshUnblockEvidence = false,
    ): array {
        // Priority 1: superseded target or duplicate-family saturation — retire ahead of every
        // other check, including load-bearing keep; isolated per task, never affects unrelated
        // fresh/high-value tasks.
        if ($supersededTarget) {
            return ['retire', 'superseded_target'];
        }
        if ($duplicateFamilyCount >= self::DUPLICATE_FAMILY_THRESHOLD) {
            if ($muscleSuccessRate !== null && $muscleSuccessRate >= self::LOW_SUCCESS_THRESHOLD) {
                return ['consolidate', 'duplicate_family_saturation_family_still_succeeding'];
            }

            return ['retire', 'duplicate_family_saturation'];
        }

        // Priority 1.5: load-bearing tasks are kept ONLY with fresh value proof. High
        // blocking_count on stale/missing proof is not a free pass — it must be refreshed
        // (or consolidated when the underlying family is also underperforming).
        if ($blockingCount >= $minBlockingKeep) {
            if ($freshValueProof) {
                return ['keep', 'high_blocking_count_load_bearing_fresh_proof'];
            }
            if ($muscleSuccessRate !== null && $muscleSuccessRate < self::LOW_SUCCESS_THRESHOLD) {
                return ['consolidate', 'high_blocking_count_stale_proof_low_muscle_success'];
            }

            return ['refresh', 'high_blocking_count_stale_or_missing_value_proof'];
        }

        // AC2/AC4: Repeated low-impact family without strong evidence → demote.
        if ($repeatedFamilyCount >= self::DEMOTE_FAMILY_REPEAT_THRESHOLD
            && $impactEvidence < self::LOW_IMPACT_THRESHOLD
            && $autonomyGain < self::STRONG_AUTONOMY_THRESHOLD) {
            return ['demote', 'repeated_low_impact_family_demotion'];
        }

        // AC3: Fresh autonomy/unblock evidence prevents age-only retire.
        $hasFreshEvidence = $freshUnblockEvidence || $autonomyGain >= self::STRONG_AUTONOMY_THRESHOLD;

        // Priority 2: age-decayed with no value proof (unless fresh evidence overrides).
        if ($ageDays > $maxAge && ! $hasValueProof && ! $hasFreshEvidence) {
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

        // AC2/AC4: Stale backlog without autonomy/impact evidence → demote (unless fresh unblock evidence).
        if ($ageDays > $staleAge && $impactEvidence < self::LOW_IMPACT_THRESHOLD
            && $autonomyGain < self::STRONG_AUTONOMY_THRESHOLD && ! $freshUnblockEvidence) {
            return ['demote', 'stale_backlog_demotion'];
        }

        // AC4: Stale evidence without other negative signals → refresh_or_keep.
        if ($staleEvidenceAge > $staleAge) {
            return ['refresh_or_keep', 'stale_evidence_refresh_or_keep'];
        }

        // AC3: Fresh autonomy/unblock evidence — if we reached here without matching any
        // age-based or context-shift rule, the task is kept because fresh evidence shows
        // it still advances autonomy or unblocks downstream work.
        if ($hasFreshEvidence) {
            return ['keep', 'fresh_autonomy_or_unblock_evidence'];
        }

        // Priority 5: default — keep as-is.
        return ['keep', 'no_decay_signals'];
    }
}
