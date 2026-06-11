<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketExec;

use App\Services\Ai\Finance\PolymarketExec\OnChain\PolyOnChainClient;
use App\Services\Ai\Finance\PolymarketShadow\PolymarketShadowFeed;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The SHORT basket state machine — the motor. The part that mints on-chain and
 * sells on the CLOB in live mode.
 *
 * The short arb: mint a full set of every outcome on-chain for $1/set, then sell
 * the sellable legs for a per-set bid sum > $1, banking the difference NOW. Any
 * leg with no usable bid is kept as a freeroll (it can only add value).
 *
 * Why this is (near) risk-free in the designed case: minting gives one share of
 * every outcome, which is worth EXACTLY $1/set at resolution. If the sellable
 * legs' bids sum to > $1, selling them banks more than the mint cost; the held
 * legs are pure upside. The residual risk is bounded to gas + post-mint slippage
 * (liquidity vanishing between the fresh-book verify and the sells), which is why
 * we re-verify the bids immediately before minting and size to micro stakes.
 *
 * Invariants (all enforced here, all provable in sim against real books):
 *  - Verify-before-mint: we never mint unless the FRESH bids still sum > 1 with
 *    depth and net edge above the floor. A collapsed edge is gated with NO cost.
 *  - Idempotent + resumable: the mint is recorded once (mint_tx_hash); a resume
 *    skips the mint and continues selling unsold legs. A sold leg is never
 *    re-sold; a leg marked freeroll is never re-attempted.
 *  - Mint failure leaves NO position (nothing minted) → status 'failed', clean.
 *  - A leg that will not sell at its floor is NOT an abort — it is held as a
 *    freeroll. Forcing a sale would realize a loss the freeroll avoids.
 *  - Kill-switch mid-flight: before mint → gated (no cost); during selling →
 *    stop selling and HOLD the residual (the minted set is safe at resolution).
 *  - Fail-closed: any exception ends 'failed' with the minted residual surfaced
 *    by reconciliation (never auto-merged, which could compound a bad state).
 *
 * status: planning -> verifying -> minting -> selling -> settled
 *                           \-> gated (quality/verify reject, NEVER minted)
 *                  any -> halted (runtime cap) | failed (mint fail / exception)
 */
final class MintSellStateMachine
{
    private const PRICE_EPS = 1e-6;

    /** @var callable(string): ?array{asks: list<array{price: float, size: float}>, bids: list<array{price: float, size: float}>} */
    private $bookSource;

    private readonly AtlasEvidenceLedger $ledger;

    /**
     * @param  null|callable(string): ?array{asks: list<array{price: float, size: float}>, bids: list<array{price: float, size: float}>}  $bookSource
     */
    public function __construct(
        private readonly PolyExecConfig $cfg,
        private readonly PolyExecClient $client,
        private readonly PolyOnChainClient $onChain,
        private readonly PolyExecGate $gate,
        ?callable $bookSource = null,
        ?AtlasEvidenceLedger $ledger = null,
    ) {
        $feed = new PolymarketShadowFeed;
        $this->bookSource = $bookSource ?? fn (string $token): ?array => $feed->bookLevels($token);
        $this->ledger = $ledger ?? app(AtlasEvidenceLedger::class);
    }

    /**
     * Run (or resume) one short basket to a terminal state.
     *
     * @return array<string, mixed>
     */
    public function execute(ShortBasketPlan $plan, string $basketId, string $sessionId): array
    {
        $mode = $this->client->mode();

        $existing = DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->first();
        if ($existing !== null && $this->isTerminal((string) $existing->status)) {
            return $this->summary($basketId, true); // idempotent
        }

        if ($existing === null) {
            $runtime = $this->gate->checkRuntimeCaps($mode);
            if (! $runtime->allowed) {
                $this->createBasket($plan, $basketId, $sessionId, $mode, status: 'halted');
                $this->recordGateBlock($basketId, 'runtime_caps', $runtime);
                $this->finalize($basketId, 'halted');
                $this->bumpDaily($mode, attempted: 1);

                return $this->summary($basketId);
            }
            $this->createBasket($plan, $basketId, $sessionId, $mode, status: 'planning');
            $this->bumpDaily($mode, attempted: 1);
        }

        try {
            return $this->drive($plan, $basketId, $mode);
        } catch (Throwable $e) {
            // Fail-closed: surface whatever was minted; never auto-merge on exception.
            $minted = (string) DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->value('mint_tx_hash');
            $this->event($basketId, 'state_change', ['to' => 'failed', 'cause' => 'exception', 'error' => $e->getMessage(), 'minted' => $minted !== '']);
            DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->update([
                'error' => mb_substr($e->getMessage(), 0, 500),
                'updated_at' => now(),
            ]);
            $this->finalize($basketId, 'failed');
            $this->reconcile($basketId, $plan);
            $this->recordReceipt($basketId, $mode);

            return $this->summary($basketId);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function drive(ShortBasketPlan $plan, string $basketId, string $mode): array
    {
        $status = (string) DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->value('status');

        if ($status === 'planning') {
            // Structural quality gate (same gate as the long side) but with the short's
            // own generous resolution ceiling — the short banks now, it does not carry.
            $opp = $this->gate->checkOpportunity($plan->toGateInput(), $mode, $this->cfg->shortMaxResolutionHours);
            if (! $opp->allowed) {
                $this->recordGateBlock($basketId, 'opportunity', $opp);
                $this->setStatus($basketId, 'gated');
                $this->finalize($basketId, 'gated');
                $this->recordReceipt($basketId, $mode);

                return $this->summary($basketId);
            }

            // Re-verify the FRESH bids right before committing capital to a mint.
            $this->setStatus($basketId, 'verifying');
            $fresh = $this->verifyFreshBids($plan);
            $this->event($basketId, 'state_change', ['to' => 'verifying'] + $fresh);
            if (! $fresh['ok']) {
                $this->setStatus($basketId, 'gated');
                DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)
                    ->update(['error' => 'verify: '.$fresh['reason'], 'updated_at' => now()]);
                $this->finalize($basketId, 'gated');
                $this->recordReceipt($basketId, $mode);

                return $this->summary($basketId);
            }

            // Kill-switch BEFORE minting = a free abort (nothing committed).
            if ($this->cfg->killSwitchEngaged()) {
                $this->event($basketId, 'kill', ['phase' => 'pre_mint']);
                $this->setStatus($basketId, 'gated');
                DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)
                    ->update(['error' => 'kill_switch_pre_mint', 'updated_at' => now()]);
                $this->finalize($basketId, 'gated');
                $this->recordReceipt($basketId, $mode);

                return $this->summary($basketId);
            }

            $this->setStatus($basketId, 'minting');
            $status = 'minting';
        }

        if ($status === 'minting') {
            if (! $this->mint($plan, $basketId, $mode)) {
                $this->recordReceipt($basketId, $mode); // mint failed: no position, clean

                return $this->summary($basketId);
            }
            $status = 'selling';
        }

        if ($status === 'selling') {
            $this->sellLegs($plan, $basketId);
            $this->settle($plan, $basketId, $mode);
        }

        $this->reconcile($basketId, $plan);
        $this->recordReceipt($basketId, $mode);

        return $this->summary($basketId);
    }

    /**
     * Mint the full set on-chain. On failure: NO position, status 'failed'.
     */
    private function mint(ShortBasketPlan $plan, string $basketId, string $mode): bool
    {
        $sets = (float) DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->value('target_sets');
        $tx = $this->onChain->splitFullSet((string) ($plan->conditionId ?? ''), $plan->allTokenIds, $sets, $plan->negRisk);

        if (! $tx->ok) {
            $this->event($basketId, 'mint_fail', ['reason' => $tx->error, 'sets' => $sets]);
            DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->update([
                'status' => 'failed', 'error' => 'mint: '.(string) $tx->error, 'finalized_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($basketId, 'state_change', ['to' => 'failed']);

            return false;
        }

        // Capital is committed at the mint: collateral = $1 * sets. Record it and
        // count it against the daily budget (the conservative, churn-bounding view).
        DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->update([
            'mint_tx_hash' => mb_substr((string) $tx->txHash, 0, 120),
            'realized_cost_usd' => round($tx->collateralUsd, 4),
            'status' => 'selling',
            'updated_at' => now(),
        ]);
        $this->event($basketId, 'mint', [
            'sets' => $tx->sets,
            'collateral_usd' => $tx->collateralUsd,
            'gas_usd' => $tx->gasUsd,
            'real_tx' => $tx->realTx,
            'tx_hash' => $tx->txHash,
        ]);
        $this->bumpDaily($mode, deployed: $tx->collateralUsd);
        $this->event($basketId, 'state_change', ['to' => 'selling']);

        return true;
    }

    /**
     * Sell each sellable leg at its protective floor, thinnest-bid-first. A leg
     * that will not sell is held as a freeroll (NOT an abort). The kill-switch
     * stops selling and holds the residual.
     */
    private function sellLegs(ShortBasketPlan $plan, string $basketId): void
    {
        $legs = DB::table('atlas_poly_exec_legs')->where('basket_id', $basketId)->orderBy('position')->get();

        $killed = false;
        foreach ($legs as $leg) {
            if (in_array($leg->status, ['sold', 'freeroll'], true)) {
                continue; // resume: never re-sell or re-attempt a settled leg
            }

            if (! $killed && $this->cfg->killSwitchEngaged()) {
                $this->event($basketId, 'kill', ['phase' => 'selling', 'at_position' => $leg->position]);
                $killed = true;
            }

            if ($killed) {
                // Hold the rest as freeroll — the minted shares are safe at resolution.
                DB::table('atlas_poly_exec_legs')->where('id', $leg->id)->update([
                    'status' => 'freeroll', 'freeroll' => true,
                    'error' => trim((string) $leg->error.' kill_hold'), 'updated_at' => now(),
                ]);

                continue;
            }

            $floor = (float) $leg->limit_price;
            $size = (float) $leg->target_size;
            $fill = $this->client->sellLimit((string) $leg->token, $floor, $size);

            $atOrAboveFloor = $fill->ok && $fill->avgPrice + self::PRICE_EPS >= $floor;
            if (! $fill->ok || $fill->filledSize <= 0.0 || ! $atOrAboveFloor) {
                // Not sellable at the floor → hold as a freeroll (bounded, no forced loss).
                DB::table('atlas_poly_exec_legs')->where('id', $leg->id)->update([
                    'status' => 'freeroll', 'freeroll' => true,
                    'error' => $fill->error ?? ($atOrAboveFloor ? 'no_fill' : 'below_floor'),
                    'updated_at' => now(),
                ]);
                $this->event($basketId, 'leg_freeroll', [
                    'position' => $leg->position,
                    'reason' => $fill->error ?? ($atOrAboveFloor ? 'no_fill' : 'below_floor'),
                    'avg_price' => $fill->avgPrice,
                    'floor' => $floor,
                ]);

                continue;
            }

            DB::table('atlas_poly_exec_legs')->where('id', $leg->id)->update([
                'status' => 'sold',
                'sold_size' => $fill->filledSize,
                'avg_fill_price' => $fill->avgPrice,
                'sold_proceeds_usd' => $fill->cashUsd,
                'sell_order_id' => $fill->orderId,
                // a partial fill leaves the remainder of THIS leg's shares held (freeroll)
                'freeroll' => $fill->filledSize + 1e-4 < $size,
                'updated_at' => now(),
            ]);
            DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->update([
                'cash_in_usd' => DB::raw('cash_in_usd + '.$this->dec($fill->cashUsd)),
                'legs_sold' => DB::raw('legs_sold + 1'),
                'updated_at' => now(),
            ]);
            $this->event($basketId, 'sell', [
                'position' => $leg->position,
                'size' => $fill->filledSize,
                'avg_price' => $fill->avgPrice,
                'proceeds_usd' => $fill->cashUsd,
                'order_id' => $fill->orderId,
            ]);
        }
    }

    /**
     * Compute the honest result and bank it. Sold legs bank cash now; the held
     * (freeroll) legs are upside not counted in P&L. If NOTHING sold, the COMPLETE
     * minted set is worth its collateral at resolution (loss bounded to gas) —
     * optionally merged back to bank the collateral immediately.
     */
    private function settle(ShortBasketPlan $plan, string $basketId, string $mode): void
    {
        $b = DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->first();
        $collateral = (float) $b->realized_cost_usd;
        $cashIn = (float) $b->cash_in_usd;
        $legsSold = (int) $b->legs_sold;
        $sets = (float) $b->target_sets;
        $mintGas = (float) $b->est_gas_usd; // estimate; sim/live record the real number in events
        $gas = $mintGas;

        $nOutcomes = count($plan->allTokenIds);
        $heldLegs = DB::table('atlas_poly_exec_legs')->where('basket_id', $basketId)
            ->where(fn ($q) => $q->where('status', 'freeroll')->orWhere('freeroll', true))->count();
        // Freeroll = never-sellable outcomes (not stored as legs) + held/partial sell legs.
        $freerollCount = count($plan->freerollTokens) + $heldLegs;
        $pnlLocked = false;
        $mergeTx = null;

        if ($legsSold === 0) {
            // A COMPLETE full set is held → worth exactly the collateral at resolution.
            if ($this->cfg->shortMergeOnNoSell) {
                $merge = $this->onChain->mergeFullSet((string) ($plan->conditionId ?? ''), $plan->allTokenIds, $sets, $plan->negRisk);
                if ($merge->ok) {
                    $cashIn += $merge->collateralUsd;
                    $gas += $merge->gasUsd;
                    $mergeTx = $merge->txHash;
                    $realizeMethod = 'merge';
                    $freerollCount = 0;
                    $pnl = round($cashIn - $collateral - $gas, 4); // ≈ -(mint+merge gas)
                    $this->event($basketId, 'merge', ['sets' => $merge->sets, 'collateral_usd' => $merge->collateralUsd, 'gas_usd' => $merge->gasUsd, 'tx_hash' => $merge->txHash]);
                } else {
                    $realizeMethod = 'hold';
                    $pnl = round(-$gas, 4);   // collateral recovered at resolution; only gas is lost
                    $pnlLocked = true;
                    $freerollCount = $nOutcomes;
                    $this->event($basketId, 'merge_fail', ['reason' => $merge->error]);
                }
            } else {
                $realizeMethod = 'hold';
                $pnl = round(-$gas, 4);       // hold the complete set to resolution
                $pnlLocked = true;
                $freerollCount = $nOutcomes;
            }
        } else {
            // Banked the sells now; any held leg is contingent upside (value 0 in P&L).
            $allSellableSold = $heldLegs === 0;
            $realizeMethod = $allSellableSold ? 'sold' : 'sold_partial';
            $pnl = round($cashIn - $collateral - $gas, 4);
        }

        DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->update([
            'cash_in_usd' => round($cashIn, 4),
            'realized_pnl_usd' => $pnl,
            'legs_freeroll' => max(0, $freerollCount),
            'realize_method' => $realizeMethod,
            'merge_tx_hash' => $mergeTx !== null ? mb_substr((string) $mergeTx, 0, 120) : $b->merge_tx_hash,
            'updated_at' => now(),
        ]);
        $this->setStatus($basketId, 'settled');
        $this->finalize($basketId, 'settled');
        $this->event($basketId, 'settled', [
            'realize_method' => $realizeMethod,
            'cash_in_usd' => round($cashIn, 4),
            'collateral_usd' => $collateral,
            'gas_usd' => $gas,
            'realized_pnl_usd' => $pnl,
            'pnl_is_locked_at_resolution' => $pnlLocked,
            'legs_sold' => $legsSold,
            'legs_freeroll' => max(0, $freerollCount),
        ]);
        $this->bumpDaily($mode, filled: 1, realizedPnl: $pnl);
        $this->maybeHalt($mode);
    }

    /**
     * @return array{ok: bool, reason: string, fresh_sum: float, fresh_depth: float, net_edge: float}
     */
    private function verifyFreshBids(ShortBasketPlan $plan): array
    {
        $sum = 0.0;
        $depth = INF;
        $sellable = 0;
        foreach ($plan->legs as $leg) {
            $book = ($this->bookSource)($leg['token']);
            $bids = is_array($book) ? ($book['bids'] ?? []) : null;
            if (! is_array($bids) || $bids === []) {
                continue; // a leg that lost its bid simply drops to freeroll
            }
            $best = (float) ($bids[0]['price'] ?? 0.0);
            $floor = (float) $leg['limit_price'];
            if ($best < $floor) {
                continue;
            }
            $legDepth = 0.0;
            foreach ($bids as $lvl) {
                $p = (float) ($lvl['price'] ?? 0);
                if ($p >= $floor && $p < 1.0) {
                    $legDepth += (float) ($lvl['size'] ?? 0);
                }
            }
            if ($legDepth <= 0.0) {
                continue;
            }
            $sum += $best;
            $depth = min($depth, $legDepth);
            $sellable++;
        }

        $sum = round($sum, 6);
        $netEdge = round(($sum - 1.0) - $this->cfg->takerFeeRate * $sum
            - ($plan->targetSets > 0.0 ? $this->cfg->estMintGasUsd / $plan->targetSets : INF), 6);
        $needDepth = $plan->targetSets * $this->cfg->minDepthMultiple;

        if ($sellable < 2 || $sum <= 1.0) {
            return ['ok' => false, 'reason' => 'sum_no_longer_over_1', 'fresh_sum' => $sum, 'fresh_depth' => is_finite($depth) ? round($depth, 4) : 0.0, 'net_edge' => $netEdge];
        }
        if ($netEdge < $this->cfg->minNetEdgePerSet) {
            return ['ok' => false, 'reason' => 'edge_collapsed', 'fresh_sum' => $sum, 'fresh_depth' => round($depth, 4), 'net_edge' => $netEdge];
        }
        if ($depth < $needDepth) {
            return ['ok' => false, 'reason' => 'depth_collapsed', 'fresh_sum' => $sum, 'fresh_depth' => round($depth, 4), 'net_edge' => $netEdge];
        }

        return ['ok' => true, 'reason' => 'fresh_ok', 'fresh_sum' => $sum, 'fresh_depth' => round($depth, 4), 'net_edge' => $netEdge];
    }

    /**
     * Compare expected held shares (minted - sold) against the venue per outcome.
     */
    private function reconcile(string $basketId, ShortBasketPlan $plan): void
    {
        $sets = (float) DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->value('target_sets');
        if ($sets <= 0.0 || (string) DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->value('mint_tx_hash') === '') {
            return; // nothing minted
        }

        // A merge burned the whole set back to collateral → nothing is held.
        $merged = (string) DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->value('realize_method') === 'merge';

        $sold = [];
        foreach (DB::table('atlas_poly_exec_legs')->where('basket_id', $basketId)->get() as $leg) {
            $sold[(string) $leg->token] = (float) $leg->sold_size;
        }

        $discrepancies = [];
        $checked = 0;
        foreach ($plan->allTokenIds as $token) {
            $expectedHeld = $merged ? 0.0 : round($sets - ($sold[$token] ?? 0.0), 4);
            $actual = $this->client->positionSize($token);
            $checked++;
            if ($actual === null) {
                $discrepancies[] = ['token' => $token, 'expected_held' => $expectedHeld, 'actual' => null];

                continue;
            }
            if (abs($actual - $expectedHeld) > 1e-3) {
                $discrepancies[] = ['token' => $token, 'expected_held' => $expectedHeld, 'actual' => round($actual, 4)];
            }
        }

        $this->event($basketId, 'reconcile', [
            'outcomes' => $checked,
            'matched' => $checked - count($discrepancies),
            'discrepancies' => $discrepancies,
        ]);
    }

    // ---- persistence helpers -------------------------------------------------

    private function createBasket(ShortBasketPlan $plan, string $basketId, string $sessionId, string $mode, string $status): void
    {
        DB::table('atlas_poly_exec_baskets')->insert([
            'basket_id' => $basketId,
            'session_id' => $sessionId,
            'mode' => $mode,
            'event_slug' => mb_substr($plan->eventSlug, 0, 180),
            'kind' => $plan->kind,
            'execution_class' => $plan->executionClass,
            'status' => $status,
            'n_legs' => $plan->nLegs(),
            'legs_filled' => 0,
            'legs_sold' => 0,
            'legs_freeroll' => count($plan->freerollTokens),
            'target_sets' => $plan->targetSets,
            'target_sum' => $plan->targetSumBids,
            'target_cost_usd' => $plan->mintCostUsd,
            'est_profit_usd' => $plan->estProfitUsd,
            'est_edge_per_set' => $plan->estEdgePerSet,
            'est_fee_usd' => $plan->estFeeUsd,
            'est_gas_usd' => $plan->estGasUsd,
            'cap_usd' => $plan->capUsd,
            'slippage_bps' => $plan->slippageBps,
            'resolution_at' => $plan->resolutionAt,
            'plan' => json_encode($plan->toArray()),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($plan->legs as $leg) {
            DB::table('atlas_poly_exec_legs')->insert([
                'basket_id' => $basketId,
                'position' => $leg['position'],
                'token' => mb_substr((string) $leg['token'], 0, 80),
                'question' => mb_substr((string) $leg['question'], 0, 300),
                'side' => 'sell',
                'plan_depth' => $leg['plan_depth'],
                'target_price' => $leg['target_price'],
                'limit_price' => $leg['limit_price'],
                'target_size' => $leg['target_size'],
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->event($basketId, 'state_change', ['to' => $status, 'mode' => $mode, 'plan' => $plan->toArray()]);
    }

    private function setStatus(string $basketId, string $status): void
    {
        DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)
            ->update(['status' => $status, 'updated_at' => now()]);
        $this->event($basketId, 'state_change', ['to' => $status]);
    }

    private function finalize(string $basketId, string $status): void
    {
        DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)
            ->update(['status' => $status, 'finalized_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function event(string $basketId, string $kind, array $detail): void
    {
        $seq = (int) DB::table('atlas_poly_exec_events')->where('basket_id', $basketId)->max('seq') + 1;
        DB::table('atlas_poly_exec_events')->insert([
            'basket_id' => $basketId,
            'seq' => $seq,
            'kind' => $kind,
            'detail' => json_encode($detail),
            'created_at' => now(),
        ]);
    }

    private function recordGateBlock(string $basketId, string $layer, GateDecision $decision): void
    {
        $this->event($basketId, 'gate_block', [
            'layer' => $layer,
            'failed' => $decision->failedNames(),
            'checks' => $decision->checks,
        ]);
    }

    private function bumpDaily(string $mode, int $attempted = 0, int $filled = 0, int $aborted = 0, float $deployed = 0.0, float $realizedPnl = 0.0): void
    {
        $date = Carbon::now()->toDateString();
        $row = DB::table('atlas_poly_exec_daily')->where('trade_date', $date)->where('mode', $mode)->first();
        if ($row === null) {
            DB::table('atlas_poly_exec_daily')->insert([
                'trade_date' => $date,
                'mode' => $mode,
                'deployed_usd' => round($deployed, 4),
                'realized_pnl_usd' => round($realizedPnl, 4),
                'baskets_attempted' => $attempted,
                'baskets_filled' => $filled,
                'baskets_aborted' => $aborted,
                'halted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('atlas_poly_exec_daily')->where('id', $row->id)->update([
            'deployed_usd' => round((float) $row->deployed_usd + $deployed, 4),
            'realized_pnl_usd' => round((float) $row->realized_pnl_usd + $realizedPnl, 4),
            'baskets_attempted' => (int) $row->baskets_attempted + $attempted,
            'baskets_filled' => (int) $row->baskets_filled + $filled,
            'baskets_aborted' => (int) $row->baskets_aborted + $aborted,
            'updated_at' => now(),
        ]);
    }

    private function maybeHalt(string $mode): void
    {
        if ($this->gate->deployedToday($mode) >= $this->cfg->dailyCapUsd) {
            DB::table('atlas_poly_exec_daily')
                ->where('trade_date', Carbon::now()->toDateString())->where('mode', $mode)
                ->update(['halted' => true, 'updated_at' => now()]);
        }
    }

    private function recordReceipt(string $basketId, string $mode): void
    {
        $b = DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->first();
        if ($b === null) {
            return;
        }

        try {
            $this->ledger->record(LedgerEventType::ToolEvidenceRecorded, [
                'tool' => 'atlas:finance:poly-exec',
                'mode' => $mode,
                'signed_real_orders' => $mode === 'live',
                'basket_id' => $basketId,
                'event_slug' => $b->event_slug,
                'kind' => $b->kind,
                'execution_class' => $b->execution_class,
                'status' => $b->status,
                'mint_tx_hash' => $b->mint_tx_hash,
                'merge_tx_hash' => $b->merge_tx_hash,
                'intent' => [
                    'target_sets' => (float) $b->target_sets,
                    'target_sum_bids' => (float) $b->target_sum,
                    'mint_cost_usd' => (float) $b->target_cost_usd,
                    'est_profit_usd' => (float) $b->est_profit_usd,
                    'est_edge_per_set' => (float) $b->est_edge_per_set,
                ],
                'result' => [
                    'legs_sold' => (int) $b->legs_sold,
                    'legs_freeroll' => (int) $b->legs_freeroll,
                    'realize_method' => $b->realize_method,
                    'realized_cost_usd' => (float) $b->realized_cost_usd,
                    'cash_in_usd' => (float) $b->cash_in_usd,
                    'realized_pnl_usd' => (float) $b->realized_pnl_usd,
                    'pnl_is_locked_at_resolution' => $b->realize_method === 'hold',
                ],
            ], [
                'scope_type' => 'finance_poly_exec',
                'scope_id' => $basketId,
            ]);
        } catch (Throwable) {
            // ledger best-effort only — a receipt failure must never undo a real trade
        }
    }

    private function isTerminal(string $status): bool
    {
        return in_array($status, ['settled', 'gated', 'failed', 'halted'], true);
    }

    /** DB::raw-safe decimal literal. */
    private function dec(float $v): string
    {
        return number_format($v, 6, '.', '');
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(string $basketId, bool $idempotentReplay = false): array
    {
        $b = DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->first();
        if ($b === null) {
            return ['basket_id' => $basketId, 'status' => 'unknown'];
        }
        $legs = DB::table('atlas_poly_exec_legs')->where('basket_id', $basketId)->orderBy('position')
            ->get(['position', 'question', 'status', 'target_size', 'sold_size', 'avg_fill_price', 'sold_proceeds_usd', 'freeroll'])
            ->map(fn ($r) => (array) $r)->all();

        $summary = [
            'basket_id' => (string) $b->basket_id,
            'mode' => (string) $b->mode,
            'event_slug' => (string) $b->event_slug,
            'kind' => (string) $b->kind,
            'execution_class' => (string) $b->execution_class,
            'status' => (string) $b->status,
            'n_legs' => (int) $b->n_legs,
            'legs_sold' => (int) $b->legs_sold,
            'legs_freeroll' => (int) $b->legs_freeroll,
            'realize_method' => $b->realize_method,
            'target_sets' => (float) $b->target_sets,
            'target_sum_bids' => (float) $b->target_sum,
            'mint_cost_usd' => (float) $b->target_cost_usd,
            'est_profit_usd' => (float) $b->est_profit_usd,
            'realized_cost_usd' => (float) $b->realized_cost_usd,
            'cash_in_usd' => (float) $b->cash_in_usd,
            'realized_pnl_usd' => (float) $b->realized_pnl_usd,
            'pnl_is_locked_at_resolution' => $b->realize_method === 'hold',
            'mint_tx_hash' => $b->mint_tx_hash,
            'merge_tx_hash' => $b->merge_tx_hash,
            'error' => $b->error,
            'legs' => $legs,
        ];

        if ($idempotentReplay) {
            $summary['idempotent_replay'] = true;
            $summary['status_reason'] = 'terminal_basket_already_recorded';
        }

        return $summary;
    }
}
