<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Finance\StrategyLoop\Campaign\ChampionQuarantine;
use App\Services\Ai\Finance\StrategyLoop\Campaign\CandidateRediscoveryLedger;
use App\Services\Ai\Finance\StrategyLoop\Campaign\CrossCampaignRediscoveryGate;
use App\Services\Ai\Finance\StrategyLoop\Campaign\IndependentTrendBreakoutReplay;
use App\Services\Ai\Finance\StrategyLoop\Campaign\MarketRegimeAnalyzer;
use App\Services\Ai\Finance\StrategyLoop\Campaign\SecondEngineDivergenceGate;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyCampaignReporter;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyCampaignStore;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyCandidateSignature;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyParetoSelector;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyRobustnessChecks;
use App\Services\Ai\Finance\StrategyLoop\Metrics\HonestMetrics;
use App\Services\Ai\Finance\StrategyLoop\MarketDataCache;
use App\Services\Ai\Finance\StrategyLoop\Strategy\TrendBreakoutStrategy;
use App\Services\Ai\Finance\StrategyLoop\TradingHonestyGate;
use Illuminate\Console\Command;

/**
 * THE TRADING SEARCH LOOP (fast engine). Param optimization is what an optimizer does, not a
 * heavyweight LLM agent: this evaluates HUNDREDS of candidate strategies per round IN-PROCESS
 * (no provider, no subprocess), reusing the entire AUDITED honesty harness — the same frozen
 * backtest, the same Deflated-Sharpe / PBO / sealed-holdout gate.
 *
 * Each round is one honest experiment: sample K candidates (random + evolutionary mutation of
 * the best-so-far), backtest each on the scoring region, keep those clearing the sanity gate,
 * then run the honesty gate on the best with N = K (the TRUE trial count — so the DSR deflation
 * is genuinely strict; the more strategies a round tries, the higher the bar). Propose-only,
 * never-merge, no real money. A per-round honest NULL is the expected, correct outcome.
 *
 * Cross-round note: running many rounds is itself a softer multiple-testing the per-round N does
 * not fully correct — the sealed holdout (re-faced every round) is the cross-round backstop, and
 * every proposal records this honestly. A human reviews; nothing trades.
 */
final class AtlasFinanceStrategySearchCommand extends Command
{
    protected $signature = 'atlas:finance:strategy-search
        {--symbol=BTCUSDT}
        {--interval=1d}
        {--family=trend-breakout-v1 : Strategy family identifier for the research campaign}
        {--candidates=600 : Candidate strategies evaluated per round (= the DSR trial count N)}
        {--rounds=0 : Stop after this many rounds (0 = unbounded)}
        {--max-rounds=0 : Campaign pre-registered max rounds (alias for --rounds when set)}
        {--max-seconds=0 : Stop after this many seconds (0 = unbounded)}
        {--sleep=2 : Seconds between rounds}
        {--holdout-frac=0.25}
        {--confirmation-holdout-frac=0.10 : Final data slice reserved for champion quarantine only}
        {--holdout-max-reuse=1000 : Max rounds this holdout may support before it is exhausted}
        {--confirmation-holdout-max-reuse=1 : Max uses for the reserved confirmation holdout}
        {--fee-bps=10 : FROZEN per-side fee}
        {--slippage-bps=5 : FROZEN per-side slippage}
        {--min-trades=20}
        {--max-dd=0.6}
        {--campaign-id= : Research campaign id (auto-generated when omitted)}
        {--worker-id=main : Worker/island id inside the campaign}
        {--ledger= : Override campaign ledger path}
        {--seed= : Base mt_rand seed for reproducibility}
        {--islands=conservative,aggressive,robustness : Comma-separated parameter islands inside one campaign}
        {--second-engine=independent-replay : independent-replay|freqtrade|none for champion quarantine}
        {--cross-campaign-confirmations=1 : Independent prior campaign rediscoveries required before certification}
        {--no-ledger : Run without writing campaign files or ledgers (smoke-safe)}
        {--dry-run-ledger : Write campaign artifacts under storage/framework instead of the real campaign root}
        {--kill-switch=}';

    protected $description = 'Fast in-process evolutionary search for a trading strategy under the audited honesty gate (propose-only, no live trading).';

    private float $feeBps = 10.0;

    private float $slippageBps = 5.0;

    private int $minTrades = 20;

    private float $maxDd = 0.6;

    private float $ppy = 365.0;

    private string $secondEngine = 'independent-replay';

    private int $crossCampaignConfirmations = 1;

    public function handle(): int
    {
        @mkdir(storage_path('atlas/finance'), 0o755, true);
        $lockHandle = fopen(storage_path('atlas/finance/strategy-search.lock'), 'c');
        if ($lockHandle === false || ! flock($lockHandle, LOCK_EX | LOCK_NB)) {
            $this->error('another atlas:finance:strategy-search loop is already active; refusing to start a second loop');

            return self::FAILURE;
        }

        $symbol = (string) $this->option('symbol');
        $interval = (string) $this->option('interval');
        $family = (string) $this->option('family');
        $workerId = (string) $this->option('worker-id');
        $this->feeBps = max(0.0, (float) $this->option('fee-bps'));
        $this->slippageBps = max(0.0, (float) $this->option('slippage-bps'));
        $this->minTrades = max(1, (int) $this->option('min-trades'));
        $this->maxDd = (float) $this->option('max-dd');
        $this->ppy = $this->periodsPerYear($interval);
        $this->secondEngine = in_array((string) $this->option('second-engine'), ['independent-replay', 'freqtrade', 'none'], true)
            ? (string) $this->option('second-engine')
            : 'independent-replay';
        $this->crossCampaignConfirmations = max(0, (int) $this->option('cross-campaign-confirmations'));
        $k = max(10, (int) $this->option('candidates'));
        $kill = trim((string) $this->option('kill-switch')) ?: storage_path('atlas/finance/STOP');
        $maxRounds = max(max(0, (int) $this->option('rounds')), max(0, (int) $this->option('max-rounds')));
        $maxSeconds = max(0, (int) $this->option('max-seconds'));
        $sleep = max(0, (int) $this->option('sleep'));
        $seedRaw = trim((string) $this->option('seed'));
        $seed = $seedRaw !== '' ? (int) $seedRaw : random_int(1, PHP_INT_MAX);
        mt_srand($seed);
        $islands = $this->islands();

        $cache = MarketDataCache::default();
        if (! $cache->has($symbol, $interval)) {
            $this->error("market-data cache missing for {$symbol}-{$interval}");

            return self::FAILURE;
        }
        $allBars = $cache->load($symbol, $interval);
        $n = count($allBars);
        $holdoutFrac = min(0.9, max(0.0, (float) $this->option('holdout-frac')));
        $confirmationFrac = min($holdoutFrac, max(0.0, (float) $this->option('confirmation-holdout-frac')));
        $scoringEnd = (int) floor($n * (1.0 - $holdoutFrac));
        $confirmationStart = (int) floor($n * (1.0 - $confirmationFrac));
        $scoringBars = array_slice($allBars, 0, $scoringEnd);
        $holdoutBars = array_slice($allBars, $scoringEnd, max(0, $confirmationStart - $scoringEnd));
        $confirmationHoldoutBars = array_slice($allBars, $confirmationStart);
        if ($holdoutBars === []) {
            $holdoutBars = $confirmationHoldoutBars;
        }
        $campaign = StrategyCampaignStore::open([
            'campaign_id' => (string) $this->option('campaign-id'),
            'symbol' => $symbol,
            'interval' => $interval,
            'family' => $family,
            'candidates' => $k,
            'max_rounds' => $maxRounds,
            'max_seconds' => $maxSeconds,
            'seed' => $seed,
            'fee_bps' => $this->feeBps,
            'slippage_bps' => $this->slippageBps,
            'data_path' => $cache->path($symbol, $interval),
            'holdout_max_reuse' => (int) $this->option('holdout-max-reuse'),
            'confirmation_holdout_max_reuse' => (int) $this->option('confirmation-holdout-max-reuse'),
            'islands' => $islands,
            'pareto_objectives' => StrategyParetoSelector::defaultObjectives(),
            'second_engine' => $this->secondEngine,
            'cross_campaign_confirmations' => $this->crossCampaignConfirmations,
            'ledger' => (string) $this->option('ledger'),
            'no_ledger' => (bool) $this->option('no-ledger'),
            'dry_run_ledger' => (bool) $this->option('dry-run-ledger'),
        ], $scoringBars, $holdoutBars, $confirmationHoldoutBars);

        $this->info("Atlas trading SEARCH loop — {$symbol}-{$interval}, {$k} candidates/round (N), in-process.");
        $this->line('Propose-only · never-merge · no real money · DSR/PBO/holdout gate. Kill-switch: '.$kill);
        $this->line('Campaign: '.$campaign->campaignId.' · seed '.$seed.' · ledger '.($campaign->writesEnabled ? $campaign->ledgerPath : '(disabled)'));
        $this->newLine();

        $start = time();
        $existing = $this->existingLedgerState($campaign->ledgerPath);
        $round = $campaign->writesEnabled ? (int) ($existing['max_round'] ?? 0) : 0;
        $certified = 0;
        $promoted = 0;
        $bestPool = []; // elite params to mutate around (evolutionary memory)
        $bestDsr = is_numeric($existing['best_dsr'] ?? null) ? (float) $existing['best_dsr'] : null;
        $lastHoldoutStatus = StrategyCampaignStore::HOLDOUT_FRESH;

        while (true) {
            if (is_file($kill)) {
                $this->warn('kill-switch present — stopping.');
                break;
            }
            if ($maxRounds > 0 && $round >= $maxRounds) {
                break;
            }
            if ($maxSeconds > 0 && (time() - $start) >= $maxSeconds) {
                break;
            }
            $round++;
            $campaignTrials = $round * $k;
            $lastHoldoutStatus = $campaign->holdoutStatusForReuse($round);

            $confirmationHoldoutStatus = $campaign->confirmationHoldoutStatus();
            $res = $this->runRound($campaign, $symbol, $interval, $family, $scoringBars, $holdoutBars, $confirmationHoldoutBars, $k, $bestPool, $campaignTrials, $lastHoldoutStatus, $confirmationHoldoutStatus, $islands);
            $bestPool = $res['elite'];
            if ($res['certified']) {
                $certified++;
            }
            if ($res['promoted']) {
                $promoted++;
            }
            $dsr = $res['report']['deflated_sharpe'] ?? null;
            if (is_numeric($dsr) && ($bestDsr === null || $dsr > $bestDsr)) {
                $bestDsr = (float) $dsr;
            }
            $campaign->appendHoldoutEvent([
                'at' => gmdate('c'),
                'campaign_id' => $campaign->campaignId,
                'round' => $round,
                'holdout_id' => $campaign->campaign['holdout']['holdout_id'] ?? null,
                'reuse_count' => $round,
                'max_reuse' => $campaign->campaign['holdout']['max_reuse'] ?? null,
                'status' => $lastHoldoutStatus,
                'role' => 'validation',
            ]);
            if ((bool) ($res['confirmation_holdout_used'] ?? false)) {
                $campaign->appendHoldoutEvent([
                    'at' => gmdate('c'),
                    'campaign_id' => $campaign->campaignId,
                    'round' => $round,
                    'holdout_id' => $campaign->campaign['confirmation_holdout']['holdout_id'] ?? null,
                    'reuse_count' => 1,
                    'max_reuse' => $campaign->campaign['confirmation_holdout']['max_reuse'] ?? null,
                    'status' => $confirmationHoldoutStatus,
                    'role' => 'confirmation',
                ]);
            }
            $this->appendLedger($campaign, $round, $symbol, $interval, $family, $workerId, $seed, $k, $campaignTrials, $lastHoldoutStatus, $res);
            if ($res['certified']) {
                $this->persistProposal($campaign, $symbol, $interval, $family, $k, $campaignTrials, $res);
            }

            $this->line(sprintf(
                '[round %d] passed %d/%d · %s · best ann-Sharpe %s · DSR %s · %s',
                $round,
                $res['n_passed'],
                $k,
                $res['certified'] ? '<info>CERTIFIED</info>' : ($res['promoted'] ? '<comment>PROMOTED</comment>' : 'null'),
                $res['best_ann_sharpe'] !== null ? number_format($res['best_ann_sharpe'], 3) : 'n/a',
                $dsr !== null ? (string) $dsr : 'n/a',
                $res['certified'] ? 'proposal saved' : implode('; ', array_slice($res['reasons'], 0, 2)),
            ));

            if ($sleep > 0 && ! is_file($kill)) {
                sleep($sleep);
            }
        }

        if ($campaign->writesEnabled) {
            $report = (new StrategyCampaignReporter)->summarizeLedger($campaign->ledgerPath, [
                'campaign_id' => $campaign->campaignId,
                'symbol' => $symbol,
                'interval' => $interval,
                'strategy_family' => $family,
                'candidates_per_round' => $k,
                'holdout_reuse_count' => $round,
                'holdout_status' => $lastHoldoutStatus,
            ]);
            $campaign->writeNullReport($report);
        }

        $this->newLine();
        $this->components->twoColumnDetail('Rounds run', (string) $round);
        $this->components->twoColumnDetail('Promoted to quarantine', (string) $promoted);
        $this->components->twoColumnDetail('Certified proposals', (string) $certified);
        $this->components->twoColumnDetail('Best deflated Sharpe seen', $bestDsr !== null ? (string) $bestDsr : 'none');
        $this->components->twoColumnDetail('Campaign', $campaign->campaignId);
        $this->components->twoColumnDetail('Ledger', $campaign->writesEnabled ? $campaign->ledgerPath : '(disabled)');

        return self::SUCCESS;
    }

    /**
     * One honest experiment: K candidates, sanity-filter, then the honesty gate on the best.
     *
     * @param  list<\App\Services\Ai\Finance\StrategyLoop\Bar>  $scoringBars
     * @param  list<\App\Services\Ai\Finance\StrategyLoop\Bar>  $holdoutBars
     * @param  list<array<string,mixed>>  $elite
     * @return array<string,mixed>
     */
    private function runRound(StrategyCampaignStore $campaign, string $symbol, string $interval, string $family, array $scoringBars, array $holdoutBars, array $confirmationHoldoutBars, int $k, array $elite, int $campaignTrials, string $holdoutStatus, string $confirmationHoldoutStatus, array $islands): array
    {
        $strategy = new TrendBreakoutStrategy;
        $metrics = new HonestMetrics;
        $passing = [];

        for ($i = 0; $i < $k; $i++) {
            $island = $islands[$i % count($islands)] ?? 'robustness';
            $islandElite = array_values(array_filter($elite, static fn (array $e): bool => (string) ($e['island'] ?? '') === $island));
            $baseElite = $islandElite !== [] ? $islandElite : $elite;
            $params = ($baseElite !== [] && $i % 5 !== 0)
                ? $this->mutate($baseElite[$i % count($baseElite)]['params'] ?? $baseElite[$i % count($baseElite)], $island)
                : $this->randomParams($island);
            $scored = $this->scoreScoring($strategy, $metrics, $scoringBars, $params);
            if ($scored !== null) {
                $scored['island'] = $island;
                $passing[] = $scored;
            }
        }

        if ($passing === []) {
            return $this->roundResult(0, $k, null, false, false, 'rejected_honest_null', ['no_candidate_passed_sanity_gate'], [], []);
        }

        // Internal selection is Pareto; final certification remains the hard honesty gate.
        $selector = new StrategyParetoSelector;
        $paretoElite = $selector->selectElite($passing, 6);
        $winner = $paretoElite[0] ?? $passing[0];
        $newElite = array_map(static fn (array $p): array => ['params' => $p['params'], 'island' => $p['island'] ?? 'unknown'], $paretoElite);

        $holdout = $this->scoreRegion($strategy, $metrics, $holdoutBars, $winner['params']);

        $gate = new TradingHonestyGate;
        $roundVerdict = $gate->evaluate([
            'winner_daily_returns' => $winner['daily_returns'],
            'sibling_windows' => array_map(static fn (array $p): array => $p['windows'], $passing),
            'sibling_sharpes' => array_map(static fn (array $p): float => $p['pp_sharpe'], $passing), // per-period
            'scenarios_explored' => $k, // the TRUE trial count this round
            'holdout_sharpe' => $holdout['ann_sharpe'],
            'holdout_trades' => $holdout['n_trades'],
        ]);
        $campaignVerdict = $roundVerdict;
        $quarantine = null;
        $certified = false;
        $promoted = false;
        $status = 'rejected_honest_null';
        $reasons = $roundVerdict['reasons'];

        if ((bool) $roundVerdict['certified']) {
            $campaignVerdict = $gate->evaluate([
                'winner_daily_returns' => $winner['daily_returns'],
                'sibling_windows' => array_map(static fn (array $p): array => $p['windows'], $passing),
                'sibling_sharpes' => array_map(static fn (array $p): float => $p['pp_sharpe'], $passing),
                'scenarios_explored' => max($k, $campaignTrials), // cumulative campaign penalty
                'holdout_sharpe' => $holdout['ann_sharpe'],
                'holdout_trades' => $holdout['n_trades'],
            ]);
            $confirmationHoldout = $this->scoreRegion($strategy, $metrics, $confirmationHoldoutBars, $winner['params']);
            $quarantine = $this->quarantineChampion($campaign, $symbol, $interval, $family, $strategy, $metrics, $scoringBars, $confirmationHoldoutBars, $winner, $confirmationHoldout, $roundVerdict, $campaignVerdict, $confirmationHoldoutStatus);
            $certified = (bool) ($quarantine['certified'] ?? false);
            $promoted = (bool) ($quarantine['promoted'] ?? false);
            $status = (string) ($quarantine['status'] ?? 'promoted_pending_quarantine');
            $reasons = $quarantine['reasons'] ?? ['promoted_pending_quarantine'];
        }

        return $this->roundResult(
            count($passing),
            $k,
            $winner,
            $certified,
            $promoted,
            $status,
            $reasons,
            $roundVerdict['report'],
            $newElite,
            $holdout,
            $roundVerdict,
            $campaignVerdict,
            $quarantine,
            $confirmationHoldout ?? [],
        );
    }

    /**
     * @param  array<string,mixed>|null  $winner
     * @param  list<string>  $reasons
     * @param  array<string,mixed>  $report
     * @param  list<array<string,mixed>>  $elite
     * @param  array<string,mixed>  $holdout
     * @return array<string,mixed>
     */
    private function roundResult(int $nPassed, int $k, ?array $winner, bool $certified, bool $promoted, string $status, array $reasons, array $report, array $elite, array $holdout = [], array $roundVerdict = [], array $campaignVerdict = [], ?array $quarantine = null, array $confirmationHoldout = []): array
    {
        return [
            'n_passed' => $nPassed,
            'candidates' => $k,
            'certified' => $certified,
            'promoted' => $promoted,
            'status' => $status,
            'reasons' => $reasons === [] ? ['certified'] : $reasons,
            'report' => $report,
            'best_ann_sharpe' => $winner['ann_sharpe'] ?? null,
            'winner_island' => $winner['island'] ?? null,
            'winner_params' => $winner['params'] ?? null,
            'holdout' => $holdout,
            'confirmation_holdout' => $confirmationHoldout,
            'confirmation_holdout_used' => $confirmationHoldout !== [],
            'elite' => $elite,
            'round_verdict' => $roundVerdict,
            'campaign_verdict' => $campaignVerdict,
            'quarantine' => $quarantine,
        ];
    }

    /**
     * Score a candidate on the SCORING region; return null if it fails the sanity gate.
     *
     * @param  list<\App\Services\Ai\Finance\StrategyLoop\Bar>  $bars
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>|null
     */
    private function scoreScoring(TrendBreakoutStrategy $strategy, HonestMetrics $metrics, array $bars, array $params): ?array
    {
        $m = $this->scoreRegion($strategy, $metrics, $bars, $params);
        if ($m['n_trades'] < $this->minTrades || $m['max_dd'] > $this->maxDd || ! is_finite($m['ann_sharpe'])) {
            return null;
        }

        return [
            'params' => $params,
            'pp_sharpe' => $m['pp_sharpe'],
            'ann_sharpe' => $m['ann_sharpe'],
            'max_dd' => $m['max_dd'],
            'n_trades' => $m['n_trades'],
            'windows' => $m['windows'],
            'daily_returns' => $m['daily_returns'],
            'stability_score' => $this->stabilityScore($m['windows']),
            'robustness_score' => $this->stabilityScore($m['windows']) - $m['max_dd'],
        ];
    }

    /**
     * @param  list<\App\Services\Ai\Finance\StrategyLoop\Bar>  $bars
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function scoreRegion(TrendBreakoutStrategy $strategy, HonestMetrics $metrics, array $bars, array $params): array
    {
        $params['fee_bps'] = $this->feeBps;        // FROZEN costs — never from the candidate
        $params['slippage_bps'] = $this->slippageBps;
        $r = $strategy->run($bars, $params);

        return [
            'pp_sharpe' => $metrics->perPeriodSharpe($r->dailyReturns),
            'ann_sharpe' => $metrics->sharpe($r->dailyReturns, $this->ppy),
            'max_dd' => $metrics->maxDrawdown($r->equityCurve),
            'n_trades' => $r->nTrades,
            'windows' => $this->windowReturns($r->dailyReturns, 16),
            'daily_returns' => $r->dailyReturns,
            'regime_metrics' => (new MarketRegimeAnalyzer)->summarize($bars, $r->dailyReturns, $this->ppy),
        ];
    }

    /**
     * A round-level pass is only a promotion. This quarantine fails closed unless the
     * champion survives campaign-level penalties, fresh holdout policy, cost stress,
     * neighborhood robustness, and an independent-engine report.
     *
     * @param  list<\App\Services\Ai\Finance\StrategyLoop\Bar>  $scoringBars
     * @param  list<\App\Services\Ai\Finance\StrategyLoop\Bar>  $holdoutBars
     * @param  array<string,mixed>  $winner
     * @param  array<string,mixed>  $holdout
     * @param  array<string,mixed>  $roundVerdict
     * @param  array<string,mixed>  $campaignVerdict
     * @return array<string,mixed>
     */
    private function quarantineChampion(StrategyCampaignStore $campaign, string $symbol, string $interval, string $family, TrendBreakoutStrategy $strategy, HonestMetrics $metrics, array $scoringBars, array $holdoutBars, array $winner, array $holdout, array $roundVerdict, array $campaignVerdict, string $holdoutStatus): array
    {
        $checks = new StrategyRobustnessChecks;
        $oldFee = $this->feeBps;
        $oldSlip = $this->slippageBps;
        $this->feeBps = $oldFee * 2.0;
        $this->slippageBps = $oldSlip * 2.0;
        try {
            $stressedHoldout = $this->scoreRegion($strategy, $metrics, $holdoutBars, $winner['params']);
        } finally {
            $this->feeBps = $oldFee;
            $this->slippageBps = $oldSlip;
        }

        $neighbors = [];
        for ($i = 0; $i < 8; $i++) {
            $neighbors[] = $this->scoreScoring($strategy, $metrics, $scoringBars, $this->mutate($winner['params']));
        }

        $primaryEngine = [
            'engine' => 'atlas_php',
            'trade_count' => $holdout['n_trades'] ?? 0,
            'ann_sharpe' => $holdout['ann_sharpe'] ?? null,
            'max_dd' => $holdout['max_dd'] ?? null,
        ];
        $secondaryEngine = match ($this->secondEngine) {
            'independent-replay' => (new IndependentTrendBreakoutReplay)->evaluate($holdoutBars, [
                ...$winner['params'],
                'fee_bps' => $this->feeBps,
                'slippage_bps' => $this->slippageBps,
            ], $this->ppy),
            'freqtrade' => ['status' => 'unavailable', 'engine' => 'freqtrade', 'reason' => 'freqtrade_backend_not_configured'],
            default => null,
        };
        $dryRun = ! $campaign->writesEnabled || str_contains($campaign->directory, '/framework/');
        $signature = (new StrategyCandidateSignature)->make($symbol, $interval, $family, $winner['params']);
        $rediscoveryLedger = CandidateRediscoveryLedger::default($dryRun);
        $crossCampaign = (new CrossCampaignRediscoveryGate)->evaluate($rediscoveryLedger, $signature, $campaign->campaignId, $this->crossCampaignConfirmations);
        if ($campaign->writesEnabled) {
            $rediscoveryLedger->record([
                'campaign_id' => $campaign->campaignId,
                'symbol' => $symbol,
                'interval' => $interval,
                'strategy_family' => $family,
                'campaign_trials' => $campaignVerdict['report']['n_trials'] ?? null,
                'status' => 'promoted_to_quarantine',
                'signature' => $signature,
                'deflated_sharpe' => $roundVerdict['report']['deflated_sharpe'] ?? null,
                'campaign_deflated_sharpe' => $campaignVerdict['report']['deflated_sharpe'] ?? null,
                'holdout_sharpe' => $holdout['ann_sharpe'] ?? null,
                'holdout_trades' => $holdout['n_trades'] ?? null,
            ]);
        }

        return (new ChampionQuarantine)->evaluate([
            'round_verdict' => $roundVerdict,
            'campaign_verdict' => $campaignVerdict,
            'holdout_status' => $holdoutStatus,
            'fresh_holdout' => $holdout,
            'cost_stress' => $checks->costStress($holdout, $stressedHoldout, 0.0, $this->maxDd),
            'neighborhood' => $checks->neighborhood($neighbors, 3, 0.0),
            'second_engine' => (new SecondEngineDivergenceGate)->evaluate($primaryEngine, $secondaryEngine),
            'cross_campaign' => $crossCampaign,
        ]);
    }

    /** @return array<string,float|int> a random point in the strategy search space */
    private function randomParams(string $island = 'robustness'): array
    {
        $params = [
            'regime_period' => $this->chance(0.30) ? 0 : $this->randInt(20, 300),
            'entry_lookback' => $this->randInt(5, 100),
            'exit_lookback' => $this->chance(0.30) ? 0 : $this->randInt(5, 100),
            'atr_period' => $this->randInt(5, 50),
            'atr_mult' => $this->randFloat(1.0, 8.0),
            'risk_pct' => $this->randFloat(0.05, 0.5),
            'min_hold_bars' => $this->randInt(0, 20),
        ];

        return $this->shapeParamsForIsland($params, $island);
    }

    /**
     * @param  array<string,mixed>  $base
     * @return array<string,float|int>
     */
    private function mutate(array $base, string $island = 'robustness'): array
    {
        $params = [
            'regime_period' => $this->chance(0.15) ? ($this->chance(0.5) ? 0 : $this->randInt(20, 300)) : $this->jitterInt((int) ($base['regime_period'] ?? 100), 0, 300, 30),
            'entry_lookback' => $this->jitterInt((int) ($base['entry_lookback'] ?? 20), 5, 100, 10),
            'exit_lookback' => $this->chance(0.15) ? 0 : $this->jitterInt((int) ($base['exit_lookback'] ?? 10), 5, 100, 10),
            'atr_period' => $this->jitterInt((int) ($base['atr_period'] ?? 14), 5, 50, 6),
            'atr_mult' => $this->jitterFloat((float) ($base['atr_mult'] ?? 3.0), 1.0, 8.0, 1.0),
            'risk_pct' => $this->jitterFloat((float) ($base['risk_pct'] ?? 0.2), 0.05, 0.5, 0.08),
            'min_hold_bars' => $this->jitterInt((int) ($base['min_hold_bars'] ?? 3), 0, 20, 4),
        ];

        return $this->shapeParamsForIsland($params, $island);
    }

    /** @return list<string> */
    private function islands(): array
    {
        $raw = array_filter(array_map('trim', explode(',', (string) $this->option('islands'))));
        $allowed = ['conservative', 'aggressive', 'robustness'];
        $islands = array_values(array_filter($raw, static fn (string $island): bool => in_array($island, $allowed, true)));

        return $islands !== [] ? $islands : $allowed;
    }

    /**
     * @param  array<string,float|int>  $params
     * @return array<string,float|int>
     */
    private function shapeParamsForIsland(array $params, string $island): array
    {
        if ($island === 'conservative') {
            $params['entry_lookback'] = max(35, (int) $params['entry_lookback']);
            $params['exit_lookback'] = max(20, (int) $params['exit_lookback']);
            $params['atr_mult'] = max(2.5, (float) $params['atr_mult']);
            $params['risk_pct'] = min(0.18, (float) $params['risk_pct']);
            $params['min_hold_bars'] = max(5, (int) $params['min_hold_bars']);
        } elseif ($island === 'aggressive') {
            $params['entry_lookback'] = min(35, (int) $params['entry_lookback']);
            $params['exit_lookback'] = $this->chance(0.25) ? 0 : min(35, (int) $params['exit_lookback']);
            $params['atr_mult'] = min(4.5, (float) $params['atr_mult']);
            $params['risk_pct'] = max(0.12, min(0.5, (float) $params['risk_pct']));
            $params['min_hold_bars'] = min(8, (int) $params['min_hold_bars']);
        } else {
            $params['risk_pct'] = min(0.28, max(0.08, (float) $params['risk_pct']));
            $params['atr_mult'] = min(6.0, max(1.5, (float) $params['atr_mult']));
        }

        return $params;
    }

    private function chance(float $p): bool
    {
        return mt_rand(0, 1_000_000) / 1_000_000.0 < $p;
    }

    private function randInt(int $lo, int $hi): int
    {
        return mt_rand($lo, $hi);
    }

    private function randFloat(float $lo, float $hi): float
    {
        return $lo + ($hi - $lo) * (mt_rand(0, 1_000_000) / 1_000_000.0);
    }

    private function jitterInt(int $v, int $lo, int $hi, int $span): int
    {
        return max($lo, min($hi, $v + mt_rand(-$span, $span)));
    }

    private function jitterFloat(float $v, float $lo, float $hi, float $span): float
    {
        return max($lo, min($hi, $v + $this->randFloat(-$span, $span)));
    }

    /**
     * @param  list<float>  $returns
     * @return list<float>
     */
    private function windowReturns(array $returns, int $k): array
    {
        $n = count($returns);
        if ($n === 0) {
            return array_fill(0, $k, 0.0);
        }
        $out = [];
        for ($w = 0; $w < $k; $w++) {
            $lo = (int) floor($n * $w / $k);
            $hi = (int) floor($n * ($w + 1) / $k);
            $slice = array_slice($returns, $lo, max(0, $hi - $lo));
            $out[] = $slice === [] ? 0.0 : array_sum($slice) / count($slice);
        }

        return $out;
    }

    /** @param list<float> $windows */
    private function stabilityScore(array $windows): float
    {
        if ($windows === []) {
            return 0.0;
        }
        $mean = array_sum($windows) / count($windows);
        $variance = 0.0;
        $positive = 0;
        foreach ($windows as $v) {
            $variance += ($v - $mean) ** 2;
            if ($v > 0.0) {
                $positive++;
            }
        }
        $std = sqrt($variance / max(1, count($windows)));
        $positiveShare = $positive / count($windows);

        return round($positiveShare + ($mean / ($std + abs($mean) + 1e-9)), 6);
    }

    /** @param array<string,mixed> $res */
    private function appendLedger(StrategyCampaignStore $campaign, int $round, string $symbol, string $interval, string $family, string $workerId, int $seed, int $k, int $campaignTrials, string $holdoutStatus, array $res): void
    {
        $campaign->appendLedger([
            'schema_version' => 'atlas.finance.strategy_search_round.v2',
            'campaign_id' => $campaign->campaignId,
            'worker_id' => $workerId,
            'pid' => getmypid(),
            'round' => $round,
            'at' => gmdate('c'),
            'symbol' => $symbol,
            'interval' => $interval,
            'strategy_family' => $family,
            'seed' => $seed,
            'candidates' => $k,
            'campaign_trials' => $campaignTrials,
            'winner_island' => $res['winner_island'] ?? null,
            'n_passed' => $res['n_passed'],
            'certified' => $res['certified'],
            'promoted' => $res['promoted'],
            'status' => $res['status'],
            'best_ann_sharpe' => $res['best_ann_sharpe'],
            'deflated_sharpe' => $res['report']['deflated_sharpe'] ?? null,
            'campaign_deflated_sharpe' => $res['campaign_verdict']['report']['deflated_sharpe'] ?? null,
            'pbo' => $res['report']['pbo'] ?? null,
            'holdout_sharpe' => $res['holdout']['ann_sharpe'] ?? null,
            'holdout_regime_metrics' => $res['holdout']['regime_metrics'] ?? null,
            'holdout_id' => $campaign->campaign['holdout']['holdout_id'] ?? null,
            'holdout_status' => $holdoutStatus,
            'holdout_reuse_count' => $round,
            'confirmation_holdout_id' => $campaign->campaign['confirmation_holdout']['holdout_id'] ?? null,
            'confirmation_holdout_status' => $campaign->confirmationHoldoutStatus(),
            'confirmation_holdout_used' => (bool) ($res['confirmation_holdout_used'] ?? false),
            'confirmation_holdout_sharpe' => $res['confirmation_holdout']['ann_sharpe'] ?? null,
            'confirmation_holdout_trades' => $res['confirmation_holdout']['n_trades'] ?? null,
            'confirmation_holdout_regime_metrics' => $res['confirmation_holdout']['regime_metrics'] ?? null,
            'cost_profile_hash' => $campaign->campaign['cost_profile']['cost_profile_hash'] ?? null,
            'data_sha' => $campaign->campaign['data_manifest']['sha256'] ?? null,
            'reasons' => $res['reasons'],
            'quarantine' => $res['quarantine'],
            'merged_to_main' => false,
        ]);
    }

    /**
     * @return array{max_round:int,best_dsr:float|null}
     */
    private function existingLedgerState(string $ledgerPath): array
    {
        if (! is_file($ledgerPath)) {
            return ['max_round' => 0, 'best_dsr' => null];
        }
        $maxRound = 0;
        $bestDsr = null;
        foreach (file($ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (! is_array($row)) {
                continue;
            }
            $round = (int) ($row['round'] ?? 0);
            $maxRound = max($maxRound, $round);
            $dsr = $row['deflated_sharpe'] ?? null;
            if (is_numeric($dsr) && ($bestDsr === null || (float) $dsr > $bestDsr)) {
                $bestDsr = (float) $dsr;
            }
        }

        return ['max_round' => $maxRound, 'best_dsr' => $bestDsr];
    }

    /** @param array<string,mixed> $res */
    private function persistProposal(StrategyCampaignStore $campaign, string $symbol, string $interval, string $family, int $k, int $campaignTrials, array $res): void
    {
        $payload = [
            'created_at' => gmdate('c'),
            'flow' => 'finance.strategy_evolution',
            'engine' => 'in_process_search',
            'classification' => 'sensitive',
            'campaign_id' => $campaign->campaignId,
            'symbol' => $symbol,
            'interval' => $interval,
            'strategy_family' => $family,
            'scenarios_explored' => $k,
            'campaign_trials' => $campaignTrials,
            'certified' => true,
            'honesty_report' => $res['report'],
            'campaign_verdict' => $res['campaign_verdict'],
            'quarantine' => $res['quarantine'],
            'winner_island' => $res['winner_island'] ?? null,
            'winner_strategy' => $res['winner_params'],
            'holdout' => $res['holdout'],
            'confirmation_holdout' => $res['confirmation_holdout'],
            'status' => 'certified_for_review',
            'merged_to_main' => false,
            'live_trading' => 'forbidden',
            'review_only' => true,
        ];
        $campaign->writeProposal($payload);
    }

    private function periodsPerYear(string $interval): float
    {
        return match ($interval) {
            '1h' => 24 * 365,
            '4h' => 6 * 365,
            '1w' => 52,
            default => 365,
        };
    }
}
