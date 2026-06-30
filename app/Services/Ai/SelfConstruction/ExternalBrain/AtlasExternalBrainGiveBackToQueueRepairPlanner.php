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

    private const SAFETY_SCORES = [
        'add_allowed_file' => 3,
        'rewrite_acceptance' => 3,
        'split_task' => 2,
        'cancel_duplicate' => 2,
        'quarantine_poison' => 1,
        'operator_only_fix' => 0,
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $events = is_array($input['give_backs'] ?? null) ? $input['give_backs'] : [];

        $candidates = [];
        foreach ($events as $event) {
            if (! is_array($event) || ! isset($event['task_id'])) {
                continue;
            }
            $candidates[] = $this->planOne($event);
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
     * @return array<string,mixed>
     */
    private function planOne(array $event): array
    {
        $taskId = (string) $event['task_id'];
        $rootCause = strtolower(trim((string) ($event['root_cause'] ?? '')));
        $missingFiles = is_array($event['missing_files'] ?? null) ? array_values(array_filter(array_map('strval', $event['missing_files']))) : [];
        $forbiddenTarget = (bool) ($event['forbidden_target'] ?? false);
        $duplicateCapability = (bool) ($event['duplicate_capability'] ?? false);
        $acceptanceContradiction = (bool) ($event['acceptance_contradiction'] ?? false);
        $tokenSavings = (float) ($event['token_savings'] ?? 0.0);
        $unblockCount = max(0, (int) ($event['unblock_count'] ?? 0));

        [$repairPlan, $reason] = match (true) {
            $forbiddenTarget => ['operator_only_fix', 'forbidden_target_requires_operator_authorisation'],
            $acceptanceContradiction => ['rewrite_acceptance', 'acceptance_criteria_are_contradictory'],
            $duplicateCapability => ['cancel_duplicate', 'capability_already_satisfied_elsewhere'],
            $missingFiles !== [] => ['add_allowed_file', 'allowed_files_missing_a_required_implementation_target'],
            str_contains($rootCause, 'poison') => ['quarantine_poison', 'root_cause_flags_a_poison_packet'],
            str_contains($rootCause, 'scope') || str_contains($rootCause, 'multi_file') || str_contains($rootCause, 'too_broad') => ['split_task', 'task_scope_is_too_broad_for_a_single_packet'],
            default => ['operator_only_fix', 'root_cause_not_auto_repairable_refusing_fake_green_or_retry'],
        };

        return [
            'task_id' => $taskId,
            'repair_plan' => $repairPlan,
            'reason' => $reason,
            'safety_score' => self::SAFETY_SCORES[$repairPlan],
            'token_savings' => $tokenSavings,
            'unblock_count' => $unblockCount,
        ];
    }
}
