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
        $registry = $this->readJson($this->scenarioRegistryPath($dryRun));
        $holdouts = $this->readJson($this->holdoutRegistryPath($dryRun));
        $ledgerRows = is_array($campaign) ? $this->readLedgerRows((string) ($campaign['_ledger_path'] ?? '')) : [];
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
            $checks[] = $this->check('campaign_costs_frozen', is_numeric(data_get($campaign, 'cost_profile.fee_bps')) && is_numeric(data_get($campaign, 'cost_profile.slippage_bps')), 'cost profile is recorded');
        }

        $checks[] = $this->check('scenario_registry_exists', $registry !== [], 'scenario registry exists');
        $checks[] = $this->check('scenario_parallelism_policy', (bool) ($registry['do_not_start_in_parallel'] ?? false) === true, 'registry forbids parallel strategy loops');
        $checks[] = $this->check('scenario_roadmap_family_scoped', $this->roadmapIsFamilyScoped((array) ($registry['sequential_roadmap'] ?? [])), 'roadmap entries include symbol, interval, and strategy family');
        $checks[] = $this->check('scenario_required_families_present', $this->familiesPresent((array) ($registry['sequential_roadmap'] ?? [])), 'roadmap includes implemented strategy families');
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
        $base = $dryRun
            ? storage_path('framework/atlas/finance/dry-run-campaigns')
            : storage_path('atlas/finance/campaigns');
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

    private function holdoutRegistryPath(bool $dryRun): string
    {
        return $dryRun
            ? storage_path('framework/atlas/finance/dry-run-holdouts/registry.json')
            : storage_path('atlas/finance/holdouts/registry.json');
    }
}
