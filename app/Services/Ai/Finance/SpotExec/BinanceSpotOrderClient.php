<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\SpotExec;

use Illuminate\Support\Facades\Http;

/**
 * Cliente assinado da Binance spot — o ÚNICO ponto do código que fala com
 * endpoints autenticados. HMAC-SHA256 sobre a query string (o padrão da chave
 * "gerada pelo sistema"); a API key vai no header, o secret NUNCA sai do
 * processo, NUNCA é logado, NUNCA aparece em mensagem de erro.
 *
 * Métodos de LEITURA (account) não movem dinheiro. O método de ORDEM exige que
 * o chamador atravesse o SpotExecGate — este cliente não conhece os gates de
 * propósito (separação: gate decide, cliente executa).
 */
final class BinanceSpotOrderClient
{
    private const BASE = 'https://api.binance.com';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly int $recvWindowMs = 5000,
    ) {}

    public function hasCredentials(): bool
    {
        return $this->apiKey !== '' && $this->apiSecret !== '';
    }

    /**
     * Saldos spot não-zero + permissões da conta. Leitura pura.
     *
     * @return array{ok:bool,error?:string,balances?:array<string,array{free:float,locked:float}>,can_trade?:bool}
     */
    public function account(): array
    {
        $res = $this->signedRequest('GET', '/api/v3/account', ['omitZeroBalances' => 'true']);
        if (! ($res['ok'] ?? false)) {
            return $res;
        }
        $json = (array) $res['json'];
        $balances = [];
        foreach ((array) ($json['balances'] ?? []) as $b) {
            $free = (float) ($b['free'] ?? 0);
            $locked = (float) ($b['locked'] ?? 0);
            if ($free > 0.0 || $locked > 0.0) {
                $balances[(string) ($b['asset'] ?? '?')] = ['free' => $free, 'locked' => $locked];
            }
        }

        return [
            'ok' => true,
            'can_trade' => (bool) ($json['canTrade'] ?? false),
            'can_withdraw' => (bool) ($json['canWithdraw'] ?? false),
            'balances' => $balances,
        ];
    }

    /**
     * Ordem MARKET por valor em quote (USDT). O chamador JÁ passou pelo gate.
     *
     * @return array{ok:bool,error?:string,order_id?:int,executed_qty?:float,quote_spent?:float,fills?:list<array<string,mixed>>}
     */
    public function placeMarketOrder(string $symbol, string $side, float $quoteUsd): array
    {
        $side = strtoupper($side);
        if (! in_array($side, ['BUY', 'SELL'], true)) {
            return ['ok' => false, 'error' => 'invalid_side'];
        }
        if ($quoteUsd <= 0.0) {
            return ['ok' => false, 'error' => 'invalid_quote_qty'];
        }

        $res = $this->signedRequest('POST', '/api/v3/order', [
            'symbol' => strtoupper($symbol),
            'side' => $side,
            'type' => 'MARKET',
            'quoteOrderQty' => number_format($quoteUsd, 2, '.', ''),
            'newOrderRespType' => 'FULL',
        ]);
        if (! ($res['ok'] ?? false)) {
            return $res;
        }
        $json = (array) $res['json'];

        return [
            'ok' => true,
            'order_id' => (int) ($json['orderId'] ?? 0),
            'status' => (string) ($json['status'] ?? ''),
            'executed_qty' => (float) ($json['executedQty'] ?? 0),
            'quote_spent' => (float) ($json['cummulativeQuoteQty'] ?? 0),
            'fills' => (array) ($json['fills'] ?? []),
        ];
    }

    /**
     * Quantidade livre de um ativo (para SELL de posição inteira).
     */
    public function freeBalance(string $asset): ?float
    {
        $acc = $this->account();
        if (! ($acc['ok'] ?? false)) {
            return null;
        }

        return (float) (($acc['balances'][strtoupper($asset)] ?? ['free' => 0.0])['free']);
    }

    /**
     * Ordem MARKET SELL por QUANTIDADE base (vender a posição, ex.: 0.0001 BTC).
     *
     * @return array{ok:bool,error?:string,order_id?:int,executed_qty?:float,quote_spent?:float}
     */
    public function placeMarketSellQty(string $symbol, float $baseQty): array
    {
        if ($baseQty <= 0.0) {
            return ['ok' => false, 'error' => 'invalid_base_qty'];
        }

        $res = $this->signedRequest('POST', '/api/v3/order', [
            'symbol' => strtoupper($symbol),
            'side' => 'SELL',
            'type' => 'MARKET',
            'quantity' => $this->trimQty($baseQty),
            'newOrderRespType' => 'FULL',
        ]);
        if (! ($res['ok'] ?? false)) {
            return $res;
        }
        $json = (array) $res['json'];

        return [
            'ok' => true,
            'order_id' => (int) ($json['orderId'] ?? 0),
            'status' => (string) ($json['status'] ?? ''),
            'executed_qty' => (float) ($json['executedQty'] ?? 0),
            'quote_spent' => (float) ($json['cummulativeQuoteQty'] ?? 0),
        ];
    }

    /**
     * @param  array<string,string|int|float>  $params
     * @return array{ok:bool,error?:string,json?:mixed}
     */
    private function signedRequest(string $method, string $path, array $params): array
    {
        if (! $this->hasCredentials()) {
            return ['ok' => false, 'error' => 'credentials_missing'];
        }

        $params['timestamp'] = (int) round(microtime(true) * 1000);
        $params['recvWindow'] = $this->recvWindowMs;
        $query = http_build_query($params);
        $signature = hash_hmac('sha256', $query, $this->apiSecret);
        $url = self::BASE.$path.'?'.$query.'&signature='.$signature;

        try {
            $pending = Http::timeout(15)->connectTimeout(5)
                ->withHeaders(['X-MBX-APIKEY' => $this->apiKey]);
            $response = $method === 'POST' ? $pending->post($url) : $pending->get($url);
        } catch (\Throwable $e) {
            // Nunca incluir a mensagem crua (poderia ecoar a URL assinada).
            return ['ok' => false, 'error' => 'network_error'];
        }

        if (! $response->ok()) {
            // Códigos da Binance são seguros de logar; a query assinada não.
            return [
                'ok' => false,
                'error' => 'http_'.$response->status(),
                'binance_code' => $response->json('code'),
                'binance_msg' => $response->json('msg'),
            ];
        }

        return ['ok' => true, 'json' => $response->json()];
    }

    /** Binance rejeita excesso de casas decimais; 5 casas cobre BTC/ETH com folga. */
    private function trimQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 5, '.', ''), '0'), '.');
    }
}
