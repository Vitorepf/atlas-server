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
        $featureSet = is_array($campaign['feature_set'] ?? null)
            ? $campaign['feature_set']
            : (new StrategyFeatureSetProfile)->describe(StrategyFeatureSetProfile::PRICE_ONLY);
        $timeframeProfile = is_array($campaign['timeframe_profile'] ?? null)
            ? $campaign['timeframe_profile']
            : (new StrategyTimeframeProfile)->describe($interval);
        $key = $this->scenarioKey(strtoupper($symbol), $interval, $family, (string) ($featureSet['feature_set_id'] ?? StrategyFeatureSetProfile::PRICE_ONLY));
        $existing = is_array($registry['scenarios'][$key] ?? null) ? $registry['scenarios'][$key] : [];
        $registry['scenarios'][$key] = array_replace($existing, [
            'scenario_key' => $key,
            'symbol' => strtoupper($symbol),
            'interval' => $interval,
            'timeframe_profile' => $timeframeProfile,
            'strategy_family' => $family,
            'feature_set' => $featureSet,
            'research_rationale' => $this->researchRationale(strtoupper($symbol), $interval, $family, $timeframeProfile, $featureSet),
            'latest_campaign_id' => $campaign['campaign_id'] ?? null,
            'latest_status' => $campaign['status'] ?? 'running',
            'active_holdout_generation' => data_get($campaign, 'data_manifest.holdout_generation', data_get($campaign, 'pre_registered_budget.holdout_generation')),
            'active_max_holdout_generation' => data_get($campaign, 'data_manifest.max_holdout_generation', data_get($campaign, 'pre_registered_budget.max_holdout_generation')),
            'updated_at' => gmdate('c'),
            'parallelism_policy' => 'one_active_campaign_at_a_time',
            'strategy_universality_policy' => 'do_not_assume_transfer_between_assets_timeframes_or_regimes',
        ]);
        $registry['scenario_knowledge_matrix'] = $this->scenarioKnowledgeMatrix($registry);
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
        $featureSet = is_array($report['feature_set'] ?? null)
            ? $report['feature_set']
            : (new StrategyFeatureSetProfile)->describe(StrategyFeatureSetProfile::PRICE_ONLY);
        $timeframeProfile = is_array($report['timeframe_profile'] ?? null)
            ? $report['timeframe_profile']
            : (new StrategyTimeframeProfile)->describe($interval);
        $key = $this->scenarioKey($symbol, $interval, $family, (string) ($featureSet['feature_set_id'] ?? StrategyFeatureSetProfile::PRICE_ONLY));
        $scenario = $registry['scenarios'][$key] ?? [
            'scenario_key' => $key,
            'symbol' => $symbol,
            'interval' => $interval,
            'timeframe_profile' => $timeframeProfile,
            'strategy_family' => $family,
            'feature_set' => $featureSet,
            'research_rationale' => $this->researchRationale($symbol, $interval, $family, $timeframeProfile, $featureSet),
            'parallelism_policy' => 'one_active_campaign_at_a_time',
            'strategy_universality_policy' => 'do_not_assume_transfer_between_assets_timeframes_or_regimes',
        ];
        $scenario['timeframe_profile'] = $timeframeProfile;
        $scenario['feature_set'] = $featureSet;
        $scenario['research_rationale'] = $this->researchRationale($symbol, $interval, $family, $timeframeProfile, $featureSet);
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $rawVerdict = (string) ($report['verdict'] ?? 'INCONCLUSIVE');
        $verdict = $this->normalizeVerdictForEvidence($rawVerdict, $summary);
        $scenario['latest_campaign_id'] = $report['campaign_id'] ?? ($scenario['latest_campaign_id'] ?? null);
        $scenario['latest_status'] = $verdict;
        $scenario['latest_verdict'] = $verdict;
        if ($rawVerdict !== $verdict) {
            $scenario['latest_raw_verdict'] = $rawVerdict;
            $scenario['latest_verdict_normalized_reason'] = 'terminal_null_requires_at_least_one_tested_candidate';
        }
        $scenario['updated_at'] = gmdate('c');
        $scenario['family_exhausted'] = $verdict === 'NULL_FAMILY_EXHAUSTED';
        $scenario['research_history'] = array_values(array_slice([
            [
                'campaign_id' => $report['campaign_id'] ?? null,
                'verdict' => $verdict,
                'raw_verdict' => $rawVerdict !== $verdict ? $rawVerdict : null,
                'rounds' => $summary['rounds'] ?? null,
                'total_candidates' => $summary['total_candidates'] ?? null,
                'best_ann_sharpe' => $summary['best_ann_sharpe'] ?? null,
                'best_dsr' => $summary['best_dsr'] ?? null,
                'best_campaign_dsr' => $summary['best_campaign_dsr'] ?? null,
                'best_holdout_sharpe' => $summary['best_holdout_sharpe'] ?? null,
                'holdout_generation' => $summary['holdout_generation'] ?? data_get($report, 'data_manifest.holdout_generation'),
                'max_holdout_generation' => $summary['max_holdout_generation'] ?? data_get($report, 'data_manifest.max_holdout_generation'),
                'stop_reason' => $summary['stop_reason'] ?? null,
                'timeframe_bucket' => $timeframeProfile['horizon_bucket'] ?? null,
                'feature_set_id' => $featureSet['feature_set_id'] ?? StrategyFeatureSetProfile::PRICE_ONLY,
                'data_sha' => $summary['data_sha'] ?? data_get($report, 'data_manifest.sha256'),
                'cost_profile_hash' => $summary['cost_profile_hash'] ?? data_get($report, 'cost_profile.cost_profile_hash'),
                'negative_conclusion' => $report['negative_conclusion'] ?? null,
                'recorded_at' => gmdate('c'),
            ],
            ...array_values((array) ($scenario['research_history'] ?? [])),
        ], 0, 20));
        $scenario['regime_note'] = $report['regime_summary']['scenario_note'] ?? null;
        $scenario['latest_summary'] = [
            'rounds' => $summary['rounds'] ?? null,
            'total_candidates' => $summary['total_candidates'] ?? null,
            'best_ann_sharpe' => $summary['best_ann_sharpe'] ?? null,
            'best_dsr' => $summary['best_dsr'] ?? null,
            'best_campaign_dsr' => $summary['best_campaign_dsr'] ?? null,
            'best_holdout_sharpe' => $summary['best_holdout_sharpe'] ?? null,
            'holdout_generation' => $summary['holdout_generation'] ?? data_get($report, 'data_manifest.holdout_generation'),
            'max_holdout_generation' => $summary['max_holdout_generation'] ?? data_get($report, 'data_manifest.max_holdout_generation'),
            'stop_reason' => $summary['stop_reason'] ?? null,
            'timeframe_bucket' => $timeframeProfile['horizon_bucket'] ?? null,
            'feature_set_id' => $featureSet['feature_set_id'] ?? StrategyFeatureSetProfile::PRICE_ONLY,
            'data_sha' => $summary['data_sha'] ?? data_get($report, 'data_manifest.sha256'),
            'cost_profile_hash' => $summary['cost_profile_hash'] ?? data_get($report, 'cost_profile.cost_profile_hash'),
        ];
        $scenario['best_observed'] = data_get($report, 'scenario_profile.best_observed', []);
        $scenario['knowledge_note'] = data_get($report, 'scenario_profile.knowledge_note', 'Scenario knowledge is research-only and not an executable signal.');
        $registry['scenarios'][$key] = $scenario;
        $registry['scenario_knowledge_matrix'] = $this->scenarioKnowledgeMatrix($registry);
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
        $profiler = new StrategyTimeframeProfile;
        $featureProfiler = new StrategyFeatureSetProfile;
        $priceOnly = $featureProfiler->describe(StrategyFeatureSetProfile::PRICE_ONLY);
        $families = [
            ['family' => 'trend-breakout-v1', 'priority' => 1, 'hypothesis' => 'trend_continuation_or_breakout_edge_after_costs'],
            ['family' => 'mean-reversion-v1', 'priority' => 2, 'hypothesis' => 'short_term_reversion_edge_after_costs'],
            ['family' => 'momentum-v1', 'priority' => 3, 'hypothesis' => 'directional_momentum_edge_after_costs'],
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
                $timeframeProfile = $profiler->describe((string) $market['interval']);
                $roadmap[] = [
                    'symbol' => $market['symbol'],
                    'interval' => $market['interval'],
                    'timeframe_profile' => $timeframeProfile,
                    'strategy_family' => $family['family'],
                    'feature_set' => $priceOnly,
                    'priority' => ((int) $market['priority'] * 10) + (int) $family['priority'],
                    'research_rationale' => $this->researchRationale(
                        (string) $market['symbol'],
                        (string) $market['interval'],
                        (string) $family['family'],
                        $timeframeProfile,
                        $priceOnly,
                        (string) $family['hypothesis'],
                    ),
                ];
            }
        }
        $timeframeProfiles = [];
        foreach (['5m', '15m', '1h', '4h', '1d', '1w', '1mo'] as $interval) {
            $profile = $profiler->describe($interval);
            $timeframeProfiles[$profile['normalized_interval']] = $profile;
        }
        $deferred = [];
        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            foreach (['5m', '15m', '1mo'] as $interval) {
                foreach ($families as $family) {
                    $profile = $profiler->describe($interval);
                    $deferred[] = [
                        'symbol' => $symbol,
                        'interval' => $interval,
                        'timeframe_profile' => $profile,
                        'strategy_family' => $family['family'],
                        'feature_set' => $priceOnly,
                        'research_rationale' => $this->researchRationale(
                            $symbol,
                            $interval,
                            (string) $family['family'],
                            $profile,
                            $priceOnly,
                            (string) ($family['hypothesis'] ?? 'scenario_specific_edge_after_costs'),
                        ),
                        'status' => $profile['roadmap_readiness'],
                        'activation_requirements' => $profile['activation_requirements'],
                        'reason' => 'registered_as_deferred_research_hypothesis_not_active_roadmap',
                    ];
                }
            }
        }

        return [
                'schema_version' => 'atlas.finance.strategy_scenario_registry.v1',
                'created_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
                'strategy_families' => $families,
                'feature_sets' => [StrategyFeatureSetProfile::PRICE_ONLY => $priceOnly],
                'feature_set_activation_roadmap' => $featureProfiler->activationRoadmap(),
                'deferred_feature_set_backlog' => $featureProfiler->deferredBacklog(),
                'timeframe_profiles' => $timeframeProfiles,
                'sequential_roadmap' => $roadmap,
                'deferred_timeframe_backlog' => $deferred,
                'scenario_knowledge_matrix' => [],
                'do_not_start_in_parallel' => true,
                'avoid_until_ready' => ['15m', '5m', 'leverage', 'perps_funding', 'survivorship_biased_top_coins'],
                'scenarios' => [],
            ];
    }

    /** @return array<string,mixed>|null */
    public function nextRoadmapScenario(?string $family = 'trend-breakout-v1', ?string $symbol = null, ?string $interval = null): ?array
    {
        $registry = $this->load();
        $requestedFamily = $family !== null && $family !== '' && $family !== 'roadmap' ? $family : null;
        $requestedSymbol = $symbol !== null && trim($symbol) !== '' ? strtoupper(trim($symbol)) : null;
        $requestedInterval = $interval !== null && trim($interval) !== '' ? trim($interval) : null;
        $roadmap = array_values((array) ($registry['sequential_roadmap'] ?? []));
        usort($roadmap, static fn (array $a, array $b): int => (int) ($a['priority'] ?? 999) <=> (int) ($b['priority'] ?? 999));
        $active = $this->activeRoadmapScenario($registry, $roadmap, $requestedFamily, $requestedSymbol, $requestedInterval);
        if ($active !== null) {
            return $active;
        }

        foreach ($roadmap as $candidate) {
            $symbol = strtoupper((string) ($candidate['symbol'] ?? ''));
            $interval = (string) ($candidate['interval'] ?? '');
            $candidateFamily = (string) ($candidate['strategy_family'] ?? $requestedFamily ?? 'trend-breakout-v1');
            $featureSet = is_array($candidate['feature_set'] ?? null)
                ? $candidate['feature_set']
                : (new StrategyFeatureSetProfile)->describe(StrategyFeatureSetProfile::PRICE_ONLY);
            if ($symbol === '' || $interval === '') {
                continue;
            }
            if ($requestedSymbol !== null && $symbol !== $requestedSymbol) {
                continue;
            }
            if ($requestedInterval !== null && $interval !== $requestedInterval) {
                continue;
            }
            if ($requestedFamily !== null && $candidateFamily !== $requestedFamily) {
                continue;
            }
            $key = $this->scenarioKey($symbol, $interval, $candidateFamily, (string) ($featureSet['feature_set_id'] ?? StrategyFeatureSetProfile::PRICE_ONLY));
            $scenario = $registry['scenarios'][$key] ?? null;
            if (! is_array($scenario)) {
                return [
                    'symbol' => $symbol,
                    'interval' => $interval,
                    'strategy_family' => $candidateFamily,
                    'feature_set' => $featureSet,
                    'research_rationale' => is_array($candidate['research_rationale'] ?? null) ? $candidate['research_rationale'] : $this->researchRationale($symbol, $interval, $candidateFamily, (array) ($candidate['timeframe_profile'] ?? []), $featureSet),
                    'reason' => 'scenario_not_started',
                    'priority' => $candidate['priority'] ?? null,
                ];
            }
            if ((bool) ($scenario['family_exhausted'] ?? false)) {
                continue;
            }
            $latest = (string) ($scenario['latest_verdict'] ?? $scenario['latest_status'] ?? '');
            $latestStatus = (string) ($scenario['latest_status'] ?? '');
            $latestSummary = is_array($scenario['latest_summary'] ?? null) ? $scenario['latest_summary'] : [];
            if (in_array($latestStatus, ['running', 'paused', 'inconclusive'], true)) {
                return [
                    'symbol' => $symbol,
                    'interval' => $interval,
                    'strategy_family' => $candidateFamily,
                    'feature_set' => $featureSet,
                    'research_rationale' => is_array($candidate['research_rationale'] ?? null) ? $candidate['research_rationale'] : $this->researchRationale($symbol, $interval, $candidateFamily, (array) ($candidate['timeframe_profile'] ?? []), $featureSet),
                    'reason' => 'scenario_incomplete',
                    'priority' => $candidate['priority'] ?? null,
                    'latest_campaign_id' => $scenario['latest_campaign_id'] ?? null,
                    'latest_verdict' => $latest !== '' ? $latest : null,
                    'holdout_generation' => max(0, (int) ($scenario['active_holdout_generation'] ?? $latestSummary['holdout_generation'] ?? 0)),
                ];
            }
            if (! $this->hasCandidateEvidence($latestSummary)
                && in_array($latest, ['INCONCLUSIVE', 'NULL_WEAK', 'NULL_STRONG', 'NULL_HOLDOUT_EXHAUSTED', 'NULL_FAMILY_EXHAUSTED', 'CERTIFIED'], true)) {
                return [
                    'symbol' => $symbol,
                    'interval' => $interval,
                    'strategy_family' => $candidateFamily,
                    'feature_set' => $featureSet,
                    'research_rationale' => is_array($candidate['research_rationale'] ?? null) ? $candidate['research_rationale'] : $this->researchRationale($symbol, $interval, $candidateFamily, (array) ($candidate['timeframe_profile'] ?? []), $featureSet),
                    'reason' => 'zero_candidate_campaign_needs_fresh_holdout_generation',
                    'priority' => $candidate['priority'] ?? null,
                    'previous_campaign_id' => $scenario['latest_campaign_id'] ?? null,
                    'latest_verdict' => $latest !== '' ? $latest : null,
                    'holdout_generation' => $this->nextHoldoutGeneration($scenario),
                ];
            }
            if ($latest === 'NULL_HOLDOUT_EXHAUSTED' && $this->hasCandidateEvidence($latestSummary) && $this->hasFreshHoldoutGeneration($scenario)) {
                return [
                    'symbol' => $symbol,
                    'interval' => $interval,
                    'strategy_family' => $candidateFamily,
                    'feature_set' => $featureSet,
                    'research_rationale' => is_array($candidate['research_rationale'] ?? null) ? $candidate['research_rationale'] : $this->researchRationale($symbol, $interval, $candidateFamily, (array) ($candidate['timeframe_profile'] ?? []), $featureSet),
                    'reason' => 'holdout_exhausted_needs_fresh_holdout_generation',
                    'priority' => $candidate['priority'] ?? null,
                    'previous_campaign_id' => $scenario['latest_campaign_id'] ?? null,
                    'latest_verdict' => $latest,
                    'holdout_generation' => $this->nextHoldoutGeneration($scenario),
                ];
            }
            if (in_array($latest, ['NULL_HOLDOUT_EXHAUSTED', 'NULL_STRONG', 'NULL_FAMILY_EXHAUSTED', 'CERTIFIED'], true)) {
                continue;
            }

            return [
                'symbol' => $symbol,
                'interval' => $interval,
                'strategy_family' => $candidateFamily,
                'feature_set' => $featureSet,
                'research_rationale' => is_array($candidate['research_rationale'] ?? null) ? $candidate['research_rationale'] : $this->researchRationale($symbol, $interval, $candidateFamily, (array) ($candidate['timeframe_profile'] ?? []), $featureSet),
                'reason' => 'scenario_incomplete',
                'priority' => $candidate['priority'] ?? null,
                'latest_campaign_id' => $scenario['latest_campaign_id'] ?? null,
                'latest_verdict' => $latest !== '' ? $latest : null,
                'holdout_generation' => max(0, (int) ($latestSummary['holdout_generation'] ?? 0)),
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
            'feature_sets' => $default['feature_sets'],
            'feature_set_activation_roadmap' => $default['feature_set_activation_roadmap'],
            'deferred_feature_set_backlog' => $default['deferred_feature_set_backlog'],
            'timeframe_profiles' => $default['timeframe_profiles'],
            'sequential_roadmap' => $default['sequential_roadmap'],
            'deferred_timeframe_backlog' => $default['deferred_timeframe_backlog'],
            'scenario_knowledge_matrix' => [],
            'do_not_start_in_parallel' => true,
            'avoid_until_ready' => $default['avoid_until_ready'],
            'scenarios' => [],
        ];
        if (! is_array($registry['strategy_families'] ?? null) || $registry['strategy_families'] === []) {
            $registry['strategy_families'] = $default['strategy_families'];
        }
        if (! $this->featureRowsHaveActivationPriority((array) ($registry['deferred_feature_set_backlog'] ?? []))) {
            $registry['deferred_feature_set_backlog'] = $default['deferred_feature_set_backlog'];
        }
        if (! $this->featureRowsHaveActivationPriority((array) ($registry['feature_set_activation_roadmap'] ?? []))) {
            $registry['feature_set_activation_roadmap'] = $default['feature_set_activation_roadmap'];
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
                        'timeframe_profile' => (new StrategyTimeframeProfile)->describe((string) ($market['interval'] ?? '1d')),
                        'strategy_family' => (string) ($family['family'] ?? 'trend-breakout-v1'),
                        'feature_set' => (new StrategyFeatureSetProfile)->describe(StrategyFeatureSetProfile::PRICE_ONLY),
                        'priority' => ((int) ($market['priority'] ?? 999) * 10) + (int) ($family['priority'] ?? 1),
                        'research_rationale' => $this->researchRationale(
                            strtoupper((string) ($market['symbol'] ?? '')),
                            (string) ($market['interval'] ?? '1d'),
                            (string) ($family['family'] ?? 'trend-breakout-v1'),
                            (new StrategyTimeframeProfile)->describe((string) ($market['interval'] ?? '1d')),
                            (new StrategyFeatureSetProfile)->describe(StrategyFeatureSetProfile::PRICE_ONLY),
                        ),
                    ];
                }
            }
            $registry['sequential_roadmap'] = $expanded !== [] ? $expanded : $default['sequential_roadmap'];
        } else {
            $profiler = new StrategyTimeframeProfile;
            $registry['sequential_roadmap'] = array_values(array_map(
                function (mixed $entry) use ($profiler): mixed {
                    if (! is_array($entry)) {
                        return $entry;
                    }
                    if (! is_array($entry['timeframe_profile'] ?? null)) {
                        $entry['timeframe_profile'] = $profiler->describe((string) ($entry['interval'] ?? '1d'));
                    }
                    if (! is_array($entry['feature_set'] ?? null)) {
                        $entry['feature_set'] = (new StrategyFeatureSetProfile)->describe(StrategyFeatureSetProfile::PRICE_ONLY);
                    }
                    if (! is_array($entry['research_rationale'] ?? null)) {
                        $entry['research_rationale'] = $this->researchRationale(
                            strtoupper((string) ($entry['symbol'] ?? '')),
                            (string) ($entry['interval'] ?? '1d'),
                            (string) ($entry['strategy_family'] ?? 'trend-breakout-v1'),
                            (array) ($entry['timeframe_profile'] ?? []),
                            (array) ($entry['feature_set'] ?? []),
                        );
                    }

                    return $entry;
                },
                $roadmap,
            ));
        }
        $registry['scenario_knowledge_matrix'] = $this->scenarioKnowledgeMatrix($registry);
        $registry['scenarios'] = $this->normalizeScenarioEvidenceVerdicts((array) ($registry['scenarios'] ?? []));
        $registry['scenario_knowledge_matrix'] = $this->scenarioKnowledgeMatrix($registry);

        return $registry;
    }

    /** @param array<string,mixed> $registry */
    private function save(array $registry): void
    {
        @mkdir(dirname($this->path), 0o755, true);
        file_put_contents($this->path, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function scenarioKey(string $symbol, string $interval, string $family, string $featureSetId): string
    {
        $base = strtoupper($symbol).'-'.$interval.'-'.$family;

        return $featureSetId === StrategyFeatureSetProfile::PRICE_ONLY ? $base : $base.'-'.$featureSetId;
    }

    /**
     * @param  array<string,mixed>  $registry
     * @return array<string,mixed>
     */
    private function scenarioKnowledgeMatrix(array $registry): array
    {
        $familyUniverse = array_values(array_filter(array_map(
            static fn (mixed $row): ?string => is_array($row) ? (is_string($row['family'] ?? null) ? (string) $row['family'] : null) : null,
            (array) ($registry['strategy_families'] ?? []),
        )));
        $groups = [];

        foreach ((array) ($registry['scenarios'] ?? []) as $scenario) {
            if (! is_array($scenario)) {
                continue;
            }
            $symbol = strtoupper((string) ($scenario['symbol'] ?? ''));
            $interval = (string) ($scenario['interval'] ?? '');
            $family = (string) ($scenario['strategy_family'] ?? '');
            if ($symbol === '' || $interval === '' || $family === '') {
                continue;
            }
            $featureSet = is_array($scenario['feature_set'] ?? null)
                ? $scenario['feature_set']
                : (new StrategyFeatureSetProfile)->describe(StrategyFeatureSetProfile::PRICE_ONLY);
            $featureSetId = (string) ($featureSet['feature_set_id'] ?? StrategyFeatureSetProfile::PRICE_ONLY);
            $scopeKey = $this->scenarioScopeKey($symbol, $interval, $featureSetId);
            $latestVerdict = (string) ($scenario['latest_verdict'] ?? $scenario['latest_status'] ?? 'unknown');
            $latestSummary = is_array($scenario['latest_summary'] ?? null) ? $scenario['latest_summary'] : [];
            $hasEvidence = $this->hasCandidateEvidence($latestSummary);

            $groups[$scopeKey] ??= [
                'schema_version' => 'atlas.finance.strategy_scenario_knowledge_matrix.v1',
                'scope_key' => $scopeKey,
                'symbol' => $symbol,
                'interval' => $interval,
                'feature_set_id' => $featureSetId,
                'families' => [],
                'coverage' => [],
                'leaderboard' => [],
                'current_best_research_observation' => null,
                'knowledge_policy' => 'research_only_not_an_executable_signal',
                'transfer_policy' => 'do_not_transfer_between_assets_timeframes_families_or_feature_sets_without_new_campaign',
                'updated_at' => gmdate('c'),
            ];
            $groups[$scopeKey]['families'][$family] = [
                'strategy_family' => $family,
                'latest_campaign_id' => $scenario['latest_campaign_id'] ?? null,
                'latest_verdict' => $latestVerdict,
                'terminal' => $hasEvidence && in_array($latestVerdict, ['CERTIFIED', 'NULL_WEAK', 'NULL_STRONG', 'NULL_HOLDOUT_EXHAUSTED', 'NULL_FAMILY_EXHAUSTED'], true),
                'certified' => $latestVerdict === 'CERTIFIED',
                'family_exhausted' => (bool) ($scenario['family_exhausted'] ?? false),
                'rounds' => $latestSummary['rounds'] ?? null,
                'total_candidates' => $latestSummary['total_candidates'] ?? null,
                'best_campaign_dsr' => $latestSummary['best_campaign_dsr'] ?? null,
                'best_dsr' => $latestSummary['best_dsr'] ?? null,
                'best_ann_sharpe' => $latestSummary['best_ann_sharpe'] ?? null,
                'best_holdout_sharpe' => $latestSummary['best_holdout_sharpe'] ?? null,
                'holdout_generation' => $latestSummary['holdout_generation'] ?? null,
                'research_score' => $this->researchScore($latestSummary),
                'research_score_basis' => $this->researchScoreBasis($latestSummary),
                'data_sha' => $latestSummary['data_sha'] ?? null,
                'cost_profile_hash' => $latestSummary['cost_profile_hash'] ?? null,
                'updated_at' => $scenario['updated_at'] ?? null,
                'knowledge_note' => $scenario['knowledge_note'] ?? 'Scenario knowledge is research-only and not an executable signal.',
            ];
        }

        foreach ($groups as $scopeKey => $group) {
            $families = array_values((array) ($group['families'] ?? []));
            usort($families, static function (array $a, array $b): int {
                $certified = ((bool) ($b['certified'] ?? false) <=> (bool) ($a['certified'] ?? false));
                if ($certified !== 0) {
                    return $certified;
                }

                return (float) ($b['research_score'] ?? -INF) <=> (float) ($a['research_score'] ?? -INF);
            });
            $knownFamilyCount = $familyUniverse !== [] ? count($familyUniverse) : count($families);
            $groups[$scopeKey]['families'] = array_column($families, null, 'strategy_family');
            $groups[$scopeKey]['leaderboard'] = array_map(
                static fn (array $row): array => [
                    'strategy_family' => $row['strategy_family'],
                    'latest_verdict' => $row['latest_verdict'],
                    'best_campaign_dsr' => $row['best_campaign_dsr'],
                    'best_dsr' => $row['best_dsr'],
                    'best_holdout_sharpe' => $row['best_holdout_sharpe'],
                    'research_score' => $row['research_score'],
                    'research_score_basis' => $row['research_score_basis'],
                    'certified' => $row['certified'],
                ],
                $families,
            );
            $groups[$scopeKey]['current_best_research_observation'] = $groups[$scopeKey]['leaderboard'][0] ?? null;
            $groups[$scopeKey]['coverage'] = [
                'known_strategy_families' => $knownFamilyCount,
                'studied_strategy_families' => count($families),
                'terminal_strategy_families' => count(array_filter($families, static fn (array $row): bool => (bool) ($row['terminal'] ?? false))),
                'certified_strategy_families' => count(array_filter($families, static fn (array $row): bool => (bool) ($row['certified'] ?? false))),
                'null_strategy_families' => count(array_filter($families, static fn (array $row): bool => (bool) ($row['terminal'] ?? false) && str_starts_with((string) ($row['latest_verdict'] ?? ''), 'NULL_'))),
            ];
        }

        ksort($groups);

        return $groups;
    }

    /** @param array<string,mixed> $summary */
    private function hasCandidateEvidence(array $summary): bool
    {
        return (int) ($summary['rounds'] ?? 0) > 0
            && (int) ($summary['total_candidates'] ?? 0) > 0;
    }

    /**
     * @param  array<string,mixed>  $registry
     * @param  list<array<string,mixed>>  $roadmap
     * @return array<string,mixed>|null
     */
    private function activeRoadmapScenario(array $registry, array $roadmap, ?string $requestedFamily, ?string $requestedSymbol, ?string $requestedInterval): ?array
    {
        foreach ($roadmap as $candidate) {
            $symbol = strtoupper((string) ($candidate['symbol'] ?? ''));
            $interval = (string) ($candidate['interval'] ?? '');
            $candidateFamily = (string) ($candidate['strategy_family'] ?? $requestedFamily ?? 'trend-breakout-v1');
            $featureSet = is_array($candidate['feature_set'] ?? null)
                ? $candidate['feature_set']
                : (new StrategyFeatureSetProfile)->describe(StrategyFeatureSetProfile::PRICE_ONLY);
            if ($symbol === ''
                || $interval === ''
                || ($requestedSymbol !== null && $symbol !== $requestedSymbol)
                || ($requestedInterval !== null && $interval !== $requestedInterval)
                || ($requestedFamily !== null && $candidateFamily !== $requestedFamily)) {
                continue;
            }
            $key = $this->scenarioKey($symbol, $interval, $candidateFamily, (string) ($featureSet['feature_set_id'] ?? StrategyFeatureSetProfile::PRICE_ONLY));
            $scenario = $registry['scenarios'][$key] ?? null;
            if (! is_array($scenario) || ! in_array((string) ($scenario['latest_status'] ?? ''), ['running', 'paused'], true)) {
                continue;
            }
            $latestSummary = is_array($scenario['latest_summary'] ?? null) ? $scenario['latest_summary'] : [];
            $activeCampaign = $this->readCampaign((string) ($scenario['latest_campaign_id'] ?? ''));

            return [
                'symbol' => $symbol,
                'interval' => $interval,
                'strategy_family' => $candidateFamily,
                'feature_set' => $featureSet,
                'research_rationale' => is_array($candidate['research_rationale'] ?? null) ? $candidate['research_rationale'] : $this->researchRationale($symbol, $interval, $candidateFamily, (array) ($candidate['timeframe_profile'] ?? []), $featureSet),
                'reason' => 'scenario_incomplete',
                'priority' => $candidate['priority'] ?? null,
                'latest_campaign_id' => $scenario['latest_campaign_id'] ?? null,
                'latest_verdict' => $scenario['latest_verdict'] ?? null,
                'holdout_generation' => max(0, (int) (data_get($activeCampaign, 'data_manifest.holdout_generation', $scenario['active_holdout_generation'] ?? $latestSummary['holdout_generation'] ?? 0))),
            ];
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function readCampaign(string $campaignId): array
    {
        $campaignId = StrategyCampaignStore::sanitizeId($campaignId);
        if ($campaignId === '') {
            return [];
        }
        $base = rtrim(dirname($this->path), '/');
        $root = str_contains($this->path, '/framework/')
            ? $base.'/dry-run-campaigns'
            : $base.'/campaigns';
        $path = rtrim($root, '/').'/'.$campaignId.'/campaign.json';
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $summary */
    private function normalizeVerdictForEvidence(string $verdict, array $summary): string
    {
        if (! $this->hasCandidateEvidence($summary)
            && (str_starts_with($verdict, 'NULL_') || $verdict === 'CERTIFIED')) {
            return 'INCONCLUSIVE';
        }

        return $verdict !== '' ? $verdict : 'INCONCLUSIVE';
    }

    /** @param array<string,mixed> $scenario */
    private function hasFreshHoldoutGeneration(array $scenario): bool
    {
        $next = $this->nextHoldoutGeneration($scenario);
        $summary = is_array($scenario['latest_summary'] ?? null) ? $scenario['latest_summary'] : [];
        $max = null;
        if (is_numeric($summary['max_holdout_generation'] ?? null)) {
            $max = (int) $summary['max_holdout_generation'];
        }
        foreach ((array) ($scenario['research_history'] ?? []) as $row) {
            if (is_array($row) && is_numeric($row['max_holdout_generation'] ?? null)) {
                $max = $max === null ? (int) $row['max_holdout_generation'] : max($max, (int) $row['max_holdout_generation']);
            }
        }
        if ($max === null) {
            return $next <= 1;
        }

        return $next <= $max;
    }

    /**
     * @param  array<string,mixed>  $scenarios
     * @return array<string,mixed>
     */
    private function normalizeScenarioEvidenceVerdicts(array $scenarios): array
    {
        foreach ($scenarios as $key => $scenario) {
            if (! is_array($scenario)) {
                continue;
            }
            $summary = is_array($scenario['latest_summary'] ?? null) ? $scenario['latest_summary'] : [];
            $latest = (string) ($scenario['latest_verdict'] ?? $scenario['latest_status'] ?? '');
            $normalized = $this->normalizeVerdictForEvidence($latest, $summary);
            if ($latest !== $normalized) {
                $scenario['latest_raw_verdict'] = $latest;
                $scenario['latest_verdict_normalized_reason'] = 'terminal_null_requires_at_least_one_tested_candidate';
                $scenario['latest_status'] = $normalized;
                $scenario['latest_verdict'] = $normalized;
                $scenario['family_exhausted'] = false;
                $scenarios[$key] = $scenario;
            }
        }

        return $scenarios;
    }

    /** @param array<string,mixed> $scenario */
    private function nextHoldoutGeneration(array $scenario): int
    {
        $maxGeneration = -1;
        $summary = is_array($scenario['latest_summary'] ?? null) ? $scenario['latest_summary'] : [];
        if (is_numeric($summary['holdout_generation'] ?? null)) {
            $maxGeneration = max($maxGeneration, (int) $summary['holdout_generation']);
        }
        foreach ((array) ($scenario['research_history'] ?? []) as $row) {
            if (is_array($row) && is_numeric($row['holdout_generation'] ?? null)) {
                $maxGeneration = max($maxGeneration, (int) $row['holdout_generation']);
            }
        }

        return $maxGeneration >= 0 ? $maxGeneration + 1 : 1;
    }

    /** @param array<string,mixed> $summary */
    private function researchScore(array $summary): ?float
    {
        foreach (['best_campaign_dsr', 'best_dsr', 'best_holdout_sharpe'] as $field) {
            if (is_numeric($summary[$field] ?? null)) {
                return (float) $summary[$field];
            }
        }

        return null;
    }

    /** @param array<string,mixed> $summary */
    private function researchScoreBasis(array $summary): ?string
    {
        foreach (['best_campaign_dsr', 'best_dsr', 'best_holdout_sharpe'] as $field) {
            if (is_numeric($summary[$field] ?? null)) {
                return $field;
            }
        }

        return null;
    }

    private function scenarioScopeKey(string $symbol, string $interval, string $featureSetId): string
    {
        $base = strtoupper($symbol).'-'.$interval;

        return $featureSetId === StrategyFeatureSetProfile::PRICE_ONLY ? $base : $base.'-'.$featureSetId;
    }

    /** @param array<int|string,mixed> $rows */
    private function featureRowsHaveActivationPriority(array $rows): bool
    {
        if ($rows === []) {
            return false;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                return false;
            }
            if ((string) ($row['feature_set_id'] ?? '') === '') {
                return false;
            }
            if (! is_int($row['activation_priority'] ?? null) && ! ctype_digit((string) ($row['activation_priority'] ?? ''))) {
                return false;
            }
            if ((string) ($row['activation_phase'] ?? '') === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $timeframeProfile
     * @param  array<string,mixed>  $featureSet
     * @return array<string,mixed>
     */
    private function researchRationale(string $symbol, string $interval, string $family, array $timeframeProfile, array $featureSet, string $hypothesis = 'scenario_specific_edge_after_costs'): array
    {
        return [
            'schema_version' => 'atlas.finance.strategy_research_rationale.v1',
            'hypothesis' => $hypothesis,
            'scenario_scope' => 'exact_symbol_interval_family_feature_set',
            'symbol' => strtoupper($symbol),
            'interval' => $interval,
            'strategy_family' => $family,
            'timeframe_bucket' => $timeframeProfile['horizon_bucket'] ?? null,
            'trade_style' => $timeframeProfile['trade_style'] ?? null,
            'feature_set_id' => $featureSet['feature_set_id'] ?? StrategyFeatureSetProfile::PRICE_ONLY,
            'selection_reason' => $this->selectionReason((string) ($timeframeProfile['horizon_bucket'] ?? ''), (string) ($featureSet['feature_set_id'] ?? StrategyFeatureSetProfile::PRICE_ONLY)),
            'transfer_policy' => 'do_not_transfer_results_between_assets_timeframes_families_or_feature_sets_without_new_campaign',
            'certification_policy' => 'same_honesty_gate_or_stricter_no_shortcut_for_easier_timeframe',
        ];
    }

    private function selectionReason(string $bucket, string $featureSetId): string
    {
        if ($featureSetId !== StrategyFeatureSetProfile::PRICE_ONLY) {
            return 'non_price_feature_sets_are_separate_research_hypotheses_and_must_not_replace_price_only_baseline';
        }

        return match ($bucket) {
            'daily_swing' => 'daily_price_only_campaigns_are_the_lowest_noise_baseline_for_honest_crypto_discovery',
            'intraday_swing' => '4h_price_only_campaigns_expand_after_daily_baseline_with_higher_trade_floor_and_same_honesty_gate',
            'high_frequency_intraday' => 'short_timeframes_are_deferred_until_microstructure_and_cost_controls_exist',
            'position' => 'long_horizon_campaigns_are_deferred_until_enough_cycle_depth_exists',
            default => 'scenario_requires_explicit_profile_before_activation',
        };
    }
}
