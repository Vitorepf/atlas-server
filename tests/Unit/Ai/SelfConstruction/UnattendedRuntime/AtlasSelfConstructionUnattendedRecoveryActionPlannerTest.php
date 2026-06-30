<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\UnattendedRuntime;

use App\Services\Ai\SelfConstruction\UnattendedRuntime\AtlasSelfConstructionUnattendedRecoveryActionPlanner;
use App\Services\Ai\SelfConstruction\UnattendedRuntime\AtlasSelfConstructionUnattendedStallClassifier;
use Tests\TestCase;

class AtlasSelfConstructionUnattendedRecoveryActionPlannerTest extends TestCase
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

    public function test_healthy_classification_produces_no_actions(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedRecoveryActionPlanner)->plan(
            $this->classification(AtlasSelfConstructionUnattendedStallClassifier::HEALTHY, recoveryNeeded: false),
        );

        self::assertSame([], $verdict['actions']);
        self::assertFalse($verdict['requires_emergency_override']);
    }

    public function test_queue_dry_maps_to_replenisher_actions(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedRecoveryActionPlanner)->plan(
            $this->classification(AtlasSelfConstructionUnattendedStallClassifier::QUEUE_DRY),
        );

        $actionLabels = array_column($verdict['actions'], 'action');
        self::assertContains(AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_RUN_REPLENISHER_DRY_RUN, $actionLabels);
        self::assertContains(AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_APPLY_SAFE_REPLENISHER_PLAN, $actionLabels);
        self::assertFalse($verdict['requires_emergency_override']);
    }

    public function test_heartbeat_stale_maps_to_recover_expired_leases(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedRecoveryActionPlanner)->plan(
            $this->classification(AtlasSelfConstructionUnattendedStallClassifier::HEARTBEAT_STALE),
        );

        $labels = array_column($verdict['actions'], 'action');
        self::assertContains(AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_RECOVER_EXPIRED_LEASES, $labels);
    }

    public function test_unsafe_stop_only_emits_safety_stop_and_requires_emergency_override(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedRecoveryActionPlanner)->plan(
            $this->classification(AtlasSelfConstructionUnattendedStallClassifier::UNSAFE_STOP),
        );

        self::assertTrue($verdict['requires_emergency_override']);
        $labels = array_column($verdict['actions'], 'action');
        self::assertSame([AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_SAFETY_STOP], $labels);
        self::assertNotEmpty($verdict['blocked_actions']);
    }

    public function test_verification_blocked_maps_to_rerun_verification(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedRecoveryActionPlanner)->plan(
            $this->classification(AtlasSelfConstructionUnattendedStallClassifier::VERIFICATION_BLOCKED),
        );

        self::assertSame(
            AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_RERUN_VERIFICATION,
            $verdict['actions'][0]['action'],
        );
    }

    public function test_merge_blocked_maps_to_quarantine(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedRecoveryActionPlanner)->plan(
            $this->classification(AtlasSelfConstructionUnattendedStallClassifier::MERGE_BLOCKED),
        );

        self::assertSame(
            AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_QUARANTINE_POISON_PACKET,
            $verdict['actions'][0]['action'],
        );
    }

    public function test_planner_never_emits_external_worker_actions(): void
    {
        $allLabels = [];
        foreach ([
            AtlasSelfConstructionUnattendedStallClassifier::QUEUE_DRY,
            AtlasSelfConstructionUnattendedStallClassifier::HEARTBEAT_STALE,
            AtlasSelfConstructionUnattendedStallClassifier::WORKER_UNAVAILABLE,
            AtlasSelfConstructionUnattendedStallClassifier::VERIFICATION_BLOCKED,
            AtlasSelfConstructionUnattendedStallClassifier::MERGE_BLOCKED,
            AtlasSelfConstructionUnattendedStallClassifier::REPLENISHER_BLOCKED,
            AtlasSelfConstructionUnattendedStallClassifier::WAITING_ON_DEPENDENCIES,
            AtlasSelfConstructionUnattendedStallClassifier::UNSAFE_STOP,
        ] as $label) {
            $verdict = (new AtlasSelfConstructionUnattendedRecoveryActionPlanner)->plan($this->classification($label));
            foreach ($verdict['actions'] as $action) {
                $allLabels[] = $action['action'];
                self::assertStringNotContainsString('external_worker', $action['action']);
                self::assertStringNotContainsString('manual_progress', $action['action']);
            }
        }
        self::assertNotEmpty($allLabels);
    }

    public function test_plan_hash_is_stable_for_identical_input(): void
    {
        $planner = new AtlasSelfConstructionUnattendedRecoveryActionPlanner();
        $a = $planner->plan($this->classification(AtlasSelfConstructionUnattendedStallClassifier::QUEUE_DRY));
        $b = $planner->plan($this->classification(AtlasSelfConstructionUnattendedStallClassifier::QUEUE_DRY));

        self::assertSame($a['plan_hash'], $b['plan_hash']);
        self::assertStringStartsWith('recovery_plan_', $a['plan_hash']);
    }

    public function test_must_run_now_brain_quota_emits_run_brain_action_with_stable_id_and_atlas_native(): void
    {
        $snapshot = ['facts' => ['brain_quota' => ['must_run_now' => true, 'status' => 'stalled', 'temp_spec_path' => '']]];

        $verdict = (new AtlasSelfConstructionUnattendedRecoveryActionPlanner)->plan(
            $this->classification(AtlasSelfConstructionUnattendedStallClassifier::HEALTHY, recoveryNeeded: false),
            $snapshot,
        );

        $kinds = array_column($verdict['actions'], 'action');
        self::assertContains(AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_RUN_BRAIN_MUST_RUN_NOW, $kinds);

        $brainAction = array_values(array_filter($verdict['actions'], fn ($a) => $a['action'] === AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_RUN_BRAIN_MUST_RUN_NOW))[0];
        self::assertSame('act_run_brain_must_run_now', $brainAction['action_id'], 'action_id must be stable');
        self::assertTrue($brainAction['atlas_native'], 'brain action must be marked atlas_native');
    }

    public function test_done_temp_spec_emits_discard_action_with_stable_id_and_atlas_native(): void
    {
        $snapshot = ['facts' => ['brain_quota' => ['must_run_now' => false, 'status' => 'done', 'temp_spec_path' => '/tmp/brain-spec.json']]];

        $verdict = (new AtlasSelfConstructionUnattendedRecoveryActionPlanner)->plan(
            $this->classification(AtlasSelfConstructionUnattendedStallClassifier::HEALTHY, recoveryNeeded: false),
            $snapshot,
        );

        $kinds = array_column($verdict['actions'], 'action');
        self::assertContains(AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_DISCARD_DONE_TEMP_SPEC, $kinds);

        $discardAction = array_values(array_filter($verdict['actions'], fn ($a) => $a['action'] === AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_DISCARD_DONE_TEMP_SPEC))[0];
        self::assertSame('act_discard_done_temp_spec', $discardAction['action_id'], 'action_id must be stable');
        self::assertTrue($discardAction['atlas_native'], 'discard action must be marked atlas_native');
    }

    public function test_brain_actions_are_deterministic_across_calls(): void
    {
        $snapshot = ['facts' => ['brain_quota' => ['must_run_now' => true, 'status' => '', 'temp_spec_path' => '']]];
        $planner = new AtlasSelfConstructionUnattendedRecoveryActionPlanner;

        $a = $planner->plan($this->classification(AtlasSelfConstructionUnattendedStallClassifier::HEALTHY, false), $snapshot);
        $b = $planner->plan($this->classification(AtlasSelfConstructionUnattendedStallClassifier::HEALTHY, false), $snapshot);

        self::assertSame($a['actions'], $b['actions'], 'brain actions must be deterministic');
        self::assertSame($a['plan_hash'], $b['plan_hash']);
    }

    public function test_planner_blocks_unsafe_action_kinds(): void
    {
        // The planner must never emit provider/git_push/network/unrestricted_shell actions
        $planner = new AtlasSelfConstructionUnattendedRecoveryActionPlanner;
        foreach ([
            AtlasSelfConstructionUnattendedStallClassifier::QUEUE_DRY,
            AtlasSelfConstructionUnattendedStallClassifier::HEARTBEAT_STALE,
            AtlasSelfConstructionUnattendedStallClassifier::MERGE_BLOCKED,
            AtlasSelfConstructionUnattendedStallClassifier::UNSAFE_STOP,
        ] as $label) {
            $verdict = $planner->plan($this->classification($label));
            foreach ($verdict['actions'] as $action) {
                foreach (['provider', 'git_push', 'network', 'unrestricted_shell'] as $forbidden) {
                    self::assertStringNotContainsString($forbidden, $action['action'], "must not emit {$forbidden} action kind for {$label}");
                }
            }
        }
    }
}
