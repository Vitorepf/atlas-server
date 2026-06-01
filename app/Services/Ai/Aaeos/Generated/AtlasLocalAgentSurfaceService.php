<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Local Agent Surface — pure, deterministic background-job claim gate.
 *
 * The Mac Agent is a LOCAL surface/automation for power, wake, readiness and
 * background jobs. It is NOT mother-architecture: it is subordinate to the
 * Kernel/Operating System. This service turns the doc's "Fronteira" boundary
 * (the explicit CAN / CANNOT lists) into a deterministic decision: given a
 * background-job claim request, local readiness and the governance attestations
 * the Kernel requires, decide whether the local agent may claim the job.
 *
 * Contract (from "Fronteira" + frontmatter decisions):
 *   Entrada: claim request (job_class, local readiness signals, watchdog set,
 *            and the governance attestations: receipt, policy, evidence).
 *   Saida:   decision (allow|block), blocking reasons, the documented invariant
 *            each reason maps to, and a machine-readable evidence envelope.
 *
 * Documented invariants this code enforces (each is load-bearing and tested):
 *   - "bloquear background jobs quando readiness local falha" (CAN)
 *       => when any local readiness signal is not ready, the claim is BLOCKED.
 *   - "Readiness local pode bloquear claim de jobs, mas nao bypassa Kernel
 *      policy, receipt ou evidence." (frontmatter decision)
 *       => readiness=green is NEVER sufficient. A missing receipt, denied policy
 *          or absent evidence ALWAYS blocks, even when readiness is fully green.
 *          Readiness can only ever REMOVE the claim, never GRANT it past Kernel.
 *   - "executar provider ou tool sem receipt/policy" (CANNOT)
 *       => no signed receipt OR policy not allowed => BLOCK.
 *   - "elevar permissao por estar local" (CANNOT)
 *       => an `elevate_request` / `local_override` flag is rejected as a hard
 *          stop: being local never raises the permission the Kernel granted.
 *   - "esconder falha de readiness" (CANNOT)
 *       => readiness failures are always surfaced in `readiness_failures`; a
 *          request that asks to suppress them (`suppress_readiness_failure`) is
 *          itself a blocking violation.
 *   - "depender de push/inbox como unico watchdog" (CANNOT)
 *       => if the only declared watchdog is push/inbox, the claim is BLOCKED:
 *          a local heartbeat watchdog is required.
 *
 * Non-goals honoured (read-only decision):
 *   - It does NOT execute the job, does NOT mutate power state, does NOT call
 *     caffeinate/pmset, and does NOT replace Kernel policy — it only decides
 *     whether a local claim is permitted and emits evidence.
 *
 * @see docs/engineering-knowledge-base/atlas-local-agent-surface.md
 */
final class AtlasLocalAgentSurfaceService
{
    /** Stable evidence schema id this gate emits. */
    public const SCHEMA = 'atlas.local_agent_surface.claim_gate.v1';

    /** Decisions (closed set). */
    public const DECISION_ALLOW = 'allow';
    public const DECISION_BLOCK = 'block';

    /** Documented invariant ids each blocking reason maps to. */
    public const INV_READINESS_LOCAL = 'readiness_blocks_claim';
    public const INV_NO_BYPASS_KERNEL = 'readiness_never_bypasses_kernel';
    public const INV_RECEIPT_REQUIRED = 'no_run_without_receipt';
    public const INV_POLICY_REQUIRED = 'no_run_without_policy';
    public const INV_EVIDENCE_REQUIRED = 'no_run_without_evidence';
    public const INV_NO_LOCAL_ELEVATION = 'no_elevation_by_locality';
    public const INV_NO_HIDDEN_READINESS = 'no_hidden_readiness_failure';
    public const INV_WATCHDOG_REQUIRED = 'no_push_inbox_only_watchdog';

    /**
     * Local readiness signals the surface owns. Each must be `true` for the
     * local readiness check to pass. Mirrors "manter heartbeat local; segurar o
     * Mac acordado; reconciliar caffeinate" from the CAN list.
     *
     * @var array<string,string>
     */
    private const READINESS_SIGNALS = [
        'agent_online' => 'Mac Agent heartbeat is stale or the agent is offline/sleeping.',
        'caffeinate_reconciled' => 'caffeinate power retention is unavailable or has orphans.',
        'awake_for_session' => 'The Mac is not held awake for the session window.',
    ];

    /** Watchdog kinds that may NOT be the sole watchdog. */
    private const NON_AUTONOMOUS_WATCHDOGS = ['push', 'inbox'];

    /**
     * Decide whether the local agent may claim a background job.
     *
     * @param array<string,mixed> $request
     *        job_class                  : string (free label, e.g. "stewardship_loop")
     *        readiness                  : array<string,bool> keyed by READINESS_SIGNALS
     *        watchdogs                  : list<string> declared watchdog kinds
     *        receipt_signed             : bool   Kernel-issued decision receipt present
     *        policy_allowed             : bool   Kernel policy permits this job
     *        evidence_present           : bool   evidence sink is wired
     *        elevate_request            : bool   asks to raise permission because local (hard stop)
     *        local_override             : bool   alias for elevate_request
     *        suppress_readiness_failure : bool   asks to hide readiness failure (hard stop)
     *
     * @return array<string,mixed> the evidence envelope + decision
     */
    public function decideClaim(array $request): array
    {
        $jobClass = $this->str($request['job_class'] ?? null) ?? 'unspecified';

        $readiness = $this->normalizeReadiness($request['readiness'] ?? []);
        $watchdogs = $this->normalizeWatchdogs($request['watchdogs'] ?? []);

        $receiptSigned = $this->bool($request['receipt_signed'] ?? null);
        $policyAllowed = $this->bool($request['policy_allowed'] ?? null);
        $evidencePresent = $this->bool($request['evidence_present'] ?? null);
        $elevate = $this->bool($request['elevate_request'] ?? null) || $this->bool($request['local_override'] ?? null);
        $suppressReadiness = $this->bool($request['suppress_readiness_failure'] ?? null);

        $reasons = [];
        $readinessFailures = [];

        // --- Kernel governance gate (evaluated FIRST and INDEPENDENTLY of
        // readiness, so green readiness can never bypass it). ----------------
        if (! $receiptSigned) {
            $reasons[] = $this->reason(
                self::INV_RECEIPT_REQUIRED,
                'No signed Kernel decision receipt: a local agent cannot run a provider/tool without a receipt.',
            );
        }

        if (! $policyAllowed) {
            $reasons[] = $this->reason(
                self::INV_POLICY_REQUIRED,
                'Kernel policy does not permit this job: locality does not grant policy.',
            );
        }

        if (! $evidencePresent) {
            $reasons[] = $this->reason(
                self::INV_EVIDENCE_REQUIRED,
                'No evidence sink wired: the local agent cannot run without producing evidence.',
            );
        }

        // --- Elevation-by-locality is a hard stop. --------------------------
        if ($elevate) {
            $reasons[] = $this->reason(
                self::INV_NO_LOCAL_ELEVATION,
                'Request asks to elevate permission because it is local; locality never raises Kernel-granted permission.',
            );
        }

        // --- Readiness must never be hidden. --------------------------------
        if ($suppressReadiness) {
            $reasons[] = $this->reason(
                self::INV_NO_HIDDEN_READINESS,
                'Request asks to suppress readiness failure; readiness failures must always be surfaced.',
            );
        }

        // --- Local readiness gate (can BLOCK, never GRANT). -----------------
        foreach (self::READINESS_SIGNALS as $signal => $message) {
            if (($readiness[$signal] ?? false) !== true) {
                $readinessFailures[] = ['signal' => $signal, 'message' => $message];
                $reasons[] = $this->reason(self::INV_READINESS_LOCAL, $message, $signal);
            }
        }

        // --- Watchdog gate: push/inbox may not be the sole watchdog. --------
        if (! $this->hasAutonomousWatchdog($watchdogs)) {
            $reasons[] = $this->reason(
                self::INV_WATCHDOG_REQUIRED,
                'No autonomous local watchdog (heartbeat) declared; push/inbox cannot be the only watchdog.',
            );
        }

        $kernelGoverned = $receiptSigned && $policyAllowed && $evidencePresent && ! $elevate;
        $localReady = $readinessFailures === [] && ! $suppressReadiness;
        $watchdogOk = $this->hasAutonomousWatchdog($watchdogs);

        $decision = $reasons === [] ? self::DECISION_ALLOW : self::DECISION_BLOCK;

        return [
            'schema' => self::SCHEMA,
            'decision' => $decision,
            'job_class' => $jobClass,
            'kernel_governed' => $kernelGoverned,
            'local_ready' => $localReady,
            'watchdog_ok' => $watchdogOk,
            'readiness_failures' => $readinessFailures,
            'blocking_reasons' => $reasons,
            'evidence_ready' => true,
            'claim_allowed' => $decision === self::DECISION_ALLOW,
        ];
    }

    /**
     * Convenience predicate for the claim path: may the local agent claim this?
     */
    public function mayClaim(array $request): bool
    {
        return $this->decideClaim($request)['decision'] === self::DECISION_ALLOW;
    }

    /**
     * Does the watchdog set contain at least one autonomous (non push/inbox)
     * watchdog? Enforces "nao depender de push/inbox como unico watchdog".
     *
     * @param list<string> $watchdogs
     */
    public function hasAutonomousWatchdog(array $watchdogs): bool
    {
        foreach ($watchdogs as $w) {
            if (! in_array($w, self::NON_AUTONOMOUS_WATCHDOGS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $readiness
     * @return array<string,bool>
     */
    private function normalizeReadiness(mixed $readiness): array
    {
        $out = [];
        if (is_array($readiness)) {
            foreach (self::READINESS_SIGNALS as $signal => $_message) {
                $out[$signal] = ($readiness[$signal] ?? false) === true;
            }
        }

        return $out;
    }

    /**
     * @param mixed $watchdogs
     * @return list<string>
     */
    private function normalizeWatchdogs(mixed $watchdogs): array
    {
        if (! is_array($watchdogs)) {
            return [];
        }

        $clean = [];
        foreach ($watchdogs as $w) {
            if (is_string($w) && trim($w) !== '') {
                $clean[] = strtolower(trim($w));
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * @return array{invariant:string,message:string,signal?:string}
     */
    private function reason(string $invariant, string $message, ?string $signal = null): array
    {
        $row = ['invariant' => $invariant, 'message' => $message];
        if ($signal !== null) {
            $row['signal'] = $signal;
        }

        return $row;
    }

    private function bool(mixed $v): bool
    {
        return $v === true;
    }

    private function str(mixed $v): ?string
    {
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }

        return null;
    }
}
