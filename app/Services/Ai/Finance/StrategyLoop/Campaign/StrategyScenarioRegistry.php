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
     * @param  array<string,mixed>  $report
     */
    public function recordReport(array $report): void
    {
        $registry = $this->load();
        $symbol = strtoupper((string) ($report['symbol'] ?? 'BTCUSDT'));
        $interval = (string) ($report['interval'] ?? '1d');
        $family = (string) ($report['strategy_family'] ?? 'trend-breakout-v1');
        $key = $symbol.'-'.$interval.'-'.$family;
        $scenario = $registry['scenarios'][$key] ?? [
            'scenario_key' => $key,
            'symbol' => $symbol,
            'interval' => $interval,
            'strategy_family' => $family,
            'parallelism_policy' => 'one_active_campaign_at_a_time',
            'strategy_universality_policy' => 'do_not_assume_transfer_between_assets_timeframes_or_regimes',
        ];
        $verdict = (string) ($report['verdict'] ?? 'INCONCLUSIVE');
        $scenario['latest_campaign_id'] = $report['campaign_id'] ?? ($scenario['latest_campaign_id'] ?? null);
        $scenario['latest_status'] = $verdict;
        $scenario['latest_verdict'] = $verdict;
        $scenario['updated_at'] = gmdate('c');
        $scenario['family_exhausted'] = $verdict === 'NULL_FAMILY_EXHAUSTED';
        $scenario['research_history'] = array_values(array_slice([
            [
                'campaign_id' => $report['campaign_id'] ?? null,
                'verdict' => $verdict,
                'rounds' => $report['summary']['rounds'] ?? null,
                'total_candidates' => $report['summary']['total_candidates'] ?? null,
                'best_ann_sharpe' => $report['summary']['best_ann_sharpe'] ?? null,
                'best_dsr' => $report['summary']['best_dsr'] ?? null,
                'best_holdout_sharpe' => $report['summary']['best_holdout_sharpe'] ?? null,
                'data_sha' => $report['summary']['data_sha'] ?? data_get($report, 'data_manifest.sha256'),
                'cost_profile_hash' => $report['summary']['cost_profile_hash'] ?? data_get($report, 'cost_profile.cost_profile_hash'),
                'negative_conclusion' => $report['negative_conclusion'] ?? null,
                'recorded_at' => gmdate('c'),
            ],
            ...array_values((array) ($scenario['research_history'] ?? [])),
        ], 0, 20));
        $scenario['regime_note'] = $report['regime_summary']['scenario_note'] ?? null;
        $scenario['latest_summary'] = [
            'rounds' => $report['summary']['rounds'] ?? null,
            'total_candidates' => $report['summary']['total_candidates'] ?? null,
            'best_ann_sharpe' => $report['summary']['best_ann_sharpe'] ?? null,
            'best_dsr' => $report['summary']['best_dsr'] ?? null,
            'best_holdout_sharpe' => $report['summary']['best_holdout_sharpe'] ?? null,
            'data_sha' => $report['summary']['data_sha'] ?? data_get($report, 'data_manifest.sha256'),
            'cost_profile_hash' => $report['summary']['cost_profile_hash'] ?? data_get($report, 'cost_profile.cost_profile_hash'),
        ];
        $scenario['best_observed'] = data_get($report, 'scenario_profile.best_observed', []);
        $scenario['knowledge_note'] = data_get($report, 'scenario_profile.knowledge_note', 'Scenario knowledge is research-only and not an executable signal.');
        $registry['scenarios'][$key] = $scenario;
        $registry['updated_at'] = gmdate('c');
        $this->save($registry);
    }

    /**
     * @return array<string,mixed>
     */
    public function load(): array
    {
        if (! is_file($this->path)) {
            return $this->defaultRegistry();
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);
        $registry = is_array($decoded) ? $decoded + ['scenarios' => []] : ['scenarios' => []];

        return $this->normalizeRegistry($registry);
    }

    /** @return array<string,mixed> */
    private function defaultRegistry(): array
    {
        $families = [
            ['family' => 'trend-breakout-v1', 'priority' => 1],
            ['family' => 'mean-reversion-v1', 'priority' => 2],
            ['family' => 'momentum-v1', 'priority' => 3],
        ];
        $markets = [
            ['symbol' => 'BTCUSDT', 'interval' => '1d', 'priority' => 1],
            ['symbol' => 'ETHUSDT', 'interval' => '1d', 'priority' => 2],
            ['symbol' => 'SOLUSDT', 'interval' => '1d', 'priority' => 3],
            ['symbol' => 'BTCUSDT', 'interval' => '4h', 'priority' => 4],
            ['symbol' => 'ETHUSDT', 'interval' => '4h', 'priority' => 5],
        ];
        $roadmap = [];
        foreach ($markets as $market) {
            foreach ($families as $family) {
                $roadmap[] = [
                    'symbol' => $market['symbol'],
                    'interval' => $market['interval'],
                    'strategy_family' => $family['family'],
                    'priority' => ((int) $market['priority'] * 10) + (int) $family['priority'],
                ];
            }
        }

        return [
                'schema_version' => 'atlas.finance.strategy_scenario_registry.v1',
                'created_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
                'strategy_families' => $families,
                'sequential_roadmap' => $roadmap,
                'do_not_start_in_parallel' => true,
                'avoid_until_ready' => ['15m', '5m', 'leverage', 'perps_funding', 'survivorship_biased_top_coins'],
                'scenarios' => [],
            ];
    }

    /** @return array<string,mixed>|null */
    public function nextRoadmapScenario(?string $family = 'trend-breakout-v1'): ?array
    {
        $registry = $this->load();
        $requestedFamily = $family !== null && $family !== '' && $family !== 'roadmap' ? $family : null;
        $roadmap = array_values((array) ($registry['sequential_roadmap'] ?? []));
        usort($roadmap, static fn (array $a, array $b): int => (int) ($a['priority'] ?? 999) <=> (int) ($b['priority'] ?? 999));

        foreach ($roadmap as $candidate) {
            $symbol = strtoupper((string) ($candidate['symbol'] ?? ''));
            $interval = (string) ($candidate['interval'] ?? '');
            $candidateFamily = (string) ($candidate['strategy_family'] ?? $requestedFamily ?? 'trend-breakout-v1');
            if ($symbol === '' || $interval === '') {
                continue;
            }
            if ($requestedFamily !== null && $candidateFamily !== $requestedFamily) {
                continue;
            }
            $key = $symbol.'-'.$interval.'-'.$candidateFamily;
            $scenario = $registry['scenarios'][$key] ?? null;
            if (! is_array($scenario)) {
                return [
                    'symbol' => $symbol,
                    'interval' => $interval,
                    'strategy_family' => $candidateFamily,
                    'reason' => 'scenario_not_started',
                    'priority' => $candidate['priority'] ?? null,
                ];
            }
            if ((bool) ($scenario['family_exhausted'] ?? false)) {
                continue;
            }
            $latest = (string) ($scenario['latest_verdict'] ?? $scenario['latest_status'] ?? '');
            if (in_array($latest, ['NULL_HOLDOUT_EXHAUSTED', 'NULL_STRONG', 'NULL_FAMILY_EXHAUSTED', 'CERTIFIED'], true)) {
                continue;
            }

            return [
                'symbol' => $symbol,
                'interval' => $interval,
                'strategy_family' => $candidateFamily,
                'reason' => 'scenario_incomplete',
                'priority' => $candidate['priority'] ?? null,
                'latest_campaign_id' => $scenario['latest_campaign_id'] ?? null,
                'latest_verdict' => $latest !== '' ? $latest : null,
            ];
        }

        return null;
    }

    /** @param array<string,mixed> $registry */
    private function normalizeRegistry(array $registry): array
    {
        $default = $this->defaultRegistry();
        $registry += [
            'schema_version' => $default['schema_version'],
            'created_at' => $default['created_at'],
            'updated_at' => gmdate('c'),
            'strategy_families' => $default['strategy_families'],
            'sequential_roadmap' => $default['sequential_roadmap'],
            'do_not_start_in_parallel' => true,
            'avoid_until_ready' => $default['avoid_until_ready'],
            'scenarios' => [],
        ];
        if (! is_array($registry['strategy_families'] ?? null) || $registry['strategy_families'] === []) {
            $registry['strategy_families'] = $default['strategy_families'];
        }
        $roadmap = array_values((array) ($registry['sequential_roadmap'] ?? []));
        if ($roadmap === []) {
            $registry['sequential_roadmap'] = $default['sequential_roadmap'];

            return $registry;
        }
        $hasFamilyScopedRoadmap = false;
        foreach ($roadmap as $entry) {
            if (is_array($entry) && isset($entry['strategy_family'])) {
                $hasFamilyScopedRoadmap = true;
                break;
            }
        }
        if (! $hasFamilyScopedRoadmap) {
            $families = array_values((array) ($registry['strategy_families'] ?? $default['strategy_families']));
            $expanded = [];
            foreach ($roadmap as $market) {
                if (! is_array($market)) {
                    continue;
                }
                foreach ($families as $family) {
                    if (! is_array($family)) {
                        continue;
                    }
                    $expanded[] = [
                        'symbol' => strtoupper((string) ($market['symbol'] ?? '')),
                        'interval' => (string) ($market['interval'] ?? ''),
                        'strategy_family' => (string) ($family['family'] ?? 'trend-breakout-v1'),
                        'priority' => ((int) ($market['priority'] ?? 999) * 10) + (int) ($family['priority'] ?? 1),
                    ];
                }
            }
            $registry['sequential_roadmap'] = $expanded !== [] ? $expanded : $default['sequential_roadmap'];
        }

        return $registry;
    }

    /** @param array<string,mixed> $registry */
    private function save(array $registry): void
    {
        @mkdir(dirname($this->path), 0o755, true);
        file_put_contents($this->path, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }
}
