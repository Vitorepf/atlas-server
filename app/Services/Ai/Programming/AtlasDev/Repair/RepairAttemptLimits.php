<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Repair;

use InvalidArgumentException;

/**
 * Hard cap on repair attempts per risk level (doc principal §11, table
 * "Risk Levels R0-R5"). The contract's own `repair_policy.max_attempts`
 * is the absolute ceiling — this table is a floor that can never be
 * raised above it.
 *
 * R4/R5 never receive a Dev repair attempt: those tasks must be escalated
 * to Forge before any patch ever runs.
 */
final class RepairAttemptLimits
{
    /** @var array<string, int> */
    private const PER_RISK = [
        'R0' => 1,
        'R1' => 1,
        'R2' => 2,
        'R3' => 2,
        'R4' => 0,
        'R5' => 0,
    ];

    public function attemptsAllowed(string $riskLevel, int $contractMax): int
    {
        if ($contractMax < 0) {
            throw new InvalidArgumentException("RepairAttemptLimits: contract_max must be non-negative, got {$contractMax}.");
        }
        if (! array_key_exists($riskLevel, self::PER_RISK)) {
            throw new InvalidArgumentException("RepairAttemptLimits: risk_level '{$riskLevel}' is not canonical (R0..R5).");
        }

        return min($contractMax, self::PER_RISK[$riskLevel]);
    }

    public function escalateImmediately(string $riskLevel): bool
    {
        return in_array($riskLevel, ['R4', 'R5'], true);
    }
}
