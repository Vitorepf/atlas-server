<?php

declare(strict_types=1);

namespace App\Services\Ai\Organism\Finance;

use App\Services\Ai\Finance\StrategyLoop\Bar;
use App\Services\Ai\Finance\StrategyLoop\Strategy\MeanReversionStrategy;
use App\Services\Ai\Finance\StrategyLoop\Strategy\StrategyRunner;
use App\Services\Ai\Organism\DomainProposal;
use App\Services\Ai\Organism\DomainProposer;
use Throwable;

/**
 * AOBG N4.F2 — the FINANCE {@see DomainProposer}: the REAL strategy-loop generation, REUSED.
 *
 * F1 produced a deterministic trade-IDEA description. F2 plugs the proposer into the SHIPPED
 * strategy-evolution loop's generation path: when the on-machine payload carries OHLCV
 * `bars` (the same bars the loop backtests), this REUSES a real {@see StrategyRunner} (the
 * shipped {@see MeanReversionStrategy} by default — the long-only spot family) to run an
 * actual in-process backtest, and folds the produced equity curve + per-bar returns into the
 * proposal payload as the on-machine validator input. We REUSE the loop's backtest engine;
 * we do NOT reimplement trading logic.
 *
 * COST: the real generation here is the in-process backtest reduction — NO provider, NO
 * subprocess, NO network, NO order. (A FUTURE provider-backed proposer would be GATED behind
 * a flag + cost guard and MUST still run on-machine — finance is sensitive.) For cost-free
 * tests a caller may pass `daily_returns`/`equity_curve` directly, or inject a runner stub.
 *
 * SENSITIVE: finance is sensitive (per {@see \App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap}).
 * Generation stays ON-MACHINE; the proposal is provider-safe (a trade IDEA + citations, never
 * an order, never keys, never bars), and the structured payload is the on-machine validator
 * input only — the {@see DomainProposal} drops it from the provider-safe view by construction.
 *
 * Either way the output is a PROPOSE-ONLY {@see DomainProposal}: a description the operator
 * may choose to execute themselves. The honest validation is {@see FinanceDomainValidator}.
 */
final class FinanceDomainProposer implements DomainProposer
{
    public const DOMAIN = 'finance';

    public function __construct(private readonly ?StrategyRunner $runner = null) {}

    public function propose(string $intent, array $brainContext = [], array $opts = []): DomainProposal
    {
        $payload = is_array($opts['payload'] ?? null) ? $opts['payload'] : [];

        $brainRefs = [];
        foreach ((array) ($brainContext['brain_refs'] ?? []) as $ref) {
            if (is_string($ref) && trim($ref) !== '') {
                $brainRefs[] = trim($ref);
            }
        }

        // Cross-domain compounding signal (provider-safe labels): how many prior finance
        // proposals the brain already holds — folded into the rationale, never copied raw.
        $priorSeen = count((array) ($brainContext['prior_proposals'] ?? []));

        // REUSE the real strategy-loop backtest when bars are supplied (on-machine, cost-free).
        // This is what makes the proposal a REAL generated candidate, not just a label — the
        // produced returns/equity are exactly what the honest validator scores.
        $generated = $this->generateCandidate($payload);
        $payload = $generated['payload'];
        $instrument = trim((string) ($payload['instrument'] ?? 'BTC/USDT spot (daily)'));

        $rationale = 'Brain-anchored finance proposal'
            .($brainRefs !== [] ? ' citing '.count($brainRefs).' brain ref(s)' : ' (no brain refs)')
            .'; '.$priorSeen.' prior finance proposal(s) considered (cross-domain compounding).'
            .' Candidate generated '.$generated['how'].'.'
            .' Validated on the honest metric (N-deflated Deflated Sharpe / PBO / sealed holdout,'
            .' or in-process annualized Sharpe), NOT win-rate.';

        return DomainProposal::fromArray([
            'domain' => self::DOMAIN,
            'intent' => $intent,
            // PROVIDER-SAFE content: a trade IDEA description — never an order ticket,
            // never keys, never a position to send anywhere. Propose-only by construction.
            'content' => 'Trade idea on '.$instrument.': '.$intent
                .'. PROPOSE-ONLY — Atlas does not place orders or move money; review and, if you choose, execute it yourself.',
            'rationale' => $rationale,
            'brain_refs' => $brainRefs,
            'sensitive' => true, // finance is always sensitive — stays on-machine
            'payload' => $payload, // on-machine validator input only (e.g. daily_returns/equity_curve)
        ]);
    }

    public function domain(): string
    {
        return self::DOMAIN;
    }

    public function label(): string
    {
        return 'finance.strategy_loop_backtest.on_machine.propose_only';
    }

    /**
     * Produce the candidate the validator scores. Preference order, all ON-MACHINE + cost-free:
     *   1. payload already carries scorable returns ⇒ use them as-is (a pre-computed candidate
     *      from the strategy loop, or a test fixture);
     *   2. payload carries OHLCV `bars` ⇒ run the REAL {@see StrategyRunner} backtest in-process
     *      to derive equity_curve + daily_returns (this is the loop's generation, reused);
     *   3. otherwise ⇒ a bare trade-idea with no candidate (the validator scores honest-empty).
     *
     * @param  array<string,mixed>  $payload
     * @return array{payload: array<string,mixed>, how: string}
     */
    private function generateCandidate(array $payload): array
    {
        // 1) Already-scorable candidate present — keep it (don't re-run; respect supplied data).
        $hasReturns = is_array($payload['daily_returns'] ?? null) && count($payload['daily_returns']) >= 2;
        $hasReturns = $hasReturns || (is_array($payload['returns'] ?? null) && count($payload['returns']) >= 2);
        $hasReturns = $hasReturns || (is_array($payload['winner_daily_returns'] ?? null) && count($payload['winner_daily_returns']) >= 2);
        if ($hasReturns) {
            return ['payload' => $payload, 'how' => 'from supplied candidate returns (on-machine)'];
        }

        // 2) Bars present — run the REAL strategy-loop backtest in-process (reuse, cost-free).
        $bars = $this->bars($payload['bars'] ?? []);
        if (count($bars) >= 2) {
            $runner = $this->runner ?? new MeanReversionStrategy;
            $params = is_array($payload['strategy_params'] ?? null) ? $payload['strategy_params'] : [];
            try {
                $result = $runner->run($bars, $params);
                $payload['equity_curve'] = $result->equityCurve;
                $payload['daily_returns'] = $result->dailyReturns;
                $payload['n_trades'] = $result->nTrades;

                return [
                    'payload' => $payload,
                    'how' => 'by an on-machine '.class_basename($runner).' backtest over '.count($bars).' bars (strategy-loop reuse, NO provider/order)',
                ];
            } catch (Throwable) {
                // A broken bar series should never fabricate a candidate; fall through to
                // honest-empty so the validator reports no_returns rather than inventing one.
                return ['payload' => $payload, 'how' => 'unsuccessfully (bar series rejected) — honest-empty candidate'];
            }
        }

        // 3) No candidate — honest-empty. The validator will report no_returns.
        return ['payload' => $payload, 'how' => 'without a scorable candidate (no bars/returns) — honest-empty'];
    }

    /**
     * Build {@see Bar} objects from on-machine OHLCV rows (the loop's bar shape). Rows missing
     * a usable close are skipped — never invent price history.
     *
     * @param  mixed  $rows
     * @return list<Bar>
     */
    private function bars($rows): array
    {
        if (! is_array($rows)) {
            return [];
        }
        $bars = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $close = $row['close'] ?? $row['c'] ?? null;
            if (! is_numeric($close)) {
                continue;
            }
            $open = is_numeric($row['open'] ?? $row['o'] ?? null) ? (float) ($row['open'] ?? $row['o']) : (float) $close;
            $high = is_numeric($row['high'] ?? $row['h'] ?? null) ? (float) ($row['high'] ?? $row['h']) : max($open, (float) $close);
            $low = is_numeric($row['low'] ?? $row['l'] ?? null) ? (float) ($row['low'] ?? $row['l']) : min($open, (float) $close);
            $vol = is_numeric($row['volume'] ?? $row['v'] ?? null) ? (float) ($row['volume'] ?? $row['v']) : 0.0;
            $openTime = (int) ($row['open_time'] ?? $row['openTime'] ?? 0);
            $closeTime = (int) ($row['close_time'] ?? $row['closeTime'] ?? $openTime);
            $bars[] = new Bar($openTime, $open, $high, $low, (float) $close, $vol, $closeTime);
        }

        return $bars;
    }
}
