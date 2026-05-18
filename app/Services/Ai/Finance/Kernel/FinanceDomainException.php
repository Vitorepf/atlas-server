<?php

namespace App\Services\Ai\Finance\Kernel;

use RuntimeException;

class FinanceDomainException extends RuntimeException
{
    public static function liveTradingBlocked(string $detail): self
    {
        return new self("Finance live trading is blocked by default. Request rejected: {$detail}");
    }

    public static function forbiddenAction(string $action): self
    {
        return new self("Finance forbidden action [{$action}]: this is on the hard-block list (live trades, broker orders, transfers, auto-rebalance) and cannot be bypassed by policy.");
    }

    public static function manifestMissing(): self
    {
        return new self('Finance domain manifest not registered. Run FinanceDomainManifestSeeder::seed() first.');
    }

    public static function invalidAsset(string $detail): self
    {
        return new self("Finance invalid asset descriptor: {$detail}");
    }

    public static function insufficientEvidence(string $detail): self
    {
        return new self("Finance insufficient evidence: {$detail}");
    }

    public static function paperOnly(string $detail): self
    {
        return new self("Finance paper-only operation refused live path: {$detail}");
    }
}
