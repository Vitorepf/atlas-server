<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\RuntimeDaemon;

/**
 * Pure, facts-only auditor over a soak-runner report ({@see AtlasSelfConstructionRuntimeSoakRunner}).
 *
 * Detects autonomy regressions, fake-green results, missing recovery, missing evidence, weak
 * heartbeat continuity, scheduler manifest mismatch, and any return of operator/human/provider
 * dependency in ordinary progress. Emits a typed verdict (pass | hold | blocked).
 */
final class AtlasSelfConstructionRuntimeRegressionAuditor
{
    public const SCHEMA = 'atlas.self_construction.runtime_regression_auditor.v1';

    public const VERDICT_PASS = 'pass';

    public const VERDICT_HOLD = 'hold';

    public const VERDICT_BLOCKED = 'blocked';

    /** @var list<string> */
    public const REQUIRED_CASES = [
        'green_cycle',
        'empty_queue_replenish',
        'give_back_repair',
        'failed_gate_hold',
        'stale_heartbeat_recovery',
        'pause_resume',
        'safety_stop',
        'scope_expansion_hold',
    ];

    /** @var list<string> */
    public const RECOVERY_CASES = [
        'empty_queue_replenish',
        'stale_heartbeat_recovery',
        'replenisher_recovery',
        'scope_expansion_admit',
    ];

    public const REGRESSION_QUEUE_HEALTH = 'queue_health';

    public const REGRESSION_PROOF_FRESHNESS = 'proof_freshness';

    public const REGRESSION_WORKER_OUTCOMES = 'worker_outcomes';

    public const REGRESSION_SAFETY_STOP = 'safety_stop';

    public const REGRESSION_LEARNING_LOOP_CONTINUITY = 'learning_loop_continuity';

    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_MEDIUM = 'medium';

    private const DEFAULT_WORKER_FAILURE_RATE_THRESHOLD = 0.2;

    /**
     * @param  array<string,mixed>  $soakReport
     * @param  array<string,mixed>  $facts  {evidence_refs?:list<string>, scheduler_manifest_match?:bool, heartbeat_continuity_breaks?:int}
     * @return array<string,mixed>
     */
    public function audit(array $soakReport, array $facts = []): array
    {
        $blockers = [];
        $warnings = [];
        $regressions = [];

        $passed = (bool) ($soakReport['passed'] ?? false);
        $tickCount = (int) ($soakReport['tick_count'] ?? 0);
        $failedCount = (int) ($soakReport['failed_count'] ?? 0);
        $dependencyViolations = array_values((array) ($soakReport['dependency_violations'] ?? []));
        $tickResults = array_values((array) ($soakReport['tick_results'] ?? []));
        $evidenceRefs = array_values((array) ($facts['evidence_refs'] ?? []));
        $primaryEvidenceRef = $evidenceRefs[0] ?? null;

        $kinds = array_column($tickResults, 'kind');
        $exercisedCases = array_values(array_unique($kinds));

        // AC1/AC2: queue health regression — malformed packets always blocks; a claimable
        // depth below floor is a hold-level signal, not an outright block.
        $queueHealth = is_array($facts['queue_health'] ?? null) ? $facts['queue_health'] : [];
        if ($queueHealth !== []) {
            $malformedInQueue = (int) ($queueHealth['malformed_count'] ?? 0);
            $claimableDepth = (int) ($queueHealth['claimable_depth'] ?? 0);
            $depthFloor = (int) ($queueHealth['depth_floor'] ?? 0);
            if ($malformedInQueue > 0) {
                $regressions[] = $this->regression(self::REGRESSION_QUEUE_HEALTH, self::SEVERITY_HIGH, $primaryEvidenceRef, 'repair malformed packets in the queue before promotion continues', true);
            } elseif ($depthFloor > 0 && $claimableDepth < $depthFloor) {
                $regressions[] = $this->regression(self::REGRESSION_QUEUE_HEALTH, self::SEVERITY_MEDIUM, $primaryEvidenceRef, 'replenish the claimable queue above its depth floor before promotion continues', false);
            }
        }

        // AC1/AC2: worker outcome regression — failure rate above threshold blocks promotion.
        $workerOutcomes = is_array($facts['worker_outcomes'] ?? null) ? $facts['worker_outcomes'] : [];
        if ($workerOutcomes !== []) {
            $failedTasks = max(0, (int) ($workerOutcomes['failed_task_count'] ?? 0));
            $totalTasks = max(0, (int) ($workerOutcomes['total_task_count'] ?? 0));
            $failureThreshold = (float) ($workerOutcomes['failure_rate_threshold'] ?? self::DEFAULT_WORKER_FAILURE_RATE_THRESHOLD);
            if ($totalTasks > 0 && ($failedTasks / $totalTasks) > $failureThreshold) {
                $regressions[] = $this->regression(self::REGRESSION_WORKER_OUTCOMES, self::SEVERITY_HIGH, $primaryEvidenceRef, 'investigate and repair failing worker task outcomes before promotion continues', true);
            }
        }

        // AC1/AC2: safety-stop regression — a safety_stop tick that did NOT actually stop
        // safely is a critical, always-blocking regression.
        foreach ($tickResults as $row) {
            if ((string) ($row['kind'] ?? '') === 'safety_stop' && (string) ($row['classification'] ?? '') !== 'stop') {
                $regressions[] = $this->regression(self::REGRESSION_SAFETY_STOP, self::SEVERITY_CRITICAL, $primaryEvidenceRef, 'review the safety-stop trigger and confirm the stop cause is resolved before promotion continues', true);
            }
        }

        // AC1/AC2: learning loop continuity regression — a hold-level signal, not an outright block.
        $learningLoopBreaks = (int) ($facts['learning_loop_continuity_breaks'] ?? 0);
        if ($learningLoopBreaks > 0) {
            $regressions[] = $this->regression(self::REGRESSION_LEARNING_LOOP_CONTINUITY, self::SEVERITY_MEDIUM, $primaryEvidenceRef, 'restore learning loop continuity (missing outcome-learning evidence sync) before promotion continues', false);
        }

        if ($tickCount === 0) {
            $blockers[] = 'fake_green:tick_count_zero';
        }

        foreach (self::REQUIRED_CASES as $required) {
            if (! in_array($required, $kinds, true)) {
                $blockers[] = 'missing_required_case:'.$required;
            }
        }

        // Duplicate required case without an explicit acknowledged_repeat is not creditable.
        $acknowledgedRepeats = array_values((array) ($facts['acknowledged_repeats'] ?? []));
        $requiredKindCounts = array_count_values(array_filter(
            $kinds,
            static fn (string $k): bool => in_array($k, self::REQUIRED_CASES, true),
        ));
        foreach ($requiredKindCounts as $kind => $count) {
            if ($count > 1 && ! in_array($kind, $acknowledgedRepeats, true)) {
                $blockers[] = 'duplicate_required_case_unacknowledged:'.$kind;
            }
        }

        $recoveryKindsExercised = array_intersect(self::RECOVERY_CASES, $kinds);
        if ($tickCount > 0 && $recoveryKindsExercised === []) {
            $blockers[] = 'missing_recovery:no_recovery_kind_exercised';
        } else {
            // For every exercised recovery kind, the tick result must classify as 'recovered'.
            foreach ($tickResults as $row) {
                if (in_array((string) $row['kind'], self::RECOVERY_CASES, true) && (string) ($row['classification'] ?? '') !== 'recovered') {
                    $blockers[] = 'recovery_case_did_not_recover:'.(string) $row['kind'];
                }
            }
        }

        if ($passed && $evidenceRefs === []) {
            $blockers[] = 'fake_green:success_without_evidence_refs';
            $regressions[] = $this->regression(self::REGRESSION_PROOF_FRESHNESS, self::SEVERITY_HIGH, null, 'attach at least one evidence_ref before promotion continues', true);
        }

        // When recovery cases are exercised, at least 2 evidence refs are required (runtime + recovery proof).
        if ($recoveryKindsExercised !== [] && $passed && count($evidenceRefs) < 2) {
            $blockers[] = 'missing_recovery_evidence:insufficient_refs_for_recovery_proof';
        }

        // Stale evidence: any timestamp older than max_evidence_age_seconds prevents PASS.
        $evidenceTimestamps = array_values((array) ($facts['evidence_timestamps'] ?? []));
        $maxAgeSeconds = (int) ($facts['max_evidence_age_seconds'] ?? 0);
        if ($evidenceTimestamps !== [] && $maxAgeSeconds > 0) {
            $nowUnix = (int) ($facts['now_unix'] ?? time());
            foreach ($evidenceTimestamps as $ts) {
                if (($nowUnix - (int) $ts) > $maxAgeSeconds) {
                    $blockers[] = 'stale_evidence:max_age='.$maxAgeSeconds.'s_exceeded';
                    $regressions[] = $this->regression(self::REGRESSION_PROOF_FRESHNESS, self::SEVERITY_HIGH, $primaryEvidenceRef, 'refresh soak evidence within the max-age window before promotion continues', true);
                    break;
                }
            }
        }

        if ($dependencyViolations !== []) {
            foreach ($dependencyViolations as $v) {
                $blockers[] = 'dependency_regression:'.(string) ($v['violation'] ?? '?').'@'.(string) ($v['tick_kind'] ?? '?');
            }
        }

        if (! $passed) {
            $blockers[] = 'soak_failed:'.($failedCount > 0 ? 'tick_failures='.$failedCount : 'soak_passed_false');
        }

        // Soft / refreshable signals → warnings; do not block on their own.
        if (array_key_exists('scheduler_manifest_match', $facts) && ! (bool) $facts['scheduler_manifest_match']) {
            $warnings[] = 'scheduler_manifest_mismatch';
        }
        if ((int) ($facts['heartbeat_continuity_breaks'] ?? 0) > 0) {
            $warnings[] = 'weak_heartbeat_continuity:'.(int) $facts['heartbeat_continuity_breaks'];
        }

        $hasContractBlocker = $dependencyViolations !== []
            || in_array('fake_green:tick_count_zero', $blockers, true)
            || in_array('fake_green:success_without_evidence_refs', $blockers, true);

        if ($blockers === []) {
            $verdict = $warnings !== [] ? self::VERDICT_HOLD : self::VERDICT_PASS;
        } else {
            $verdict = $hasContractBlocker ? self::VERDICT_BLOCKED : self::VERDICT_HOLD;
        }

        $promotionBlocked = $hasContractBlocker || array_reduce(
            $regressions,
            static fn (bool $carry, array $r): bool => $carry || (bool) $r['promotion_blocked'],
            false,
        );

        $payload = [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'verdict' => $verdict,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'regressions' => $regressions,
            'promotion_blocked' => $promotionBlocked,
            'exercised_cases' => $exercisedCases,
            'evidence_refs' => $evidenceRefs,
            'soak_passed' => $passed,
        ];
        $payload['regression_audit_hash'] = $this->auditHash($payload);

        return $payload;
    }

    /** @return array{type:string, severity:string, evidence_ref:?string, required_repair:string, promotion_blocked:bool} */
    private function regression(string $type, string $severity, ?string $evidenceRef, string $requiredRepair, bool $promotionBlocked): array
    {
        return [
            'type' => $type,
            'severity' => $severity,
            'evidence_ref' => $evidenceRef,
            'required_repair' => $requiredRepair,
            'promotion_blocked' => $promotionBlocked,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function auditHash(array $payload): string
    {
        unset($payload['regression_audit_hash']);
        $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'regression_audit_'.substr(hash('sha256', (string) $canonical), 0, 32);
    }
}
