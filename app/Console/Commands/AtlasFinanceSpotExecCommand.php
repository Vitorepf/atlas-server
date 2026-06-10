<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Finance\SpotExec\BinanceSpotOrderClient;
use App\Services\Ai\Finance\SpotExec\SpotExecGate;
use Illuminate\Console\Command;

/**
 * Executor LIVE de Binance spot (Fase B) — o ÚNICO comando que envia ordem real.
 *
 *   preflight  leitura pura: prova credenciais + permissões + saldos (NUNCA ordena)
 *   status     gate + gasto do dia + kill-switch
 *   buy/sell   ordem MARKET real — exige o gate COMPLETO:
 *              live_enabled + ARMED + --confirm + sem kill-switch + caps + allowlist
 *
 * Cada ordem aceita vira recibo em jsonl (ledger append-only). Segredos nunca
 * são logados; erros ecoam só códigos da Binance, nunca a query assinada.
 */
final class AtlasFinanceSpotExecCommand extends Command
{
    protected $signature = 'atlas:finance:spot-exec
        {action : preflight|status|buy|sell}
        {--symbol=BTCUSDT : BTCUSDT|ETHUSDT}
        {--usd=0 : valor em USD (buy: quote a gastar; sell: ignora se --all)}
        {--qty=0 : quantidade base a vender (sell)}
        {--all : sell vende todo o saldo livre do ativo base}
        {--confirm : confirmação explícita exigida para QUALQUER ordem real}
        {--json : saída JSON}';

    protected $description = 'Executor live Binance spot fail-closed: preflight/status sempre; ordens só com o gate completo.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $cfg = (array) config('atlas.finance_spot_exec', []);
        $client = new BinanceSpotOrderClient(
            apiKey: (string) env((string) ($cfg['api_key_env'] ?? ''), ''),
            apiSecret: (string) env((string) ($cfg['api_secret_env'] ?? ''), ''),
            recvWindowMs: (int) ($cfg['recv_window_ms'] ?? 5000),
        );
        $gate = SpotExecGate::fromConfig();

        return match ($action) {
            'preflight' => $this->preflight($client),
            'status' => $this->status($gate, $client),
            'buy' => $this->order($client, $gate, 'BUY'),
            'sell' => $this->order($client, $gate, 'SELL'),
            default => $this->refuse("ação desconhecida: {$action} (use preflight|status|buy|sell)"),
        };
    }

    private function preflight(BinanceSpotOrderClient $client): int
    {
        if (! $client->hasCredentials()) {
            return $this->refuse('credenciais ausentes: defina ATLAS_BINANCE_API_KEY e ATLAS_BINANCE_API_SECRET no .env');
        }
        $acc = $client->account();
        if (! ($acc['ok'] ?? false)) {
            return $this->refuse('preflight falhou: '.json_encode(['error' => $acc['error'] ?? '?', 'code' => $acc['binance_code'] ?? null, 'msg' => $acc['binance_msg'] ?? null]));
        }

        return $this->emit([
            'status' => 'ready',
            'can_trade' => $acc['can_trade'],
            'can_withdraw_MUST_BE_FALSE' => $acc['can_withdraw'],
            'balances' => $acc['balances'],
        ], self::SUCCESS);
    }

    private function status(SpotExecGate $gate, BinanceSpotOrderClient $client): int
    {
        // checkOrder com valor de sonda 1 USD mostra exatamente o que falta para armar.
        $probe = $gate->checkOrder('BTCUSDT', 1.0, confirmed: (bool) $this->option('confirm'));

        return $this->emit([
            'credentials_present' => $client->hasCredentials(),
            'would_allow_1usd_order' => $probe['allowed'],
            'blocking_reasons' => $probe['reasons'],
            'spent_today_usd' => $gate->spentTodayUsd(),
            'kill_switch' => $gate->killSwitchEngaged() ? 'ENGAGED' : 'clear',
            'kill_switch_path' => $gate->killSwitchPath(),
        ], self::SUCCESS);
    }

    private function order(BinanceSpotOrderClient $client, SpotExecGate $gate, string $side): int
    {
        $symbol = strtoupper((string) $this->option('symbol'));
        $usd = (float) $this->option('usd');
        $qty = (float) $this->option('qty');
        $sellAll = (bool) $this->option('all');

        // SELL --all: resolve a quantidade antes do gate (o cap usa valor estimado).
        if ($side === 'SELL' && $sellAll) {
            $base = str_ends_with($symbol, 'USDT') ? substr($symbol, 0, -4) : $symbol;
            $free = $client->freeBalance($base);
            if ($free === null || $free <= 0.0) {
                return $this->refuse("sem saldo livre de {$base} para vender");
            }
            $qty = $free;
        }

        // Estimativa de valor para o gate (sell por qty usa o mid público).
        $gateUsd = $usd;
        if ($side === 'SELL' && $qty > 0.0) {
            $mid = (new \App\Services\Ai\Finance\SpotExec\BinanceLiveMarket)->mid($symbol);
            if ($mid === null) {
                return $this->refuse('não consegui obter o mid para estimar o valor da venda (rede?)');
            }
            $gateUsd = $qty * $mid;
        }

        $check = $gate->checkOrder($symbol, $gateUsd, confirmed: (bool) $this->option('confirm'));
        if (! $check['allowed']) {
            return $this->refuse('ORDEM RECUSADA pelo gate: '.implode(' | ', $check['reasons']));
        }

        $res = $side === 'BUY'
            ? $client->placeMarketOrder($symbol, 'BUY', $usd)
            : ($qty > 0.0 ? $client->placeMarketSellQty($symbol, $qty) : $client->placeMarketOrder($symbol, 'SELL', $usd));

        if (! ($res['ok'] ?? false)) {
            return $this->refuse('ordem falhou na Binance: '.json_encode(['error' => $res['error'] ?? '?', 'code' => $res['binance_code'] ?? null, 'msg' => $res['binance_msg'] ?? null]));
        }

        $gate->recordSpend((float) ($res['quote_spent'] ?? $gateUsd));
        $receipt = [
            'at' => gmdate('c'),
            'side' => $side,
            'symbol' => $symbol,
            'order_id' => $res['order_id'] ?? null,
            'status' => $res['status'] ?? null,
            'executed_qty' => $res['executed_qty'] ?? null,
            'quote_spent_usd' => $res['quote_spent'] ?? null,
        ];
        $dir = rtrim((string) config('atlas.finance_spot_exec.ledger_dir'), '/');
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        file_put_contents($dir.'/orders.jsonl', json_encode($receipt, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);

        return $this->emit(['status' => 'EXECUTADA'] + $receipt, self::SUCCESS);
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }

    /** @param array<string,mixed> $payload */
    private function emit(array $payload, int $exit): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exit;
        }
        foreach ($payload as $k => $v) {
            $this->components->twoColumnDetail((string) $k, is_scalar($v) || $v === null ? var_export($v, true) : (string) json_encode($v));
        }

        return $exit;
    }
}
