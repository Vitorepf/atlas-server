<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Campaign;

/**
 * Read-only operational audit for the scientific campaign platform.
 *
 * This is not a trading gate and never emits orders. It answers one question:
 * is the loop currently shaped like a governed, sequential, propose-only
 * campaign platform rather than a loose background process?
 */
final class StrategyLoopOperationalAudit
{
    /** @var list<string> */
    private const REQUIRED_FAMILIES = ['trend-breakout-v1', 'mean-reversion-v1', 'momentum-v1'];

    /**
     * @return array<string,mixed>
     */
    public function audit(bool $dryRun = false, ?string $campaignId = null, bool $includeRuntime = false): array
    {
        $campaign = $this->findCampaign($dryRun, $campaignId);
        $registry = StrategyScenarioRegistry::default($dryRun)->load();
        $holdouts = $this->readJson($this->holdoutRegistryPath($dryRun));
        $ledgerRows = is_array($campaign) ? $this->readLedgerRows((string) ($campaign['_ledger_path'] ?? '')) : [];
        $researchEvidenceRows = $this->readLedgerRows($this->researchEvidenceLedgerPath($dryRun));
        $terminalReports = $this->readTerminalReports($dryRun, $campaignId ?? (is_array($campaign) ? (string) ($campaign['campaign_id'] ?? '') : null));
        $latestRow = $ledgerRows !== [] ? $ledgerRows[count($ledgerRows) - 1] : null;
        $executionSurface = (new StrategyNoExecutionSurfaceAudit)->scanDefault();
        $checks = [];

        $checks[] = $this->check('campaign_found', is_array($campaign), is_array($campaign) ? 'campaign.json found' : 'no running/latest campaign found');
        if (is_array($campaign)) {
            $checks[] = $this->check('campaign_running_or_terminal_scientific', in_array((string) ($campaign['status'] ?? ''), ['running', 'paused', 'completed'], true), 'campaign status is governed');
            $checks[] = $this->check('campaign_pre_registered_budget', (int) data_get($campaign, 'pre_registered_budget.max_rounds', 0) > 0 && (int) data_get($campaign, 'pre_registered_budget.candidates_per_round', 0) > 0, 'max rounds and candidates per round are pre-registered');
            $checks[] = $this->check('campaign_propose_only', (bool) ($campaign['propose_only'] ?? false) === true && (string) ($campaign['live_trading'] ?? '') === 'forbidden', 'campaign is propose-only and live trading is forbidden');
            $secondEngineMode = (string) data_get($campaign, 'second_engine.mode', '');
            $checks[] = $this->check('campaign_second_engine_real', in_array($secondEngineMode, ['python-replay', 'independent-replay', 'freqtrade'], true), 'campaign uses a real independent second-engine mode');
            if ($secondEngineMode === 'freqtrade') {
                $freqtradeReport = (string) data_get($campaign, 'second_engine.freqtrade_report', '');
                $checks[] = $this->check('campaign_freqtrade_report_pinned', $freqtradeReport !== '' && is_file($freqtradeReport), 'freqtrade campaigns require a pinned external report path');
                $freqtrade = (new FreqtradeSecondEngineAdapter)->evaluate($freqtradeReport, [
                    'symbol' => $campaign['symbol'] ?? '',
                    'interval' => $campaign['interval'] ?? '',
                    'strategy_family' => $campaign['strategy_family'] ?? '',
                    'data_sha' => data_get($campaign, 'data_manifest.sha256', ''),
                    'holdout_id' => data_get($campaign, 'holdout.holdout_id', ''),
                    'cost_profile_hash' => data_get($campaign, 'cost_profile.cost_profile_hash', ''),
                ]);
                $checks[] = $this->check(
                    'campaign_freqtrade_report_validated',
                    (string) ($freqtrade['status'] ?? '') === 'ready',
                    'freqtrade report metadata and metrics match the campaign',
                    ['reason' => $freqtrade['reason'] ?? null],
                );
            }
            $checks[] = $this->check('campaign_cross_campaign_scope', (string) data_get($campaign, 'cross_campaign_rediscovery.scope', '') === 'same_symbol_interval_family_and_coarse_parameter_signature', 'cross-campaign rediscovery scope is scenario-specific');
            $checks[] = $this->check('campaign_data_hash_present', is_string(data_get($campaign, 'data_manifest.sha256')) && (string) data_get($campaign, 'data_manifest.sha256') !== '', 'data hash is recorded');
            $checks[] = $this->check('campaign_holdout_generation_manifested', $this->holdoutGenerationManifested($campaign), 'campaign records coherent holdout generation and maximum generation in budget, data manifest, and holdout blocks');
            $checks[] = $this->check('campaign_costs_frozen', is_numeric(data_get($campaign, 'cost_profile.fee_bps')) && is_numeric(data_get($campaign, 'cost_profile.slippage_bps')), 'cost profile is recorded');
            $checks[] = $this->check('campaign_timeframe_profile_present', $this->timeframeProfileIsGoverned((array) ($campaign['timeframe_profile'] ?? []), (string) ($campaign['interval'] ?? '')), 'campaign records a governed timeframe profile');
            $checks[] = $this->check('campaign_timeframe_policy_present', $this->timeframePolicyIsGoverned((array) ($campaign['timeframe_policy'] ?? []), (string) ($campaign['interval'] ?? '')), 'campaign records effective timeframe-specific search controls');
            $checks[] = $this->check('campaign_feature_set_governed', $this->featureSetIsGoverned((array) ($campaign['feature_set'] ?? [])), 'campaign feature set is governed and price-only unless explicitly activated');
        }

        $checks[] = $this->check('scenario_registry_exists', $registry !== [], 'scenario registry exists');
        $checks[] = $this->check('scenario_parallelism_policy', (bool) ($registry['do_not_start_in_parallel'] ?? false) === true, 'registry forbids parallel strategy loops');
        $checks[] = $this->check('scenario_roadmap_family_scoped', $this->roadmapIsFamilyScoped((array) ($registry['sequential_roadmap'] ?? [])), 'roadmap entries include symbol, interval, and strategy family');
        $checks[] = $this->check('scenario_required_families_present', $this->familiesPresent((array) ($registry['sequential_roadmap'] ?? [])), 'roadmap includes implemented strategy families');
        $checks[] = $this->check('scenario_timeframe_policy_present', $this->timeframePolicyPresent($registry), 'registry profiles active and deferred timeframes without starting them in parallel');
        $checks[] = $this->check('scenario_feature_set_policy_present', $this->featureSetPolicyPresent($registry), 'registry records price-only active feature set and defers future indices');
        $checks[] = $this->check('scenario_roadmap_rationale_present', $this->roadmapRationalePresent((array) ($registry['sequential_roadmap'] ?? [])), 'roadmap explains symbol/timeframe/family/feature-set selection without implying universality');
        $checks[] = $this->check('scenario_knowledge_matrix_present', $this->scenarioKnowledgeMatrixPresent((array) ($registry['scenario_knowledge_matrix'] ?? [])), 'registry compares strategy families per exact symbol/timeframe/feature-set without emitting trade signals');
        $checks[] = $this->check(
            'research_evidence_ledger_records_terminal_reports',
            $this->terminalReportsHaveResearchEvidence($terminalReports, $researchEvidenceRows),
            $terminalReports === [] ? 'no terminal reports to reconcile yet' : 'terminal reports are recorded in the governed research evidence ledger',
            ['terminal_reports' => count($terminalReports)],
        );
        $checks[] = $this->check(
            'scenario_registry_records_terminal_reports',
            $this->terminalReportsHaveScenarioRecords($terminalReports, $registry),
            $terminalReports === [] ? 'no terminal reports to reconcile yet' : 'terminal reports are recorded in scenario research history',
            ['terminal_reports' => count($terminalReports)],
        );
        $checks[] = $this->check(
            'research_evidence_propose_only_surface',
            $this->researchEvidenceIsProposeOnly($researchEvidenceRows),
            $researchEvidenceRows === [] ? 'no research evidence rows yet' : 'research evidence is propose-only and forbids live trading',
            ['evidence_rows' => count($researchEvidenceRows)],
        );
        $checks[] = $this->check(
            'code_no_execution_surface',
            (bool) ($executionSurface['passed'] ?? false),
            'finance strategy-loop code has no broker/order/key/live-money surface',
            ['violations' => $executionSurface['violations'] ?? []],
        );

        if (is_array($campaign)) {
            $validationId = (string) data_get($campaign, 'holdout.holdout_id', '');
            $confirmationId = (string) data_get($campaign, 'confirmation_holdout.holdout_id', '');
            $validation = is_array(data_get($holdouts, "holdouts.{$validationId}")) ? data_get($holdouts, "holdouts.{$validationId}") : [];
            $confirmation = is_array(data_get($holdouts, "holdouts.{$confirmationId}")) ? data_get($holdouts, "holdouts.{$confirmationId}") : [];
            $checks[] = $this->check('validation_holdout_registered', $validation !== [], 'validation holdout is globally registered');
            $checks[] = $this->check('validation_holdout_not_exhausted_for_running_campaign', (string) ($campaign['status'] ?? '') !== 'running' || (string) ($validation['status'] ?? '') !== StrategyCampaignStore::HOLDOUT_EXHAUSTED, 'running campaign does not use exhausted validation holdout');
            $checks[] = $this->check('confirmation_holdout_registered', $confirmation !== [], 'confirmation holdout is globally registered');
            $checks[] = $this->check('confirmation_holdout_reserved_or_governed', in_array((string) ($confirmation['status'] ?? ''), [StrategyCampaignStore::HOLDOUT_RESERVED, StrategyCampaignStore::HOLDOUT_ACTIVE, StrategyCampaignStore::HOLDOUT_EXHAUSTED], true), 'confirmation holdout has governed status');
        }

        $checks[] = $this->check('ledger_exists', $ledgerRows !== [], 'campaign ledger has at least one row');
        if (is_array($campaign) && is_array($latestRow)) {
            $checks[] = $this->check('ledger_matches_campaign', (string) ($latestRow['campaign_id'] ?? '') === (string) ($campaign['campaign_id'] ?? '') && (string) ($latestRow['strategy_family'] ?? '') === (string) ($campaign['strategy_family'] ?? ''), 'latest ledger row matches campaign and family');
            $checks[] = $this->check('ledger_never_merges', (bool) ($latestRow['merged_to_main'] ?? false) === false, 'latest row did not merge to main');
            $checks[] = $this->check('certification_requires_quarantine_payload', ! (bool) ($latestRow['certified'] ?? false) || is_array($latestRow['quarantine'] ?? null), 'certified rows must carry quarantine evidence');
        }

        if ($includeRuntime) {
            $processes = $this->strategyProcesses();
            $checks[] = $this->check('runtime_single_strategy_process', count($processes) === 1, 'exactly one strategy runner/search process is active', ['processes' => $processes]);
            $checks[] = $this->check('runtime_stop_switch_absent', ! is_file(storage_path('atlas/finance/STOP')), 'STOP switch is absent');
        }

        $passed = array_reduce($checks, static fn (bool $ok, array $check): bool => $ok && (bool) $check['passed'], true);

        return [
            'schema_version' => 'atlas.finance.strategy_loop_operational_audit.v1',
            'generated_at' => gmdate('c'),
            'status' => $passed ? 'pass' : 'fail',
            'score' => [
                'passed' => count(array_filter($checks, static fn (array $check): bool => (bool) $check['passed'])),
                'total' => count($checks),
            ],
            'campaign_id' => is_array($campaign) ? ($campaign['campaign_id'] ?? null) : null,
            'symbol' => is_array($campaign) ? ($campaign['symbol'] ?? null) : null,
            'interval' => is_array($campaign) ? ($campaign['interval'] ?? null) : null,
            'strategy_family' => is_array($campaign) ? ($campaign['strategy_family'] ?? null) : null,
            'checks' => $checks,
            'propose_only' => true,
            'live_trading' => 'forbidden',
        ];
    }

    /** @return array<string,mixed>|null */
    private function findCampaign(bool $dryRun, ?string $campaignId): ?array
    {
        $base = $this->campaignRootPath($dryRun);
        $paths = [];
        if ($campaignId !== null && $campaignId !== '') {
            $paths[] = rtrim($base, '/').'/'.StrategyCampaignStore::sanitizeId($campaignId).'/campaign.json';
        } else {
            foreach (glob(rtrim($base, '/').'/*/campaign.json') ?: [] as $path) {
                $paths[] = $path;
            }
            usort($paths, fn (string $a, string $b): int => $this->campaignSortScore($b) <=> $this->campaignSortScore($a));
        }

        foreach ($paths as $path) {
            $campaign = $this->readJson($path);
            if ($campaign === []) {
                continue;
            }
            if ($campaignId === null || $campaignId === '' || (string) ($campaign['campaign_id'] ?? '') === StrategyCampaignStore::sanitizeId($campaignId)) {
                $campaign['_path'] = $path;
                $campaign['_ledger_path'] = dirname($path).'/ledger.jsonl';

                return $campaign;
            }
        }

        return null;
    }

    private function campaignSortScore(string $campaignPath): int
    {
        $campaign = $this->readJson($campaignPath);
        $status = (string) ($campaign['status'] ?? '');
        $dir = dirname($campaignPath);
        $ledgerPath = $dir.'/ledger.jsonl';
        $nullReportPath = $dir.'/null-report.json';
        $freshness = max(
            is_file($ledgerPath) ? (int) filemtime($ledgerPath) : 0,
            is_file($nullReportPath) ? (int) filemtime($nullReportPath) : 0,
            is_file($campaignPath) ? (int) filemtime($campaignPath) : 0,
        );

        $priority = match ($status) {
            'running' => is_file($ledgerPath) ? 4 : 3,
            'paused' => 2,
            'completed', 'completed_certified', 'completed_null', 'inconclusive' => 1,
            default => 0,
        };

        return ($priority * 10_000_000_000) + $freshness;
    }

    /** @param array<int,mixed> $roadmap */
    private function roadmapIsFamilyScoped(array $roadmap): bool
    {
        if ($roadmap === []) {
            return false;
        }

        foreach ($roadmap as $entry) {
            if (! is_array($entry) || ! isset($entry['symbol'], $entry['interval'], $entry['strategy_family'])) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int,mixed> $roadmap */
    private function familiesPresent(array $roadmap): bool
    {
        $seen = [];
        foreach ($roadmap as $entry) {
            if (is_array($entry) && is_string($entry['strategy_family'] ?? null)) {
                $seen[(string) $entry['strategy_family']] = true;
            }
        }

        foreach (self::REQUIRED_FAMILIES as $family) {
            if (! isset($seen[$family])) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int,mixed> $roadmap */
    private function roadmapRationalePresent(array $roadmap): bool
    {
        if ($roadmap === []) {
            return false;
        }

        foreach ($roadmap as $entry) {
            if (! is_array($entry)) {
                return false;
            }
            $rationale = is_array($entry['research_rationale'] ?? null) ? $entry['research_rationale'] : [];
            if ((string) ($rationale['schema_version'] ?? '') !== 'atlas.finance.strategy_research_rationale.v1') {
                return false;
            }
            if ((string) ($rationale['scenario_scope'] ?? '') !== 'exact_symbol_interval_family_feature_set') {
                return false;
            }
            if ((string) ($rationale['transfer_policy'] ?? '') !== 'do_not_transfer_results_between_assets_timeframes_families_or_feature_sets_without_new_campaign') {
                return false;
            }
            if ((string) ($rationale['certification_policy'] ?? '') !== 'same_honesty_gate_or_stricter_no_shortcut_for_easier_timeframe') {
                return false;
            }
            foreach (['symbol', 'interval', 'strategy_family', 'feature_set_id', 'selection_reason'] as $field) {
                if ((string) ($rationale[$field] ?? '') === '') {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<string,mixed> $matrix */
    private function scenarioKnowledgeMatrixPresent(array $matrix): bool
    {
        if ($matrix === []) {
            return true;
        }

        foreach ($matrix as $entry) {
            if (! is_array($entry)) {
                return false;
            }
            if ((string) ($entry['schema_version'] ?? '') !== 'atlas.finance.strategy_scenario_knowledge_matrix.v1') {
                return false;
            }
            if ((string) ($entry['knowledge_policy'] ?? '') !== 'research_only_not_an_executable_signal') {
                return false;
            }
            if ((string) ($entry['transfer_policy'] ?? '') !== 'do_not_transfer_between_assets_timeframes_families_or_feature_sets_without_new_campaign') {
                return false;
            }
            foreach (['scope_key', 'symbol', 'interval', 'feature_set_id'] as $field) {
                if ((string) ($entry[$field] ?? '') === '') {
                    return false;
                }
            }
            foreach ((array) ($entry['leaderboard'] ?? []) as $row) {
                if (! is_array($row) || (string) ($row['strategy_family'] ?? '') === '') {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $profile
     */
    private function timeframeProfileIsGoverned(array $profile, string $interval): bool
    {
        if ($profile === []) {
            return false;
        }

        return (string) ($profile['schema_version'] ?? '') === 'atlas.finance.strategy_timeframe_profile.v1'
            && (string) ($profile['timeframe_transfer_policy'] ?? '') === 'do_not_transfer_between_timeframes_without_new_campaign'
            && (string) ($profile['knowledge_policy'] ?? '') === 'record_results_per_symbol_interval_family_and_regime'
            && (string) ($profile['interval'] ?? '') === $interval;
    }

    /**
     * @param array<string,mixed> $policy
     */
    private function timeframePolicyIsGoverned(array $policy, string $interval): bool
    {
        if ($policy === []) {
            return false;
        }

        return (string) ($policy['schema_version'] ?? '') === 'atlas.finance.strategy_timeframe_policy.v1'
            && (string) ($policy['transfer_policy'] ?? '') === 'do_not_reuse_between_timeframes_without_new_campaign'
            && (string) ($policy['policy_scope'] ?? '') === 'symbol_interval_family_campaign'
            && (string) ($policy['interval'] ?? '') === $interval
            && (int) ($policy['effective_min_trades'] ?? $policy['default_min_trades'] ?? 0) > 0
            && (int) ($policy['effective_holdout_min_trades'] ?? $policy['default_holdout_min_trades'] ?? 0) > 0
            && (int) ($policy['effective_holdout_max_reuse'] ?? $policy['default_holdout_max_reuse'] ?? 0) > 0;
    }

    /**
     * @param array<string,mixed> $registry
     */
    private function timeframePolicyPresent(array $registry): bool
    {
        $profiles = (array) ($registry['timeframe_profiles'] ?? []);
        foreach (['5m', '15m', '1d', '1mo'] as $interval) {
            if (! is_array($profiles[$interval] ?? null)) {
                return false;
            }
        }

        $deferred = array_values(array_filter((array) ($registry['deferred_timeframe_backlog'] ?? []), 'is_array'));
        $deferredIntervals = [];
        foreach ($deferred as $entry) {
            $deferredIntervals[(string) ($entry['interval'] ?? '')] = true;
            if ((string) ($entry['reason'] ?? '') !== 'registered_as_deferred_research_hypothesis_not_active_roadmap') {
                return false;
            }
        }

        return isset($deferredIntervals['5m'], $deferredIntervals['15m'], $deferredIntervals['1mo']);
    }

    /**
     * @param array<string,mixed> $featureSet
     */
    private function featureSetIsGoverned(array $featureSet): bool
    {
        if ($featureSet === []) {
            return false;
        }

        return (string) ($featureSet['schema_version'] ?? '') === 'atlas.finance.strategy_feature_set.v1'
            && (string) ($featureSet['feature_set_id'] ?? '') === StrategyFeatureSetProfile::PRICE_ONLY
            && (bool) ($featureSet['allowed_now'] ?? false) === true
            && (string) ($featureSet['lookahead_policy'] ?? '') === 'every_feature_value_must_be_available_at_or_before_the_bar_decision_time'
            && (string) ($featureSet['execution_surface'] ?? '') === 'forbidden'
            && (bool) ($featureSet['propose_only'] ?? false) === true;
    }

    /** @param array<string,mixed> $campaign */
    private function holdoutGenerationManifested(array $campaign): bool
    {
        $generation = data_get($campaign, 'data_manifest.holdout_generation');
        $maxGeneration = data_get($campaign, 'data_manifest.max_holdout_generation');
        if (! is_numeric($generation) || ! is_numeric($maxGeneration)) {
            return false;
        }

        $generation = (int) $generation;
        $maxGeneration = (int) $maxGeneration;
        if ($generation < 0 || $maxGeneration < $generation) {
            return false;
        }

        return (int) data_get($campaign, 'pre_registered_budget.holdout_generation', -1) === $generation
            && (int) data_get($campaign, 'pre_registered_budget.max_holdout_generation', -1) === $maxGeneration
            && (int) data_get($campaign, 'holdout.generation', -1) === $generation
            && (int) data_get($campaign, 'holdout.max_generation', -1) === $maxGeneration;
    }

    /**
     * @param array<string,mixed> $registry
     */
    private function featureSetPolicyPresent(array $registry): bool
    {
        $featureSets = (array) ($registry['feature_sets'] ?? []);
        $priceOnly = is_array($featureSets[StrategyFeatureSetProfile::PRICE_ONLY] ?? null)
            ? $featureSets[StrategyFeatureSetProfile::PRICE_ONLY]
            : [];
        if (! $this->featureSetIsGoverned($priceOnly)) {
            return false;
        }

        $deferred = array_values(array_filter((array) ($registry['deferred_feature_set_backlog'] ?? []), 'is_array'));
        $deferredIds = [];
        foreach ($deferred as $entry) {
            $id = (string) ($entry['feature_set_id'] ?? '');
            if ($id === '') {
                return false;
            }
            $deferredIds[$id] = true;
            if ((bool) ($entry['allowed_now'] ?? true) !== false || (bool) ($entry['ap_required_for_activation'] ?? false) !== true) {
                return false;
            }
            if (! is_int($entry['activation_priority'] ?? null) && ! ctype_digit((string) ($entry['activation_priority'] ?? ''))) {
                return false;
            }
        }
        $roadmap = array_values(array_filter((array) ($registry['feature_set_activation_roadmap'] ?? []), 'is_array'));
        $roadmapById = [];
        foreach ($roadmap as $entry) {
            $roadmapById[(string) ($entry['feature_set_id'] ?? '')] = $entry;
        }
        if ((int) data_get($roadmapById, StrategyFeatureSetProfile::PRICE_ONLY.'.activation_priority', 999) !== 0) {
            return false;
        }
        if ((string) data_get($roadmapById, 'news_sentiment_v1.activation_phase', '') !== 'late_experimental_only') {
            return false;
        }
        if ((int) data_get($roadmapById, 'ohlcv_regime_index_v1.activation_priority', 999) >= (int) data_get($roadmapById, 'derivatives_funding_oi_v1.activation_priority', 999)) {
            return false;
        }
        if ((int) data_get($roadmapById, 'derivatives_funding_oi_v1.activation_priority', 999) >= (int) data_get($roadmapById, 'news_sentiment_v1.activation_priority', 999)) {
            return false;
        }

        return isset(
            $deferredIds['ohlcv_regime_index_v1'],
            $deferredIds['cross_asset_context_v1'],
            $deferredIds['derivatives_funding_oi_v1'],
            $deferredIds['onchain_flow_v1'],
            $deferredIds['news_sentiment_v1'],
            $deferredIds['orderbook_microstructure_v1'],
        );
    }

    /**
     * @param  list<array<string,mixed>>  $terminalReports
     * @param  list<array<string,mixed>>  $evidenceRows
     */
    private function terminalReportsHaveResearchEvidence(array $terminalReports, array $evidenceRows): bool
    {
        if ($terminalReports === []) {
            return true;
        }

        foreach ($terminalReports as $report) {
            $campaignId = (string) ($report['campaign_id'] ?? '');
            $verdict = (string) ($report['verdict'] ?? '');
            if ($campaignId === '' || $verdict === '') {
                return false;
            }

            $matched = false;
            foreach ($evidenceRows as $row) {
                if ((string) ($row['campaign_id'] ?? '') !== $campaignId || (string) ($row['verdict'] ?? '') !== $verdict) {
                    continue;
                }
                if ((string) ($row['schema_version'] ?? '') !== 'atlas.finance.strategy_research_evidence.v1') {
                    return false;
                }
                $expectedKind = str_starts_with($verdict, 'NULL_')
                    ? 'negative_finding'
                    : ($verdict === 'CERTIFIED' ? 'positive_candidate' : 'inconclusive');
                if ((string) ($row['knowledge_kind'] ?? '') !== $expectedKind) {
                    return false;
                }
                $matched = true;
                break;
            }

            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string,mixed>>  $terminalReports
     * @param  array<string,mixed>  $registry
     */
    private function terminalReportsHaveScenarioRecords(array $terminalReports, array $registry): bool
    {
        if ($terminalReports === []) {
            return true;
        }

        $scenarios = (array) ($registry['scenarios'] ?? []);
        foreach ($terminalReports as $report) {
            $campaignId = (string) ($report['campaign_id'] ?? '');
            $verdict = (string) ($report['verdict'] ?? '');
            $key = (string) data_get($report, 'scenario_profile.scenario_key', '');
            if ($key === '') {
                $key = strtoupper((string) ($report['symbol'] ?? '')).'-'.(string) ($report['interval'] ?? '').'-'.(string) ($report['strategy_family'] ?? '');
            }
            $scenario = is_array($scenarios[$key] ?? null) ? $scenarios[$key] : null;
            if ($campaignId === '' || $verdict === '' || $key === '' || $scenario === null) {
                return false;
            }
            if ((string) ($scenario['latest_campaign_id'] ?? '') === $campaignId && (string) ($scenario['latest_verdict'] ?? '') === $verdict) {
                continue;
            }

            $foundInHistory = false;
            foreach ((array) ($scenario['research_history'] ?? []) as $history) {
                if (! is_array($history)) {
                    continue;
                }
                if ((string) ($history['campaign_id'] ?? '') === $campaignId && (string) ($history['verdict'] ?? '') === $verdict) {
                    $foundInHistory = true;
                    break;
                }
            }
            if (! $foundInHistory) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string,mixed>> $evidenceRows */
    private function researchEvidenceIsProposeOnly(array $evidenceRows): bool
    {
        foreach ($evidenceRows as $row) {
            if ((string) ($row['schema_version'] ?? '') !== 'atlas.finance.strategy_research_evidence.v1') {
                return false;
            }
            if ((bool) ($row['propose_only'] ?? false) !== true || (string) ($row['live_trading'] ?? '') !== 'forbidden') {
                return false;
            }
        }

        return true;
    }

    /** @return list<array<string,mixed>> */
    private function readLedgerRows(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function readTerminalReports(bool $dryRun, ?string $campaignId): array
    {
        $base = $this->campaignRootPath($dryRun);
        $paths = [];
        if ($campaignId !== null && $campaignId !== '') {
            $paths[] = rtrim($base, '/').'/'.StrategyCampaignStore::sanitizeId($campaignId).'/null-report.json';
        } else {
            $paths = glob(rtrim($base, '/').'/*/null-report.json') ?: [];
            sort($paths);
        }

        $reports = [];
        foreach ($paths as $path) {
            $report = $this->readJson($path);
            if ($report === []) {
                continue;
            }
            $report['_path'] = $path;
            $reports[] = $report;
        }

        return $reports;
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<string> */
    private function strategyProcesses(): array
    {
        $output = [];
        $exit = 1;
        @exec('pgrep -fl "strategy-(search|campaign-runner)"', $output, $exit);
        if ($exit !== 0) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $output)));
    }

    /** @param array<string,mixed> $extra */
    private function check(string $name, bool $passed, string $detail, array $extra = []): array
    {
        return [
            'name' => $name,
            'passed' => $passed,
            'detail' => $detail,
        ] + $extra;
    }

    private function scenarioRegistryPath(bool $dryRun): string
    {
        return $dryRun
            ? storage_path('framework/atlas/finance/scenario-registry.json')
            : storage_path('atlas/finance/scenario-registry.json');
    }

    private function researchEvidenceLedgerPath(bool $dryRun): string
    {
        return $dryRun
            ? storage_path('framework/atlas/finance/research-evidence-ledger.jsonl')
            : storage_path('atlas/finance/research-evidence-ledger.jsonl');
    }

    private function campaignRootPath(bool $dryRun): string
    {
        return $dryRun
            ? storage_path('framework/atlas/finance/dry-run-campaigns')
            : storage_path('atlas/finance/campaigns');
    }

    private function holdoutRegistryPath(bool $dryRun): string
    {
        return $dryRun
            ? storage_path('framework/atlas/finance/dry-run-holdouts/registry.json')
            : storage_path('atlas/finance/holdouts/registry.json');
    }
}
