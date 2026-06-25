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
}
