<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketExec;

/**
 * Typed, immutable view of config('atlas.finance_poly_exec') plus the per-run
 * overrides (--max-cesta / --daily-cap / --max-concurrent). Every structural
 * gate reads its threshold from here, so the gates are code, not prose.
 */
final class PolyExecConfig
{
    public function __construct(
        public readonly bool $liveEnabled,
        public readonly float $maxBasketUsd,
        public readonly float $dailyCapUsd,
        public readonly int $maxConcurrentBaskets,
        public readonly float $minDepthMultiple,
        public readonly int $minPersistenceSeconds,
        public readonly float $minNetEdgePerSet,
        public readonly float $maxResolutionHours,
        public readonly int $slippageBps,
        public readonly float $takerFeeRate,
        public readonly float $estGasUsdPerBasket,
        public readonly string $killSwitchPath,
        // Short side + merge (defaulted so existing constructions stay valid).
        public readonly bool $shortEnabled = true,
        public readonly float $estMintGasUsd = 0.05,
        public readonly float $estMergeGasUsd = 0.05,
        public readonly bool $shortMergeOnNoSell = false,
        public readonly string $longRealizeMethod = 'hold',
        public readonly float $shortMaxResolutionHours = 720.0,
        // Python binary the live signer / on-chain runtime is invoked with.
        // Default 'python3' (PATH) keeps behavior unchanged; point it at the
        // poly_exec venv once the live deps (py-clob-client, web3) are installed.
        public readonly string $pythonBin = 'python3',
    ) {}

    /**
     * @param  array{max_basket_usd?: float|null, daily_cap_usd?: float|null, max_concurrent?: int|null}  $overrides
     */
    public static function fromConfig(array $overrides = []): self
    {
        $c = (array) config('atlas.finance_poly_exec', []);

        $maxBasket = $overrides['max_basket_usd'] ?? null;
        $dailyCap = $overrides['daily_cap_usd'] ?? null;
        $maxConc = $overrides['max_concurrent'] ?? null;

        return new self(
            liveEnabled: (bool) ($c['live_enabled'] ?? false),
            maxBasketUsd: max(0.01, (float) ($maxBasket ?? $c['max_basket_usd'] ?? 8.0)),
            dailyCapUsd: max(0.0, (float) ($dailyCap ?? $c['daily_cap_usd'] ?? 25.0)),
            maxConcurrentBaskets: max(1, (int) ($maxConc ?? $c['max_concurrent_baskets'] ?? 2)),
            minDepthMultiple: max(1.0, (float) ($c['min_depth_multiple'] ?? 3.0)),
            minPersistenceSeconds: max(0, (int) ($c['min_persistence_seconds'] ?? 600)),
            minNetEdgePerSet: max(0.0, (float) ($c['min_net_edge_per_set'] ?? 0.01)),
            maxResolutionHours: max(0.0, (float) ($c['max_resolution_hours'] ?? 72.0)),
            slippageBps: max(0, (int) ($c['slippage_bps'] ?? 100)),
            takerFeeRate: max(0.0, (float) ($c['taker_fee_rate'] ?? 0.0)),
            estGasUsdPerBasket: max(0.0, (float) ($c['est_gas_usd_per_basket'] ?? 0.0)),
            killSwitchPath: (string) ($c['kill_switch_path'] ?? storage_path('app/atlas-poly-exec.kill')),
            shortEnabled: (bool) ($c['short_enabled'] ?? true),
            estMintGasUsd: max(0.0, (float) ($c['est_mint_gas_usd'] ?? 0.05)),
            estMergeGasUsd: max(0.0, (float) ($c['est_merge_gas_usd'] ?? 0.05)),
            shortMergeOnNoSell: (bool) ($c['short_merge_on_no_sell'] ?? false),
            longRealizeMethod: (string) ($c['long_realize_method'] ?? 'hold') === 'merge' ? 'merge' : 'hold',
            shortMaxResolutionHours: max(0.0, (float) ($c['short_max_resolution_hours'] ?? 720.0)),
            pythonBin: (string) ($c['python_bin'] ?? 'python3'),
        );
    }

    /** Protective sell floor for a short leg: accept down to bid*(1 - slippage), >= a tiny epsilon. */
    public function sellFloorFor(float $bidPrice): float
    {
        $floor = $bidPrice * (1.0 - $this->slippageBps / 10_000.0);

        return round(max($floor, 0.001), 6);
    }

    /** Limit price for a marketable buy: pay at most target*(1+slippage), capped < 1. */
    public function limitPriceFor(float $targetPrice): float
    {
        $limit = $targetPrice * (1.0 + $this->slippageBps / 10_000.0);

        return round(min($limit, 0.999), 6);
    }

    public function killSwitchEngaged(): bool
    {
        return $this->killSwitchPath !== '' && is_file($this->killSwitchPath);
    }
}
