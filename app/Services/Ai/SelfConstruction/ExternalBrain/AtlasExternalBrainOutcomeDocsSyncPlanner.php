<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Decides whether a finished implementation outcome should propose a documentation and/or
 * memory sync action — ONLY when the outcome actually changes operator understanding or future
 * task quality. Avoids the common failure mode of every green commit triggering doc churn.
 *
 * docs_sync_needed = true when ANY of:
 *   - is_architecture_decision = true
 *   - is_new_operating_policy = true
 *   - is_repeated_failure_learning = true
 *   - operator_facing_significance in [medium, high] (and the commit is not flagged trivial)
 *   - future_task_impact in [medium, high] AND capability_delta in [medium, high]
 *
 * is_trivial_commit = true ALWAYS suppresses doc sync UNLESS one of the three hard triggers
 * (architecture decision / new policy / repeated failure learning) is present — a trivial
 * commit can still teach a repeated-failure lesson worth recording.
 *
 * memory_sync_needed = true only when memory_relevant = true AND the outcome is one of the
 * three hard triggers OR operator_facing_significance = high — memory is more expensive to
 * pollute than docs, so its bar is stricter.
 *
 * INPUT:
 *   outcome: {
 *     task_id?:                       string
 *     capability_delta?:              'none'|'low'|'medium'|'high' (default 'none')
 *     docs_touched?:                  list<string> (default [])
 *     memory_relevant?:               bool (default false)
 *     operator_facing_significance?:  'trivial'|'low'|'medium'|'high' (default 'trivial')
 *     future_task_impact?:            'none'|'low'|'medium'|'high' (default 'none')
 *     is_architecture_decision?:      bool (default false)
 *     is_new_operating_policy?:       bool (default false)
 *     is_repeated_failure_learning?:  bool (default false)
 *     is_trivial_commit?:             bool (default false)
 *   }
 *
 * OUTPUT:
 *   { schema, docs_sync_needed, memory_sync_needed, recommended_docs, sync_reason, do_not_sync_reason }
 *
 * Pure: no I/O, never writes docs or memory — only proposes whether to.
 */
final class AtlasExternalBrainOutcomeDocsSyncPlanner
{
    public const SCHEMA = 'atlas.external_brain.outcome_docs_sync_planner.v1';

    private const SIGNIFICANT_LEVELS = ['medium', 'high'];

    /** How many recent trivial commits are tolerated before the noise budget is exhausted. */
    private const TRIVIAL_NOISE_BUDGET = 3;

    public function plan(array $facts): array
    {
        $outcome = is_array($facts['outcome'] ?? null) ? $facts['outcome'] : [];

        $taskId = (string) ($outcome['task_id'] ?? '');
        $capabilityDelta = (string) ($outcome['capability_delta'] ?? 'none');
        $docsTouched = $this->stringList($outcome['docs_touched'] ?? []);
        $memoryRelevant = (bool) ($outcome['memory_relevant'] ?? false);
        $operatorFacingSignificance = (string) ($outcome['operator_facing_significance'] ?? 'trivial');
        $futureTaskImpact = (string) ($outcome['future_task_impact'] ?? 'none');
        $isArchitectureDecision = (bool) ($outcome['is_architecture_decision'] ?? false);
        $isNewOperatingPolicy = (bool) ($outcome['is_new_operating_policy'] ?? false);
        $isRepeatedFailureLearning = (bool) ($outcome['is_repeated_failure_learning'] ?? false);
        $isTrivialCommit = (bool) ($outcome['is_trivial_commit'] ?? false);
        $recentTrivialCommitCount = max(0, (int) ($outcome['recent_trivial_commit_count'] ?? 0));
        $domainMapChanged = (bool) ($outcome['domain_map_changed'] ?? false);
        $maturityDelta = (bool) ($outcome['maturity_delta'] ?? false);
        $ownerChanged = (bool) ($outcome['owner_changed'] ?? false);
        $nextLeverageChanged = (bool) ($outcome['next_leverage_changed'] ?? false);

        $domainMapMaturityTrigger = $domainMapChanged && $maturityDelta;
        $hardTrigger = $isArchitectureDecision || $isNewOperatingPolicy || $isRepeatedFailureLearning || $domainMapMaturityTrigger;

        // Noise budget: repeated trivial commits must never churn docs, regardless of budget —
        // this field only makes the "no matter how many" contract explicit and auditable.
        $noiseBudgetExhausted = $recentTrivialCommitCount >= self::TRIVIAL_NOISE_BUDGET;
        $noiseBudget = [
            'budget' => self::TRIVIAL_NOISE_BUDGET,
            'recent_trivial_commit_count' => $recentTrivialCommitCount,
            'remaining' => max(0, self::TRIVIAL_NOISE_BUDGET - $recentTrivialCommitCount),
            'exhausted' => $noiseBudgetExhausted,
            'bypassed_by_hard_trigger' => $hardTrigger,
        ];

        $syncReasons = [];
        if ($isArchitectureDecision) {
            $syncReasons[] = 'architecture_decision';
        }
        if ($isNewOperatingPolicy) {
            $syncReasons[] = 'new_operating_policy';
        }
        if ($isRepeatedFailureLearning) {
            $syncReasons[] = 'repeated_failure_learning';
        }
        if ($domainMapMaturityTrigger) {
            $syncReasons[] = 'domain_map_maturity_changed';
        }
        if (! $isTrivialCommit && in_array($operatorFacingSignificance, self::SIGNIFICANT_LEVELS, true)) {
            $syncReasons[] = "operator_facing_significance_{$operatorFacingSignificance}";
        }
        if (in_array($futureTaskImpact, self::SIGNIFICANT_LEVELS, true) && in_array($capabilityDelta, self::SIGNIFICANT_LEVELS, true)) {
            $syncReasons[] = "future_task_impact_{$futureTaskImpact}_with_capability_delta_{$capabilityDelta}";
        }

        // A trivial commit suppresses everything except the three hard triggers — no amount of
        // "medium" operator significance on a trivial commit should cause doc churn.
        $docsSyncNeeded = $hardTrigger || ($syncReasons !== [] && ! $isTrivialCommit);
        if ($isTrivialCommit && ! $hardTrigger) {
            $docsSyncNeeded = false;
        }

        $ownerOrLeverageChanged = $ownerChanged || $nextLeverageChanged;
        $memorySyncNeeded = $memoryRelevant && ($hardTrigger || $operatorFacingSignificance === 'high' || $ownerOrLeverageChanged);
        if ($memorySyncNeeded) {
            $syncReasons[] = 'memory_relevant_'.match (true) {
                $hardTrigger => 'hard_trigger',
                $ownerOrLeverageChanged => 'owner_or_next_leverage_changed',
                default => 'high_operator_significance',
            };
        }
        $syncReasons = array_values(array_unique($syncReasons));

        $doNotSyncReason = [];
        if (! $docsSyncNeeded && ! $memorySyncNeeded) {
            if ($isTrivialCommit) {
                $doNotSyncReason[] = 'trivial_commit_no_architecture_policy_or_repeated_failure_signal';
            }
            if ($isTrivialCommit && $noiseBudgetExhausted) {
                $doNotSyncReason[] = 'trivial_commit_noise_budget_exhausted';
            }
            if (! in_array($operatorFacingSignificance, self::SIGNIFICANT_LEVELS, true)) {
                $doNotSyncReason[] = 'operator_facing_significance_too_low';
            }
            if (! in_array($futureTaskImpact, self::SIGNIFICANT_LEVELS, true) || ! in_array($capabilityDelta, self::SIGNIFICANT_LEVELS, true)) {
                $doNotSyncReason[] = 'future_task_impact_or_capability_delta_too_low';
            }
            if ($doNotSyncReason === []) {
                $doNotSyncReason[] = 'no_qualifying_sync_signal_present';
            }
        }

        $recommendedDocs = $docsSyncNeeded
            ? ($docsTouched !== [] ? array_values(array_unique($docsTouched)) : ['docs/engineering-knowledge-base'])
            : [];

        return [
            'schema' => self::SCHEMA,
            'task_id' => $taskId,
            'docs_sync_needed' => $docsSyncNeeded,
            'memory_sync_needed' => $memorySyncNeeded,
            'recommended_docs' => $recommendedDocs,
            'sync_reason' => $syncReasons,
            'do_not_sync_reason' => $doNotSyncReason,
            'noise_budget' => $noiseBudget,
            'mutates_docs_or_memory' => false,
        ];
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $value), static fn (string $s): bool => $s !== ''));
    }
}
