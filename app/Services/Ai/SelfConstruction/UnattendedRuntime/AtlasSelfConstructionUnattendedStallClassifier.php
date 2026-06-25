<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\UnattendedRuntime;

/**
 * Pure deterministic classifier over an {@see AtlasSelfConstructionUnattendedLivenessSnapshot}
 * envelope. NO I/O, NO providers, NO scalar scoring.
 *
 * Classifications follow a fixed precedence (most severe first):
 *   1. unsafe_stop         — queue.safety_stop=true
 *   2. heartbeat_stale     — heartbeat.is_stale=true
 *   3. merge_blocked       — merge.blocked=true
 *   4. verification_blocked — verification.failed_run_count > 0
 *   5. replenisher_blocked — replenisher.last_run_status='blocked'
 *   6. worker_unavailable  — native_worker.ready=false
 *   7. queue_dry           — queue.depth=0 and queue.claimable_count=0
 *   8. waiting_on_dependencies — queue has depth but claimable_count=0 and dependencies pending
 *   9. healthy             — otherwise
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

    public const SEVERITY_NONE = 'none';

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

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

        $reasons = [];
        $classification = self::HEALTHY;
        $severity = self::SEVERITY_NONE;

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
            'classifier_hash' => $classifierHash,
        ];
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
