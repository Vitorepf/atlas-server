<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * AP-810 / LHL-13 — Always-On Loop Supervisor (owner AP-809).
 *
 * The watchdog that keeps the long-horizon loop alive WITHOUT ever faking
 * progress. It answers exactly one question:
 *
 *   > Is the supervised loop healthy right now, and if not, is a SAFE restart
 *   > eligible — or must it stay blocked?
 *
 * It monitors (all via input seams — never real probing here):
 *   - heartbeat_age (seconds since the loop last beat);
 *   - orphan provider processes left over from a prior cycle;
 *   - stale loop / merge locks;
 *   - AP-807 / AP-808 / AP-809 certification freshness;
 *   - the operator-visible status string.
 *
 * It CAN recommend a safe restart, propose killing orphan processes, and
 * propose clearing stale locks — but ONLY as a PLAN / recommendation. This
 * service is read-only / deterministic / input-seam driven. It NEVER mutates
 * code, NEVER merges, NEVER deletes a branch/worktree, NEVER spawns or kills a
 * process for real, and NEVER fabricates success.
 *
 * Honesty rules (operator does not accept false claims):
 *   - a restart is REFUSED (status=blocked) when AP-808 chaos/assurance
 *     certification is stale or failed — you do not restart a loop you cannot
 *     currently prove is safe;
 *   - orphan cleanup and lock clearing are ALWAYS plan-only (executed=false);
 *   - status=healthy ONLY when the heartbeat is fresh, assurance is fresh, and
 *     no blocking condition is present;
 *   - blocked is NEVER dressed as healthy or restart_eligible.
 *
 * Contract: AP-809; AP-810 build contract slice LHL-13.
 * Reference shape: LoopPreflightCycleFirewallService (house style).
 */
final class AlwaysOnLoopSupervisorService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.loop_supervisor.v1';

    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_RESTART_ELIGIBLE = 'restart_eligible';

    public const STATUS_BLOCKED = 'blocked';

    /** A heartbeat older than this many seconds means the loop is not beating. */
    public const MAX_HEARTBEAT_AGE_SECONDS = 900;

    /** Certifications older than this many seconds are stale (24h freshness floor). */
    public const MAX_CERT_AGE_SECONDS = 86400;

    /** Assurance (AP-808 chaos) outcomes that block a restart. */
    private const ASSURANCE_FRESH_PASS = 'pass';

    /**
     * Single entrypoint. Every key is optional; the diagnostic default analyzes
     * an unknown/empty state and never crashes. With nothing proven fresh, an
     * empty state is honestly blocked (we never assume health we cannot see).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function assess(array $input = []): array
    {
        // A wiring-phase `fixture` (from --fixture-file) may carry a whole
        // supervisor snapshot; merge it under the explicit input so direct keys
        // still win (input-seam composition).
        $input = $this->mergeFixture($input);

        $area = trim((string) ($input['area'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        $focus = trim((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';
        $runId = trim((string) ($input['run_id'] ?? ''));

        $blockers = [];
        $warnings = [];

        // ---------------------------------------------------------------- heartbeat
        $heartbeat = $this->buildHeartbeat($input, $blockers, $warnings);

        // ---------------------------------------------------------------- environment health
        $orphanCount = max(0, (int) ($input['orphan_provider_processes'] ?? 0));
        $orphanCleanupPlan = $this->buildOrphanCleanupPlan($input, $orphanCount);

        $staleLockNames = $this->resolveStaleLocks($input);
        if ($staleLockNames !== []) {
            // A stale lock is recoverable (plan to clear it) but is a restart
            // pre-condition, not a hard block on its own.
            $warnings[] = 'stale_locks_present';
        }

        // ---------------------------------------------------------------- certification freshness
        $assurance = $this->evaluateAssurance($input, $blockers, $warnings);
        $certifications = $this->evaluateCertifications($input, $warnings);

        // ---------------------------------------------------------------- operator-visible status
        $operatorStatus = trim((string) ($input['operator_visible_status'] ?? ''));
        if ($operatorStatus === '') {
            $warnings[] = 'operator_visible_status_missing';
        }

        // ---------------------------------------------------------------- restart decision
        $restartRequested = (bool) ($input['restart_requested'] ?? false)
            || ! $heartbeat['fresh']
            || $staleLockNames !== []
            || $orphanCount > 0;

        $restartRefusedReason = null;
        $restartRecommended = false;

        // HARD HONESTY RULE: refuse a restart whenever AP-808 chaos/assurance
        // certification is stale or failed. You never restart a loop you cannot
        // currently prove is safe.
        if (! $assurance['fresh_pass']) {
            $restartRefusedReason = $assurance['refuse_reason'];
        } elseif ($restartRequested) {
            // Assurance is a fresh pass AND a restart is warranted => safe restart
            // is eligible. The actual restart is executed elsewhere; here we only
            // recommend it.
            $restartRecommended = true;
        }

        // ---------------------------------------------------------------- status resolution
        $status = $this->resolveStatus(
            $heartbeat,
            $assurance,
            $restartRecommended,
            $restartRefusedReason,
            $blockers,
        );

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-809',
            'slice_id' => 'LHL-13',
            'status' => $status,
            'supervisor_id' => 'lsup_'.substr(MissionCanonicalHash::sha256([
                $area,
                $focus,
                $runId,
                $heartbeat['source'],
                $assurance['status'],
            ]), 0, 16),
            'run_id' => $runId,
            'area' => $area,
            'focus' => $focus,
            'checked_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'heartbeat' => $heartbeat,
            'assurance' => $assurance,
            'certifications' => $certifications,
            'operator_visible_status' => $operatorStatus,
            'stale_locks' => $staleLockNames,
            'restart_requested' => $restartRequested,
            'restart_recommended' => $restartRecommended,
            'restart_refused_reason' => $restartRefusedReason,
            'orphan_cleanup_plan' => $orphanCleanupPlan,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'next_action' => $this->nextAction($status),
            'claim_policy' => [
                'read_only' => true,
                'runs_provider' => false,
                'runs_loop' => false,
                'runs_merge' => false,
                'mutates_code' => false,
                'kills_processes' => false,
                'deletes_branches' => false,
                'cleanup_is_plan_only' => true,
                'blocked_never_dressed_as_healthy' => true,
            ],
        ];

        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->withoutVolatile($payload));

        return $payload;
    }

    // ---------------------------------------------------------------- heartbeat

    /**
     * Build the heartbeat block. A heartbeat is fresh only when an age is known
     * and within the floor.
     *
     * @param  array<string,mixed>  $input
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     * @return array<string,mixed>
     */
    private function buildHeartbeat(array $input, array &$blockers, array &$warnings): array
    {
        $hasAge = array_key_exists('heartbeat_age', $input) || array_key_exists('heartbeat_age_seconds', $input);
        $ageRaw = $input['heartbeat_age'] ?? ($input['heartbeat_age_seconds'] ?? null);
        $age = $ageRaw === null ? null : max(0, (int) $ageRaw);
        $maxAge = (int) ($input['max_heartbeat_age_seconds'] ?? self::MAX_HEARTBEAT_AGE_SECONDS);
        $maxAge = $maxAge > 0 ? $maxAge : self::MAX_HEARTBEAT_AGE_SECONDS;

        $fresh = $hasAge && $age !== null && $age <= $maxAge;

        if (! $hasAge || $age === null) {
            $blockers[] = 'heartbeat_age_unknown';
        } elseif (! $fresh) {
            $blockers[] = 'heartbeat_stale';
            $warnings[] = 'loop_not_beating';
        }

        return [
            'age_seconds' => $age,
            'max_age_seconds' => $maxAge,
            'fresh' => $fresh,
            'source' => trim((string) ($input['heartbeat_source'] ?? 'input_seam')) ?: 'input_seam',
        ];
    }

    // ---------------------------------------------------------------- assurance (AP-808)

    /**
     * Evaluate AP-808 chaos/assurance certification freshness. A restart is only
     * eligible when assurance is a fresh pass; otherwise it is refused.
     *
     * @param  array<string,mixed>  $input
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     * @return array{status:string,age_seconds:?int,fresh_pass:bool,refuse_reason:?string}
     */
    private function evaluateAssurance(array $input, array &$blockers, array &$warnings): array
    {
        $statusRaw = strtolower(trim((string) ($input['assurance_status'] ?? '')));
        $ageRaw = $input['assurance_age_seconds'] ?? ($input['assurance_cert_age_seconds'] ?? null);
        $age = $ageRaw === null ? null : max(0, (int) $ageRaw);
        $maxAge = (int) ($input['max_cert_age_seconds'] ?? self::MAX_CERT_AGE_SECONDS);
        $maxAge = $maxAge > 0 ? $maxAge : self::MAX_CERT_AGE_SECONDS;

        $refuseReason = null;

        if ($statusRaw === '') {
            $refuseReason = 'assurance_status_unknown';
            $warnings[] = 'assurance_certification_unknown';
        } elseif (in_array($statusRaw, ['failed', 'fail', 'blocked'], true)) {
            $refuseReason = 'assurance_certification_failed';
            $blockers[] = 'ap808_assurance_failed';
        } elseif ($age !== null && $age > $maxAge) {
            $refuseReason = 'assurance_certification_stale';
            $blockers[] = 'ap808_assurance_stale';
        } elseif (! in_array($statusRaw, [self::ASSURANCE_FRESH_PASS, 'passed', 'healthy', 'ok'], true)) {
            // Any non-pass status (e.g. degraded, partial) refuses restart.
            $refuseReason = 'assurance_certification_not_passing';
            $warnings[] = 'assurance_certification_not_passing';
        }

        return [
            'status' => $statusRaw !== '' ? $statusRaw : 'unknown',
            'age_seconds' => $age,
            'fresh_pass' => $refuseReason === null,
            'refuse_reason' => $refuseReason,
        ];
    }

    // ---------------------------------------------------------------- certifications (AP-807/808/809)

    /**
     * Surface AP-807/808/809 certification freshness as a read-only view. Stale
     * certs are warnings (assurance staleness is already a hard restart refusal).
     *
     * @param  array<string,mixed>  $input
     * @param  list<string>  $warnings
     * @return array<string,array{status:string,age_seconds:?int,fresh:bool}>
     */
    private function evaluateCertifications(array $input, array &$warnings): array
    {
        $maxAge = (int) ($input['max_cert_age_seconds'] ?? self::MAX_CERT_AGE_SECONDS);
        $maxAge = $maxAge > 0 ? $maxAge : self::MAX_CERT_AGE_SECONDS;

        $certs = is_array($input['certifications'] ?? null) ? $input['certifications'] : [];
        $out = [];

        foreach (['ap807', 'ap808', 'ap809'] as $key) {
            $row = is_array($certs[$key] ?? null) ? $certs[$key] : [];
            $statusRaw = strtolower(trim((string) ($row['status'] ?? '')));
            $ageRaw = $row['age_seconds'] ?? null;
            $age = $ageRaw === null ? null : max(0, (int) $ageRaw);
            $fresh = $statusRaw !== ''
                && in_array($statusRaw, [self::ASSURANCE_FRESH_PASS, 'passed', 'healthy', 'ok', 'available'], true)
                && ($age === null || $age <= $maxAge);

            if ($statusRaw !== '' && ! $fresh) {
                $warnings[] = $key.'_certification_not_fresh';
            }

            $out[$key] = [
                'status' => $statusRaw !== '' ? $statusRaw : 'unknown',
                'age_seconds' => $age,
                'fresh' => $fresh,
            ];
        }

        return $out;
    }

    // ---------------------------------------------------------------- orphan cleanup PLAN

    /**
     * Build a PLAN to kill orphan provider processes. This is a recommendation
     * only — executed=false ALWAYS. We never kill a process here.
     *
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function buildOrphanCleanupPlan(array $input, int $orphanCount): array
    {
        if ($orphanCount <= 0) {
            return [];
        }

        $pids = [];
        foreach ((array) ($input['orphan_provider_pids'] ?? []) as $pid) {
            if (is_int($pid) || (is_string($pid) && $pid !== '' && ctype_digit($pid))) {
                $pids[] = (int) $pid;
            }
        }

        return [[
            'action' => 'kill_orphan_provider_processes',
            'count' => $orphanCount,
            'pids' => $pids,
            'executed' => false,
            'plan_only' => true,
            'reason' => 'orphan_provider_processes_detected',
        ]];
    }

    // ---------------------------------------------------------------- stale locks

    /**
     * @param  array<string,mixed>  $input
     * @return list<string>
     */
    private function resolveStaleLocks(array $input): array
    {
        $names = $this->stringList($input['stale_locks'] ?? []);
        if ((bool) ($input['stale_loop_lock'] ?? false) && ! in_array('loop_lock', $names, true)) {
            $names[] = 'loop_lock';
        }
        if ((bool) ($input['stale_merge_lock'] ?? false) && ! in_array('merge_lock', $names, true)) {
            $names[] = 'merge_lock';
        }

        return array_values(array_unique($names));
    }

    // ---------------------------------------------------------------- status

    /**
     * Resolve the supervisor status. Order matters: blockers and a refused
     * restart force `blocked`; a recommended restart is `restart_eligible`; only
     * a fully clean state is `healthy`.
     *
     * @param  array<string,mixed>  $heartbeat
     * @param  array{status:string,age_seconds:?int,fresh_pass:bool,refuse_reason:?string}  $assurance
     * @param  list<string>  $blockers
     */
    private function resolveStatus(
        array $heartbeat,
        array $assurance,
        bool $restartRecommended,
        ?string $restartRefusedReason,
        array $blockers,
    ): string {
        // A refused restart is always blocked — blocked is never dressed up.
        if ($restartRefusedReason !== null) {
            return self::STATUS_BLOCKED;
        }

        if ($blockers !== []) {
            // Heartbeat stale / unknown with a fresh-pass assurance still produces
            // a restart recommendation; otherwise it stays blocked.
            return $restartRecommended ? self::STATUS_RESTART_ELIGIBLE : self::STATUS_BLOCKED;
        }

        if ($restartRecommended) {
            return self::STATUS_RESTART_ELIGIBLE;
        }

        // Healthy ONLY when the heartbeat is fresh AND assurance is a fresh pass.
        if (($heartbeat['fresh'] ?? false) === true && $assurance['fresh_pass'] === true) {
            return self::STATUS_HEALTHY;
        }

        return self::STATUS_BLOCKED;
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            self::STATUS_HEALTHY => 'continue',
            self::STATUS_RESTART_ELIGIBLE => 'recommend_safe_restart',
            default => 'hold_blocked',
        };
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList($value): array
    {
        return array_values(array_filter(array_map(
            static fn ($item): string => is_string($item) ? trim($item) : '',
            is_array($value) ? $value : [],
        ), static fn (string $item): bool => $item !== ''));
    }

    /**
     * A wiring-phase `fixture` may be a single supervisor snapshot; fold it under
     * the explicit input so direct keys still take precedence.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function mergeFixture(array $input): array
    {
        $fixture = $input['fixture'] ?? null;
        if (! is_array($fixture) || $fixture === []) {
            return $input;
        }
        unset($input['fixture']);

        return array_merge($fixture, $input);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function withoutVolatile(array $payload): array
    {
        unset($payload['checked_at'], $payload['report_hash']);

        return $payload;
    }
}
