<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Campaign;

/**
 * Scenario map for sequential research. It records which market/timeframe/family
 * was studied without starting multiple loops or pretending one strategy is universal.
 */
final class StrategyScenarioRegistry
{
    public function __construct(private readonly string $path) {}

    public static function default(bool $dryRun = false): self
    {
        return new self($dryRun
            ? storage_path('framework/atlas/finance/scenario-registry.json')
            : storage_path('atlas/finance/scenario-registry.json'));
    }

    /**
     * @param  array<string,mixed>  $campaign
     */
    public function registerCampaign(array $campaign): void
    {
        $registry = $this->load();
        $symbol = (string) ($campaign['symbol'] ?? 'BTCUSDT');
        $interval = (string) ($campaign['interval'] ?? '1d');
        $family = (string) ($campaign['strategy_family'] ?? 'trend-breakout-v1');
        $key = strtoupper($symbol).'-'.$interval.'-'.$family;
        $registry['scenarios'][$key] = [
            'scenario_key' => $key,
            'symbol' => strtoupper($symbol),
            'interval' => $interval,
            'strategy_family' => $family,
            'latest_campaign_id' => $campaign['campaign_id'] ?? null,
            'latest_status' => $campaign['status'] ?? 'running',
            'updated_at' => gmdate('c'),
            'parallelism_policy' => 'one_active_campaign_at_a_time',
            'strategy_universality_policy' => 'do_not_assume_transfer_between_assets_timeframes_or_regimes',
        ];
        $registry['updated_at'] = gmdate('c');
        $this->save($registry);
    }

    /**
     * @return array<string,mixed>
     */
    public function load(): array
    {
        if (! is_file($this->path)) {
            return [
                'schema_version' => 'atlas.finance.strategy_scenario_registry.v1',
                'created_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
                'sequential_roadmap' => [
                    ['symbol' => 'BTCUSDT', 'interval' => '1d', 'priority' => 1],
                    ['symbol' => 'ETHUSDT', 'interval' => '1d', 'priority' => 2],
                    ['symbol' => 'SOLUSDT', 'interval' => '1d', 'priority' => 3],
                    ['symbol' => 'BTCUSDT', 'interval' => '4h', 'priority' => 4],
                    ['symbol' => 'ETHUSDT', 'interval' => '4h', 'priority' => 5],
                ],
                'do_not_start_in_parallel' => true,
                'avoid_until_ready' => ['15m', '5m', 'leverage', 'perps_funding', 'survivorship_biased_top_coins'],
                'scenarios' => [],
            ];
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);

        return is_array($decoded) ? $decoded + ['scenarios' => []] : ['scenarios' => []];
    }

    /** @param array<string,mixed> $registry */
    private function save(array $registry): void
    {
        @mkdir(dirname($this->path), 0o755, true);
        file_put_contents($this->path, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }
}
