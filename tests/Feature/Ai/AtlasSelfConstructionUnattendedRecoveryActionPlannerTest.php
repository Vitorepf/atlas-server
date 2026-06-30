<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\UnattendedRuntime\AtlasSelfConstructionUnattendedRecoveryActionPlanner;
use App\Services\Ai\SelfConstruction\UnattendedRuntime\AtlasSelfConstructionUnattendedStallClassifier;
use Tests\TestCase;

/**
 * Feature-level gate for AtlasSelfConstructionUnattendedRecoveryActionPlanner.
 *
 * Verifies each stall classification maps to bounded Atlas-native recovery
 * actions, and that orthogonal brain_quota actions are additive without
 * executing providers/git/subprocesses/files.
 */
final class AtlasSelfConstructionUnattendedRecoveryActionPlannerTest extends TestCase
{
    private function classification(string $label, bool $recoveryNeeded = true): array
    {
        return [
            'classification' => $label,
            'severity' => 'high',
            'reasons' => [$label],
            'recovery_needed' => $recoveryNeeded,
            'classifier_hash' => 'h',
        ];
    }

    // ── AC1: queue_dry and waiting_on_dependencies mappings ──────────────────

    public function test_queue_dry_maps_to_replenisher_dry_run_plus_apply_safe(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedRecoveryActionPlanner)->plan(
            $this->classification(AtlasSelfConstructionUnattendedStallClassifier::QUEUE_DRY),
        );

        $actions = array_column($verdict['actions'], 'action');
        $this->assertContains(AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_RUN_REPLENISHER_DRY_RUN, $actions);
        $this->assertContains(AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_APPLY_SAFE_REPLENISHER_PLAN, $actions);
    }

    public function test_waiting_on_dependencies_maps_to_wait_for_dependencies(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedRecoveryActionPlanner)->plan(
            $this->classification(AtlasSelfConstructionUnattendedStallClassifier::WAITING_ON_DEPENDENCIES),
        );

        $actions = array_column($verdict['actions'], 'action');
        $this->assertContains(AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_WAIT_FOR_DEPENDENCIES, $actions);
    }

    // ── AC2: stall classifications to recovery actions ───────────────────────

    public function test_heartbeat_stale_maps_to_recover_expired_leases(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedRecoveryActionPlanner)->plan(
            $this->classification(AtlasSelfConstructionUnattendedStallClassifier::HEARTBEAT_STALE),
        );

        $actions = array_column($verdict['actions'], 'action');
        $this->assertContains(AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_RECOVER_EXPIRED_LEASES, $actions);
    }

    public function test_worker_unavailable_maps_to_degrade_autonomy_level(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedRecoveryActionPlanner)->plan(
            $this->classification(AtlasSelfConstructionUnattendedStallClassifier::WORKER_UNAVAILABLE),
        );

        $actions = array_column($verdict['actions'], 'action');
        $this->assertContains(AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_DEGRADE_AUTONOMY_LEVEL, $actions);
    }

    public function test_verification_blocked_maps_to_rerun_verification(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedRecoveryActionPlanner)->plan(
            $this->classification(AtlasSelfConstructionUnattendedStallClassifier::VERIFICATION_BLOCKED),
        );

        $actions = array_column($verdict['actions'], 'action');
        $this->assertContains(AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_RERUN_VERIFICATION, $actions);
    }

    public function test_merge_blocked_maps_to_quarantine_poison_packet(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedRecoveryActionPlanner)->plan(
            $this->classification(AtlasSelfConstructionUnattendedStallClassifier::MERGE_BLOCKED),
        );

        $actions = array_column($verdict['actions'], 'action');
        $this->assertContains(AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_QUARANTINE_POISON_PACKET, $actions);
    }

    public function test_unsafe_stop_maps_to_safety_stop_with_emergency_override(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedRecoveryActionPlanner)->plan(
            $this->classification(AtlasSelfConstructionUnattendedStallClassifier::UNSAFE_STOP),
        );

        $this->assertTrue($verdict['requires_emergency_override']);
        $actions = array_column($verdict['actions'], 'action');
        $this->assertContains(AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_SAFETY_STOP, $actions);
    }

    // ── AC3: brain_quota orthogonal actions ──────────────────────────────────

    public function test_brain_quota_must_run_now_adds_run_brain_action(): void
    {
        $snapshot = ['facts' => ['brain_quota' => ['must_run_now' => true, 'status' => 'stalled', 'temp_spec_path' => '']]];

        $verdict = (new AtlasSelfConstructionUnattendedRecoveryActionPlanner)->plan(
            $this->classification(AtlasSelfConstructionUnattendedStallClassifier::QUEUE_DRY),
            $snapshot,
        );

        $actions = array_column($verdict['actions'], 'action');
        $this->assertContains(AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_RUN_BRAIN_MUST_RUN_NOW, $actions);
        // queue_dry actions still present alongside orthogonal brain action.
        $this->assertContains(AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_RUN_REPLENISHER_DRY_RUN, $actions);
    }

    public function test_done_temp_spec_adds_discard_action(): void
    {
        $snapshot = ['facts' => ['brain_quota' => ['must_run_now' => false, 'status' => 'done', 'temp_spec_path' => '/tmp/spec.json']]];

        $verdict = (new AtlasSelfConstructionUnattendedRecoveryActionPlanner)->plan(
            $this->classification(AtlasSelfConstructionUnattendedStallClassifier::HEALTHY, false),
            $snapshot,
        );

        $actions = array_column($verdict['actions'], 'action');
        $this->assertContains(AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_DISCARD_DONE_TEMP_SPEC, $actions);
    }
}
