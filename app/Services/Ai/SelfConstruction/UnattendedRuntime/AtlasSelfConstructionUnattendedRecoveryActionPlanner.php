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
final class AtlasSelfConstructionUnattendedRecoveryActionPlanner implements AtlasSelfConstructionUnattendedRecoveryActionPlannerPort
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

    public const ACTION_RUN_BRAIN_MUST_RUN_NOW = 'run_brain_must_run_now';

    public const ACTION_DISCARD_DONE_TEMP_SPEC = 'discard_done_temp_spec';

    public const ACTION_DIAGNOSE_LEASE_MISMATCH = 'diagnose_lease_mismatch';

    public const ACTION_MONITOR_CLAIMABLE_DRAIN = 'monitor_claimable_drain';

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

        if ($recoveryNeeded && $classLabel !== AtlasSelfConstructionUnattendedStallClassifier::HEALTHY) {
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

                case AtlasSelfConstructionUnattendedStallClassifier::LEASE_LEAK:
                    // Nonblocking mismatch: diagnose + monitor, but let workers keep draining safe tasks.
                    $actions[] = $this->action(self::ACTION_DIAGNOSE_LEASE_MISMATCH, 'lease leak detected — run mismatch repair plan to diagnose and fix.');
                    $actions[] = $this->action(self::ACTION_MONITOR_CLAIMABLE_DRAIN, 'workers can continue draining safe claimable tasks while mismatch is monitored.');
                    break;
            }
        }

        // Brain quota orthogonal actions — checked regardless of main classification.
        $brainQuota = is_array($snapshot['facts']['brain_quota'] ?? null) ? $snapshot['facts']['brain_quota'] : [];
        if ((bool) ($brainQuota['must_run_now'] ?? false)) {
            $actions[] = $this->action(self::ACTION_RUN_BRAIN_MUST_RUN_NOW, 'brain_quota.must_run_now=true: run atlas:brain:next to drain the spec backlog.');
        }
        $tempSpec = (string) ($brainQuota['temp_spec_path'] ?? '');
        if ($tempSpec !== '' && (string) ($brainQuota['status'] ?? '') === 'done') {
            $actions[] = $this->action(self::ACTION_DISCARD_DONE_TEMP_SPEC, "brain_quota temp spec is done (path={$tempSpec}): discard to reclaim resources.");
        }

        // Hard guard: planner never emits non-Atlas-native action kinds.
        foreach ($actions as $a) {
            foreach (['external_worker', 'manual_progress', 'provider', 'git_push', 'network', 'unrestricted_shell'] as $forbidden) {
                if (str_contains($a['action'], $forbidden)) {
                    $blocked[] = ['action' => $a['action'], 'reason' => 'non_atlas_native_action_refused'];
                }
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
        // Determine safe_to_continue_workers and action_priority from classification.
        $safeToContinue = ! $emergency;
        $priority = match (true) {
            $emergency => 'critical',
            $classification === AtlasSelfConstructionUnattendedStallClassifier::LEASE_LEAK => 'medium',
            default => 'normal',
        };
        // atlas:unattended:health does not exist — there is no atlas:unattended:*
        // command at all, so both branches emitted a recheck the operator could
        // never run, and the invented flags (--check-lease-parity, --replenish)
        // had nowhere to land either. atlas:task:health --json is the real
        // read-only surface, and it reports both lease parity and queue depth, so
        // it answers both classifications. A recheck must not mutate: replenishing
        // is a repair, not a re-measurement, so it is deliberately not called here.
        $recheckCommand = match ($classification) {
            AtlasSelfConstructionUnattendedStallClassifier::LEASE_LEAK,
            AtlasSelfConstructionUnattendedStallClassifier::QUEUE_DRY,
            AtlasSelfConstructionUnattendedStallClassifier::REPLENISHER_BLOCKED => 'php artisan atlas:task:health --json',
            default => null,
        };

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'classification' => $classification,
            'actions' => $actions,
            'blocked_actions' => $blocked,
            'requires_emergency_override' => $emergency,
            'snapshot_hash' => (string) ($snapshot['snapshot_hash'] ?? ''),
            'plan_hash' => $this->planHash($actions, $blocked, $classification, $emergency),
            'action_priority' => $priority,
            'safe_to_continue_workers' => $safeToContinue,
            'recheck_command' => $recheckCommand,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function action(string $action, string $rationale): array
    {
        return ['action' => $action, 'action_id' => 'act_'.$action, 'atlas_native' => true, 'rationale' => $rationale];
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
