<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketExec\OnChain;

/**
 * The seam between the (testable, deterministic) mint/sell state machine and the
 * Polygon chain. Two implementations, mirroring the CLOB seam:
 *
 *  - {@see SimulatedPolyOnChainClient}: default. Models the ECONOMICS of a CTF
 *    split/merge (mint a full set = lock $1/set and receive one share of every
 *    outcome token; merge = the reverse) without touching the chain or holding
 *    keys. This is what proves the short state machine end-to-end against real
 *    CLOB books before a single transaction is signed.
 *  - {@see LivePolyOnChainClient}: dormant on-chain seam. It is not reachable
 *    while the canonical Finance no-live-execution policy is active, and remains
 *    UNPROVEN until the exact NegRisk/CTF call is operator-verified.
 *
 * The state machine never knows which one it holds, keeping the idempotent,
 * abort-safe logic proven in sim decoupled from chain access.
 */
interface PolyOnChainClient
{
    /** sim | live — for receipts and the "did we actually transact" assertion. */
    public function mode(): string;

    /**
     * Mint $sets full sets: lock $sets * $1 USDC collateral and receive $sets
     * shares of EVERY token in $tokenIds (one outcome will pay $1 at resolution).
     * For a Polymarket multi-outcome (NegRisk) event this is the NegRiskAdapter
     * split; for a single condition it is the ConditionalTokens split.
     *
     * @param  list<string>  $tokenIds  every outcome token of the set (full set)
     */
    public function splitFullSet(string $conditionId, array $tokenIds, float $sets, bool $negRisk): TxResult;

    /**
     * Burn $sets shares of every token in $tokenIds and receive $sets * $1 USDC.
     * Used to (a) realize a long basket early without waiting for resolution and
     * (b) unwind a short basket that minted but could not sell.
     *
     * @param  list<string>  $tokenIds
     */
    public function mergeFullSet(string $conditionId, array $tokenIds, float $sets, bool $negRisk): TxResult;
}
