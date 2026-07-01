<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\UnattendedRuntime;

/**
 * Pure deterministic classifier over an {@see AtlasSelfConstructionUnattendedLivenessSnapshot}
 * envelope. NO I/O, NO providers, NO scalar scoring.
 *
 * Classifications follow a fixed precedence (most severe first):
 *   1.  unsafe_stop                — queue.safety_stop=true
 *   2.  heartbeat_stale           — heartbeat.is_stale=true
 *   3.  merge_blocked             — merge.blocked=true
 *   4.  verification_blocked      — verification.failed_run_count > 0
 *   5.  replenisher_blocked       — replenisher.last_run_status='blocked'
 *   6.  worker_unavailable        — native_worker.ready=false
 *   7.  stale_brain_heartbeat     — brain_quota.stall_reason='stale_brain_heartbeat'
 *   8.  stalled_before_quota      — brain_quota.stall_reason='stalled_before_quota' or remaining=0
 *   9.  temp_spec_already_done    — brain_quota.stall_reason='temp_spec_already_done'
 *   10. zero_active_brain_commands — brain_quota.active_brain_commands=0 and brain_quota.status≠''
 *   11. queue_dry                 — queue.depth=0 and queue.claimable_count=0
 *   12. waiting_on_dependencies   — queue has depth but claimable_count=0 and dependencies pending
 *   13. healthy                   — otherwise
 *
 * Severity enum: 'none', 'low', 'medium', 'high', 'critical'.
 * Recovery_needed is true for any classification other than 'healthy'.
 */
final class AtlasSelfConstructionUnattendedStallClassifier
{
    public const SCHEMA = 'atlas.self_construction.unattended_stall_classifier.v1';

    public const HEALTHY = 'healthy';

    public const WAITING_ON_DEPENDENCIES = 'waiting_on_dependencies';

    public const QUEUE_DRY = 'queue_dry';

    public const WORKER_UNAVAILABLE = 'worker_unavailable';

    public const HEARTBEAT_STALE = 'heartbeat_stale';

    public const VERIFICATION_BLOCKED = 'verification_blocked';

    public const MERGE_BLOCKED = 'merge_blocked';

    public const REPLENISHER_BLOCKED = 'replenisher_blocked';

    public const UNSAFE_STOP = 'unsafe_stop';

    public const STALE_BRAIN_HEARTBEAT = 'stale_brain_heartbeat';

    public const TEMP_SPEC_ALREADY_DONE = 'temp_spec_already_done';

    public const STALLED_BEFORE_QUOTA = 'stalled_before_quota';

    public const ZERO_ACTIVE_BRAIN_COMMANDS = 'zero_active_brain_commands';

    public const FEED_STARVATION_RISK = 'feed_starvation_risk';

    public const LEASE_LEAK = 'lease_leak';

    public const ACTION_REAP_LEASES = 'atlas:acp:reap-leases';

    /** claimable_count / active_leases at or below this ratio is a thin buffer. */
    private const FEED_STARVATION_RATIO_CEILING = 2.0;

    public const SEVERITY_NONE = 'none';

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    public const STALL_NO_CLAIMABLE = 'no_claimable';

    public const STALL_MALFORMED_QUEUE = 'malformed_queue';

    public const STALL_POISON_LOOP = 'poison_loop';

    public const STALL_STALE_HEARTBEAT = 'stale_heartbeat';

    public const STALL_WORKER_STARVATION = 'worker_starvation';

    public const STALL_PROOF_BLOCKED = 'proof_blocked';

    public const STALL_LEARNING_STALE = 'learning_stale';

    public const STALL_NONE = 'none';

    /** repeated poison count at or above this is treated as a loop, not an isolated poison event. */
    private const POISON_LOOP_REPEAT_CEILING = 3;

    /** stall_class => [recovery_action, safe_to_auto_recover, evidence_needed] */
    private const STALL_ACTION_CATALOG = [
        self::STALL_MALFORMED_QUEUE => ['atlas:task:sweep-malformed', true, ['queue.malformed_count']],
        self::STALL_POISON_LOOP => ['quarantine_repeated_poison_and_alert_for_review', false, ['queue.poison_loop_detected', 'queue.repeated_poison_count']],
        self::STALL_STALE_HEARTBEAT => ['restart_heartbeat_emitter', true, ['heartbeat.last_seen_age_seconds', 'heartbeat.stale_threshold_seconds']],
        self::STALL_WORKER_STARVATION => ['scale_up_native_workers_or_wait_for_capacity', true, ['native_worker.ready', 'queue.active_leases', 'queue.claimable_count']],
        self::STALL_PROOF_BLOCKED => ['attach_missing_proof_or_fix_failing_verification', false, ['verification.failed_run_count', 'verification.proof_missing']],
        self::STALL_LEARNING_STALE => ['run_learning_sync', true, ['learning.last_sync_age_seconds']],
        self::STALL_NO_CLAIMABLE => ['wait_or_originate_more_claimable_work', true, ['queue.claimable_count', 'queue.depth']],
        self::STALL_NONE => [null, true, []],
    ];

    /**
     * Classifies unattended runtime stalls into a REPAIRABLE action — never a generic "stuck" state.
     * Distinguishes: no_claimable, malformed_queue, poison_loop, stale_heartbeat, worker_starvation,
     * proof_blocked, and learning_stale. Precedence (most structurally unsafe first): malformed_queue
     * > poison_loop > stale_heartbeat > worker_starvation > proof_blocked > learning_stale >
     * no_claimable > none.
     *
     * @param  array<string,mixed>  $snapshot
     * @return array{schema_version:string, stall_class:string, reasons:list<string>, recovery_action:?string, safe_to_auto_recover:bool, evidence_needed:list<string>}
     */
    public function classifyStallAction(array $snapshot): array
    {
        $facts = is_array($snapshot['facts'] ?? null) ? $snapshot['facts'] : [];
        $queue = is_array($facts['queue'] ?? null) ? $facts['queue'] : [];
        $heartbeat = is_array($facts['heartbeat'] ?? null) ? $facts['heartbeat'] : [];
        $worker = is_array($facts['native_worker'] ?? null) ? $facts['native_worker'] : [];
        $verification = is_array($facts['verification'] ?? null) ? $facts['verification'] : [];
        $learning = is_array($facts['learning'] ?? null) ? $facts['learning'] : [];

        $stallClass = self::STALL_NONE;
        $reasons = [];

        if ((int) ($queue['malformed_count'] ?? 0) > 0) {
            $stallClass = self::STALL_MALFORMED_QUEUE;
            $reasons[] = 'queue_malformed_count_positive';
        } elseif ((bool) ($queue['poison_loop_detected'] ?? false) || (int) ($queue['repeated_poison_count'] ?? 0) >= self::POISON_LOOP_REPEAT_CEILING) {
            $stallClass = self::STALL_POISON_LOOP;
            $reasons[] = 'repeated_poison_signal_at_or_above_ceiling';
        } elseif ((bool) ($heartbeat['is_stale'] ?? false)) {
            $stallClass = self::STALL_STALE_HEARTBEAT;
            $reasons[] = 'heartbeat_stale';
        } elseif (! (bool) ($worker['ready'] ?? true) || $this->feedStarvationRisk($queue)) {
            $stallClass = self::STALL_WORKER_STARVATION;
            $reasons[] = ! (bool) ($worker['ready'] ?? true) ? 'native_worker_not_ready' : 'claimable_per_active_worker_thin';
        } elseif ((int) ($verification['failed_run_count'] ?? 0) > 0 || (bool) ($verification['proof_missing'] ?? false)) {
            $stallClass = self::STALL_PROOF_BLOCKED;
            $reasons[] = (int) ($verification['failed_run_count'] ?? 0) > 0 ? 'verification_failed_runs' : 'verification_proof_missing';
        } elseif ((bool) ($learning['stale'] ?? false)) {
            $stallClass = self::STALL_LEARNING_STALE;
            $reasons[] = 'learning_sync_stale';
        } elseif ((int) ($queue['claimable_count'] ?? 0) === 0) {
            $stallClass = self::STALL_NO_CLAIMABLE;
            $reasons[] = 'queue_claimable_count_zero';
        }

        [$recoveryAction, $safeToAutoRecover, $evidenceNeeded] = self::STALL_ACTION_CATALOG[$stallClass];

        return [
            'schema_version' => self::SCHEMA,
            'stall_class' => $stallClass,
            'reasons' => $reasons,
            'recovery_action' => $recoveryAction,
            'safe_to_auto_recover' => $safeToAutoRecover,
            'evidence_needed' => $evidenceNeeded,
        ];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    public function classify(array $snapshot): array
    {
        $facts = is_array($snapshot['facts'] ?? null) ? $snapshot['facts'] : [];
        $queue = is_array($facts['queue'] ?? null) ? $facts['queue'] : [];
        $heartbeat = is_array($facts['heartbeat'] ?? null) ? $facts['heartbeat'] : [];
        $worker = is_array($facts['native_worker'] ?? null) ? $facts['native_worker'] : [];
        $verification = is_array($facts['verification'] ?? null) ? $facts['verification'] : [];
        $merge = is_array($facts['merge'] ?? null) ? $facts['merge'] : [];
        $replenisher = is_array($facts['replenisher'] ?? null) ? $facts['replenisher'] : [];
        $brainQuota = is_array($facts['brain_quota'] ?? null) ? $facts['brain_quota'] : [];
        $brainStallReason = (string) ($brainQuota['stall_reason'] ?? '');
        $brainStatus = (string) ($brainQuota['status'] ?? '');

        $reasons = [];
        $classification = self::HEALTHY;
        $severity = self::SEVERITY_NONE;
        $recommendedAction = null;

        if ((bool) ($queue['safety_stop'] ?? false)) {
            $classification = self::UNSAFE_STOP;
            $severity = self::SEVERITY_CRITICAL;
            $reasons[] = 'queue_safety_stop_active';
        } elseif ((bool) ($heartbeat['is_stale'] ?? false)) {
            $classification = self::HEARTBEAT_STALE;
            $severity = self::SEVERITY_HIGH;
            $reasons[] = 'heartbeat_stale';
        } elseif ((bool) ($merge['blocked'] ?? false)) {
            $classification = self::MERGE_BLOCKED;
            $severity = self::SEVERITY_HIGH;
            $reasons[] = 'merge_blocked';
        } elseif ((int) ($verification['failed_run_count'] ?? 0) > 0) {
            $classification = self::VERIFICATION_BLOCKED;
            $severity = self::SEVERITY_HIGH;
            $reasons[] = 'verification_failed_runs';
        } elseif ((string) ($replenisher['last_run_status'] ?? '') === 'blocked') {
            $classification = self::REPLENISHER_BLOCKED;
            $severity = self::SEVERITY_MEDIUM;
            $reasons[] = 'replenisher_last_run_blocked';
        } elseif (! (bool) ($worker['ready'] ?? false)) {
            $classification = self::WORKER_UNAVAILABLE;
            $severity = self::SEVERITY_HIGH;
            $reasons[] = 'native_worker_not_ready';
        } elseif ($this->leaseLeakDetected($queue)) {
            $classification = self::LEASE_LEAK;
            $severity = self::SEVERITY_MEDIUM;
            $recommendedAction = self::ACTION_REAP_LEASES;
            $reasons[] = (bool) ($queue['lease_leak_detected'] ?? false) ? 'lease_leak_detected' : 'leases_match_claimed_false';
        } elseif ($this->feedStarvationRisk($queue)) {
            $classification = self::FEED_STARVATION_RISK;
            $severity = self::SEVERITY_MEDIUM;
            $reasons[] = 'claimable_per_active_worker_at_or_below_ceiling_with_recent_no_claimable';
        } elseif ($brainStallReason === 'stale_brain_heartbeat') {
            $classification = self::STALE_BRAIN_HEARTBEAT;
            $severity = self::SEVERITY_MEDIUM;
            $reasons[] = 'brain_quota_stall_reason_stale_brain_heartbeat';
        } elseif ($brainStallReason === 'stalled_before_quota' || ($brainQuota !== [] && ($brainQuota['remaining'] ?? null) === 0)) {
            $classification = self::STALLED_BEFORE_QUOTA;
            $severity = self::SEVERITY_MEDIUM;
            $reasons[] = 'brain_quota_stall_reason_stalled_before_quota';
        } elseif ($brainStallReason === 'temp_spec_already_done') {
            $classification = self::TEMP_SPEC_ALREADY_DONE;
            $severity = self::SEVERITY_LOW;
            $reasons[] = 'brain_quota_stall_reason_temp_spec_already_done';
        } elseif ($brainStatus !== '' && (int) ($brainQuota['active_brain_commands'] ?? -1) === 0) {
            $classification = self::ZERO_ACTIVE_BRAIN_COMMANDS;
            $severity = self::SEVERITY_LOW;
            $reasons[] = 'brain_quota_active_brain_commands_zero';
        } elseif ((int) ($queue['depth'] ?? 0) === 0 && (int) ($queue['claimable_count'] ?? 0) === 0) {
            $classification = self::QUEUE_DRY;
            $severity = self::SEVERITY_LOW;
            $reasons[] = 'queue_depth_zero';
        } elseif ((int) ($queue['claimable_count'] ?? 0) === 0 && (int) ($queue['depth'] ?? 0) > 0) {
            $classification = self::WAITING_ON_DEPENDENCIES;
            $severity = self::SEVERITY_LOW;
            $reasons[] = 'queue_has_depth_but_nothing_claimable';
        }

        $recoveryNeeded = $classification !== self::HEALTHY;

        $classifierHash = $this->classifierHash($classification, $severity, $reasons, $facts);

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'classification' => $classification,
            'severity' => $severity,
            'reasons' => $reasons,
            'recovery_needed' => $recoveryNeeded,
            'recommended_action' => $recommendedAction,
            'classifier_hash' => $classifierHash,
        ];
    }

    /**
     * Detects a recoverable lease/claim mismatch — the same cheap, safe signal
     * {@see \App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskServingLeaseMismatchRepairPlan}
     * resolves via `atlas:acp:reap-leases` — so an unattended runtime never idles on a stall that a
     * single safe normalization command would clear.
     *
     * @param  array<string,mixed>  $queue
     */
    private function leaseLeakDetected(array $queue): bool
    {
        return (bool) ($queue['lease_leak_detected'] ?? false)
            || ($queue['leases_match_claimed'] ?? true) === false;
    }

    /**
     * Detects a thin claimable buffer BEFORE workers actually stall: active workers are drawing
     * down a claimable pool that is already near-empty per worker, and the queue has recently
     * surfaced no_claimable observations — a leading indicator, not a confirmed dry queue.
     *
     * @param  array<string,mixed>  $queue
     */
    private function feedStarvationRisk(array $queue): bool
    {
        $activeLeases = (int) ($queue['active_leases'] ?? 0);
        $claimableDepth = (int) ($queue['claimable_count'] ?? $queue['claimable_depth'] ?? 0);
        $recentNoClaimable = (int) ($queue['recent_no_claimable_count'] ?? 0);

        if ($activeLeases <= 0 || $recentNoClaimable <= 0) {
            return false;
        }

        $claimablePerActiveWorker = $claimableDepth / $activeLeases;

        return $claimablePerActiveWorker <= self::FEED_STARVATION_RATIO_CEILING;
    }

    /**
     * @param  list<string>  $reasons
     * @param  array<string,mixed>  $facts
     */
    private function classifierHash(string $classification, string $severity, array $reasons, array $facts): string
    {
        $canonical = json_encode([
            'classification' => $classification,
            'severity' => $severity,
            'reasons' => $reasons,
            'facts' => $facts,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'classifier_'.substr(hash('sha256', (string) $canonical), 0, 32);
    }
}
