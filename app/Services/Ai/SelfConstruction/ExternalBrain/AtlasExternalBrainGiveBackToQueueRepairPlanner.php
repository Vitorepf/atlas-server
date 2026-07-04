<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure planner. Turns repeated give_back events into a concrete queue
 * REPAIR candidate instead of dead backlog or repeated worker waste.
 *
 * repair_plan selection (first match wins, evaluated in this priority
 * order because a forbidden target or contradictory acceptance must be
 * fixed before anything else is even attempted):
 *   forbidden_target          → operator_only_fix   (pétreo/forbidden target — never auto-repaired)
 *   acceptance_contradiction  → rewrite_acceptance
 *   duplicate_capability      → cancel_duplicate
 *   missing_files non-empty   → add_allowed_file
 *   root_cause mentions "poison" → quarantine_poison
 *   root_cause mentions "scope" or "multi_file" or "too_broad" → split_task
 *   default                   → operator_only_fix   (refuse to guess; never "just retry")
 *
 * "retry" is intentionally NEVER a valid repair_plan value — a structural
 * blocker (missing file, contradiction, duplicate, poison, forbidden
 * target) cannot be fixed by retrying the same packet unchanged.
 *
 * safety_score per plan (higher = safer to auto-apply):
 *   add_allowed_file=3, rewrite_acceptance=3, split_task=2,
 *   cancel_duplicate=2, quarantine_poison=1, operator_only_fix=0.
 *
 * ranked_repair_candidates is sorted by (safety_score, unblock_count,
 * token_savings) all descending — safety first, so a high-token-savings but
 * unsafe repair never outranks a safe one.
 *
 * INPUT:
 *   give_backs: list<{
 *     task_id:                  string
 *     root_cause?:              string
 *     allowed_files?:           list<string>
 *     missing_files?:           list<string>
 *     forbidden_target?:        bool (default false)
 *     duplicate_capability?:    bool (default false)
 *     acceptance_contradiction?:bool (default false)
 *     worker_notes?:            string
 *     token_savings?:           float (default 0.0)
 *     unblock_count?:           int (default 0)
 *   }>
 *
 * Pure: no I/O, no side effects, never repairs or mutates the queue itself.
 */
final class AtlasExternalBrainGiveBackToQueueRepairPlanner
{
    public const SCHEMA = 'atlas.external_brain.giveback_to_queue_repair_planner.v1';

    /** repair_plan values that describe a bad/impossible PACKET SCOPE — distinct from queue starvation. */
    private const SCOPE_REPAIR_PLANS = ['add_allowed_file', 'rewrite_acceptance', 'split_task', 'quarantine_poison', 'respec_for_queue_feed'];

    private const SAFETY_SCORES = [
        'respec_for_queue_feed' => 4,
        'add_allowed_file' => 3,
        'rewrite_acceptance' => 3,
        'split_task' => 2,
        'cancel_duplicate' => 2,
        'quarantine_poison' => 1,
        'operator_only_fix' => 0,
    ];

    /** claimable_per_active_worker at or below this ratio means workers are about to starve. */
    private const WORKER_FLOOR_LOW_THRESHOLD = 2.0;

    /** A root_cause seen this many times or more in the same batch is a "repeated" give_back pattern. */
    private const REPEATED_GIVE_BACK_MIN_COUNT = 2;

    private const RUNNABLE_ACCEPTANCE_MARKERS = ['phpunit', 'artisan test', 'pytest', 'jest', 'rspec'];

    public const ACTION_REPAIR = 'repair';
    public const ACTION_REFUSE = 'refuse';

    private const SAFETY_SCORE_FORBIDDEN = 0.0;
    private const SAFETY_SCORE_OPERATOR_UNCLASSIFIED = 0.2;
    private const SAFETY_SCORE_OPERATOR_CLASSIFIED = 0.8;
    private const SAFETY_SCORE_CLEAN = 1.0;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $events = is_array($input['give_backs'] ?? null) ? $input['give_backs'] : [];
        $claimablePerActiveWorker = isset($input['claimable_per_active_worker']) ? (float) $input['claimable_per_active_worker'] : null;
        $workerFloorLow = $claimablePerActiveWorker !== null && $claimablePerActiveWorker <= self::WORKER_FLOOR_LOW_THRESHOLD;

        // Count how many give_back events in THIS batch share the same root_cause — a repeated
        // pattern, not a one-off, is what justifies prioritizing immediate queue-feed repair.
        $rootCauseCounts = [];
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }
            $rc = strtolower(trim((string) ($event['root_cause'] ?? '')));
            if ($rc === '') {
                continue;
            }
            $rootCauseCounts[$rc] = ($rootCauseCounts[$rc] ?? 0) + 1;
        }

        $candidates = [];
        foreach ($events as $event) {
            if (! is_array($event) || ! isset($event['task_id'])) {
                continue;
            }
            $candidates[] = $this->planOne($event, $workerFloorLow, $rootCauseCounts);
        }

        $ranked = $candidates;
        usort($ranked, static function (array $a, array $b): int {
            return [$b['safety_score'], $b['unblock_count'], $b['token_savings']]
                <=> [$a['safety_score'], $a['unblock_count'], $a['token_savings']];
        });

        return [
            'schema' => self::SCHEMA,
            'repair_candidates' => $candidates,
            'ranked_repair_candidates' => $ranked,
            'candidate_count' => count($candidates),
        ];
    }

    /**
     * @param  array<string,mixed>  $event
     * @param  array<string,int>  $rootCauseCounts
     * @return array<string,mixed>
     */
    private function planOne(array $event, bool $workerFloorLow, array $rootCauseCounts): array
    {
        $taskId = (string) $event['task_id'];
        $rootCause = strtolower(trim((string) ($event['root_cause'] ?? '')));
        $missingFiles = is_array($event['missing_files'] ?? null) ? array_values(array_filter(array_map('strval', $event['missing_files']))) : [];
        $forbiddenTarget = (bool) ($event['forbidden_target'] ?? false);
        $duplicateCapability = (bool) ($event['duplicate_capability'] ?? false);
        $acceptanceContradiction = (bool) ($event['acceptance_contradiction'] ?? false);
        $tokenSavings = (float) ($event['token_savings'] ?? 0.0);
        $unblockCount = max(0, (int) ($event['unblock_count'] ?? 0));
        $giveBackReason = strtolower(trim((string) ($event['reason'] ?? '')));
        $impossibleScope = (bool) ($event['impossible_scope'] ?? false);

        // Starvation give_backs (worker had nothing claimable) need MORE queue supply, not a
        // packet rewrite — distinguish them from bad-scope give_backs before the scope-repair chain.
        if ($giveBackReason === 'no_claimable_task' && ! $forbiddenTarget && ! $acceptanceContradiction
            && ! $duplicateCapability && $missingFiles === [] && ! $impossibleScope) {
            return [
                'task_id' => $taskId,
                'repair_plan' => 'replenish_queue',
                'repair_type' => 'replenish_queue',
                'reason' => 'give_back_caused_by_queue_starvation_not_bad_scope',
                'safety_score' => 2,
                'token_savings' => $tokenSavings,
                'unblock_count' => $unblockCount,
            ];
        }

        [$repairPlan, $reason] = match (true) {
            $forbiddenTarget => ['operator_only_fix', 'forbidden_target_requires_operator_authorisation'],
            $acceptanceContradiction => ['rewrite_acceptance', 'acceptance_criteria_are_contradictory'],
            $duplicateCapability => ['cancel_duplicate', 'capability_already_satisfied_elsewhere'],
            $missingFiles !== [] => ['add_allowed_file', 'allowed_files_missing_a_required_implementation_target'],
            $impossibleScope => ['split_task', 'task_scope_is_impossible_for_a_single_packet'],
            str_contains($rootCause, 'poison') => ['quarantine_poison', 'root_cause_flags_a_poison_packet'],
            str_contains($rootCause, 'scope') || str_contains($rootCause, 'multi_file') || str_contains($rootCause, 'too_broad') => ['split_task', 'task_scope_is_too_broad_for_a_single_packet'],
            default => ['operator_only_fix', 'root_cause_not_auto_repairable_refusing_fake_green_or_retry'],
        };

        // Repeated-give_back + low-worker-floor prioritization: a passive "operator_only_fix"
        // diagnostic is upgraded to an immediate, SAFE respec action when (a) this root_cause has
        // repeated across the batch, (b) claimable supply per active worker is already thin, AND
        // (c) the packet has enough material to respec safely — allowed_files plus a runnable
        // acceptance criterion. Missing either of those refuses the upgrade and stays diagnostic
        // (AC2): we never guess a respec without the scope to do it safely.
        if ($repairPlan === 'operator_only_fix' && $reason === 'root_cause_not_auto_repairable_refusing_fake_green_or_retry') {
            $isRepeatedPattern = $rootCause !== '' && ($rootCauseCounts[$rootCause] ?? 0) >= self::REPEATED_GIVE_BACK_MIN_COUNT;
            if ($isRepeatedPattern && $workerFloorLow) {
                $allowedFiles = is_array($event['allowed_files'] ?? null)
                    ? array_values(array_filter(array_map('strval', $event['allowed_files'])))
                    : [];
                $hasRunnableAcceptance = $this->hasRunnableAcceptance((array) ($event['acceptance_criteria'] ?? []));

                if ($allowedFiles !== [] && $hasRunnableAcceptance) {
                    $repairPlan = 'respec_for_queue_feed';
                    $reason = 'repeated_give_back_pattern_prioritized_for_queue_feed_repair_under_worker_floor_pressure';
                } else {
                    $reason = 'repeated_give_back_pattern_but_missing_allowed_files_or_runnable_acceptance_refusing_respec';
                }
            }
        }

        // Build safe_respec_envelope when respec_for_queue_feed has scope and runnable proof
        $safeRespecEnvelope = null;
        if ($repairPlan === 'respec_for_queue_feed') {
            $safeRespecEnvelope = $this->buildSafeRespecEnvelope($event);
        }

        $repairType = in_array($repairPlan, self::SCOPE_REPAIR_PLANS, true) ? 'respec_packet' : $repairPlan;

        return [
            'task_id' => $taskId,
            'repair_plan' => $repairPlan,
            'repair_type' => $repairType,
            'reason' => $reason,
            'safety_score' => self::SAFETY_SCORES[$repairPlan],
            'token_savings' => $tokenSavings,
            'unblock_count' => $unblockCount,
            'safe_respec_envelope' => $safeRespecEnvelope,
        ];
    }

    /**
     * @param  list<mixed>  $acceptanceCriteria
     */
    private function hasRunnableAcceptance(array $acceptanceCriteria): bool
    {
        foreach ($acceptanceCriteria as $criterion) {
            $lower = strtolower((string) $criterion);
            foreach (self::RUNNABLE_ACCEPTANCE_MARKERS as $marker) {
                if (str_contains($lower, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Build safe_respec_envelope with patches only when scope and runnable proof are present.
     *
     * @param  array<string, mixed>  $event
     * @return array{objective_patch:string,allowed_files_patch:list<string>,acceptance_patch:list<string>,required_evidence_patch:list<string>}|null
     */
    private function buildSafeRespecEnvelope(array $event): ?array
    {
        $allowedFiles = is_array($event['allowed_files'] ?? null)
            ? array_values(array_filter(array_map('strval', $event['allowed_files'])))
            : [];
        $acceptanceCriteria = is_array($event['acceptance_criteria'] ?? null)
            ? array_values(array_filter(array_map('strval', $event['acceptance_criteria'])))
            : [];
        $requiredEvidence = is_array($event['required_evidence'] ?? null)
            ? array_values(array_filter(array_map('strval', $event['required_evidence'])))
            : [];
        $objective = (string) ($event['objective'] ?? '');

        // Refuse safe_respec_envelope when missing runnable acceptance or empty allowed_files
        if ($allowedFiles === [] || ! $this->hasRunnableAcceptance($acceptanceCriteria)) {
            return null;
        }

        return [
            'objective_patch' => $objective !== '' ? 'retain_objective_with_scope_narrowing' : 'objective_missing_cannot_respec',
            'allowed_files_patch' => $allowedFiles,
            'acceptance_patch' => $acceptanceCriteria,
            'required_evidence_patch' => $requiredEvidence !== [] ? $requiredEvidence : ['tests_or_gates_result'],
        ];
    }

    /**
     * Collapses repeated give_backs against the same target_file into ONE prioritized repair
     * batch instead of N duplicate diagnostics. Unlike {@see plan()} (per-event candidates for the
     * legacy repair_plan taxonomy), this groups by target so the muscle acts on the target once.
     *
     * SAFETY (first match wins):
     *   forbidden_target=true on ANY event in the group                       → refuse, safety_score=0.0
     *   requires_operator_only_files=true AND no operator_only_classification_confirmed → refuse, safety_score=0.2
     *   requires_operator_only_files=true AND confirmed                       → repair, safety_score=0.8
     *   otherwise                                                             → repair, safety_score=1.0
     *
     * INPUT (per event, in addition to {@see plan()}'s shape):
     *   target_file?:                            string (falls back to missing_files[0]/allowed_files[0]/task_id)
     *   requires_operator_only_files?:           bool (default false)
     *   operator_only_classification_confirmed?: bool (default false)
     *
     * @param  array<string,mixed>  $input
     * @return array{schema:string, batches:list<array{target_file:string, action:string, safety_score:float, give_back_count:int, unblock_count:int, token_savings:float, required_scope_changes:list<string>, reason:string}>}
     */
    public function planBatches(array $input): array
    {
        $events = is_array($input['give_backs'] ?? null) ? $input['give_backs'] : [];

        $byTarget = [];
        foreach ($events as $event) {
            if (! is_array($event) || ! isset($event['task_id'])) {
                continue;
            }
            $missingFiles = is_array($event['missing_files'] ?? null) ? array_values(array_filter(array_map('strval', $event['missing_files']))) : [];
            $allowedFiles = is_array($event['allowed_files'] ?? null) ? array_values(array_filter(array_map('strval', $event['allowed_files']))) : [];
            $targetFile = (string) ($event['target_file'] ?? ($missingFiles[0] ?? ($allowedFiles[0] ?? (string) $event['task_id'])));
            if ($targetFile === '') {
                continue;
            }

            $byTarget[$targetFile][] = [
                'unblock_count' => max(0, (int) ($event['unblock_count'] ?? 0)),
                'token_savings' => (float) ($event['token_savings'] ?? 0.0),
                'forbidden_target' => (bool) ($event['forbidden_target'] ?? false),
                'requires_operator_only_files' => (bool) ($event['requires_operator_only_files'] ?? false),
                'operator_only_classification_confirmed' => (bool) ($event['operator_only_classification_confirmed'] ?? false),
                'scope_files' => array_merge($missingFiles, $allowedFiles),
            ];
        }

        $batches = [];
        foreach ($byTarget as $targetFile => $groupEvents) {
            $unblockCount = 0;
            $tokenSavings = 0.0;
            $forbidden = false;
            $operatorOnly = false;
            $operatorClassified = false;
            $scopeFiles = [];

            foreach ($groupEvents as $e) {
                $unblockCount += $e['unblock_count'];
                $tokenSavings += $e['token_savings'];
                $forbidden = $forbidden || $e['forbidden_target'];
                $operatorOnly = $operatorOnly || $e['requires_operator_only_files'];
                $operatorClassified = $operatorClassified || $e['operator_only_classification_confirmed'];
                $scopeFiles = array_merge($scopeFiles, $e['scope_files']);
            }

            $requiredScopeChanges = array_values(array_unique(array_filter($scopeFiles, static fn (string $f): bool => $f !== '')));
            sort($requiredScopeChanges, SORT_STRING);
            if ($requiredScopeChanges === []) {
                $requiredScopeChanges = [$targetFile];
            }

            if ($forbidden) {
                $action = self::ACTION_REFUSE;
                $safetyScore = self::SAFETY_SCORE_FORBIDDEN;
                $reason = 'forbidden_target_requires_human_decision';
            } elseif ($operatorOnly && ! $operatorClassified) {
                $action = self::ACTION_REFUSE;
                $safetyScore = self::SAFETY_SCORE_OPERATOR_UNCLASSIFIED;
                $reason = 'operator_only_files_without_explicit_classification';
            } elseif ($operatorOnly) {
                $action = self::ACTION_REPAIR;
                $safetyScore = self::SAFETY_SCORE_OPERATOR_CLASSIFIED;
                $reason = 'operator_only_files_explicitly_classified';
            } else {
                $action = self::ACTION_REPAIR;
                $safetyScore = self::SAFETY_SCORE_CLEAN;
                $reason = 'safe_repeated_give_back_repair';
            }

            $batches[] = [
                'target_file' => $targetFile,
                'action' => $action,
                'safety_score' => $safetyScore,
                'give_back_count' => count($groupEvents),
                'unblock_count' => $unblockCount,
                'token_savings' => round($tokenSavings, 2),
                'required_scope_changes' => $requiredScopeChanges,
                'reason' => $reason,
            ];
        }

        usort($batches, static fn (array $a, array $b): int =>
            $b['token_savings'] <=> $a['token_savings']
                ?: strcmp($a['target_file'], $b['target_file']));

        return [
            'schema' => self::SCHEMA,
            'batches' => $batches,
        ];
    }
}
