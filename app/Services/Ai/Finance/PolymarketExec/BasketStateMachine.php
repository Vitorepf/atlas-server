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
 * The basket execution state machine — the part that touches money in live mode.
 *
 * Invariants (all enforced here, all proven in sim against real books):
 *  - Idempotent + resumable: the whole flow is driven off the persisted basket
 *    and leg rows. A leg with status 'filled' is NEVER re-bought; an unwound leg
 *    is NEVER re-sold. Re-running the same basket_id resumes, it does not double.
 *  - Thinnest leg first: legs are filled in plan order (ascending depth) so an
 *    abort leaves the fewest filled legs to unwind.
 *  - Abort + unwind: if any leg fails to fill within its limit, every already
 *    filled leg is sold to market and the basket ends 'unwound'.
 *  - Kill-switch honored mid-flight: checked before every leg, so a kill during
 *    filling aborts and unwinds rather than completing.
 *  - Fail-closed: any exception ends the basket 'failed' and triggers unwind of
 *    whatever was filled; the residual is surfaced by reconciliation.
 *
 * status: planning -> verifying -> filling -> filled
 *                           \-> gated (quality/cap reject, no position)
 *                  filling/verifying -> aborting -> unwound (had position)
 *                  any -> halted (runtime cap) | failed (exception)
 */
final class BasketStateMachine
{
    private const PRICE_EPS = 1e-6;

    /** @var callable(string): ?array{asks: list<array{price: float, size: float}>, bids: list<array{price: float, size: float}>} */
    private $bookSource;

    private readonly AtlasEvidenceLedger $ledger;

    /**
     * @param  null|callable(string): ?array{asks: list<array{price: float, size: float}>, bids: list<array{price: float, size: float}>}  $bookSource
     */
    /** Optional on-chain client; only used when long_realize_method=merge. Null = carry to resolution (v1 default). */
    private readonly ?PolyOnChainClient $onChain;

    public function __construct(
        private readonly PolyExecConfig $cfg,
        private readonly PolyExecClient $client,
        private readonly PolyExecGate $gate,
        ?callable $bookSource = null,
        ?AtlasEvidenceLedger $ledger = null,
        ?PolyOnChainClient $onChain = null,
    ) {
        $feed = new PolymarketShadowFeed;
        $this->bookSource = $bookSource ?? fn (string $token): ?array => $feed->bookLevels($token);
        $this->ledger = $ledger ?? app(AtlasEvidenceLedger::class);
        $this->onChain = $onChain;
    }

    /**
     * Run (or resume) one basket to a terminal state. Returns the summary.
     *
     * @return array<string, mixed>
     */
    public function execute(BasketPlan $plan, string $basketId, string $sessionId): array
    {
        $mode = $this->client->mode();

        $existing = DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->first();
        if ($existing !== null && $this->isTerminal((string) $existing->status)) {
            return $this->summary($basketId); // idempotent: already done, do nothing
        }

        if ($existing === null) {
            // Runtime caps gate BEFORE we create anything that holds a slot.
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
            // Fail-closed: unwind anything that filled, then mark failed.
            $this->event($basketId, 'state_change', ['to' => 'aborting', 'cause' => 'exception', 'error' => $e->getMessage()]);
            $this->setStatus($basketId, 'aborting');
            $this->unwind($basketId, $mode);
            $this->setStatus($basketId, 'failed');
            DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)
                ->update(['error' => mb_substr($e->getMessage(), 0, 500)]);
            $this->finalize($basketId, 'failed');
            $this->recordReceipt($basketId, $mode);

            return $this->summary($basketId);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function drive(BasketPlan $plan, string $basketId, string $mode): array
    {
        $status = (string) DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->value('status');

        // Quality gate (only fresh baskets; a resumed 'filling' one already passed).
        if ($status === 'planning') {
            $opp = $this->gate->checkOpportunity($plan->toGateInput(), $mode);
            if (! $opp->allowed) {
                $this->recordGateBlock($basketId, 'opportunity', $opp);
                $this->setStatus($basketId, 'gated');
                $this->finalize($basketId, 'gated');
                $this->recordReceipt($basketId, $mode);

                return $this->summary($basketId);
            }

            // Verify against a FRESH book right before committing capital.
            $this->setStatus($basketId, 'verifying');
            $fresh = $this->verifyFreshBook($plan);
            $this->event($basketId, 'state_change', ['to' => 'verifying'] + $fresh);
            if (! $fresh['ok']) {
                $this->setStatus($basketId, 'gated');
                DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)
                    ->update(['error' => 'verify: '.$fresh['reason']]);
                $this->finalize($basketId, 'gated');
                $this->recordReceipt($basketId, $mode);

                return $this->summary($basketId);
            }

            $this->setStatus($basketId, 'filling');
            $status = 'filling';
        }

        if ($status === 'filling') {
            $this->fillLegs($basketId, $mode);
            $status = (string) DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->value('status');
        }

        // Early realize: merge the held set back to $1 now instead of waiting for
        // resolution. Default-off; a no-op unless long_realize_method=merge AND an
        // on-chain client is wired. On any failure we keep the carried position.
        if ($status === 'filled') {
            $this->maybeMerge($plan, $basketId, $mode);
        }

        // Resumed mid-unwind.
        if ($status === 'aborting') {
            $this->unwind($basketId, $mode);
            $this->setStatus($basketId, 'unwound');
            $this->finalize($basketId, 'unwound');
        }

        $this->reconcile($basketId);
        $this->recordReceipt($basketId, $mode);

        return $this->summary($basketId);
    }

    /**
     * Fill legs in plan order (thinnest first), skipping any already filled.
     * Any failure flips the basket to 'aborting'.
     */
    private function fillLegs(string $basketId, string $mode): void
    {
        $legs = DB::table('atlas_poly_exec_legs')->where('basket_id', $basketId)->orderBy('position')->get();

        foreach ($legs as $leg) {
            if ($leg->status === 'filled') {
                continue; // resume: never re-buy
            }

            // Mid-flight kill-switch: abort and unwind rather than keep buying.
            if ($this->cfg->killSwitchEngaged()) {
                $this->event($basketId, 'kill', ['at_position' => $leg->position]);
                $this->beginAbort($basketId, $mode, 'kill_switch');

                return;
            }

            $limit = (float) $leg->limit_price;
            $size = (float) $leg->target_size;
            $fill = $this->client->buyLimit((string) $leg->token, $limit, $size);

            // Acceptable only if (near-)complete AND within the limit price.
            $withinLimit = $fill->ok && $fill->avgPrice <= $limit + self::PRICE_EPS;
            if (! $fill->isComplete($size) || ! $withinLimit) {
                // Record whatever partially filled so unwind can undo it.
                DB::table('atlas_poly_exec_legs')->where('id', $leg->id)->update([
                    'status' => $fill->filledSize > 0.0 ? 'partial' : 'failed',
                    'filled_size' => $fill->filledSize,
                    'avg_fill_price' => $fill->avgPrice,
                    'cost_usd' => $fill->cashUsd,
                    'order_id' => $fill->orderId,
                    'error' => $fill->error ?? ($withinLimit ? 'incomplete_fill' : 'slippage_breach'),
                    'updated_at' => now(),
                ]);
                if ($fill->filledSize > 0.0) {
                    DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)
                        ->increment('realized_cost_usd', $fill->cashUsd);
                }
                $this->event($basketId, 'leg_fail', [
                    'position' => $leg->position,
                    'requested' => $size,
                    'filled' => $fill->filledSize,
                    'avg_price' => $fill->avgPrice,
                    'limit' => $limit,
                    'reason' => $fill->error ?? ($withinLimit ? 'incomplete_fill' : 'slippage_breach'),
                ]);
                $this->beginAbort($basketId, $mode, 'leg_fail');

                return;
            }

            DB::table('atlas_poly_exec_legs')->where('id', $leg->id)->update([
                'status' => 'filled',
                'filled_size' => $fill->filledSize,
                'avg_fill_price' => $fill->avgPrice,
                'cost_usd' => $fill->cashUsd,
                'order_id' => $fill->orderId,
                'updated_at' => now(),
            ]);
            DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->update([
                'realized_cost_usd' => DB::raw('realized_cost_usd + '.$this->dec($fill->cashUsd)),
                'legs_filled' => DB::raw('legs_filled + 1'),
                'updated_at' => now(),
            ]);
            $this->event($basketId, 'fill', [
                'position' => $leg->position,
                'size' => $fill->filledSize,
                'avg_price' => $fill->avgPrice,
                'cost_usd' => $fill->cashUsd,
                'order_id' => $fill->orderId,
            ]);
        }

        // Every leg filled — the long basket is complete; position carries to resolution.
        $cost = (float) DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->value('realized_cost_usd');
        $estProfit = (float) DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->value('est_profit_usd');
        DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->update([
            // Cash is still out until resolution; realized_pnl reflects the locked-at-
            // resolution figure (NOT yet-banked profit — honest label is in the receipt).
            'realized_pnl_usd' => round($estProfit, 4),
            'realize_method' => 'hold', // may become 'merge' if early-realize is enabled
            'updated_at' => now(),
        ]);
        $this->setStatus($basketId, 'filled');
        $this->finalize($basketId, 'filled');
        $this->bumpDaily($mode, filled: 1, deployed: $cost);
        $this->maybeHalt($mode);
    }

    private function beginAbort(string $basketId, string $mode, string $cause): void
    {
        $this->event($basketId, 'abort', ['cause' => $cause]);
        $this->setStatus($basketId, 'aborting');
        $this->unwind($basketId, $mode);
        $this->setStatus($basketId, 'unwound');

        $cost = (float) DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->value('realized_cost_usd');
        $proceeds = (float) DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->value('unwind_proceeds_usd');
        DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->update([
            'realized_pnl_usd' => round($proceeds - $cost, 4), // actual cash result of the failed attempt
            'updated_at' => now(),
        ]);
        $this->finalize($basketId, 'unwound');
        // Conservative: the gross capital briefly deployed still counts against the
        // daily cap, so an abort loop cannot churn the budget indefinitely.
        $this->bumpDaily($mode, aborted: 1, deployed: $cost, realizedPnl: round($proceeds - $cost, 4));
        $this->maybeHalt($mode);
    }

    /**
     * Sell every filled/partial leg back to market. Idempotent: a leg already
     * unwound (status 'unwound') is skipped.
     */
    private function unwind(string $basketId, string $mode): void
    {
        $legs = DB::table('atlas_poly_exec_legs')
            ->where('basket_id', $basketId)
            ->whereIn('status', ['filled', 'partial'])
            ->where('filled_size', '>', 0)
            ->get();

        foreach ($legs as $leg) {
            $size = (float) $leg->filled_size;
            $sell = $this->client->sellMarket((string) $leg->token, $size);
            DB::table('atlas_poly_exec_legs')->where('id', $leg->id)->update([
                'status' => 'unwound',
                'unwind_size' => $sell->filledSize,
                'unwind_proceeds_usd' => $sell->cashUsd,
                'unwind_order_id' => $sell->orderId,
                'error' => $sell->ok ? $leg->error : trim((string) $leg->error.' unwind:'.($sell->error ?? 'failed')),
                'updated_at' => now(),
            ]);
            if ($sell->cashUsd > 0.0) {
                DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)
                    ->update(['unwind_proceeds_usd' => DB::raw('unwind_proceeds_usd + '.$this->dec($sell->cashUsd)), 'updated_at' => now()]);
            }
            $this->event($basketId, 'unwind', [
                'position' => $leg->position,
                'size' => $sell->filledSize,
                'proceeds_usd' => $sell->cashUsd,
                'ok' => $sell->ok,
                'reason' => $sell->error,
            ]);
        }
    }

    /**
     * Compare expected position (filled shares) against the venue. In sim this
     * always matches; live reads the data API. A mismatch is recorded, never
     * silently ignored.
     */
    private function reconcile(string $basketId): void
    {
        $legs = DB::table('atlas_poly_exec_legs')->where('basket_id', $basketId)->where('status', 'filled')->get();
        if ($legs->isEmpty()) {
            return;
        }

        $discrepancies = [];
        foreach ($legs as $leg) {
            $expected = (float) $leg->filled_size;
            $actual = $this->client->positionSize((string) $leg->token);
            if ($actual === null) {
                $discrepancies[] = ['position' => $leg->position, 'expected' => $expected, 'actual' => null];

                continue;
            }
            if (abs($actual - $expected) > 1e-3) {
                $discrepancies[] = ['position' => $leg->position, 'expected' => $expected, 'actual' => $actual];
            }
        }

        $this->event($basketId, 'reconcile', [
            'filled_legs' => $legs->count(),
            'matched' => $legs->count() - count($discrepancies),
            'discrepancies' => $discrepancies,
        ]);
    }

    /**
     * Realize a completed long basket early by merging the held full set back to
     * $1 on-chain — banking the profit now instead of waiting for resolution.
     * Default-off and fail-safe: a no-op unless configured + wired, and any merge
     * failure leaves the carried-to-resolution position untouched (the safe state).
     */
    private function maybeMerge(BasketPlan $plan, string $basketId, string $mode): void
    {
        if ($this->cfg->longRealizeMethod !== 'merge' || $this->onChain === null) {
            return; // carry to resolution (proven v1 default)
        }

        $sets = (float) DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->value('target_sets');
        if ($sets <= 0.0) {
            return;
        }

        $merge = $this->onChain->mergeFullSet((string) ($plan->conditionId ?? ''), $plan->tokenIds(), $sets, $plan->negRisk);
        if (! $merge->ok) {
            $this->event($basketId, 'merge_fail', ['reason' => $merge->error]);

            return; // hold: the position carries to resolution, exactly as without merge
        }

        $cost = (float) DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->value('realized_cost_usd');
        $proceeds = round($merge->collateralUsd, 4); // sets * $1 returned
        $pnl = round($proceeds - $cost - $merge->gasUsd, 4); // banked NOW

        DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->update([
            'cash_in_usd' => $proceeds,
            'merge_tx_hash' => mb_substr((string) $merge->txHash, 0, 120),
            'realize_method' => 'merge',
            'realized_pnl_usd' => $pnl, // banked, no longer locked at resolution
            'updated_at' => now(),
        ]);
        $this->event($basketId, 'merge', [
            'sets' => $merge->sets,
            'proceeds_usd' => $proceeds,
            'gas_usd' => $merge->gasUsd,
            'tx_hash' => $merge->txHash,
            'real_tx' => $merge->realTx,
            'realized_pnl_usd' => $pnl,
        ]);
        $this->bumpDaily($mode, realizedPnl: $pnl);
    }

    /**
     * @return array{ok: bool, reason: string, fresh_sum: float, fresh_depth: float, net_edge: float}
     */
    private function verifyFreshBook(BasketPlan $plan): array
    {
        $sum = 0.0;
        $depth = INF;
        foreach ($plan->legs as $leg) {
            $book = ($this->bookSource)($leg['token']);
            $asks = is_array($book) ? ($book['asks'] ?? []) : null;
            if (! is_array($asks) || $asks === []) {
                return ['ok' => false, 'reason' => 'leg_unreadable', 'fresh_sum' => 0.0, 'fresh_depth' => 0.0, 'net_edge' => 0.0];
            }
            $best = (float) ($asks[0]['price'] ?? 1.0);
            $limit = (float) $leg['limit_price'];
            $legDepth = 0.0;
            foreach ($asks as $lvl) {
                $p = (float) ($lvl['price'] ?? 0);
                if ($p > 0.0 && $p <= $limit) {
                    $legDepth += (float) ($lvl['size'] ?? 0);
                }
            }
            $sum += $best;
            $depth = min($depth, $legDepth);
        }

        $sum = round($sum, 6);
        $netEdge = round((1.0 - $sum) - $this->cfg->takerFeeRate * $sum
            - ($plan->targetSets > 0.0 ? $this->cfg->estGasUsdPerBasket / $plan->targetSets : INF), 6);
        $needDepth = $plan->targetSets * $this->cfg->minDepthMultiple;

        if ($sum >= 1.0) {
            return ['ok' => false, 'reason' => 'sum_no_longer_under_1', 'fresh_sum' => $sum, 'fresh_depth' => $depth, 'net_edge' => $netEdge];
        }
        if ($netEdge < $this->cfg->minNetEdgePerSet) {
            return ['ok' => false, 'reason' => 'edge_collapsed', 'fresh_sum' => $sum, 'fresh_depth' => $depth, 'net_edge' => $netEdge];
        }
        if ($depth < $needDepth) {
            return ['ok' => false, 'reason' => 'depth_collapsed', 'fresh_sum' => $sum, 'fresh_depth' => $depth, 'net_edge' => $netEdge];
        }

        return ['ok' => true, 'reason' => 'fresh_ok', 'fresh_sum' => $sum, 'fresh_depth' => round($depth, 4), 'net_edge' => $netEdge];
    }

    // ---- persistence helpers -------------------------------------------------

    private function createBasket(BasketPlan $plan, string $basketId, string $sessionId, string $mode, string $status): void
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
            'target_sets' => $plan->targetSets,
            'target_sum' => $plan->targetSum,
            'target_cost_usd' => $plan->targetCostUsd,
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

        // A receipt-write failure must NEVER undo a completed trade — record it
        // best-effort and swallow, so the ledger can't trigger a spurious unwind.
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
                'intent' => [
                    'target_sets' => (float) $b->target_sets,
                    'target_cost_usd' => (float) $b->target_cost_usd,
                    'est_profit_usd' => (float) $b->est_profit_usd,
                    'est_edge_per_set' => (float) $b->est_edge_per_set,
                ],
                'result' => [
                    'legs_filled' => (int) $b->legs_filled,
                    'n_legs' => (int) $b->n_legs,
                    'realize_method' => $b->realize_method,
                    'realized_cost_usd' => (float) $b->realized_cost_usd,
                    'unwind_proceeds_usd' => (float) $b->unwind_proceeds_usd,
                    'cash_in_usd' => (float) $b->cash_in_usd,
                    'merge_tx_hash' => $b->merge_tx_hash,
                    'realized_pnl_usd' => (float) $b->realized_pnl_usd,
                    'pnl_is_locked_at_resolution' => $b->status === 'filled' && ($b->realize_method ?? 'hold') !== 'merge',
                ],
            ], [
                'scope_type' => 'finance_poly_exec',
                'scope_id' => $basketId,
            ]);
        } catch (Throwable) {
            // ledger best-effort only
        }
    }

    private function isTerminal(string $status): bool
    {
        return in_array($status, ['filled', 'unwound', 'halted', 'gated', 'failed'], true);
    }

    /** DB::raw-safe decimal literal. */
    private function dec(float $v): string
    {
        return number_format($v, 6, '.', '');
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(string $basketId): array
    {
        $b = DB::table('atlas_poly_exec_baskets')->where('basket_id', $basketId)->first();
        if ($b === null) {
            return ['basket_id' => $basketId, 'status' => 'unknown'];
        }
        $legs = DB::table('atlas_poly_exec_legs')->where('basket_id', $basketId)->orderBy('position')
            ->get(['position', 'question', 'status', 'target_size', 'filled_size', 'avg_fill_price', 'cost_usd', 'unwind_proceeds_usd'])
            ->map(fn ($r) => (array) $r)->all();

        return [
            'basket_id' => (string) $b->basket_id,
            'mode' => (string) $b->mode,
            'event_slug' => (string) $b->event_slug,
            'kind' => (string) $b->kind,
            'status' => (string) $b->status,
            'n_legs' => (int) $b->n_legs,
            'legs_filled' => (int) $b->legs_filled,
            'target_sets' => (float) $b->target_sets,
            'target_cost_usd' => (float) $b->target_cost_usd,
            'est_profit_usd' => (float) $b->est_profit_usd,
            'realize_method' => $b->realize_method,
            'realized_cost_usd' => (float) $b->realized_cost_usd,
            'unwind_proceeds_usd' => (float) $b->unwind_proceeds_usd,
            'cash_in_usd' => (float) $b->cash_in_usd,
            'merge_tx_hash' => $b->merge_tx_hash,
            'realized_pnl_usd' => (float) $b->realized_pnl_usd,
            'pnl_is_locked_at_resolution' => $b->status === 'filled' && ($b->realize_method ?? 'hold') !== 'merge',
            'error' => $b->error,
            'legs' => $legs,
        ];
    }
}
