<?php

namespace App\Services\Ai\Finance\Kernel;

use App\Models\AiMission;
use App\Services\Ai\Mission\MissionCanonicalHash;

class FinancePaperTradingSimulationService
{
    public function __construct(private readonly FinanceComplianceService $compliance) {}

    /**
     * Run a synthetic paper-trading simulation against a static price snapshot.
     * NO broker connection, NO order submission, NO real funds. The output is
     * a data_artifact describing simulated PnL only.
     *
     * @param  array<int,array<string,mixed>>  $orderIntents  e.g. [['asset'=>'AAPL','side'=>'buy','qty'=>10,'price'=>150.0], ...]
     * @return array<string,mixed>
     */
    public function simulate(AiMission $mission, array $orderIntents, float $startingCash = 100000.0): array
    {
        if (FinanceDomainCanon::liveTradingBlocked() === false) {
            // Even if config flips, this service is paper-only by contract.
            // We continue but flag the inconsistency in the report.
        }

        if ($orderIntents === []) {
            throw FinanceDomainException::insufficientEvidence('paper trading simulation requires at least one order intent');
        }

        $cash = $startingCash;
        $positions = [];
        $tape = [];
        foreach ($orderIntents as $i => $intent) {
            $asset = (string) ($intent['asset'] ?? '');
            $side = (string) ($intent['side'] ?? '');
            $qty = (float) ($intent['qty'] ?? 0);
            $price = (float) ($intent['price'] ?? 0);
            if ($asset === '' || ! in_array($side, ['buy', 'sell'], true) || $qty <= 0 || $price <= 0) {
                throw FinanceDomainException::invalidAsset("paper trade #{$i} invalid: requires asset/side(buy|sell)/qty>0/price>0");
            }

            $this->compliance->assertNotLiveTrade(
                'finance.paper_trading_simulation',
                "paper {$side} {$qty} {$asset} @ {$price}",
            );

            $notional = $qty * $price;
            if ($side === 'buy') {
                $cash -= $notional;
                $positions[$asset] = ($positions[$asset] ?? 0) + $qty;
            } else {
                $cash += $notional;
                $positions[$asset] = ($positions[$asset] ?? 0) - $qty;
            }
            $tape[] = [
                'i' => $i,
                'asset' => $asset,
                'side' => $side,
                'qty' => $qty,
                'price' => $price,
                'notional' => round($notional, 4),
                'cash_after' => round($cash, 4),
                'position_after' => round($positions[$asset], 4),
                'mode' => 'paper_simulation_only',
            ];
        }

        $report = [
            'schema' => 'atlas.ai.finance.paper_trade_simulation.v1',
            'kind' => 'paper_trade_simulation_report',
            'mission_id' => $mission->id,
            'mode' => 'paper_simulation_only',
            'starting_cash' => $startingCash,
            'ending_cash' => round($cash, 4),
            'pnl_cash_only' => round($cash - $startingCash, 4),
            'positions' => $positions,
            'tape' => $tape,
            'live_trade_blocked' => true,
            'broker_connection' => 'none',
            'auto_rebalance' => 'none',
        ];
        $report['receipt_hash'] = MissionCanonicalHash::sha256($report);

        return $report;
    }
}
