<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\UnattendedRuntime;

/**
 * Pure planner mapping {@see AtlasSelfConstructionUnattendedStallClassifier} verdicts into bounded
 * Atlas-native recovery actions. NEVER executes; only emits a deterministic plan envelope.
 *
 * Atlas-native action vocabulary:
 *   - wait_for_dependencies
 *   - run_replenisher_dry_run
 *   - apply_safe_replenisher_plan
 *   - recover_expired_leases
 *   - quarantine_poison_packet
 *   - rerun_verification
 *   - degrade_autonomy_level
 *   - safety_stop
 *
 * `requires_emergency_override` is true only for unsafe_stop classifications.
 */
final class AtlasSelfConstructionUnattendedRecoveryActionPlanner
{
    public const SCHEMA = 'atlas.self_construction.unattended_recovery_action_plan.v1';

    public const ACTION_WAIT_FOR_DEPENDENCIES = 'wait_for_dependencies';

    public const ACTION_RUN_REPLENISHER_DRY_RUN = 'run_replenisher_dry_run';

    public const ACTION_APPLY_SAFE_REPLENISHER_PLAN = 'apply_safe_replenisher_plan';

    public const ACTION_RECOVER_EXPIRED_LEASES = 'recover_expired_leases';

    public const ACTION_QUARANTINE_POISON_PACKET = 'quarantine_poison_packet';

    public const ACTION_RERUN_VERIFICATION = 'rerun_verification';

    public const ACTION_DEGRADE_AUTONOMY_LEVEL = 'degrade_autonomy_level';

    public const ACTION_SAFETY_STOP = 'safety_stop';

    /**
     * @param  array<string,mixed>  $classification
     * @param  array<string,mixed>  $snapshot
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function plan(array $classification, array $snapshot = [], array $options = []): array
    {
        $classLabel = (string) ($classification['classification'] ?? '');
        $recoveryNeeded = (bool) ($classification['recovery_needed'] ?? false);

        $actions = [];
        $blocked = [];
        $emergency = false;

        if (! $recoveryNeeded || $classLabel === AtlasSelfConstructionUnattendedStallClassifier::HEALTHY) {
            return $this->envelope([], [], $classLabel, false, $snapshot);
        }

        switch ($classLabel) {
            case AtlasSelfConstructionUnattendedStallClassifier::UNSAFE_STOP:
                $actions[] = $this->action(self::ACTION_SAFETY_STOP, 'queue safety_stop active — refuse all work until operator clears.');
                $emergency = true;
                $blocked[] = ['action' => self::ACTION_APPLY_SAFE_REPLENISHER_PLAN, 'reason' => 'unsafe_stop'];
                break;

            case AtlasSelfConstructionUnattendedStallClassifier::QUEUE_DRY:
                $actions[] = $this->action(self::ACTION_RUN_REPLENISHER_DRY_RUN, 'queue is dry — propose new packets dry-run first.');
                $actions[] = $this->action(self::ACTION_APPLY_SAFE_REPLENISHER_PLAN, 'apply only safe drafts that pass the quality gate.');
                break;

            case AtlasSelfConstructionUnattendedStallClassifier::REPLENISHER_BLOCKED:
                $actions[] = $this->action(self::ACTION_RUN_REPLENISHER_DRY_RUN, 'last replenisher run blocked — dry-run a fresh plan.');
                break;

            case AtlasSelfConstructionUnattendedStallClassifier::WAITING_ON_DEPENDENCIES:
                $actions[] = $this->action(self::ACTION_WAIT_FOR_DEPENDENCIES, 'queue has depth but nothing claimable — wait for dependencies to clear.');
                break;

            case AtlasSelfConstructionUnattendedStallClassifier::HEARTBEAT_STALE:
                $actions[] = $this->action(self::ACTION_RECOVER_EXPIRED_LEASES, 'heartbeat stale — recover any expired leases so workers can re-claim.');
                break;

            case AtlasSelfConstructionUnattendedStallClassifier::WORKER_UNAVAILABLE:
                $actions[] = $this->action(self::ACTION_DEGRADE_AUTONOMY_LEVEL, 'native worker not ready — degrade autonomy level until worker recovers.');
                break;

            case AtlasSelfConstructionUnattendedStallClassifier::VERIFICATION_BLOCKED:
                $actions[] = $this->action(self::ACTION_RERUN_VERIFICATION, 'verification has failed runs — rerun on a refreshed verifier.');
                break;

            case AtlasSelfConstructionUnattendedStallClassifier::MERGE_BLOCKED:
                $actions[] = $this->action(self::ACTION_QUARANTINE_POISON_PACKET, 'merge governor is blocked — quarantine the offending packet.');
                break;
        }

        // Hard guard: planner never emits external-worker / manual-progress actions.
        foreach ($actions as $action) {
            if (str_contains($action['action'], 'external_worker') || str_contains($action['action'], 'manual_progress')) {
                $blocked[] = ['action' => $action['action'], 'reason' => 'non_atlas_native_action_refused'];
            }
        }

        return $this->envelope($actions, $blocked, $classLabel, $emergency, $snapshot);
    }

    /**
     * @param  list<array<string,mixed>>  $actions
     * @param  list<array<string,mixed>>  $blocked
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    private function envelope(array $actions, array $blocked, string $classification, bool $emergency, array $snapshot): array
    {
        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'classification' => $classification,
            'actions' => $actions,
            'blocked_actions' => $blocked,
            'requires_emergency_override' => $emergency,
            'snapshot_hash' => (string) ($snapshot['snapshot_hash'] ?? ''),
            'plan_hash' => $this->planHash($actions, $blocked, $classification, $emergency),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function action(string $action, string $rationale): array
    {
        return ['action' => $action, 'rationale' => $rationale];
    }

    /**
     * @param  list<array<string,mixed>>  $actions
     * @param  list<array<string,mixed>>  $blocked
     */
    private function planHash(array $actions, array $blocked, string $classification, bool $emergency): string
    {
        $canonical = json_encode([
            'actions' => $actions,
            'blocked' => $blocked,
            'classification' => $classification,
            'emergency' => $emergency,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'recovery_plan_'.substr(hash('sha256', (string) $canonical), 0, 32);
    }
}
