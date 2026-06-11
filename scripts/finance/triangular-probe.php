<?php

declare(strict_types=1);

/**
 * Sonda de arbitragem TRIANGULAR intra-Binance (censo contínuo, zero dinheiro).
 * Um snapshot por execução: avalia todos os ciclos USDT->X->Y->USDT (hubs
 * BTC/ETH/BNB/SOL) com custos taker 3x e registra o melhor ciclo em jsonl.
 * Censo 2026-06-10 (1ª sonda): 184 ciclos, 0 lucrativos, melhor -28,5bps —
 * a pista só abre em stress; este probe existe para FLAGRAR esses momentos.
 */
$raw = @file_get_contents('https://api.binance.com/api/v3/ticker/bookTicker');
if ($raw === false) {
    exit(0); // fail-soft: sem rede, sem registro
}
$all = json_decode($raw, true);
if (! is_array($all)) {
    exit(0);
}

$book = [];
foreach ($all as $t) {
    $bid = (float) ($t['bidPrice'] ?? 0);
    $ask = (float) ($t['askPrice'] ?? 0);
    if ($bid > 0 && $ask > 0) {
        $book[(string) $t['symbol']] = [$bid, $ask];
    }
}

$fee = 0.001;
$feeMult = (1 - $fee) ** 3;
$best = ['cycle' => null, 'net' => 0.0];
$profitable = [];
foreach ($book as $sym => [$bidYX, $askYX]) {
    foreach (['BTC', 'ETH', 'BNB', 'SOL'] as $x) {
        if (! str_ends_with($sym, $x)) {
            continue;
        }
        $y = substr($sym, 0, -strlen($x));
        if ($y === '' || ! isset($book[$x.'USDT'], $book[$y.'USDT'])) {
            continue;
        }
        [$bidX, $askX] = $book[$x.'USDT'];
        [$bidY, $askY] = $book[$y.'USDT'];
        foreach ([
            ["{$y}->{$x}-A", (1.0 / $askX) * (1.0 / $askYX) * $bidY * $feeMult],
            ["{$y}->{$x}-B", (1.0 / $askY) * $bidYX * $bidX * $feeMult],
        ] as [$name, $net]) {
            if ($net > $best['net']) {
                $best = ['cycle' => $name, 'net' => $net];
            }
            if ($net > 1.0) {
                $profitable[] = ['cycle' => $name, 'net' => $net];
            }
        }
    }
}

$row = [
    'at' => gmdate('c'),
    'best_cycle' => $best['cycle'],
    'best_net' => round($best['net'], 6),
    'best_bps' => round(($best['net'] - 1) * 10000, 2),
    'profitable_count' => count($profitable),
    'profitable' => array_slice($profitable, 0, 10),
];
$dir = __DIR__.'/../../storage/atlas/finance/triangular';
if (! is_dir($dir)) {
    mkdir($dir, 0o755, true);
}
file_put_contents($dir.'/probe.jsonl', json_encode($row, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
// Sinal alto: ciclo lucrativo de verdade vira arquivo-flag (fácil de vigiar).
if ($profitable !== []) {
    file_put_contents($dir.'/OPPORTUNITY-'.gmdate('Ymd-His').'.json', json_encode($row, JSON_PRETTY_PRINT));
}
