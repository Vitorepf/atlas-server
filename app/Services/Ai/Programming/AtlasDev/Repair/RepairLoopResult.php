<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Repair;

use App\Services\Ai\Programming\AtlasDev\Schemas\FailureCapsule;
use InvalidArgumentException;

/**
 * Honest outcome of a repair loop. The orchestrator returns one of these
 * to its caller so the pipeline can decide between completion, escalation
 * and explicit failure without re-running anything.
 *
 * Statuses:
 *   - `recovered`     the loop converged to a green attempt;
 *   - `exhausted`     the loop hit the per-risk attempt cap without recovery;
 *   - `escalated`     the loop hit a stop signal (same signature, scope
 *                      explosion, blocked, escalate-immediately) and refused
 *                      to retry;
 *   - `not_attempted` the policy/risk level forbade any repair attempt
 *                      (typical for R4/R5 — Forge territory).
 */
final class RepairLoopResult
{
    public const STATUS_RECOVERED = 'recovered';
    public const STATUS_EXHAUSTED = 'exhausted';
    public const STATUS_ESCALATED = 'escalated';
    public const STATUS_NOT_ATTEMPTED = 'not_attempted';

    public const ALLOWED_STATUSES = [
        self::STATUS_RECOVERED,
        self::STATUS_EXHAUSTED,
        self::STATUS_ESCALATED,
        self::STATUS_NOT_ATTEMPTED,
    ];

    /**
     * @param  list<FailureCapsule>  $capsules  appended in attempt order
     * @param  list<string>          $escalationSignalDelta  union of all signals
     *                                                       observed in this loop
     */
    public function __construct(
        public readonly string $status,
        public readonly int $attemptsExecuted,
        public readonly int $attemptsAllowed,
        public readonly array $capsules,
        public readonly array $escalationSignalDelta,
        public readonly ?FailureCapsule $lastCapsule,
    ) {
        if (! in_array($this->status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException("RepairLoopResult.status invalid: '{$this->status}'.");
        }
        if ($this->attemptsExecuted < 0 || $this->attemptsAllowed < 0) {
            throw new InvalidArgumentException('RepairLoopResult: attempt counters must be non-negative.');
        }
        if ($this->attemptsExecuted > $this->attemptsAllowed && $this->status !== self::STATUS_RECOVERED) {
            // A recovered run can technically equal the budget; we only flag
            // the over-budget invariant when the loop is reporting failure.
            throw new InvalidArgumentException(
                'RepairLoopResult invariant: attempts_executed must not exceed attempts_allowed for non-recovered runs.'
            );
        }
        foreach ($this->capsules as $i => $c) {
            if (! $c instanceof FailureCapsule) {
                throw new InvalidArgumentException("RepairLoopResult.capsules[{$i}] must be a FailureCapsule instance.");
            }
        }
        if ($this->lastCapsule !== null && $this->capsules !== []) {
            // `end()` requires its argument by reference, but `$this->capsules`
            // is readonly. Copy locally to advance the pointer safely.
            $tail = $this->capsules;
            $last = end($tail);
            if ($last instanceof FailureCapsule
                && $last->capsuleHash !== $this->lastCapsule->capsuleHash) {
                throw new InvalidArgumentException(
                    'RepairLoopResult invariant: last_capsule must reference the tail of capsules[].'
                );
            }
        }
    }

    public function isRecovered(): bool
    {
        return $this->status === self::STATUS_RECOVERED;
    }

    public function isEscalated(): bool
    {
        return $this->status === self::STATUS_ESCALATED;
    }
}
