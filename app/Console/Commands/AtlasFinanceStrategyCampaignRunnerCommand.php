<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyCampaignStore;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyConfirmationQueue;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyFeatureSetProfile;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyScenarioRegistry;
use App\Services\Ai\Finance\StrategyLoop\MarketDataCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

final class AtlasFinanceStrategyCampaignRunnerCommand extends Command
{
    protected $signature = 'atlas:finance:strategy-campaign-runner
        {--family=roadmap : roadmap|trend-breakout-v1|mean-reversion-v1|momentum-v1}
        {--symbol= : Focus roadmap selection to one market symbol, e.g. BTCUSDT}
        {--interval= : Focus roadmap selection to one timeframe, e.g. 1d}
        {--campaign-id= : Override campaign id for the selected scenario}
        {--candidates=600}
        {--max-rounds=0}
        {--holdout-generation= : Override validation holdout generation; blank uses the scenario next fresh generation}
        {--sleep=2}
        {--continuous : Keep running the next eligible campaign after each campaign exits}
        {--max-campaigns=1 : Max campaigns for this runner invocation (0 = unbounded; mostly for --continuous)}
        {--idle-sleep=60 : Seconds to wait before polling again when --continuous has no eligible scenario}
        {--kill-switch= : Stop runner/search when this file exists}
        {--seed= : Override seed for scenario runs}
        {--dry-run-ledger : Use dry-run campaign/queue/scenario storage}
        {--json : Emit machine-readable output}';

    protected $description = 'Run exactly one sequential finance strategy campaign: pending champion confirmation first, otherwise next roadmap scenario.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run-ledger');
        $continuous = (bool) $this->option('continuous');
        $maxCampaigns = max(0, (int) $this->option('max-campaigns'));
        $idleSleep = max(1, (int) $this->option('idle-sleep'));
        $kill = trim((string) $this->option('kill-switch')) ?: storage_path('atlas/finance/STOP');
        $completed = [];

        while (true) {
            if (is_file($kill)) {
                return $this->emit([
                    'schema_version' => 'atlas.finance.strategy_campaign_runner.v1',
                    'status' => 'stopped_by_kill_switch',
                    'completed_campaigns' => $completed,
                    'parallelism_policy' => 'one_active_strategy_search_loop',
                    'propose_only' => true,
                    'live_trading' => 'forbidden',
                ], self::SUCCESS);
            }

            $selection = $this->select($dryRun);
            if ($selection === null) {
                $payload = [
                    'schema_version' => 'atlas.finance.strategy_campaign_runner.v1',
                    'status' => 'empty',
                    'reason' => 'no_pending_confirmation_or_roadmap_scenario',
                    'completed_campaigns' => $completed,
                    'parallelism_policy' => 'one_active_strategy_search_loop',
                ];
                if (! $continuous) {
                    return $this->emit($payload, self::SUCCESS);
                }
                $this->emit($payload, self::SUCCESS);
                sleep($idleSleep);
                continue;
            }

            $args = $selection['args'];
            $cache = MarketDataCache::default();
            $symbol = (string) ($args['--symbol'] ?? 'BTCUSDT');
            $interval = (string) ($args['--interval'] ?? '1d');
            if (! $cache->has($symbol, $interval)) {
                return $this->emit([
                    'schema_version' => 'atlas.finance.strategy_campaign_runner.v1',
                    'status' => 'market_data_missing',
                    'selected' => $selection['meta'],
                    'completed_campaigns' => $completed,
                    'missing_cache' => $cache->path($symbol, $interval),
                    'fetch_command' => "bash storage/atlas/finance/fetch-btc-history.sh {$symbol} {$interval} 2018 2026 5",
                    'parallelism_policy' => 'one_active_strategy_search_loop',
                    'propose_only' => true,
                    'live_trading' => 'forbidden',
                ], self::FAILURE);
            }

            $exit = Artisan::call('atlas:finance:strategy-search', $args);
            $completed[] = [
                'selected' => $selection['meta'],
                'search_exit_code' => $exit,
                'completed_at' => gmdate('c'),
            ];
            $payload = [
                'schema_version' => 'atlas.finance.strategy_campaign_runner.v1',
                'status' => $exit === 0 ? 'completed_one_campaign' : 'strategy_search_failed',
                'selected' => $selection['meta'],
                'search_exit_code' => $exit,
                'completed_campaigns' => $completed,
                'parallelism_policy' => 'one_active_strategy_search_loop',
                'propose_only' => true,
                'live_trading' => 'forbidden',
            ];

            if (! $continuous || $exit !== 0) {
                return $this->emit($payload, $exit === 0 ? self::SUCCESS : self::FAILURE);
            }
            $this->emit($payload, self::SUCCESS);
            if ($maxCampaigns > 0 && count($completed) >= $maxCampaigns) {
                return $this->emit([
                    'schema_version' => 'atlas.finance.strategy_campaign_runner.v1',
                    'status' => 'completed_campaign_limit',
                    'completed_campaigns' => $completed,
                    'parallelism_policy' => 'one_active_strategy_search_loop',
                    'propose_only' => true,
                    'live_trading' => 'forbidden',
                ], self::SUCCESS);
            }
        }
    }

    /** @return array{args:array<string,mixed>,meta:array<string,mixed>}|null */
    private function select(bool $dryRun): ?array
    {
        $queue = StrategyConfirmationQueue::default($dryRun);
        $confirmation = $queue->claimNext();
        if (is_array($confirmation)) {
            $campaign = $confirmation['confirmation_campaign'] ?? [];
            $featureSet = is_array($confirmation['feature_set'] ?? null)
                ? $confirmation['feature_set']
                : (is_array($campaign['feature_set'] ?? null)
                    ? $campaign['feature_set']
                    : (new StrategyFeatureSetProfile)->describe(StrategyFeatureSetProfile::PRICE_ONLY));
            $featureSetId = (string) ($featureSet['feature_set_id'] ?? StrategyFeatureSetProfile::PRICE_ONLY);

            return [
                'args' => [
                    '--symbol' => $confirmation['symbol'] ?? 'BTCUSDT',
                    '--interval' => $confirmation['interval'] ?? '1d',
                    '--family' => $confirmation['strategy_family'] ?? $this->option('family'),
                    '--feature-set' => $featureSetId,
                    '--campaign-id' => $campaign['campaign_id'] ?? $this->campaignId('confirmation'),
                    '--candidates' => (int) ($campaign['candidates_per_round'] ?? $this->option('candidates')),
                    '--max-rounds' => (int) ($campaign['max_rounds'] ?? $this->option('max-rounds')),
                    '--holdout-generation' => max(0, (int) data_get($campaign, 'holdout_generation', data_get($campaign, 'data_manifest.holdout_generation', 0))),
                    '--sleep' => (int) $this->option('sleep'),
                    '--seed' => (int) ($campaign['seed'] ?? $this->seed()),
                    '--kill-switch' => trim((string) $this->option('kill-switch')),
                    '--dry-run-ledger' => $dryRun,
                ],
                'meta' => [
                    'kind' => 'confirmation',
                    'request_id' => $confirmation['request_id'] ?? null,
                    'source_campaign_id' => $confirmation['source_campaign_id'] ?? null,
                    'campaign_id' => $campaign['campaign_id'] ?? null,
                    'feature_set' => $featureSet,
                ],
            ];
        }

        $familyOption = (string) $this->option('family');
        $requestedFamily = $familyOption === 'roadmap' ? null : $familyOption;
        $requestedSymbol = strtoupper(trim((string) $this->option('symbol')));
        $requestedInterval = trim((string) $this->option('interval'));
        $scenario = StrategyScenarioRegistry::default($dryRun)->nextRoadmapScenario(
            $requestedFamily,
            $requestedSymbol !== '' ? $requestedSymbol : null,
            $requestedInterval !== '' ? $requestedInterval : null,
        );
        if ($scenario === null) {
            return null;
        }
        $family = (string) ($scenario['strategy_family'] ?? $requestedFamily ?? 'trend-breakout-v1');
        $featureSet = is_array($scenario['feature_set'] ?? null)
            ? $scenario['feature_set']
            : (new StrategyFeatureSetProfile)->describe(StrategyFeatureSetProfile::PRICE_ONLY);
        $featureSetId = (string) ($featureSet['feature_set_id'] ?? StrategyFeatureSetProfile::PRICE_ONLY);
        $campaignId = trim((string) $this->option('campaign-id'));
        if ($campaignId === '') {
            $campaignId = (string) ($scenario['reason'] ?? '') === 'scenario_incomplete'
                ? trim((string) ($scenario['latest_campaign_id'] ?? ''))
                : '';
        }
        if ($campaignId === '') {
            $campaignId = $this->campaignId(strtolower((string) $scenario['symbol']).'-'.(string) $scenario['interval'].'-'.$family);
        }
        $requestedHoldoutGeneration = trim((string) $this->option('holdout-generation'));
        $holdoutGeneration = $requestedHoldoutGeneration !== ''
            ? max(0, (int) $requestedHoldoutGeneration)
            : max(0, (int) ($scenario['holdout_generation'] ?? 0));

        return [
            'args' => [
                '--symbol' => $scenario['symbol'],
                '--interval' => $scenario['interval'],
                '--family' => $family,
                '--feature-set' => $featureSetId,
                '--campaign-id' => $campaignId,
                '--candidates' => max(10, (int) $this->option('candidates')),
                '--max-rounds' => max(0, (int) $this->option('max-rounds')),
                '--holdout-generation' => $holdoutGeneration,
                '--sleep' => max(0, (int) $this->option('sleep')),
                '--seed' => $this->seed(),
                '--kill-switch' => trim((string) $this->option('kill-switch')),
                '--dry-run-ledger' => $dryRun,
            ],
            'meta' => [
                'kind' => 'roadmap',
                ...$scenario,
                'campaign_id' => $campaignId,
                'holdout_generation' => $holdoutGeneration,
            ],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function emit(array $payload, int $exit): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

            return $exit;
        }

        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        if (isset($payload['selected']['campaign_id'])) {
            $this->components->twoColumnDetail('Campaign', (string) $payload['selected']['campaign_id']);
        }

        return $exit;
    }

    private function campaignId(string $prefix): string
    {
        return StrategyCampaignStore::sanitizeId($prefix.'-'.gmdate('Ymd-His'));
    }

    private function seed(): int
    {
        $raw = trim((string) $this->option('seed'));

        return $raw !== '' ? (int) $raw : random_int(1, PHP_INT_MAX);
    }
}
