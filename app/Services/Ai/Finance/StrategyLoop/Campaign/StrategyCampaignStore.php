<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Campaign;

use App\Services\Ai\Finance\StrategyLoop\Bar;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use App\Support\UtcIsoTimestamp;

/**
 * File-backed research campaign store for the finance strategy loop.
 *
 * A campaign is the unit of scientific evidence: it pre-registers the search budget,
 * owns its ledger, and tracks holdout reuse so "no winner" can become an auditable
 * conclusion instead of a silent null.
 */
final class StrategyCampaignStore
{
    public const HOLDOUT_FRESH = 'FRESH';

    public const HOLDOUT_ACTIVE = 'ACTIVE';

    public const HOLDOUT_EXHAUSTED = 'EXHAUSTED';

    public const HOLDOUT_RESERVED = 'RESERVED_FOR_CONFIRMATION';

    /**
     * @param  array<string,mixed>  $campaign
     */
    public function __construct(
        public readonly string $campaignId,
        public readonly string $directory,
        public readonly string $ledgerPath,
        public readonly array $campaign,
        public readonly bool $writesEnabled = true,
    ) {}

    /**
     * @param  list<Bar>  $scoringBars
     * @param  list<Bar>  $holdoutBars
     * @param  list<Bar>  $confirmationHoldoutBars
     * @param  array<string,mixed>  $options
     */
    public static function open(array $options, array $scoringBars, array $holdoutBars, array $confirmationHoldoutBars = []): self
    {
        $campaignId = self::sanitizeId((string) ($options['campaign_id'] ?? ''));
        if ($campaignId === '') {
            $campaignId = self::sanitizeId(sprintf(
                '%s-%s-%s-%s',
                (string) ($options['symbol'] ?? 'BTCUSDT'),
                (string) ($options['interval'] ?? '1d'),
                (string) ($options['family'] ?? 'trend-breakout-v1'),
                gmdate('Ymd-His'),
            ));
        }

        $dryRun = (bool) ($options['dry_run_ledger'] ?? false);
        $writesEnabled = ! (bool) ($options['no_ledger'] ?? false);
        $base = $dryRun
            ? storage_path('framework/atlas/finance/dry-run-campaigns')
            : storage_path('atlas/finance/campaigns');
        $dir = rtrim($base, '/').'/'.$campaignId;
        $ledger = trim((string) ($options['ledger'] ?? ''));
        if ($ledger === '') {
            $ledger = $dir.'/ledger.jsonl';
        }
        $previousCampaign = self::readJson($dir.'/campaign.json');

        $dataPath = (string) ($options['data_path'] ?? '');
        $dataSha = is_file($dataPath) ? hash_file('sha256', $dataPath) : null;
        $holdoutRange = self::range($holdoutBars);
        $confirmationHoldoutRange = self::range($confirmationHoldoutBars);
        $scoringRange = self::range($scoringBars);
        $maxRounds = max(0, (int) ($options['max_rounds'] ?? 0));
        $candidates = max(1, (int) ($options['candidates'] ?? 1));
        $maxHoldoutReuse = max(1, (int) ($options['holdout_max_reuse'] ?? 1000));
        $confirmationMaxReuse = max(1, (int) ($options['confirmation_holdout_max_reuse'] ?? 1));
        $symbol = (string) ($options['symbol'] ?? 'BTCUSDT');
        $interval = (string) ($options['interval'] ?? '1d');
        $holdoutGeneration = max(0, (int) ($options['holdout_generation'] ?? 0));
        $maxHoldoutGeneration = max($holdoutGeneration, (int) ($options['max_holdout_generation'] ?? $holdoutGeneration));
        $splitPolicy = (string) ($options['split_policy'] ?? 'walkback_validation_holdout_before_reserved_confirmation');
        $timeframeProfile = (new StrategyTimeframeProfile)->describe($interval);
        $featureSet = is_array($options['feature_set'] ?? null)
            ? $options['feature_set']
            : (new StrategyFeatureSetProfile)->describe(StrategyFeatureSetProfile::PRICE_ONLY);
        $featureSetId = (string) ($featureSet['feature_set_id'] ?? StrategyFeatureSetProfile::PRICE_ONLY);
        $scenarioPriorTrials = $writesEnabled
            ? StrategyScenarioRegistry::default($dryRun)->scenarioPriorTrials($symbol, $interval, (string) ($options['family'] ?? 'trend-breakout-v1'), $featureSetId, $campaignId)
            : 0;
        $timeframePolicy = is_array($options['timeframe_policy'] ?? null)
            ? $options['timeframe_policy']
            : (new StrategyTimeframeProfile)->campaignPolicy($interval);
        $effectiveMinTrades = max(1, (int) ($options['min_trades'] ?? $timeframePolicy['effective_min_trades'] ?? $timeframePolicy['default_min_trades'] ?? 20));
        $effectiveHoldoutMinTrades = max(1, (int) ($options['holdout_min_trades'] ?? $timeframePolicy['effective_holdout_min_trades'] ?? $timeframePolicy['default_holdout_min_trades'] ?? 10));
        $costStressMultiplier = max(2.0, (float) ($options['cost_stress_multiplier'] ?? $timeframePolicy['cost_stress_multiplier'] ?? 2.0));
        $validationHoldoutId = self::holdoutId($symbol, $interval, $holdoutRange, $dataSha, 'validation');
        $confirmationHoldoutId = self::holdoutId($symbol, $interval, $confirmationHoldoutRange, $dataSha, 'confirmation');
        $registry = HoldoutRegistry::default($dryRun);
        $validationRegistry = $writesEnabled ? $registry->register([
            'holdout_id' => $validationHoldoutId,
            'role' => 'validation',
            'symbol' => $symbol,
            'interval' => $interval,
            'range' => $holdoutRange,
            'data_sha' => $dataSha,
            'max_reuse' => $maxHoldoutReuse,
        ]) : [];
        $confirmationRegistry = $writesEnabled ? $registry->register([
            'holdout_id' => $confirmationHoldoutId,
            'role' => 'confirmation',
            'symbol' => $symbol,
            'interval' => $interval,
            'range' => $confirmationHoldoutRange,
            'data_sha' => $dataSha,
            'max_reuse' => $confirmationMaxReuse,
        ]) : [];

        $campaign = [
            'schema_version' => 'atlas.finance.strategy_campaign.v1',
            'campaign_id' => $campaignId,
            'created_at' => (string) ($previousCampaign['created_at'] ?? UtcIsoTimestamp::now()),
            'flow' => 'finance.strategy_evolution',
            'status' => 'running',
            'symbol' => $symbol,
            'interval' => $interval,
            'timeframe_profile' => $timeframeProfile,
            'timeframe_policy' => $timeframePolicy,
            'feature_set' => $featureSet,
            'strategy_family' => (string) ($options['family'] ?? 'trend-breakout-v1'),
            'engine' => 'in_process_search',
            'pre_registered_budget' => [
                'max_rounds' => $maxRounds,
                'max_seconds' => max(0, (int) ($options['max_seconds'] ?? 0)),
                'candidates_per_round' => $candidates,
                'max_candidates' => $maxRounds > 0 ? $maxRounds * $candidates : null,
                'scenario_prior_trials' => $scenarioPriorTrials,
                'scenario_max_candidates' => $maxRounds > 0 ? $scenarioPriorTrials + ($maxRounds * $candidates) : null,
                'holdout_generation' => $holdoutGeneration,
                'max_holdout_generation' => $maxHoldoutGeneration,
                'statistical_budget_note' => 'campaign_trials are applied before any champion can leave quarantine',
                'scenario_trial_accounting' => 'scenario_trials = scenario_prior_trials + campaign_trials and must pass before any champion can leave quarantine',
            ],
            'seed_base' => (int) ($options['seed'] ?? 0),
            'search_design' => [
                'islands' => array_values((array) ($options['islands'] ?? ['conservative', 'aggressive', 'robustness'])),
                'pareto_objectives' => array_values((array) ($options['pareto_objectives'] ?? ['ann_sharpe', 'max_dd', 'stability_score', 'robustness_score'])),
                'note' => 'Internal multi-objective search only; final judge is unchanged or stricter.',
            ],
            'second_engine' => [
                'mode' => (string) ($options['second_engine'] ?? 'python-replay'),
                'freqtrade_report' => (string) ($options['freqtrade_report'] ?? ''),
                'freqtrade_preferred_when_configured' => true,
            ],
            'cross_campaign_rediscovery' => [
                'required_independent_campaigns' => max(0, (int) ($options['cross_campaign_confirmations'] ?? 1)),
                'scope' => 'same_symbol_interval_family_and_coarse_parameter_signature',
            ],
            'cost_profile' => [
                'fee_bps' => (float) ($options['fee_bps'] ?? 10.0),
                'slippage_bps' => (float) ($options['slippage_bps'] ?? 5.0),
                'cost_profile_hash' => self::hash([
                    'fee_bps' => (float) ($options['fee_bps'] ?? 10.0),
                    'slippage_bps' => (float) ($options['slippage_bps'] ?? 5.0),
                ]),
            ],
            'data_manifest' => [
                'path' => $dataPath,
                'sha256' => $dataSha,
                'split_policy' => $splitPolicy,
                'holdout_generation' => $holdoutGeneration,
                'max_holdout_generation' => $maxHoldoutGeneration,
                'scoring_range' => $scoringRange,
                'validation_holdout_range' => $holdoutRange,
                'confirmation_holdout_range' => $confirmationHoldoutRange,
                // Backward-compatible alias for older readers.
                'holdout_range' => $holdoutRange,
                'feature_inputs' => [
                    [
                        'feature_set_id' => $featureSet['feature_set_id'] ?? StrategyFeatureSetProfile::PRICE_ONLY,
                        'input_family' => 'ohlcv_price_history',
                        'source_path' => $dataPath,
                        'sha256' => $dataSha,
                        'lookahead_policy' => $featureSet['lookahead_policy'] ?? null,
                    ],
                ],
            ],
            'holdout' => [
                'holdout_id' => $validationHoldoutId,
                'role' => 'validation',
                'generation' => $holdoutGeneration,
                'max_generation' => $maxHoldoutGeneration,
                'split_policy' => $splitPolicy,
                'range' => $holdoutRange,
                'reuse_count' => (int) ($validationRegistry['reuse_count'] ?? 0),
                'max_reuse' => $maxHoldoutReuse,
                'status' => (string) ($validationRegistry['status'] ?? self::HOLDOUT_FRESH),
            ],
            'confirmation_holdout' => [
                'holdout_id' => $confirmationHoldoutId,
                'role' => 'confirmation',
                'range' => $confirmationHoldoutRange,
                'reuse_count' => (int) ($confirmationRegistry['reuse_count'] ?? 0),
                'max_reuse' => $confirmationMaxReuse,
                'status' => (string) ($confirmationRegistry['status'] ?? self::HOLDOUT_RESERVED),
            ],
            'promotion_criteria' => [
                'round_dsr_min' => 0.95,
                'pbo_max' => 0.2,
                'holdout_min_sharpe' => 0.5,
                'scoring_min_trades' => $effectiveMinTrades,
                'holdout_min_trades' => $effectiveHoldoutMinTrades,
                'cost_stress_multiplier' => $costStressMultiplier,
                'campaign_penalty_required' => true,
                'scenario_penalty_required' => true,
                'fresh_holdout_required' => true,
                'cost_stress_required' => true,
                'cost_stress_2x_required' => true,
                'neighborhood_robustness_required' => true,
                'second_engine_required' => true,
                'cross_campaign_rediscovery_required' => max(0, (int) ($options['cross_campaign_confirmations'] ?? 1)) > 0,
            ],
            'propose_only' => true,
            'live_trading' => 'forbidden',
        ];
        if (is_array($previousCampaign) && self::isTerminalCampaign($previousCampaign)) {
            $campaign = array_replace_recursive($campaign, $previousCampaign);
        }

        $store = new self($campaignId, $dir, $ledger, $campaign, $writesEnabled);
        if ($writesEnabled) {
            $store->ensureLayout();
            if (! self::isTerminalCampaign($campaign)) {
                $store->writeJson($dir.'/campaign.json', $campaign);
                $store->writeJson($dir.'/data-manifest.json', $campaign['data_manifest']);
                StrategyScenarioRegistry::default($dryRun)->registerCampaign($campaign);
            }
        }

        return $store;
    }

    public static function sanitizeId(string $id): string
    {
        $id = strtolower(trim($id));
        $id = preg_replace('/[^a-z0-9._-]+/', '-', $id) ?? '';

        return trim($id, '-');
    }

    /**
     * @param  array<string,mixed>  $line
     */
    public function appendLedger(array $line): void
    {
        if (! $this->writesEnabled) {
            return;
        }
        AppendOnlyJsonlStore::appendUsingFilePutContents(
            $this->ledgerPath,
            $line,
            JSON_UNESCAPED_SLASHES,
            FILE_APPEND | LOCK_EX,
            0o755,
        );
    }

    /**
     * @param  array<string,mixed>  $event
     */
    public function appendHoldoutEvent(array $event): void
    {
        if (! $this->writesEnabled) {
            return;
        }
        $path = $this->directory.'/holdout-ledger.jsonl';
        AppendOnlyJsonlStore::appendUsingFilePutContents(
            $path,
            $event,
            JSON_UNESCAPED_SLASHES,
            FILE_APPEND | LOCK_EX,
            0o755,
        );
        $holdoutId = (string) ($event['holdout_id'] ?? '');
        if ($holdoutId !== '') {
            HoldoutRegistry::default(str_contains($this->directory, '/framework/'))->recordUse($holdoutId, $event);
        }
    }

    /**
     * @param  array<string,mixed>  $report
     */
    public function writeNullReport(array $report): void
    {
        if (! $this->writesEnabled) {
            return;
        }
        $this->writeJson($this->directory.'/null-report.json', $report);
        $this->writeCampaignFinalState($report);
        StrategyResearchEvidenceLedger::default(str_contains($this->directory, '/framework/'))->recordReport($report);
        StrategyScenarioRegistry::default(str_contains($this->directory, '/framework/'))->recordReport($report);
    }

    public function isTerminal(): bool
    {
        return self::isTerminalCampaign($this->campaign);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function writeProposal(array $payload): string
    {
        if (! $this->writesEnabled) {
            return '';
        }

        $dir = $this->directory.'/proposals';
        @mkdir($dir, 0o755, true);
        $file = $dir.'/'.gmdate('Ymd-His').'-'.substr(self::hash($payload), 0, 8).'.json';
        $this->writeJson($file, $payload);

        return $file;
    }

    public function holdoutStatusForReuse(int $reuseCount): string
    {
        $holdoutId = (string) ($this->campaign['holdout']['holdout_id'] ?? '');
        $max = (int) ($this->campaign['holdout']['max_reuse'] ?? 1);
        if ($holdoutId !== '') {
            return HoldoutRegistry::default(str_contains($this->directory, '/framework/'))->statusForUse($holdoutId, $reuseCount, $max);
        }
        if ($reuseCount >= $max) {
            return self::HOLDOUT_EXHAUSTED;
        }

        return $reuseCount === 0 ? self::HOLDOUT_FRESH : self::HOLDOUT_ACTIVE;
    }

    public function confirmationHoldoutStatus(): string
    {
        $holdoutId = (string) ($this->campaign['confirmation_holdout']['holdout_id'] ?? '');
        $max = (int) ($this->campaign['confirmation_holdout']['max_reuse'] ?? 1);
        if ($holdoutId !== '') {
            return HoldoutRegistry::default(str_contains($this->directory, '/framework/'))->statusForUse($holdoutId, 0, $max);
        }

        return self::HOLDOUT_RESERVED;
    }

    public function proposalsDirectory(): string
    {
        return $this->directory.'/proposals';
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) ?: '');
    }

    /**
     * @return array<string,mixed>
     */
    private static function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $campaign */
    private static function isTerminalCampaign(array $campaign): bool
    {
        $status = (string) ($campaign['status'] ?? '');
        $verdict = (string) ($campaign['verdict'] ?? '');

        return in_array($status, ['completed', 'completed_certified', 'completed_null', 'aborted'], true)
            || $verdict === 'CERTIFIED'
            || str_starts_with($verdict, 'NULL_');
    }

    /** @param array<string,mixed> $report */
    private function writeCampaignFinalState(array $report): void
    {
        $campaign = $this->campaign;
        $verdict = (string) ($report['verdict'] ?? 'INCONCLUSIVE');
        $stopReason = (string) ($report['summary']['stop_reason'] ?? '');
        $campaign['status'] = $verdict === 'INCONCLUSIVE'
            ? (in_array($stopReason, ['kill_switch', 'invocation_round_limit'], true) ? 'paused' : 'inconclusive')
            : 'completed';
        $campaign['verdict'] = $verdict;
        if ($campaign['status'] === 'completed') {
            $campaign['completed_at'] = UtcIsoTimestamp::now();
        } else {
            $campaign['paused_at'] = UtcIsoTimestamp::now();
        }
        $campaign['final_summary'] = $report['summary'] ?? [];
        $campaign['next_decision'] = $report['next_decision'] ?? null;
        $this->writeJson($this->directory.'/campaign.json', $campaign);
    }

    /**
     * @param  list<Bar>  $bars
     * @return array<string,mixed>
     */
    private static function range(array $bars): array
    {
        if ($bars === []) {
            return ['open_time_ms' => null, 'close_time_ms' => null, 'bars' => 0];
        }
        $first = $bars[0];
        $last = $bars[count($bars) - 1];

        return [
            'open_time_ms' => $first->openTime,
            'close_time_ms' => $last->closeTime,
            'open_time' => gmdate('c', intdiv($first->openTime, 1000)),
            'close_time' => gmdate('c', intdiv($last->closeTime, 1000)),
            'bars' => count($bars),
        ];
    }

    /**
     * @param  array<string,mixed>  $range
     */
    private static function holdoutId(string $symbol, string $interval, array $range, ?string $dataSha, string $role = 'validation'): string
    {
        return substr(self::hash([
            'symbol' => $symbol,
            'interval' => $interval,
            'role' => $role,
            'range' => $range,
            'data_sha' => $dataSha,
        ]), 0, 16);
    }

    private function ensureLayout(): void
    {
        foreach ([$this->directory, $this->directory.'/workers', $this->directory.'/proposals', $this->directory.'/artifacts'] as $dir) {
            @mkdir($dir, 0o755, true);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        @mkdir(dirname($path), 0o755, true);
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }
}
