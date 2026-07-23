<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure planner: given capability records with lifecycle states, identifies
 * capabilities that are stalled (never reached integrated/used) and emits
 * a rescue, collapse, retire, or monitor action for each.
 *
 * Priority (first match wins):
 *   1. Has an integrated replacement owner → collapse (no rescue work for the stale one)
 *   2. state=implemented|tested AND rescue is viable → rescue (wiring work needed)
 *   2b. state=implemented|tested AND rescue NOT viable:
 *         duplicate_owner_count > 0 → collapse (another owner handles it)
 *         otherwise                 → retire
 *   3. stall_count >= 3 AND state planned|queued → retire
 *   4. default                               → monitor
 *
 * RESCUE VIABILITY:
 *   Rescue is NOT viable when (stale AND duplicated AND unused AND high_cost).
 *   Secondary: give_backs > 0 AND unused AND stale AND implementation_completeness < 0.5.
 *   All other combinations remain rescue-eligible (default).
 *
 * Capabilities already at state=integrated|used are not stalled — they are skipped.
 */
final class AtlasExternalBrainStalledCapabilityRescuePlanner
{
    public const SCHEMA = 'atlas.external_brain.stalled_capability_rescue_planner.v1';

    public const ACTION_RESCUE   = 'rescue';
    public const ACTION_COLLAPSE = 'collapse';
    public const ACTION_RETIRE   = 'retire';
    public const ACTION_MONITOR  = 'monitor';

    public const STALL_RETIRE_THRESHOLD      = 3;
    public const RESCUE_STALE_DAYS_THRESHOLD = 30;
    public const RESCUE_MAX_COST             = 1.0;
    public const RESCUE_LOW_COST_THRESHOLD   = 0.5;

    private const PROGRESS_STATES   = ['implemented', 'tested'];
    private const PLANNED_STATES    = ['planned', 'queued'];
    private const INTEGRATED_STATES = ['integrated', 'used'];

    /** @var array<string,list<string>> */
    private const EVIDENCE_BY_ACTION = [
        self::ACTION_RESCUE   => ['integration_test', 'wiring_proof'],
        self::ACTION_COLLAPSE => ['canonical_owner_confirmation', 'overlap_proof'],
        self::ACTION_RETIRE   => ['no_active_consumer', 'stall_history'],
        self::ACTION_MONITOR  => [],
    ];

    /**
     * @param  list<array<string,mixed>>  $capabilities  Each: id, state, stall_count,
     *                                                     replacement_owner_id, replacement_owner_integrated,
     *                                                     consumer_count, duplicate_owner_count,
     *                                                     last_green_commit_age_days,
     *                                                     outstanding_give_back_count,
     *                                                     implementation_completeness,
     *                                                     estimated_rescue_cost
     * @return array<string,mixed>
     */
    public function plan(array $capabilities): array
    {
        $entries  = [];
        $byAction = [
            self::ACTION_RESCUE   => [],
            self::ACTION_COLLAPSE => [],
            self::ACTION_RETIRE   => [],
            self::ACTION_MONITOR  => [],
        ];
        $highPriorityRescueCount = 0;

        foreach ($capabilities as $cap) {
            $id                    = (string) ($cap['id']                         ?? '');
            $state                 = (string) ($cap['state']                      ?? 'planned');
            $stallCount            = max(0, (int) ($cap['stall_count']            ?? 0));
            $replacementOwner      = (string) ($cap['replacement_owner_id']       ?? '');
            $replacementIntegrated = (bool) ($cap['replacement_owner_integrated'] ?? false);
            $duplicateOwnerCount   = max(0, (int) ($cap['duplicate_owner_count']  ?? 0));
            $isProgressing         = (bool) ($cap['is_progressing']               ?? false);

            if (in_array($state, self::INTEGRATED_STATES, true)) {
                continue;
            }

            $unblockCause = $this->computeUnblockCause($cap);

            if ($replacementOwner !== '' && $replacementIntegrated) {
                $action = self::ACTION_COLLAPSE;
            } elseif (in_array($state, self::PROGRESS_STATES, true)) {
                // AC2: progressing without an explicit blocker → not stalled, monitor instead
                if ($isProgressing && $unblockCause === 'unknown') {
                    $action = self::ACTION_MONITOR;
                } elseif ($this->isRescueViable($cap)) {
                    $action = self::ACTION_RESCUE;
                } elseif ($duplicateOwnerCount > 0) {
                    $action = self::ACTION_COLLAPSE;
                } else {
                    $action = self::ACTION_RETIRE;
                }
            } elseif ($stallCount >= self::STALL_RETIRE_THRESHOLD && in_array($state, self::PLANNED_STATES, true)) {
                $action = self::ACTION_RETIRE;
            } else {
                $action = self::ACTION_MONITOR;
            }

            $rescuePriority = 'n/a';
            if ($action === self::ACTION_RESCUE) {
                $impactLevel   = (string) ($cap['impact_level']         ?? 'medium');
                $estimatedCost = max(0.0, (float) ($cap['estimated_rescue_cost'] ?? 1.0));
                $rescuePriority = ($impactLevel === 'high' && $estimatedCost <= self::RESCUE_LOW_COST_THRESHOLD)
                    ? 'high'
                    : 'normal';

                if ($rescuePriority === 'high') {
                    $highPriorityRescueCount++;
                }
            }

            $entry = [
                'capability_id'         => $id,
                'action'                => $action,
                'stall_count'           => $stallCount,
                'evidence_requirements' => self::EVIDENCE_BY_ACTION[$action],
                'unblock_cause'         => $unblockCause,
                'rescue_priority'       => $rescuePriority,
                'next_action'           => $this->nextAction($action, $unblockCause, $replacementOwner),
            ];

            if ($action === self::ACTION_COLLAPSE && $replacementOwner !== '') {
                $entry['collapse_into'] = $replacementOwner;
            }

            // AC1/AC2/AC3: turn the decision into implementable muscle work — never fake work
            // for retire, and never duplicate capability work when a replacement owner exists.
            if ($action === self::ACTION_RESCUE) {
                $entry['first_safe_task'] = [
                    'objective_hint'            => $entry['next_action'],
                    'allowed_file_hints'        => array_values(array_filter(array_map(
                        'trim',
                        (array) ($cap['owning_file_paths'] ?? [])
                    ))),
                    'proof_requirements'        => self::EVIDENCE_BY_ACTION[self::ACTION_RESCUE],
                    'expected_capability_delta' => "{$id}: stalled -> integrated",
                ];
            } elseif ($action === self::ACTION_COLLAPSE) {
                $consolidationTarget = $replacementOwner !== '' ? $replacementOwner : 'canonical_owner';
                $entry['first_safe_task'] = [
                    'objective_hint'       => "collapse_into:{$consolidationTarget}",
                    'replacement_owner'    => $consolidationTarget,
                    'consolidation_proof'  => self::EVIDENCE_BY_ACTION[self::ACTION_COLLAPSE],
                ];
            } elseif ($action === self::ACTION_RETIRE) {
                // Explicitly no implementation brief — retiring never emits fake work.
                $entry['retire_conditions'] = [
                    'stall_count_at_or_above' => self::STALL_RETIRE_THRESHOLD,
                    'no_active_consumer'      => true,
                ];
            }

            $entries[]           = $entry;
            $byAction[$action][] = $id;
        }

        return [
            'schema_version'             => self::SCHEMA,
            'total_evaluated'            => count($entries),
            'high_priority_rescue_count' => $highPriorityRescueCount,
            'entries'                    => $entries,
            'by_action'                  => $byAction,
        ];
    }

    public const STALLED_GIVE_BACK_THRESHOLD = 2;
    public const STALLED_STALE_PROOF_DAYS    = 30;
    public const STALLED_NO_IMPACT_STREAK    = 3;
    public const STALLED_LOW_YIELD_THRESHOLD = 0.2;
    public const STALLED_LOW_YIELD_MIN_ATTEMPTS = 2;

    /**
     * Detects genuinely stalled capabilities (repeated give_back, stale proof, blocked
     * dependencies, or a streak of no-impact commits) and emits a concrete unblock_plan
     * instead of more adjacent feature work. A capability that is merely low priority
     * (never touched, no signal of active-but-failing work) is NOT stalled.
     *
     * @param  list<array<string,mixed>>  $capabilities  Each: id, give_back_count,
     *   last_proof_age_days, blocked_dependencies, no_impact_commit_streak, priority
     * @return array<string,mixed>
     */
    public function planUnblock(array $capabilities): array
    {
        $entries = [];

        foreach ($capabilities as $cap) {
            $id = (string) ($cap['id'] ?? '');
            $giveBackCount = max(0, (int) ($cap['give_back_count'] ?? 0));
            $lastProofAgeDays = max(0, (int) ($cap['last_proof_age_days'] ?? 0));
            $blockedDependencies = array_values(array_filter(array_map('strval', (array) ($cap['blocked_dependencies'] ?? []))));
            $noImpactStreak = max(0, (int) ($cap['no_impact_commit_streak'] ?? 0));
            $yieldScore = max(0.0, min(1.0, (float) ($cap['yield_score'] ?? 1.0)));
            $attemptCount = max(0, (int) ($cap['attempt_count'] ?? 0));
            $hasScopeGap = (bool) ($cap['has_scope_gap'] ?? false);
            $hasMissingPrerequisite = (bool) ($cap['has_missing_prerequisite'] ?? false);
            $structurallyComplex = (bool) ($cap['structurally_complex'] ?? false);
            $priorPacketIds = array_values(array_filter(array_map('strval', (array) ($cap['prior_packet_ids'] ?? []))));

            $rootCause = match (true) {
                $blockedDependencies !== [] => 'blocked_dependency',
                $giveBackCount >= self::STALLED_GIVE_BACK_THRESHOLD => 'repeated_give_back',
                $lastProofAgeDays > self::STALLED_STALE_PROOF_DAYS => 'stale_proof',
                $noImpactStreak >= self::STALLED_NO_IMPACT_STREAK => 'no_impact_commits',
                $yieldScore < self::STALLED_LOW_YIELD_THRESHOLD && $attemptCount >= self::STALLED_LOW_YIELD_MIN_ATTEMPTS => 'low_yield',
                default => null,
            };

            $isStalled = $rootCause !== null;

            $unblockPlan = null;
            if ($isStalled) {
                $firstSafeTask = match ($rootCause) {
                    'blocked_dependency' => 'resolve_blocked_dependency:'.implode(',', $blockedDependencies),
                    // AC: repeated give_back names the concrete prerequisite/scope repair
                    // needed instead of a vague "diagnose" instruction, when that evidence
                    // is available; otherwise falls back to the generic diagnosis task.
                    'repeated_give_back' => match (true) {
                        $hasMissingPrerequisite => 'resolve_missing_prerequisite_before_retry',
                        $hasScopeGap => 'repair_scope_gap_before_retry',
                        default => 'diagnose_repeated_give_back_root_cause',
                    },
                    'stale_proof' => 'refresh_proof_with_current_runnable_evidence',
                    'low_yield' => $structurallyComplex
                        ? 'simplify_before_retry'
                        : 'research_alternative_approach_before_retry',
                    default => 'break_no_impact_streak_with_one_real_committed_change',
                };

                $unblockPlan = [
                    'root_cause' => $rootCause,
                    'first_safe_task' => $firstSafeTask,
                    'required_evidence' => match ($rootCause) {
                        'blocked_dependency' => ['dependency_resolution_proof'],
                        'repeated_give_back' => ['give_back_root_cause_analysis'],
                        'stale_proof' => ['fresh_runnable_evidence'],
                        'low_yield' => ['simplification_or_research_plan'],
                        default => ['committed_change_with_measurable_delta'],
                    },
                    'stop_creating_adjacent_features' => true,
                    // AC: names the next worker-ready action and the packets that must
                    // never be reissued as-is, so the same failing task isn't repeated.
                    'next_worker_ready_task_hint' => "{$firstSafeTask} for {$id}; do not reissue the same objective unchanged",
                ];
            }

            $entries[] = [
                'capability_id' => $id,
                'is_stalled' => $isStalled,
                'unblock_plan' => $unblockPlan,
                'do_not_repeat_packet_ids' => $priorPacketIds,
            ];
        }

        return [
            'schema_version' => self::SCHEMA,
            'entries' => $entries,
        ];
    }

    private function nextAction(string $action, string $unblockCause, string $replacementOwner): string
    {
        return match ($action) {
            self::ACTION_RESCUE   => $unblockCause === 'unknown'
                ? 'wire_integration_and_add_proof'
                : "resolve_{$unblockCause}_then_wire_integration",
            self::ACTION_COLLAPSE => $replacementOwner !== ''
                ? "collapse_into:{$replacementOwner}"
                : 'collapse_into_canonical_owner',
            self::ACTION_RETIRE  => 'retire_no_active_consumer',
            default              => 'continue_monitoring_progress',
        };
    }

    private function computeUnblockCause(array $cap): string
    {
        if ((bool) ($cap['has_contradiction'] ?? false)) {
            return 'contradiction';
        }
        if ((bool) ($cap['has_missing_file'] ?? false)) {
            return 'missing_file';
        }
        if ((bool) ($cap['has_scope_gap'] ?? false)) {
            return 'scope_gap';
        }
        if ((bool) ($cap['has_missing_test'] ?? false)) {
            return 'missing_test';
        }
        return 'unknown';
    }

    private function isRescueViable(array $cap): bool
    {
        $consumerCount     = max(0, (int) ($cap['consumer_count']               ?? 0));
        $duplicateOwners   = max(0, (int) ($cap['duplicate_owner_count']        ?? 0));
        $greenAge          = max(0, (int) ($cap['last_green_commit_age_days']   ?? 0));
        $giveBacks         = max(0, (int) ($cap['outstanding_give_back_count']  ?? 0));
        $completeness      = max(0.0, min(1.0, (float) ($cap['implementation_completeness'] ?? 0.0)));
        $estimatedCost     = max(0.0, (float) ($cap['estimated_rescue_cost']    ?? 0.0));

        $isStale      = $greenAge > self::RESCUE_STALE_DAYS_THRESHOLD;
        $isDuplicated = $duplicateOwners > 0;
        $isUnused     = $consumerCount === 0;
        $isHighCost   = $estimatedCost > self::RESCUE_MAX_COST;

        // Primary: stale + duplicated + unused + high cost → clearly not worth rescuing
        if ($isStale && $isDuplicated && $isUnused && $isHighCost) {
            return false;
        }

        // Secondary: repeated give-backs on unused stale capability, unless much work was done
        $highCompleteness = $completeness >= 0.5;
        if ($giveBacks > 0 && $isUnused && $isStale && ! $highCompleteness) {
            return false;
        }

        return true;
    }
}
