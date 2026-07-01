<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ClosedLoop;

use RuntimeException;

/**
 * Thrown by {@see AtlasMaestroLearningPolicyGuard} when a learning artifact violates the pétreo anti-Goodhart
 * contract on its way to the Replenisher. The $reason is a stable machine token for the violated rule.
 */
final class AtlasMaestroLearningPolicyViolation extends RuntimeException
{
    /** hard invariant violation — the artifact must never reach the Replenisher as-is. */
    public const SEVERITY_BLOCK = 'block';

    /** borderline signal — a human/downstream policy check should look before promoting. */
    public const SEVERITY_REVIEW = 'review';

    /** advisory-only — worth surfacing, never itself a reason to refuse promotion. */
    public const SEVERITY_WARN = 'warn';

    public readonly string $policyCode;

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly string $severity = self::SEVERITY_BLOCK,
        ?string $policyCode = null,
        public readonly ?string $remediationHint = null,
        public readonly bool $failClosed = true,
    ) {
        parent::__construct($message);
        // Every existing caller supplies only (reason, message) — default the provider-safe
        // policy code to the reason token so nothing downstream ever sees an empty code.
        $this->policyCode = $policyCode ?? $reason;
    }

    /**
     * Stable, provider-safe array export for receipt ledgers — field names and shape are a
     * contract other organs persist verbatim; never rename or reorder without a migration.
     *
     * @return array{reason:string, message:string, severity:string, policy_code:string, remediation_hint:?string, fail_closed:bool}
     */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason,
            'message' => $this->getMessage(),
            'severity' => $this->severity,
            'policy_code' => $this->policyCode,
            'remediation_hint' => $this->remediationHint,
            'fail_closed' => $this->failClosed,
        ];
    }

    public static function compositeScore(string $key): self
    {
        return new self(
            'composite_score',
            "learning artifact carries a composite score/ranking field: {$key}",
            remediationHint: 'remove the composite/score/rank field — the loop learns from FACTS, never a scalar',
        );
    }

    public static function imperativeAdvice(string $token): self
    {
        return new self(
            'imperative_advice',
            "learning artifact contains imperative advice token: {$token}",
            remediationHint: 'rephrase as an observed fact, not an imperative recommendation',
        );
    }

    public static function subSupportBucket(int $total): self
    {
        return new self(
            'sub_support_bucket',
            "learning artifact carries a bucket below MIN_SUPPORT (total={$total})",
            severity: self::SEVERITY_REVIEW,
            remediationHint: 'wait for MIN_SUPPORT samples before promoting this bucket',
        );
    }

    public static function forbiddenScope(string $scope): self
    {
        return new self(
            'forbidden_scope',
            "learning artifact references a forbidden scope: {$scope}",
            remediationHint: 'remove the forbidden-scope reference — only the Loop+Cortex+Maestro perimeter is sanctioned',
        );
    }

    public static function taskFieldOverride(string $field): self
    {
        return new self(
            'task_field_override',
            "learning artifact tries to override task quality field: {$field}",
            remediationHint: 'advise via reason text — never override acceptance_criteria/required_evidence directly',
        );
    }

    /** Advisory-only: worth surfacing to an operator, never itself a reason to block promotion. */
    public static function staleEvidenceAdvisory(string $key): self
    {
        return new self(
            'stale_evidence_advisory',
            "learning artifact cites evidence that may be stale: {$key}",
            severity: self::SEVERITY_WARN,
            remediationHint: 'refresh the cited evidence before the next promotion cycle',
            failClosed: false,
        );
    }
}
