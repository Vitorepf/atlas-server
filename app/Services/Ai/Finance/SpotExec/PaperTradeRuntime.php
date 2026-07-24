<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\SpotExec;

use App\Services\Ai\Finance\StrategyLoop\Strategy\StrategyRunner;
use App\Support\UtcIsoTimestamp;

/**
 * PAPER TRADING em dados ao vivo — a ponte entre a pesquisa (backtest congelado)
 * e o dinheiro real. Roda uma estratégia das famílias do loop contra barras
 * FECHADAS ao vivo da Binance e simula fills com custos reais. ZERO dinheiro:
 * nenhuma ordem é enviada a lugar nenhum; o "broker" é aritmética local.
 *
 * Desenho honesto:
 *   - decide APENAS em barra fechada (mesma disciplina do backtest: decide
 *     close[t], executa "open[t+1]" ≈ mid ao vivo no tick seguinte ao fechamento);
 *   - idempotente e reiniciável: o estado (caixa, posição, último bar processado)
 *     persiste em JSON; rodar duas vezes no mesmo bar é no-op;
 *   - reconciliação: se o runtime perdeu ticks (máquina dormiu), o próximo tick
 *     realinha a posição paper com a posição que o engine quer no último bar;
 *   - DIVERGÊNCIA DOCUMENTADA do backtest: sizing aqui é all-in da banca paper
 *     (cash/(1+fee)) em vez do risk-sizing por ATR do engine — o paper valida a
 *     QUALIDADE DO SINAL forward, não replica o sizing. Comparar % por trade.
 */
final class PaperTradeRuntime
{
    public function __construct(
        private readonly MarketSource $market,
        private readonly string $stateDir,
    ) {}

    /**
     * Um tick idempotente de uma config paper.
     *
     * @param  array{id:string,symbol:string,interval:string,family:string,params:array<string,mixed>,window?:int}  $config
     * @return array<string,mixed>  status do tick (sempre inclui 'status')
     */
    public function tick(array $config, StrategyRunner $engine): array
    {
        $id = (string) $config['id'];
        $symbol = strtoupper((string) $config['symbol']);
        $interval = (string) $config['interval'];
        $params = (array) $config['params'];
        $window = max(100, (int) ($config['window'] ?? 600));

        $bars = $this->market->closedBars($symbol, $interval, $window);
        if (count($bars) < 50) {
            return ['status' => 'insufficient_bars', 'bars' => count($bars)];
        }

        $state = $this->readState($id) ?? [
            'config' => ['symbol' => $symbol, 'interval' => $interval, 'family' => $config['family'], 'params' => $params],
            'cash' => 1.0,
            'units' => 0.0,
            'entry_price' => 0.0,
            'entry_at' => null,
            'last_close_time' => 0,
            'fills' => 0,
            'started_at' => UtcIsoTimestamp::now(),
        ];

        $last = $bars[count($bars) - 1];
        if ($last->closeTime <= (int) $state['last_close_time']) {
            return ['status' => 'no_new_bar', 'last_close_time' => $state['last_close_time']];
        }

        $feeRate = max(0.0, (float) ($params['fee_bps'] ?? 10.0)) / 10000.0;
        $slip = max(0.0, (float) ($params['slippage_bps'] ?? 5.0)) / 10000.0;

        // O engine decide; o paper executa o delta entre o desejado e o atual.
        $result = $engine->run($bars, $params);
        $desiredLong = match ($result->pendingAction) {
            'enter' => true,
            'exit' => false,
            default => $result->openPosition !== null,
        };
        $paperLong = (float) $state['units'] > 0.0;

        $mid = $this->market->mid($symbol) ?? $last->close; // fallback honesto: último close
        $action = 'hold';

        if ($desiredLong && ! $paperLong) {
            $fill = $mid * (1.0 + $slip);
            $notional = ((float) $state['cash']) / (1.0 + $feeRate);
            $qty = $fill > 0.0 ? $notional / $fill : 0.0;
            if ($qty > 0.0) {
                $fee = $notional * $feeRate;
                $state['cash'] = (float) $state['cash'] - $notional - $fee;
                $state['units'] = $qty;
                $state['entry_price'] = $fill;
                $state['entry_at'] = UtcIsoTimestamp::now();
                $state['fills'] = (int) $state['fills'] + 1;
                $action = 'buy';
                $this->appendRow($id, 'fills.jsonl', [
                    'at' => UtcIsoTimestamp::now(), 'side' => 'buy', 'price' => $fill, 'qty' => $qty,
                    'fee' => $fee, 'bar_close_time' => $last->closeTime, 'signal' => $result->pendingAction ?? 'reconcile',
                ]);
            }
        } elseif (! $desiredLong && $paperLong) {
            $fill = $mid * (1.0 - $slip);
            $notional = (float) $state['units'] * $fill;
            $fee = $notional * $feeRate;
            $state['cash'] = (float) $state['cash'] + $notional - $fee;
            $tradeReturn = (float) $state['entry_price'] > 0.0 ? $fill / (float) $state['entry_price'] - 1.0 : 0.0;
            $action = 'sell';
            $state['fills'] = (int) $state['fills'] + 1;
            $this->appendRow($id, 'fills.jsonl', [
                'at' => UtcIsoTimestamp::now(), 'side' => 'sell', 'price' => $fill, 'qty' => $state['units'],
                'fee' => $fee, 'return' => $tradeReturn, 'bar_close_time' => $last->closeTime,
                'signal' => $result->pendingAction ?? 'reconcile',
            ]);
            $state['units'] = 0.0;
            $state['entry_price'] = 0.0;
            $state['entry_at'] = null;
        }

        $equity = (float) $state['cash'] + (float) $state['units'] * $mid;
        $state['last_close_time'] = $last->closeTime;
        $state['equity'] = $equity;
        $state['updated_at'] = UtcIsoTimestamp::now();
        $this->writeState($id, $state);
        $this->appendRow($id, 'equity.jsonl', [
            'at' => UtcIsoTimestamp::now(), 'bar_close_time' => $last->closeTime, 'equity' => $equity,
            'action' => $action, 'mid' => $mid, 'desired_long' => $desiredLong,
            'pending_action' => $result->pendingAction, 'engine_open' => $result->openPosition !== null,
        ]);

        return [
            'status' => 'ticked',
            'action' => $action,
            'equity' => $equity,
            'desired_long' => $desiredLong,
            'paper_long' => (float) $state['units'] > 0.0,
            'mid' => $mid,
            'bar_close_time' => $last->closeTime,
        ];
    }

    /** @return array<string,mixed>|null */
    public function readState(string $id): ?array
    {
        $file = $this->dirFor($id).'/state.json';
        if (! is_file($file)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $state */
    private function writeState(string $id, array $state): void
    {
        $dir = $this->ensureDir($id);
        file_put_contents($dir.'/state.json', json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string,mixed> $row */
    private function appendRow(string $id, string $file, array $row): void
    {
        $dir = $this->ensureDir($id);
        file_put_contents($dir.'/'.$file, json_encode($row, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
    }

    private function ensureDir(string $id): string
    {
        $dir = $this->dirFor($id);
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        return $dir;
    }

    private function dirFor(string $id): string
    {
        $safe = preg_replace('/[^a-z0-9\-_]/i', '-', $id) ?? $id;

        return rtrim($this->stateDir, '/').'/'.$safe;
    }
}
