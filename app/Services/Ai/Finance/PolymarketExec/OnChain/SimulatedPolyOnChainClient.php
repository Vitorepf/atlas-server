<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketExec\OnChain;

/**
 * Default on-chain backend: models a CTF split/merge as pure bookkeeping, with
 * no chain, no keys, no money. Minting $sets full sets credits $sets shares of
 * every outcome token (so the simulated CLOB client can then sell them) and
 * "locks" $sets * $1 of collateral; merging reverses it.
 *
 * Gas is a fixed per-tx estimate so the sim's net P&L reflects the real cost
 * structure the live path will incur. This is what lets the short state machine
 * — mint, sell-thinnest-first, freeroll the unsellable — be proven against the
 * REAL CLOB books before any transaction is ever signed.
 */
final class SimulatedPolyOnChainClient implements PolyOnChainClient
{
    /** @var array<string, float> shares minted per token, so a paired sim CLOB client can read holdings */
    public array $minted = [];

    public function __construct(
        private readonly float $mintGasUsd = 0.0,
        private readonly float $mergeGasUsd = 0.0,
        /** Optional sink: credit/debit minted shares into a paired SimulatedPolyExecClient. */
        private readonly mixed $onMint = null,
        private readonly mixed $onMerge = null,
        private readonly string $mode = 'sim',
    ) {}

    public function mode(): string
    {
        return $this->mode;
    }

    public function splitFullSet(string $conditionId, array $tokenIds, float $sets, bool $negRisk): TxResult
    {
        if ($sets <= 0.0 || $tokenIds === []) {
            return TxResult::nothing('nothing_to_mint');
        }
        foreach ($tokenIds as $token) {
            $this->minted[$token] = ($this->minted[$token] ?? 0.0) + $sets;
        }
        if (is_callable($this->onMint)) {
            ($this->onMint)($tokenIds, $sets);
        }

        return new TxResult(
            ok: true,
            realTx: false,
            sets: round($sets, 6),
            collateralUsd: round($sets, 6), // $1 per set
            gasUsd: $this->mintGasUsd,
            txHash: 'sim-split-'.substr(hash('sha256', $conditionId.implode(',', $tokenIds).$sets), 0, 16),
        );
    }

    public function mergeFullSet(string $conditionId, array $tokenIds, float $sets, bool $negRisk): TxResult
    {
        if ($sets <= 0.0 || $tokenIds === []) {
            return TxResult::nothing('nothing_to_merge');
        }
        foreach ($tokenIds as $token) {
            $this->minted[$token] = max(0.0, ($this->minted[$token] ?? 0.0) - $sets);
        }
        if (is_callable($this->onMerge)) {
            ($this->onMerge)($tokenIds, $sets);
        }

        return new TxResult(
            ok: true,
            realTx: false,
            sets: round($sets, 6),
            collateralUsd: round($sets, 6),
            gasUsd: $this->mergeGasUsd,
            txHash: 'sim-merge-'.substr(hash('sha256', $conditionId.implode(',', $tokenIds).$sets), 0, 16),
        );
    }
}
