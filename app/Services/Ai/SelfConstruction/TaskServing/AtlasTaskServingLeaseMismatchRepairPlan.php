<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskServing;

/**
 * Pure, advisory-only repair-plan compiler over a lease/claim PARITY REPORT (the shape produced by
 * {@see AtlasTaskServingLeaseClaimParityInspector}, or any equivalent array). Names which EXISTING
 * command or service should run, which records are safe to ignore, and which cases need a writer fix —
 * never mutates the queue itself. The cheapest, safest action (reap_leases for recoverable backlog)
 * always comes before any heavier registry repair.
 *
 * Input shape (subset of the parity inspector's output is enough):
 *   {classification?:string, active_leases:int, claimed_records:int, recoverable_candidates:{total:int}}
 */
final class AtlasTaskServingLeaseMismatchRepairPlan
{
    public const SCHEMA = 'atlas.self_construction.task_serving.lease_mismatch_repair_plan.v1';

    public const ACTION_REAP_LEASES = 'atlas:acp:reap-leases';

    public const ACTION_REPAIR_REGISTRY = 'repair_registry';

    public const ACTION_INVESTIGATE_WRITER = 'investigate_writer';

    public const ACTION_OBSERVE = 'observe';

    public const CATEGORY_AUTOMATIC_REAP = 'automatic_reap';

    public const CATEGORY_OBSERVE_GHOST = 'observe_ghost';

    public const CATEGORY_QUARANTINE_REVIEW = 'quarantine_review';

    public const CATEGORY_NOOP = 'noop';

    public const MISMATCH_LEASE_MISMATCH_WITHOUT_RECOVERABLE = 'lease_mismatch_without_recoverable';

    public const MISMATCH_CLEAN = 'clean';

    public const MISMATCH_RECOVERABLE = 'recoverable';

    public const MISMATCH_CLAIM_WITHOUT_LEASE = 'claim_without_lease';

    /**
     * @param  array<string, mixed>  $parityReport
     * @return array<string, mixed>
     */
    public function compile(array $parityReport): array
    {
        $classification = (string) ($parityReport['classification'] ?? '');
        $recoverableTotal = (int) data_get($parityReport, 'recoverable_candidates.total', 0);
        $activeLeases = (int) ($parityReport['active_leases'] ?? 0);
        $claimedRecords = (int) ($parityReport['claimed_records'] ?? 0);
        $claimableDepth = (int) ($parityReport['claimable_depth'] ?? 0);
        $servableNow = (int) ($parityReport['servable_now'] ?? 0);

        $steps = [];

        if ($recoverableTotal > 0) {
            $steps[] = $this->step(
                self::ACTION_REAP_LEASES,
                'recoverable_candidates_present:'.$recoverableTotal,
                'safe',
                'resolves_recoverable_lease_claim_mismatch',
            );
        }

        if ($recoverableTotal === 0 && $activeLeases > $claimedRecords) {
            // Observed live behavior: reap-leases restores leases_match_claimed even when
            // recoverable_candidates.total=0 — try the cheap, safe normalization FIRST, before
            // recommending the heavier registry repair.
            $steps[] = $this->step(
                self::ACTION_REAP_LEASES,
                'non_recoverable_active_lease_surplus_try_reap_first:active='.$activeLeases.',claimed='.$claimedRecords,
                'safe',
                'may_resolve_lease_claim_mismatch_without_registry_repair',
            );
            $steps[] = $this->step(
                self::ACTION_REPAIR_REGISTRY,
                'non_recoverable_active_lease_surplus:active='.$activeLeases.',claimed='.$claimedRecords,
                'caution_no_destructive_deletion',
                'reduces_lease_registry_drift',
            );
        }

        if ($classification === 'claim_without_lease_drift') {
            $steps[] = $this->step(
                self::ACTION_INVESTIGATE_WRITER,
                'claim_without_lease_detected',
                'safe',
                'clarifies_writer_responsible_for_orphan_claim',
            );
        }

        if ($steps === []) {
            $steps[] = $this->step(self::ACTION_OBSERVE, 'clean_parity', 'clean', 'none');
        }

        // AC1: detect active_leases > claimed_records with recoverable_total=0.
        $mismatchType = self::MISMATCH_CLEAN;
        if ($activeLeases > $claimedRecords && $recoverableTotal === 0) {
            $mismatchType = self::MISMATCH_LEASE_MISMATCH_WITHOUT_RECOVERABLE;
        } elseif ($recoverableTotal > 0) {
            $mismatchType = self::MISMATCH_RECOVERABLE;
        } elseif ($classification === 'claim_without_lease_drift') {
            $mismatchType = self::MISMATCH_CLAIM_WITHOUT_LEASE;
        }

        // Derive top-level plan metadata from the mismatch type and first step.
        $safeAction = $steps[0]['action'] ?? self::ACTION_OBSERVE;
        $operatorFree = $safeAction !== self::ACTION_INVESTIGATE_WRITER;
        $doNotCreateMoreTasks = $mismatchType === self::MISMATCH_LEASE_MISMATCH_WITHOUT_RECOVERABLE;

        return [
            'schema' => self::SCHEMA,
            'steps' => $steps,
            'mutates_queue' => false,
            'mismatch_type' => $mismatchType,
            'inspect_target' => match ($mismatchType) {
                self::MISMATCH_LEASE_MISMATCH_WITHOUT_RECOVERABLE => 'lease_registry',
                self::MISMATCH_RECOVERABLE => 'recoverable_leases',
                self::MISMATCH_CLAIM_WITHOUT_LEASE => 'claim_writer',
                default => null,
            },
            'safe_action' => $safeAction,
            'operator_free' => $operatorFree,
            'expected_health_delta' => $steps[0]['expected_health_delta'] ?? 'none',
            'do_not_create_more_tasks_as_fix' => $doNotCreateMoreTasks,
            'claimable_depth' => $claimableDepth,
            'servable_now' => $servableNow,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function step(string $action, string $reason, string $safetyLevel, string $expectedHealthDelta): array
    {
        return [
            'action' => $action,
            'reason' => $reason,
            'safety_level' => $safetyLevel,
            'expected_health_delta' => $expectedHealthDelta,
        ];
    }

    /**
     * Single-category classification distinguishing what MUST NEVER be auto-repaired (a blocked or
     * quarantined record — surfaced for operator review only) from what is genuinely safe to
     * auto-reap (a terminal record proves the task already ended), from a merely observed ghost
     * (no claim record to reconcile against — nothing recoverable, never touched), from a clean
     * no-op. Quarantine review always takes precedence over every other signal, so a repair command
     * can never touch a record the operator is meant to inspect first.
     *
     * @param  array<string,mixed>  $parityReport  same shape as compile()'s input, optionally with
     *                                               blocked_or_quarantined_task_ids?:list<string>
     * @return array{schema:string, category:string, reason:string, safety_note:string}
     */
    public function classifyRepairCategory(array $parityReport): array
    {
        $blockedOrQuarantined = array_values(array_map('strval', (array) ($parityReport['blocked_or_quarantined_task_ids'] ?? [])));
        $recoverableTotal = (int) data_get(
            $parityReport,
            'recoverable_leaks.total',
            data_get($parityReport, 'recoverable_candidates.total', 0),
        );
        $ghostTotal = (int) data_get($parityReport, 'ghost_active_leases.total', 0);

        if ($blockedOrQuarantined !== []) {
            return $this->category(
                self::CATEGORY_QUARANTINE_REVIEW,
                'blocked_or_quarantined_records_present:'.count($blockedOrQuarantined),
                'never_auto_repair_blocked_or_quarantined_records_operator_review_required',
            );
        }

        if ($recoverableTotal > 0) {
            return $this->category(
                self::CATEGORY_AUTOMATIC_REAP,
                'recoverable_lease_leaks_present:'.$recoverableTotal,
                'safe_to_reap_terminal_record_proves_task_already_ended',
            );
        }

        if ($ghostTotal > 0) {
            return $this->category(
                self::CATEGORY_OBSERVE_GHOST,
                'ghost_active_leases_present:'.$ghostTotal,
                'no_claim_record_to_reconcile_against_surface_for_operator_visibility_never_auto_repaired',
            );
        }

        return $this->category(
            self::CATEGORY_NOOP,
            'no_actionable_anomaly_detected',
            'no_action_required',
        );
    }

    /**
     * @return array{schema:string, category:string, reason:string, safety_note:string}
     */
    private function category(string $category, string $reason, string $safetyNote): array
    {
        return [
            'schema' => self::SCHEMA,
            'category' => $category,
            'reason' => $reason,
            'safety_note' => $safetyNote,
        ];
    }
}
