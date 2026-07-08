<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Repair Escalation kernel decider.
 *
 * Pure, deterministic implementation of the kernel `repair-escalation` step:
 * given a failure (domain + severity + evidence + repair policy) it returns the
 * single controlled action — `repair`, `escalate` (human review) or `block` —
 * and never lets a critical failure pass silently.
 *
 * Contract (from the doc "Contratos"):
 *   Entrada: falha, severidade, evidencia e policy.
 *   Saida:   acao de reparo, escalonamento ou bloqueio.
 *   Invariante: falha critica nao passa silenciosa.
 *
 * Rules grounded in the related canonical docs:
 *   - failure-domain-taxonomy.md      → closed FailureDomain enum (the inputs).
 *   - programming-repair-contract.md  → "require human review for policy,
 *     privacy, security, compliance, unknown"; "block heavy repair without
 *     evidence".
 *   - this doc "Escopo de Implementacao" → "Proibido: loop infinito ou bypass
 *     de gate" (the attempt cap that flips repair into escalation).
 *
 * The service NEVER executes a command, calls a provider, mutates a codebase or
 * touches the database. It emits the decision plus an audit receipt; callers
 * decide whether to apply the repair candidate, request review or block.
 *
 * @see docs/engineering-knowledge-base/system-graph/repair-escalation.md
 */
final class AtlasRepairEscalationService
{
    /** Stable receipt schema id for the decision this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.kernel.repair_escalation.v1';

    /** Canonical decisions (closed set). */
    public const ACTION_REPAIR = 'repair';
    public const ACTION_ESCALATE = 'escalate';
    public const ACTION_BLOCK = 'block';

    /**
     * Severity rank — higher is worse. `critical` carries the non-silent
     * invariant from this doc ("falha critica nao passa silenciosa").
     *
     * @var array<string,int>
     */
    private const SEVERITY_RANK = [
        'info' => 0,
        'low' => 1,
        'warning' => 2,
        'high' => 3,
        'critical' => 4,
    ];

    /**
     * FailureDomain values (from failure-domain-taxonomy.md) that the repair
     * contract says must go to human review and may NEVER be auto-repaired:
     * "require human review for policy, privacy, security, compliance, unknown".
     *
     * @var list<string>
     */
    private const HUMAN_REVIEW_DOMAINS = [
        'policy.denied',
        'tool.policy_denied',
        'privacy.violation',
        'security.finding',
        'compliance.violation',
        'unknown',
    ];

    /**
     * Recoverable FailureDomain families accepted by the closed domain contract.
     * Any other non-human-review domain normalizes to `unknown` and escalates.
     *
     * @var list<string>
     */
    private const RECOVERABLE_DOMAIN_PREFIXES = [
        'gate.',
        'runtime.',
        'tool.',
        'harness.',
    ];

    /**
     * Default cap on controlled repair attempts before the loop must escalate
     * ("Proibido: loop infinito"). Callers can override via policy.max_attempts.
     */
    public const DEFAULT_MAX_ATTEMPTS = 3;

    /**
     * Decide the single controlled action for one observed failure.
     *
     * @param array<string,mixed> $failure
     *        failure_domain : string  closed FailureDomain value (default unknown)
     *        severity       : string  info|low|warning|high|critical (default high)
     *        evidence       : list    evidence refs proving the failure (default [])
     *        attempt        : int     1-based repair attempt about to run (default 1)
     *        policy         : array   { max_attempts:int, allow_repair:bool }
     *        signature_repeated : bool same failure signature seen again (default false)
     *
     * @return array<string,mixed> the decision + audit receipt
     */
    public function decide(array $failure): array
    {
        $domain = $this->normalizeDomain($failure['failure_domain'] ?? null);
        $severity = $this->normalizeSeverity($failure['severity'] ?? null);
        $severityRank = self::SEVERITY_RANK[$severity];
        $isCritical = $severityRank >= self::SEVERITY_RANK['critical'];

        $evidence = $this->normalizeEvidence($failure['evidence'] ?? []);
        $hasEvidence = $evidence !== [];

        $policy = is_array($failure['policy'] ?? null) ? $failure['policy'] : [];
        $maxAttempts = $this->normalizeMaxAttempts($policy['max_attempts'] ?? null);
        $allowRepair = $this->normalizeBooleanFlag($policy['allow_repair'] ?? true);

        $attempt = $this->normalizeAttempt($failure['attempt'] ?? null);
        $signatureRepeated = $this->normalizeBooleanFlag($failure['signature_repeated'] ?? false);

        $reasons = [];
        $action = null;

        // Rule 1 — domains the contract reserves for humans never auto-repair.
        if (in_array($domain, self::HUMAN_REVIEW_DOMAINS, true)) {
            $action = self::ACTION_ESCALATE;
            $reasons[] = "domain_requires_human_review:{$domain}";
        }

        // Rule 2 — "block heavy repair without evidence": no evidence => cannot
        // repair blind. Critical with no evidence is the worst case (still a
        // hard stop, but flagged non-silent below).
        if ($action === null && ! $hasEvidence) {
            $action = self::ACTION_BLOCK;
            $reasons[] = 'no_evidence_blocks_repair';
        }

        // Rule 3 — repair explicitly disabled by policy.
        if ($action === null && ! $allowRepair) {
            $action = self::ACTION_ESCALATE;
            $reasons[] = 'repair_disabled_by_policy';
        }

        // Rule 4 — loop cap: a repeated signature or an exhausted attempt
        // budget must escalate instead of looping blindly ("Proibido: loop
        // infinito").
        if ($action === null && $signatureRepeated) {
            $action = self::ACTION_ESCALATE;
            $reasons[] = 'repeated_failure_signature';
        }
        if ($action === null && $attempt >= $maxAttempts) {
            $action = self::ACTION_ESCALATE;
            $reasons[] = "attempts_exhausted:{$attempt}/{$maxAttempts}";
        }

        // Rule 5 — otherwise a controlled repair attempt is admitted for the
        // recoverable operational domains (gate/runtime/tool/harness) that have
        // evidence and remaining budget.
        if ($action === null) {
            $action = self::ACTION_REPAIR;
            $reasons[] = 'controlled_repair_admitted';
        }

        // Invariant — a critical failure can never resolve to a silent repair:
        // the repair is allowed to run, but it is force-flagged for human review
        // so it cannot pass unseen. (this doc Riscos: "Escalar tarde demais".)
        $reviewRequired = $action === self::ACTION_ESCALATE
            || $action === self::ACTION_BLOCK;
        if ($isCritical) {
            $reviewRequired = true;
            $reasons[] = 'critical_failure_never_silent';
        }

        $remainingAttempts = max(0, $maxAttempts - $attempt);

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'action' => $action,
            'failure_domain' => $domain,
            'severity' => $severity,
            'severity_rank' => $severityRank,
            'critical' => $isCritical,
            'has_evidence' => $hasEvidence,
            'evidence_count' => count($evidence),
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'remaining_attempts' => $remainingAttempts,
            'review_required' => $reviewRequired,
            'auditable' => true,
            'reasons' => $reasons,
        ];
    }

    /**
     * Convenience predicate used by the loop driver: may a fresh repair attempt
     * run for this failure at all? (false => caller must escalate/block).
     */
    public function mayAttemptRepair(array $failure): bool
    {
        return $this->decide($failure)['action'] === self::ACTION_REPAIR;
    }

    private function normalizeDomain(mixed $domain): string
    {
        if (! is_string($domain) || trim($domain) === '') {
            return 'unknown';
        }

        $normalized = strtolower(trim($domain));
        if (in_array($normalized, self::HUMAN_REVIEW_DOMAINS, true)) {
            return $normalized;
        }

        foreach (self::RECOVERABLE_DOMAIN_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return $normalized;
            }
        }

        return 'unknown';
    }

    private function normalizeSeverity(mixed $severity): string
    {
        if (is_string($severity)) {
            $key = strtolower(trim($severity));
            if (array_key_exists($key, self::SEVERITY_RANK)) {
                return $key;
            }
        }

        // Unknown / missing severity is treated as high so it can never be
        // silently downgraded below the repair/escalate threshold.
        return 'high';
    }

    /**
     * @param mixed $evidence
     * @return list<string>
     */
    private function normalizeEvidence(mixed $evidence): array
    {
        if (! is_array($evidence)) {
            return [];
        }

        $clean = [];
        foreach ($evidence as $ref) {
            if (is_string($ref) && trim($ref) !== '') {
                $clean[] = trim($ref);
            }
        }

        return array_values($clean);
    }

    private function normalizeBooleanFlag(mixed $value): bool
    {
        if (is_string($value)) {
            $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if (is_bool($normalized)) {
                return $normalized;
            }
        }

        return (bool) $value;
    }

    private function normalizeMaxAttempts(mixed $max): int
    {
        if (is_int($max) && $max >= 1) {
            return $max;
        }
        if (is_string($max) && ctype_digit($max) && (int) $max >= 1) {
            return (int) $max;
        }

        return self::DEFAULT_MAX_ATTEMPTS;
    }

    private function normalizeAttempt(mixed $attempt): int
    {
        if (is_int($attempt) && $attempt >= 1) {
            return $attempt;
        }
        if (is_string($attempt) && ctype_digit($attempt) && (int) $attempt >= 1) {
            return (int) $attempt;
        }

        return 1;
    }
}
