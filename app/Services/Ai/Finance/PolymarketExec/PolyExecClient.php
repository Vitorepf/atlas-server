<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketExec;

/**
 * The seam between the (testable, deterministic) state machine and the venue.
 *
 * Two implementations exist:
 *  - {@see SimulatedPolyExecClient}: default. Simulates fills against the REAL
 *    live CLOB book, signs nothing, holds no keys. This is what proves the logic.
 *  - {@see LivePolyExecClient}: dormant venue seam. It is not reachable while
 *    the canonical Finance no-live-execution policy is active.
 *
 * The state machine NEVER knows which one it holds, keeping the abort/unwind
 * logic proven in sim decoupled from venue access.
 */
interface PolyExecClient
{
    /** sim | live — for receipts and the "did we actually sign" assertion. */
    public function mode(): string;

    /**
     * Buy up to $size shares of $token paying no more than $limitPrice/share.
     * A marketable limit order: fills only at <= limit; may fill partially.
     */
    public function buyLimit(string $token, float $limitPrice, float $size): FillResult;

    /**
     * Sell up to $size shares of $token receiving no less than $limitPrice/share.
     * A marketable limit sell: fills only against bids >= limit; may fill
     * partially. Used by the SHORT executor to sell minted legs at a protective
     * floor (an unfilled remainder is simply held as a freeroll, never forced).
     */
    public function sellLimit(string $token, float $limitPrice, float $size): FillResult;

    /**
     * Sell $size shares of $token to the market (unwind). Used only to undo an
     * already-filled leg when a LONG basket aborts. Best-effort liquidation.
     */
    public function sellMarket(string $token, float $size): FillResult;

    /**
     * Current on-venue position (shares held) for $token, for reconciliation.
     * Sim returns the simulated position; live reads it from the data API.
     */
    public function positionSize(string $token): ?float;
}
