<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketExec;

/**
 * Outcome of a single order attempt (buy or unwind sell). Immutable.
 *
 * `filledSize` is the shares actually transacted; `avgPrice` their VWAP. A buy
 * is acceptable only when avgPrice <= the limit and filledSize meets the
 * requested size within tolerance — otherwise the caller aborts the basket.
 */
final class FillResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly float $filledSize,
        public readonly float $avgPrice,
        public readonly float $cashUsd, // cost for a buy, proceeds for a sell
        public readonly ?string $orderId = null,
        public readonly ?string $error = null,
    ) {}

    public static function nothing(?string $error = null): self
    {
        return new self(false, 0.0, 0.0, 0.0, null, $error);
    }

    public function isComplete(float $requestedSize, float $tolerance = 1e-4): bool
    {
        return $this->ok && $this->filledSize + $tolerance >= $requestedSize;
    }
}
