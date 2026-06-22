<?php

declare(strict_types=1);

namespace App\Services\Ai\AgentGovernance;

/**
 * The operator's DECLARED intent for one agent — an immutable snapshot of a `atlas_agent_desired_state` row.
 *
 * The invariant lives here: an agent is authorized to run ONLY when {@see $on} is true AND it has not blown
 * its FREIO (TTL or budget). Everything else — orphan campaign rows, a stale process, a launchd respawn —
 * has no say. {@see effectivelyOn} is the single predicate the reconciler trusts.
 */
final readonly class AgentDesiredState
{
    public function __construct(
        public string $agentKey,
        public bool $on,
        public ?string $setBy = null,
        public ?int $setAtEpoch = null,
        /** FREIO: wall-clock deadline. Past this ⇒ auto-OFF. null = no time cap. */
        public ?int $ttlExpiresAtEpoch = null,
        /** FREIO: spend ceiling in USD. spent ≥ this ⇒ auto-OFF. null = no budget cap. */
        public ?float $budgetLimitUsd = null,
        /** The EXACT thing authorized (e.g. one campaign uuid) — scopes respawn so siblings/orphans are never revived. */
        public ?string $targetRef = null,
        public ?string $reason = null,
    ) {}

    /** Has the FREIO tripped? (TTL passed, or measured spend reached the budget ceiling.) */
    public function isExpired(int $nowEpoch, float $spentUsd = 0.0): bool
    {
        if ($this->ttlExpiresAtEpoch !== null && $nowEpoch >= $this->ttlExpiresAtEpoch) {
            return true;
        }
        if ($this->budgetLimitUsd !== null && $spentUsd >= $this->budgetLimitUsd) {
            return true;
        }

        return false;
    }

    /** Which FREIO tripped (for audit/history), or null if none. */
    public function expiryReason(int $nowEpoch, float $spentUsd = 0.0): ?string
    {
        if ($this->ttlExpiresAtEpoch !== null && $nowEpoch >= $this->ttlExpiresAtEpoch) {
            return 'ttl_expired';
        }
        if ($this->budgetLimitUsd !== null && $spentUsd >= $this->budgetLimitUsd) {
            return 'budget_exhausted';
        }

        return null;
    }

    /**
     * THE predicate: is this agent authorized to be running right now? ON and not braked. This — never a DB
     * row's status column — is what grants run/respawn authority.
     */
    public function effectivelyOn(int $nowEpoch, float $spentUsd = 0.0): bool
    {
        return $this->on && ! $this->isExpired($nowEpoch, $spentUsd);
    }

    public function ttlRemainingSeconds(int $nowEpoch): ?int
    {
        if ($this->ttlExpiresAtEpoch === null) {
            return null;
        }

        return max(0, $this->ttlExpiresAtEpoch - $nowEpoch);
    }
}
