<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Forge Obra Enterprise Loop Upgrade — pure, deterministic gate engine for
 * the Forge enterprise loop documented in
 * atlas-forge-obra-enterprise-loop-upgrade.md.
 *
 * The doc upgrades the Atlas Dev senior-engineer loop into the heavier, governed
 * Forge "Obra" scale: audit before heavy execution, Obra execution receipt,
 * repair ledger, incident capsule, evidence pack and completion gate. This
 * service turns that contract into runtime: given an Obra's state it answers the
 * three hard gating questions of the documented flow and never lets the Obra
 * skip a governed step.
 *
 * What it enforces (one public method per documented gate):
 *   1. auditEnterpriseReadiness()  -> `atlas.forge.enterprise_engineering_audit.v1`
 *        Step 3-4 of "Fluxo": run the audit, BLOCK if strict audit fails.
 *        "Nunca declare completion sem evidence pack e completion gate" starts
 *        here: an Obra that is not ready (SDD, workspace, provider topology,
 *        rollback, work packets, completion gate) is blocked honestly with the
 *        documented `blockers` list. Risco enforced: "Criar audit aspiracional
 *        sem command strict" — strict mode is a real hard stop, not a label.
 *
 *   2. evaluateRepairLoop()        -> `atlas.forge.repair_ledger.v1` + capsule
 *        Step 8 of "Fluxo": on failure, register repair ledger and, when the
 *        bounded repair budget is exhausted (or the phase needs a human), open
 *        an `atlas.forge.incident_capsule.v1`. "Nunca esconda fallback, blocker,
 *        provider failure ou repair attempt" -> every attempt and fallback is
 *        surfaced, never silent.
 *
 *   3. evaluateCompletionGate()    -> `atlas.forge.obra_execution_receipt.v1`
 *        Step 9-12 of "Fluxo": no release/promotion claim without an evidence
 *        pack AND a passed completion gate. Open blockers or open incident
 *        capsules force `release_decision = hold`. Architectural learning is
 *        never auto-applied: it is routed to the curator/approval gate
 *        ("Nunca aplique learning arquitetural sem curator/approval").
 *
 * Forge is NOT a copy of Dev (quality gate `forge-not-dev-copy`): every audit and
 * receipt requires the Obra-scale signals — SDD, phases, work packets and
 * provider decisions — or it is blocked as `not_obra_scale`.
 *
 * The service is read-only: it classifies state and emits receipts/blockers. It
 * never executes a phase, calls a provider, applies a repair, mutates a codebase
 * or touches the database.
 *
 * @see docs/engineering-knowledge-base/atlas-forge-obra-enterprise-loop-upgrade.md
 */
final class AtlasForgeObraEnterpriseLoopUpgradeService
{
    /** Stable receipt schema ids (from the doc "Contratos"). */
    public const SCHEMA_AUDIT = 'atlas.forge.enterprise_engineering_audit.v1';
    public const SCHEMA_EXECUTION_RECEIPT = 'atlas.forge.obra_execution_receipt.v1';
    public const SCHEMA_REPAIR_LEDGER = 'atlas.forge.repair_ledger.v1';
    public const SCHEMA_INCIDENT_CAPSULE = 'atlas.forge.incident_capsule.v1';

    /** Audit strict statuses (closed set). */
    public const AUDIT_READY = 'ready';
    public const AUDIT_BLOCKED = 'blocked';

    /** Repair loop actions (closed set). */
    public const REPAIR_RETRY = 'repair';
    public const REPAIR_INCIDENT = 'incident_capsule';

    /** Release / promotion decisions (closed set). */
    public const RELEASE_PROMOTE = 'promote';
    public const RELEASE_HOLD = 'hold';

    /**
     * Readiness flags the audit requires before heavy execution. These are the
     * "Campos minimos" of `enterprise_engineering_audit.v1` that gate the Obra.
     *
     * @var list<string>
     */
    private const REQUIRED_READINESS = [
        'sdd_ready',
        'workspace_ready',
        'provider_topology_ready',
        'rollback_ready',
        'work_packets_ready',
        'completion_gate_ready',
    ];

    /**
     * Obra-scale signals that separate a Forge Obra from a flat Dev task
     * (quality gate `forge-not-dev-copy`). Absence of any => `not_obra_scale`.
     *
     * @var list<string>
     */
    private const OBRA_SCALE_SIGNALS = [
        'sdd_ready',
        'work_packets_ready',
        'provider_topology_ready',
    ];

    /**
     * Default bounded repair budget before a phase failure must escalate into an
     * incident capsule. Forge repairs are phased, never an infinite loop.
     */
    public const DEFAULT_MAX_REPAIR_ATTEMPTS = 3;

    /**
     * Gate 1 — Forge Enterprise Engineering Audit (Fluxo step 3-4).
     *
     * Run BEFORE heavy execution. Returns `ready` only when every documented
     * readiness flag holds AND the Obra carries Obra-scale signals. Any missing
     * flag is an honest, named blocker; under strict mode a blocked audit is a
     * hard stop ("Bloquear se strict audit falhar").
     *
     * @param array<string,mixed> $obra
     *        obra_id, intent, risk_level, and the readiness booleans
     *        (sdd_ready, workspace_ready, provider_topology_ready, rollback_ready,
     *         work_packets_ready, completion_gate_ready).
     * @param bool $strict strict certification mode (Forge certification by Obra).
     *
     * @return array<string,mixed> the `enterprise_engineering_audit.v1` receipt
     */
    public function auditEnterpriseReadiness(array $obra, bool $strict = true): array
    {
        $blockers = [];

        // Obra-scale check first: Forge must not be a flattened Dev task.
        $missingScale = [];
        foreach (self::OBRA_SCALE_SIGNALS as $signal) {
            if (! $this->flag($obra, $signal)) {
                $missingScale[] = $signal;
            }
        }
        if ($missingScale !== []) {
            $blockers[] = [
                'reason' => 'not_obra_scale',
                'missing' => $missingScale,
            ];
        }

        // Every documented readiness flag must hold.
        foreach (self::REQUIRED_READINESS as $flag) {
            if (! $this->flag($obra, $flag)) {
                $blockers[] = [
                    'reason' => 'readiness_missing',
                    'field' => $flag,
                ];
            }
        }

        // Required evidence for the audit itself must be declared.
        $requiredEvidence = $this->stringList($obra['required_evidence'] ?? []);
        if ($requiredEvidence === []) {
            $blockers[] = ['reason' => 'required_evidence_undeclared'];
        }

        $status = $blockers === [] ? self::AUDIT_READY : self::AUDIT_BLOCKED;

        // Under strict mode a blocked audit can never be waved through: the Obra
        // may not proceed to heavy execution.
        $mayProceed = $status === self::AUDIT_READY || ! $strict;

        return [
            'schema' => self::SCHEMA_AUDIT,
            'obra_id' => $this->str($obra['obra_id'] ?? null, 'unknown-obra'),
            'intent' => $this->str($obra['intent'] ?? null, ''),
            'risk_level' => $this->str($obra['risk_level'] ?? null, 'high'),
            'strict' => $strict,
            'strict_status' => $status,
            'may_proceed' => $mayProceed,
            'required_evidence' => $requiredEvidence,
            'blockers' => $blockers,
            'auditable' => true,
        ];
    }

    /**
     * Gate 2 — Repair loop + incident capsule (Fluxo step 8).
     *
     * On a phase failure, record a repair ledger entry. While the bounded repair
     * budget has room AND no human-required signal is set, the controlled action
     * is `repair`. Once attempts are exhausted, a fallback fails, or the phase
     * explicitly needs a human, the action flips to `incident_capsule` and a
     * capsule is opened with context, impact and mitigation. Nothing is hidden.
     *
     * @param array<string,mixed> $failure
     *        obra_id, phase, provider, cause, impact, attempt (1-based),
     *        max_attempts, evidence (list), fallback_failed (bool),
     *        needs_human (bool), rollback (string mitigation).
     *
     * @return array<string,mixed> the `repair_ledger.v1` entry (+ capsule if any)
     */
    public function evaluateRepairLoop(array $failure): array
    {
        $attempt = $this->positiveInt($failure['attempt'] ?? null, 1);
        $maxAttempts = $this->positiveInt(
            $failure['max_attempts'] ?? null,
            self::DEFAULT_MAX_REPAIR_ATTEMPTS,
        );
        $fallbackFailed = (bool) ($failure['fallback_failed'] ?? false);
        $needsHuman = (bool) ($failure['needs_human'] ?? false);
        $evidence = $this->stringList($failure['evidence'] ?? []);

        $reasons = [];
        $exhausted = $attempt >= $maxAttempts;

        if ($needsHuman) {
            $reasons[] = 'phase_requires_human';
        }
        if ($fallbackFailed) {
            $reasons[] = 'provider_fallback_failed';
        }
        if ($exhausted) {
            $reasons[] = "repair_attempts_exhausted:{$attempt}/{$maxAttempts}";
        }

        $action = $reasons === [] ? self::REPAIR_RETRY : self::REPAIR_INCIDENT;
        if ($action === self::REPAIR_RETRY) {
            $reasons[] = 'bounded_repair_admitted';
        }

        $ledger = [
            'schema' => self::SCHEMA_REPAIR_LEDGER,
            'obra_id' => $this->str($failure['obra_id'] ?? null, 'unknown-obra'),
            'phase' => $this->str($failure['phase'] ?? null, 'unknown-phase'),
            'provider' => $this->str($failure['provider'] ?? null, ''),
            'cause' => $this->str($failure['cause'] ?? null, ''),
            'impact' => $this->str($failure['impact'] ?? null, ''),
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'remaining_attempts' => max(0, $maxAttempts - $attempt),
            'action' => $action,
            // Transparency invariant: fallback / failure is always visible.
            'fallback_failed' => $fallbackFailed,
            'evidence' => $evidence,
            'reasons' => $reasons,
            'incident_capsule' => null,
            'auditable' => true,
        ];

        if ($action === self::REPAIR_INCIDENT) {
            $ledger['incident_capsule'] = [
                'schema' => self::SCHEMA_INCIDENT_CAPSULE,
                'obra_id' => $ledger['obra_id'],
                'phase' => $ledger['phase'],
                'provider' => $ledger['provider'],
                'cause' => $ledger['cause'],
                'impact' => $ledger['impact'],
                'evidence' => $evidence,
                'mitigation' => $this->str($failure['rollback'] ?? null, 'rollback_pending'),
                'needs_human' => $needsHuman || $exhausted || $fallbackFailed,
                'reasons' => $reasons,
            ];
        }

        return $ledger;
    }

    /**
     * Gate 3 — Evidence pack + completion gate (Fluxo step 9-12).
     *
     * No completion / release claim is allowed without an evidence pack AND a
     * passed completion gate. Open blockers or open incident capsules force a
     * `hold`. Architectural learning is never auto-applied: any learning proposal
     * is routed to the curator/approval gate, never to the codebase.
     *
     * @param array<string,mixed> $obra
     *        obra_id, run_id, phases (list), provider_decision_refs (list),
     *        work_packet_refs (list), evidence_pack_ref (string),
     *        completion_gate_passed (bool), open_blockers (list),
     *        open_incident_capsules (list), learning_proposals (list).
     *
     * @return array<string,mixed> the `obra_execution_receipt.v1` receipt
     */
    public function evaluateCompletionGate(array $obra): array
    {
        $blockers = [];

        $evidencePack = $this->str($obra['evidence_pack_ref'] ?? null, '');
        if ($evidencePack === '') {
            $blockers[] = ['reason' => 'evidence_pack_missing'];
        }

        $gatePassed = (bool) ($obra['completion_gate_passed'] ?? false);
        if (! $gatePassed) {
            $blockers[] = ['reason' => 'completion_gate_not_passed'];
        }

        // Obra-scale receipt: phases, providers and packets are mandatory
        // (forge-not-dev-copy). A receipt without them is not an Obra receipt.
        $phases = $this->stringList($obra['phases'] ?? []);
        $providerRefs = $this->stringList($obra['provider_decision_refs'] ?? []);
        $packetRefs = $this->stringList($obra['work_packet_refs'] ?? []);
        if ($phases === [] || $providerRefs === [] || $packetRefs === []) {
            $blockers[] = [
                'reason' => 'not_obra_scale',
                'missing' => array_values(array_filter([
                    $phases === [] ? 'phases' : null,
                    $providerRefs === [] ? 'provider_decision_refs' : null,
                    $packetRefs === [] ? 'work_packet_refs' : null,
                ])),
            ];
        }

        // Open blockers / incident capsules can never be hidden under a claim.
        $openBlockers = $this->stringList($obra['open_blockers'] ?? []);
        foreach ($openBlockers as $open) {
            $blockers[] = ['reason' => 'open_blocker', 'ref' => $open];
        }
        $openCapsules = $this->stringList($obra['open_incident_capsules'] ?? []);
        foreach ($openCapsules as $capsule) {
            $blockers[] = ['reason' => 'open_incident_capsule', 'ref' => $capsule];
        }

        $releaseDecision = $blockers === [] ? self::RELEASE_PROMOTE : self::RELEASE_HOLD;

        // Learning proposals are NEVER auto-applied — always routed to curator.
        $learningProposals = $this->stringList($obra['learning_proposals'] ?? []);
        $learningRouting = [];
        foreach ($learningProposals as $proposal) {
            $learningRouting[] = [
                'ref' => $proposal,
                'route' => 'curator_approval_gate',
                'auto_applied' => false,
            ];
        }

        return [
            'schema' => self::SCHEMA_EXECUTION_RECEIPT,
            'obra_id' => $this->str($obra['obra_id'] ?? null, 'unknown-obra'),
            'run_id' => $this->str($obra['run_id'] ?? null, ''),
            'status' => $releaseDecision === self::RELEASE_PROMOTE ? 'completed' : 'held',
            'phases' => $phases,
            'work_packet_refs' => $packetRefs,
            'provider_decision_refs' => $providerRefs,
            'evidence_pack_ref' => $evidencePack,
            'completion_gate_passed' => $gatePassed,
            'release_decision' => $releaseDecision,
            'blockers' => $blockers,
            'learning_proposals' => $learningRouting,
            'auditable' => true,
        ];
    }

    /**
     * Convenience predicate for the loop driver: may this Obra emit a completion
     * / release claim right now? (false => caller must hold and resolve blockers).
     */
    public function mayClaimCompletion(array $obra): bool
    {
        return $this->evaluateCompletionGate($obra)['release_decision'] === self::RELEASE_PROMOTE;
    }

    private function flag(array $data, string $key): bool
    {
        return (bool) ($data[$key] ?? false);
    }

    private function str(mixed $value, string $default): string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return $default;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $clean = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $clean[] = trim($item);
            }
        }

        return array_values($clean);
    }

    private function positiveInt(mixed $value, int $default): int
    {
        if (is_int($value) && $value >= 1) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value >= 1) {
            return (int) $value;
        }

        return $default;
    }
}
