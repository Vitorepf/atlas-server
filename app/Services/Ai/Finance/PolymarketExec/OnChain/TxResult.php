<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketExec\OnChain;

/**
 * Outcome of a single on-chain CTF transaction (split = mint a full set, or
 * merge = redeem a full set back to collateral). Immutable.
 *
 * `realTx` is the boundary guard: only a transaction that actually settled
 * on-chain counts. A sim returns realTx=false with a synthetic hash; the live
 * runtime returns realTx=true only when a real tx hash comes back. The state
 * machine treats anything else as a hard failure (no minted position assumed).
 */
final class TxResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly bool $realTx,
        public readonly float $sets,            // full sets minted (split) or redeemed (merge)
        public readonly float $collateralUsd,   // USDC locked (split) or returned (merge) = $1 * sets
        public readonly float $gasUsd,          // measured/estimated Polygon gas for this tx
        public readonly ?string $txHash = null,
        public readonly ?string $error = null,
    ) {}

    public static function nothing(?string $error = null): self
    {
        return new self(false, false, 0.0, 0.0, 0.0, null, $error);
    }
}
