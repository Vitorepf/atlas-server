<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\LiveCycle;

use RuntimeException;

/**
 * Raised when the certify phase sees a regressed verdict — short-circuits the live-cycle;
 * a regressed candidate is never silently swallowed (operator/orchestrator reacts).
 *
 * SINGLE canonical declaration. This class used to be declared TWICE — inline in both
 * {@see AtlasLoopFullCycleConductor}'s file (message-only) and
 * {@see AtlasLoopCertifyPhaseRunner}'s file (receipt-carrying) — so any process that
 * autoloaded both files died with "Cannot redeclare class" (the whole Feature/Loop
 * suite crashed on exactly that pair). Both historical constructors are supported:
 * `new CertificationFailedException($receiptArray)` and
 * `new CertificationFailedException('message')`.
 */
final class CertificationFailedException extends RuntimeException
{
    /** @var array<string,mixed> the structured receipt the runner was about to return */
    public readonly array $receipt;

    /** @param  array<string,mixed>|string  $receipt  receipt array, or a plain message */
    public function __construct(array|string $receipt = [], ?string $message = null)
    {
        if (is_string($receipt)) {
            $message ??= $receipt;
            $receipt = [];
        }
        $this->receipt = $receipt;

        parent::__construct($message ?? 'Certification regressed: '.($receipt['reason'] ?? 'no_reason_recorded'));
    }
}
