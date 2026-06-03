<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Finance\StrategyLoop\Campaign\ChampionQuarantine;
use App\Services\Ai\Finance\StrategyLoop\Campaign\CandidateRediscoveryLedger;
use App\Services\Ai\Finance\StrategyLoop\Campaign\CrossCampaignRediscoveryGate;
use App\Services\Ai\Finance\StrategyLoop\Campaign\ExternalPythonTrendBreakoutReplay;
use App\Services\Ai\Finance\StrategyLoop\Campaign\FreqtradeSecondEngineAdapter;
use App\Services\Ai\Finance\StrategyLoop\Campaign\IndependentTrendBreakoutReplay;
use App\Services\Ai\Finance\StrategyLoop\Campaign\MarketRegimeAnalyzer;
use App\Services\Ai\Finance\StrategyLoop\Campaign\SecondEngineDivergenceGate;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyCampaignReporter;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyCampaignStore;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyCandidateSignature;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyConfirmationQueue;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyFeatureSetProfile;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyParetoSelector;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyRobustnessChecks;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyTimeframeProfile;
use App\Services\Ai\Finance\StrategyLoop\Metrics\HonestMetrics;
use App\Services\Ai\Finance\StrategyLoop\MarketDataCache;
use App\Services\Ai\Finance\StrategyLoop\Strategy\MeanReversionStrategy;
use App\Services\Ai\Finance\StrategyLoop\Strategy\MomentumStrategy;
use App\Services\Ai\Finance\StrategyLoop\Strategy\StrategyRunner;
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
    private const SUPPORTED_FAMILIES = ['trend-breakout-v1', 'mean-reversion-v1', 'momentum-v1'];

    protected $signature = 'atlas:finance:strategy-search
        {--symbol=BTCUSDT}
        {--interval=1d}
        {--family=trend-breakout-v1 : Strategy family identifier for the research campaign}
        {--candidates=600 : Candidate strategies evaluated per round (= the DSR trial count N)}
        {--rounds=0 : Stop this invocation after this many additional rounds (0 = until campaign budget/STOP)}
        {--max-rounds=0 : Campaign pre-registered max rounds (0 = use holdout reuse budget)}
        {--max-seconds=0 : Stop after this many seconds (0 = unbounded)}
        {--sleep=2 : Seconds between rounds}
        {--holdout-frac=0.25}
        {--confirmation-holdout-frac=0.10 : Final data slice reserved for champion quarantine only}
        {--holdout-generation=0 : Validation holdout generation; 0 is the latest validation slice before confirmation, higher values walk backward}
        {--holdout-max-reuse=1000 : Max rounds this holdout may support before it is exhausted}
        {--confirmation-holdout-max-reuse=1 : Max uses for the reserved confirmation holdout}
        {--fee-bps=10 : FROZEN per-side fee}
        {--slippage-bps=5 : FROZEN per-side slippage}
        {--min-trades=20}
        {--holdout-min-trades= : Holdout trade floor (default comes from timeframe policy)}
        {--max-dd=0.6}
        {--allow-deferred-timeframe : Explicitly allow a deferred timeframe after external controls are in place}
        {--feature-set=price_only_v1 : Governed feature set; only price_only_v1 is active until future indices get contracts}
        {--campaign-id= : Research campaign id (auto-generated when omitted)}
        {--worker-id=main : Worker/island id inside the campaign}
        {--ledger= : Override campaign ledger path}
        {--seed= : Base mt_rand seed for reproducibility}
        {--islands=conservative,aggressive,robustness : Comma-separated parameter islands inside one campaign}
        {--second-engine=python-replay : python-replay|independent-replay|freqtrade|none for champion quarantine}
        {--freqtrade-report= : Pinned freqtrade holdout JSON report for --second-engine=freqtrade}
        {--cross-campaign-confirmations=1 : Independent prior campaign rediscoveries required before certification}
        {--no-ledger : Run without writing campaign files or ledgers (smoke-safe)}
        {--dry-run-ledger : Write campaign artifacts under storage/framework instead of the real campaign root}
        {--kill-switch=}';

    protected $description = 'Fast in-process evolutionary search for a trading strategy under the audited honesty gate (propose-only, no live trading).';

    private float $feeBps = 10.0;

    private float $slippageBps = 5.0;

    private int $minTrades = 20;

    private int $holdoutMinTrades = 10;

    private float $maxDd = 0.6;

    private float $ppy = 365.0;

    private float $costStressMultiplier = 2.0;

    private string $secondEngine = 'python-replay';

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
        $featureSet = (new StrategyFeatureSetProfile)->describe((string) $this->option('feature-set'));
        if (! (bool) ($featureSet['allowed_now'] ?? false)) {
            $this->error('feature set '.(string) ($featureSet['feature_set_id'] ?? 'unknown').' is not active; future indices require governed feature contracts first: '.implode(', ', (array) ($featureSet['activation_requirements'] ?? [])));

            return self::FAILURE;
        }
        $timeframeProfiler = new StrategyTimeframeProfile;
        $timeframePolicy = $timeframeProfiler->campaignPolicy($interval);
        $this->costStressMultiplier = max(2.0, (float) ($timeframePolicy['cost_stress_multiplier'] ?? 2.0));
        if ((bool) ($timeframePolicy['requires_explicit_activation'] ?? false) && ! (bool) $this->option('allow-deferred-timeframe')) {
            $this->error('timeframe '.$interval.' is deferred by policy; pass --allow-deferred-timeframe only after controls are in place: '.implode(', ', (array) ($timeframePolicy['activation_requirements'] ?? [])));

            return self::FAILURE;
        }
        $family = (string) $this->option('family');
        if (! in_array($family, self::SUPPORTED_FAMILIES, true)) {
            $this->error('unsupported strategy family '.$family.'; implemented families: '.implode(', ', self::SUPPORTED_FAMILIES));

            return self::FAILURE;
        }
        $workerId = (string) $this->option('worker-id');
        $this->feeBps = max(0.0, (float) $this->option('fee-bps'));
        $this->slippageBps = max(0.0, (float) $this->option('slippage-bps'));
        $this->minTrades = max(1, $this->optionWasProvided('min-trades')
            ? (int) $this->option('min-trades')
            : (int) ($timeframePolicy['default_min_trades'] ?? 20));
        $holdoutMinTradesRaw = trim((string) $this->option('holdout-min-trades'));
        $this->holdoutMinTrades = max(1, $holdoutMinTradesRaw !== ''
            ? (int) $holdoutMinTradesRaw
            : (int) ($timeframePolicy['default_holdout_min_trades'] ?? 10));
        $this->maxDd = (float) $this->option('max-dd');
        $this->ppy = $this->periodsPerYear($interval);
        $this->secondEngine = in_array((string) $this->option('second-engine'), ['python-replay', 'independent-replay', 'freqtrade', 'none'], true)
            ? (string) $this->option('second-engine')
            : 'python-replay';
        if ($this->secondEngine === 'none' && ! (bool) $this->option('no-ledger') && ! (bool) $this->option('dry-run-ledger')) {
            $this->error('--second-engine=none is smoke-only; governed campaigns require python-replay, independent-replay, or freqtrade');

            return self::FAILURE;
        }
        $this->crossCampaignConfirmations = max(0, (int) $this->option('cross-campaign-confirmations'));
        $k = max(10, (int) $this->option('candidates'));
        $kill = trim((string) $this->option('kill-switch')) ?: storage_path('atlas/finance/STOP');
        $holdoutMaxReuse = max(1, $this->optionWasProvided('holdout-max-reuse')
            ? (int) $this->option('holdout-max-reuse')
            : (int) ($timeframePolicy['default_holdout_max_reuse'] ?? 1000));
        $runRoundLimit = max(0, (int) $this->option('rounds'));
        $requestedMaxRounds = max(0, (int) $this->option('max-rounds'));
        $maxRounds = $requestedMaxRounds > 0 ? min($requestedMaxRounds, $holdoutMaxReuse) : $holdoutMaxReuse;
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
        $holdoutFrac = (float) $this->option('holdout-frac');
        $confirmationFrac = (float) $this->option('confirmation-holdout-frac');
        $holdoutGeneration = max(0, (int) $this->option('holdout-generation'));
        if ($holdoutFrac <= 0.0 || $holdoutFrac >= 0.9) {
            $this->error('--holdout-frac must be > 0 and < 0.9 for a governed campaign');

            return self::FAILURE;
        }
        if ($confirmationFrac <= 0.0 || $confirmationFrac >= $holdoutFrac) {
            $this->error('--confirmation-holdout-frac must be > 0 and strictly smaller than --holdout-frac');

            return self::FAILURE;
        }
        $split = $this->campaignSplit($allBars, $holdoutFrac, $confirmationFrac, $holdoutGeneration);
        $scoringBars = $split['scoring_bars'];
        $holdoutBars = $split['holdout_bars'];
        $confirmationHoldoutBars = $split['confirmation_holdout_bars'];
        if ($scoringBars === [] || $holdoutBars === [] || $confirmationHoldoutBars === []) {
            $this->error('market-data split is degenerate for holdout generation '.$holdoutGeneration.'; scoring, validation holdout, and confirmation holdout must all contain bars');

            return self::FAILURE;
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
            'holdout_generation' => $holdoutGeneration,
            'max_holdout_generation' => (int) $split['max_holdout_generation'],
            'split_policy' => $split['split_policy'],
            'holdout_max_reuse' => $holdoutMaxReuse,
            'confirmation_holdout_max_reuse' => (int) $this->option('confirmation-holdout-max-reuse'),
            'min_trades' => $this->minTrades,
            'holdout_min_trades' => $this->holdoutMinTrades,
            'cost_stress_multiplier' => $this->costStressMultiplier,
            'timeframe_policy' => [
                ...$timeframePolicy,
                'effective_min_trades' => $this->minTrades,
                'effective_holdout_min_trades' => $this->holdoutMinTrades,
                'effective_holdout_max_reuse' => $holdoutMaxReuse,
                'effective_source' => [
                    'min_trades' => $this->optionWasProvided('min-trades') ? 'operator_override' : 'timeframe_policy',
                    'holdout_min_trades' => $holdoutMinTradesRaw !== '' ? 'operator_override' : 'timeframe_policy',
                    'holdout_max_reuse' => $this->optionWasProvided('holdout-max-reuse') ? 'operator_override' : 'timeframe_policy',
                    'cost_stress_multiplier' => 'timeframe_policy',
                ],
            ],
            'feature_set' => $featureSet,
            'islands' => $islands,
            'pareto_objectives' => StrategyParetoSelector::defaultObjectives(),
            'second_engine' => $this->secondEngine,
            'freqtrade_report' => (string) $this->option('freqtrade-report'),
            'cross_campaign_confirmations' => $this->crossCampaignConfirmations,
            'ledger' => (string) $this->option('ledger'),
            'no_ledger' => (bool) $this->option('no-ledger'),
            'dry_run_ledger' => (bool) $this->option('dry-run-ledger'),
        ], $scoringBars, $holdoutBars, $confirmationHoldoutBars);
        if ($campaign->isTerminal()) {
            $this->error('campaign '.$campaign->campaignId.' already has terminal verdict '.(string) ($campaign->campaign['verdict'] ?? $campaign->campaign['status'] ?? 'unknown').'; refusing to reopen it');

            return self::FAILURE;
        }

        $this->info("Atlas trading SEARCH loop — {$symbol}-{$interval}, {$k} candidates/round (N), in-process.");
        $this->line('Propose-only · never-merge · no real money · DSR/PBO/holdout gate. Kill-switch: '.$kill);
        $this->line('Campaign: '.$campaign->campaignId.' · seed '.$seed.' · ledger '.($campaign->writesEnabled ? $campaign->ledgerPath : '(disabled)'));
        $this->line('Validation holdout generation: '.$holdoutGeneration.' · policy '.$split['split_policy']);
        $this->newLine();

        $start = time();
        $existing = $this->existingLedgerState($campaign->ledgerPath);
        $round = $campaign->writesEnabled ? (int) ($existing['max_round'] ?? 0) : 0;
        $certified = 0;
        $promoted = 0;
        $bestPool = $campaign->writesEnabled ? (array) ($existing['elite'] ?? []) : []; // elite params to mutate around (evolutionary memory)
        $bestDsr = is_numeric($existing['best_dsr'] ?? null) ? (float) $existing['best_dsr'] : null;
        $lastHoldoutStatus = StrategyCampaignStore::HOLDOUT_FRESH;
        $roundsThisRun = 0;
        $stopReason = 'unknown';

        while (true) {
            if (is_file($kill)) {
                $this->warn('kill-switch present — stopping.');
                $stopReason = 'kill_switch';
                break;
            }
            if ($maxRounds > 0 && $round >= $maxRounds) {
                if ($round >= $holdoutMaxReuse) {
                    $lastHoldoutStatus = StrategyCampaignStore::HOLDOUT_EXHAUSTED;
                    $stopReason = 'holdout_exhausted';
                } else {
                    $stopReason = 'campaign_round_budget_complete';
                }
                break;
            }
            if ($runRoundLimit > 0 && $roundsThisRun >= $runRoundLimit) {
                $stopReason = 'invocation_round_limit';
                break;
            }
            if ($maxSeconds > 0 && (time() - $start) >= $maxSeconds) {
                $stopReason = 'time_budget_complete';
                break;
            }
            $round++;
            $roundsThisRun++;
            $campaignTrials = $round * $k;
            $lastHoldoutStatus = $campaign->holdoutStatusForReuse($round);
            if ($lastHoldoutStatus === StrategyCampaignStore::HOLDOUT_EXHAUSTED) {
                $this->warn('validation holdout exhausted — closing campaign with a NULL_* report before testing another candidate.');
                $round--;
                $stopReason = 'holdout_exhausted';
                break;
            }

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
                'timeframe_profile' => $campaign->campaign['timeframe_profile'] ?? [],
                'feature_set' => $campaign->campaign['feature_set'] ?? [],
                'holdout_reuse_count' => $round,
                'holdout_status' => $lastHoldoutStatus,
                'holdout_generation' => $holdoutGeneration,
                'max_holdout_generation' => (int) ($campaign->campaign['data_manifest']['max_holdout_generation'] ?? $split['max_holdout_generation']),
                'max_rounds' => $maxRounds,
                'stop_reason' => $stopReason,
                'pre_registered_budget_complete' => ($maxRounds > 0 && $round >= $maxRounds) || $stopReason === 'time_budget_complete',
                'data_manifest' => $campaign->campaign['data_manifest'] ?? [],
                'data_sha' => $campaign->campaign['data_manifest']['sha256'] ?? null,
                'cost_profile' => $campaign->campaign['cost_profile'] ?? [],
                'cost_profile_hash' => $campaign->campaign['cost_profile']['cost_profile_hash'] ?? null,
                'holdout_id' => $campaign->campaign['holdout']['holdout_id'] ?? null,
                'confirmation_holdout_id' => $campaign->campaign['confirmation_holdout']['holdout_id'] ?? null,
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
     * @param  list<\App\Services\Ai\Finance\StrategyLoop\Bar>  $allBars
     * @return array{
     *     scoring_bars:list<\App\Services\Ai\Finance\StrategyLoop\Bar>,
     *     holdout_bars:list<\App\Services\Ai\Finance\StrategyLoop\Bar>,
     *     confirmation_holdout_bars:list<\App\Services\Ai\Finance\StrategyLoop\Bar>,
     *     split_policy:string,
     *     holdout_generation:int,
     *     max_holdout_generation:int
     * }
     */
    private function campaignSplit(array $allBars, float $holdoutFrac, float $confirmationFrac, int $holdoutGeneration): array
    {
        $n = count($allBars);
        $confirmationStart = (int) floor($n * (1.0 - $confirmationFrac));
        $baseScoringEnd = (int) floor($n * (1.0 - $holdoutFrac));
        $validationBars = max(1, $confirmationStart - $baseScoringEnd);
        $maxHoldoutGeneration = max(0, (int) floor(max(0, $confirmationStart - 1) / $validationBars) - 1);
        $holdoutEnd = $confirmationStart - (max(0, $holdoutGeneration) * $validationBars);
        $holdoutStart = $holdoutEnd - $validationBars;

        if ($holdoutStart < 1 || $holdoutEnd <= $holdoutStart || $confirmationStart >= $n) {
            return [
                'scoring_bars' => [],
                'holdout_bars' => [],
                'confirmation_holdout_bars' => [],
                'split_policy' => 'walkback_validation_holdout_before_reserved_confirmation',
                'holdout_generation' => max(0, $holdoutGeneration),
                'max_holdout_generation' => $maxHoldoutGeneration,
            ];
        }

        return [
            'scoring_bars' => array_values(array_slice($allBars, 0, $holdoutStart)),
            'holdout_bars' => array_values(array_slice($allBars, $holdoutStart, $holdoutEnd - $holdoutStart)),
            'confirmation_holdout_bars' => array_values(array_slice($allBars, $confirmationStart)),
            'split_policy' => 'walkback_validation_holdout_before_reserved_confirmation',
            'holdout_generation' => max(0, $holdoutGeneration),
            'max_holdout_generation' => $maxHoldoutGeneration,
        ];
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
        $strategy = $this->strategyForFamily($family);
        $metrics = new HonestMetrics;
        $passing = [];

        for ($i = 0; $i < $k; $i++) {
            $island = $islands[$i % count($islands)] ?? 'robustness';
            $islandElite = array_values(array_filter($elite, static fn (array $e): bool => (string) ($e['island'] ?? '') === $island));
            $baseElite = $islandElite !== [] ? $islandElite : $elite;
            $params = ($baseElite !== [] && $i % 5 !== 0)
                ? $this->mutateParams($family, $baseElite[$i % count($baseElite)]['params'] ?? $baseElite[$i % count($baseElite)], $island)
                : $this->randomParams($family, $island);
            $scored = $this->scoreScoring($strategy, $metrics, $scoringBars, $params);
            if ($scored !== null) {
                $scored['island'] = $island;
                $passing[] = $scored;
            }
        }

        if ($passing === []) {
            $result = $this->roundResult(0, $k, null, false, false, 'rejected_honest_null', ['no_candidate_passed_sanity_gate'], [], []);
            $result['incoming_elite_size'] = count($elite);

            return $result;
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
            'thresholds' => $this->honestyThresholds(),
        ]);
        $campaignVerdict = $gate->evaluate([
            'winner_daily_returns' => $winner['daily_returns'],
            'sibling_windows' => array_map(static fn (array $p): array => $p['windows'], $passing),
            'sibling_sharpes' => array_map(static fn (array $p): float => $p['pp_sharpe'], $passing),
            'scenarios_explored' => max($k, $campaignTrials), // cumulative campaign penalty, even for rejected rows
            'holdout_sharpe' => $holdout['ann_sharpe'],
            'holdout_trades' => $holdout['n_trades'],
            'thresholds' => $this->honestyThresholds(),
        ]);
        $quarantine = null;
        $certified = false;
        $promoted = false;
        $status = 'rejected_honest_null';
        $reasons = $campaignVerdict['reasons'];

        if ((bool) $roundVerdict['certified']) {
            $confirmationHoldout = $this->scoreRegion($strategy, $metrics, $confirmationHoldoutBars, $winner['params']);
            $quarantine = $this->quarantineChampion($campaign, $symbol, $interval, $family, $strategy, $metrics, $scoringBars, $confirmationHoldoutBars, $winner, $confirmationHoldout, $roundVerdict, $campaignVerdict, $confirmationHoldoutStatus);
            $certified = (bool) ($quarantine['certified'] ?? false);
            $promoted = (bool) ($quarantine['promoted'] ?? false);
            $status = (string) ($quarantine['status'] ?? 'promoted_pending_quarantine');
            $reasons = $quarantine['reasons'] ?? ['promoted_pending_quarantine'];
        }

        $result = $this->roundResult(
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
        $result['incoming_elite_size'] = count($elite);

        return $result;
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
            'round_reasons' => $roundVerdict['reasons'] ?? ($reasons === [] ? ['certified'] : $reasons),
            'campaign_reasons' => $campaignVerdict['reasons'] ?? ($reasons === [] ? ['certified'] : $reasons),
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
    private function scoreScoring(StrategyRunner $strategy, HonestMetrics $metrics, array $bars, array $params): ?array
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
    private function scoreRegion(StrategyRunner $strategy, HonestMetrics $metrics, array $bars, array $params): array
    {
        $params['fee_bps'] = $this->feeBps;        // FROZEN costs — never from the candidate
        $params['slippage_bps'] = $this->slippageBps;
        $r = $strategy->run($bars, $params);

        return [
            'pp_sharpe' => $metrics->perPeriodSharpe($r->dailyReturns),
            'ann_sharpe' => $metrics->sharpe($r->dailyReturns, $this->ppy),
            'max_dd' => $metrics->maxDrawdown($r->equityCurve),
            'n_trades' => $r->nTrades,
            'total_return' => $this->totalReturn($r->equityCurve),
            'exposure' => $this->exposure($r->trades, count($r->equityCurve)),
            'equity_curve_sample' => $this->equityCurveSample($r->equityCurve),
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
    private function quarantineChampion(StrategyCampaignStore $campaign, string $symbol, string $interval, string $family, StrategyRunner $strategy, HonestMetrics $metrics, array $scoringBars, array $holdoutBars, array $winner, array $holdout, array $roundVerdict, array $campaignVerdict, string $holdoutStatus): array
    {
        $checks = new StrategyRobustnessChecks;
        $oldFee = $this->feeBps;
        $oldSlip = $this->slippageBps;
        $this->feeBps = $oldFee * $this->costStressMultiplier;
        $this->slippageBps = $oldSlip * $this->costStressMultiplier;
        try {
            $stressedHoldout = $this->scoreRegion($strategy, $metrics, $holdoutBars, $winner['params']);
        } finally {
            $this->feeBps = $oldFee;
            $this->slippageBps = $oldSlip;
        }

        $neighbors = [];
        for ($i = 0; $i < 8; $i++) {
            $neighbors[] = $this->scoreScoring($strategy, $metrics, $scoringBars, $this->mutateParams($family, $winner['params']));
        }

        $primaryEngine = [
            'engine' => 'atlas_php',
            'trade_count' => $holdout['n_trades'] ?? 0,
            'ann_sharpe' => $holdout['ann_sharpe'] ?? null,
            'max_dd' => $holdout['max_dd'] ?? null,
            'total_return' => $holdout['total_return'] ?? null,
            'exposure' => $holdout['exposure'] ?? null,
            'equity_curve_sample' => $holdout['equity_curve_sample'] ?? [],
            'holdout_passed' => ($holdout['ann_sharpe'] ?? 0.0) >= 0.5 && ($holdout['n_trades'] ?? 0) >= $this->holdoutMinTrades,
        ];
        $secondaryEngine = match ($this->secondEngine) {
            'python-replay' => (new ExternalPythonTrendBreakoutReplay)->evaluate($holdoutBars, [
                ...$winner['params'],
                'fee_bps' => $this->feeBps,
                'slippage_bps' => $this->slippageBps,
            ], $this->ppy, $family),
            'independent-replay' => $family === 'trend-breakout-v1' ? (new IndependentTrendBreakoutReplay)->evaluate($holdoutBars, [
                ...$winner['params'],
                'fee_bps' => $this->feeBps,
                'slippage_bps' => $this->slippageBps,
            ], $this->ppy) : ['status' => 'unavailable', 'engine' => 'atlas_independent_replay', 'reason' => 'family_not_supported_by_independent_replay'],
            'freqtrade' => (new FreqtradeSecondEngineAdapter)->evaluate((string) $this->option('freqtrade-report'), [
                'symbol' => $symbol,
                'interval' => $interval,
                'strategy_family' => $family,
                'data_sha' => $campaign->campaign['data_manifest']['sha256'] ?? '',
                'holdout_id' => $campaign->campaign['holdout']['holdout_id'] ?? '',
                'cost_profile_hash' => $campaign->campaign['cost_profile']['cost_profile_hash'] ?? '',
            ]),
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

        $costStress = $checks->costStress($holdout, $stressedHoldout, 0.0, $this->maxDd) + [
            'multiplier' => round($this->costStressMultiplier, 6),
            'normal_fee_bps' => round($oldFee, 6),
            'normal_slippage_bps' => round($oldSlip, 6),
            'stress_fee_bps' => round($oldFee * $this->costStressMultiplier, 6),
            'stress_slippage_bps' => round($oldSlip * $this->costStressMultiplier, 6),
        ];
        $quarantine = (new ChampionQuarantine)->evaluate([
            'round_verdict' => $roundVerdict,
            'campaign_verdict' => $campaignVerdict,
            'holdout_status' => $holdoutStatus,
            'fresh_holdout' => $holdout,
            'cost_stress' => $costStress,
            'neighborhood' => $checks->neighborhood($neighbors, 3, 0.0),
            'second_engine' => (new SecondEngineDivergenceGate)->evaluate($primaryEngine, $secondaryEngine),
            'cross_campaign' => $crossCampaign,
            'thresholds' => $this->honestyThresholds(),
        ]);

        if ($campaign->writesEnabled && $this->shouldQueueIndependentConfirmation($quarantine)) {
            $candidatesPerRound = (int) ($campaign->campaign['pre_registered_budget']['candidates_per_round'] ?? 600);
            $campaignTrials = (int) ($campaignVerdict['report']['n_trials'] ?? $candidatesPerRound);
            $quarantine['confirmation_queue'] = StrategyConfirmationQueue::default($dryRun)->enqueue([
                'source_campaign_id' => $campaign->campaignId,
                'source_round' => max(1, (int) ceil($campaignTrials / max(1, $candidatesPerRound))),
                'symbol' => $symbol,
                'interval' => $interval,
                'strategy_family' => $family,
                'feature_set' => $campaign->campaign['feature_set'] ?? (new StrategyFeatureSetProfile)->describe(StrategyFeatureSetProfile::PRICE_ONLY),
                'signature' => $signature,
                'candidate_params' => $winner['params'],
                'required_independent_campaigns' => $this->crossCampaignConfirmations,
                'candidates_per_round' => $candidatesPerRound,
                'max_rounds' => (int) ($campaign->campaign['pre_registered_budget']['max_rounds'] ?? 0),
                'seed' => $this->confirmationSeed($campaign->campaignId, (string) ($signature['signature'] ?? '')),
            ]);
        }

        return $quarantine;
    }

    /** @param array<string,mixed> $quarantine */
    private function shouldQueueIndependentConfirmation(array $quarantine): bool
    {
        if (! (bool) ($quarantine['promoted'] ?? false) || (bool) ($quarantine['certified'] ?? false)) {
            return false;
        }
        $reasons = array_values(array_filter(array_map('strval', $quarantine['reasons'] ?? [])));
        if ($reasons === []) {
            return false;
        }

        return array_values(array_diff($reasons, ['cross_campaign_rediscovery_required'])) === [];
    }

    private function confirmationSeed(string $campaignId, string $signature): int
    {
        $hex = substr(hash('sha256', $campaignId.'|'.$signature.'|confirmation'), 0, 8);

        return max(1, (int) (hexdec($hex) % 2_147_483_647));
    }

    private function strategyForFamily(string $family): StrategyRunner
    {
        return match ($family) {
            'mean-reversion-v1' => new MeanReversionStrategy,
            'momentum-v1' => new MomentumStrategy,
            default => new TrendBreakoutStrategy,
        };
    }

    /** @return array<string,float|int> a random point in the strategy search space */
    private function randomParams(string $family, string $island = 'robustness'): array
    {
        if ($family === 'mean-reversion-v1') {
            return $this->randomMeanReversionParams($island);
        }
        if ($family === 'momentum-v1') {
            return $this->randomMomentumParams($island);
        }

        return $this->randomTrendBreakoutParams($island);
    }

    /** @return array<string,float|int> */
    private function randomTrendBreakoutParams(string $island = 'robustness'): array
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
    private function mutateParams(string $family, array $base, string $island = 'robustness'): array
    {
        if ($family === 'mean-reversion-v1') {
            return $this->mutateMeanReversionParams($base, $island);
        }
        if ($family === 'momentum-v1') {
            return $this->mutateMomentumParams($base, $island);
        }

        return $this->mutateTrendBreakoutParams($base, $island);
    }

    /**
     * @param  array<string,mixed>  $base
     * @return array<string,float|int>
     */
    private function mutateTrendBreakoutParams(array $base, string $island = 'robustness'): array
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

    /** @return array<string,float|int> */
    private function randomMeanReversionParams(string $island = 'robustness'): array
    {
        $params = [
            'regime_period' => $this->chance(0.35) ? 0 : $this->randInt(20, 300),
            'lookback' => $this->randInt(8, 120),
            'entry_z' => $this->randFloat(0.5, 3.5),
            'exit_z' => $this->randFloat(-0.25, 1.0),
            'risk_pct' => $this->randFloat(0.05, 0.6),
            'stop_loss_pct' => $this->randFloat(0.015, 0.25),
            'max_hold_bars' => $this->randInt(3, 80),
        ];

        return $this->shapeMeanReversionParamsForIsland($params, $island);
    }

    /** @return array<string,float|int> */
    private function randomMomentumParams(string $island = 'robustness'): array
    {
        $params = [
            'regime_period' => $this->chance(0.25) ? 0 : $this->randInt(20, 300),
            'momentum_lookback' => $this->randInt(5, 120),
            'entry_momentum' => $this->randFloat(0.005, 0.18),
            'exit_momentum' => $this->randFloat(-0.08, 0.06),
            'risk_pct' => $this->randFloat(0.05, 0.6),
            'stop_loss_pct' => $this->randFloat(0.015, 0.25),
            'trailing_stop_pct' => $this->randFloat(0.02, 0.30),
            'max_hold_bars' => $this->randInt(3, 120),
        ];

        return $this->shapeMomentumParamsForIsland($params, $island);
    }

    /**
     * @param  array<string,mixed>  $base
     * @return array<string,float|int>
     */
    private function mutateMomentumParams(array $base, string $island = 'robustness'): array
    {
        $params = [
            'regime_period' => $this->chance(0.15) ? ($this->chance(0.5) ? 0 : $this->randInt(20, 300)) : $this->jitterInt((int) ($base['regime_period'] ?? 100), 0, 300, 30),
            'momentum_lookback' => $this->jitterInt((int) ($base['momentum_lookback'] ?? 20), 5, 120, 12),
            'entry_momentum' => $this->jitterFloat((float) ($base['entry_momentum'] ?? 0.03), 0.005, 0.18, 0.02),
            'exit_momentum' => $this->jitterFloat((float) ($base['exit_momentum'] ?? 0.0), -0.08, 0.06, 0.02),
            'risk_pct' => $this->jitterFloat((float) ($base['risk_pct'] ?? 0.2), 0.05, 0.6, 0.08),
            'stop_loss_pct' => $this->jitterFloat((float) ($base['stop_loss_pct'] ?? 0.08), 0.015, 0.25, 0.03),
            'trailing_stop_pct' => $this->jitterFloat((float) ($base['trailing_stop_pct'] ?? 0.10), 0.02, 0.30, 0.04),
            'max_hold_bars' => $this->jitterInt((int) ($base['max_hold_bars'] ?? 30), 3, 120, 12),
        ];

        return $this->shapeMomentumParamsForIsland($params, $island);
    }

    /**
     * @param  array<string,mixed>  $base
     * @return array<string,float|int>
     */
    private function mutateMeanReversionParams(array $base, string $island = 'robustness'): array
    {
        $params = [
            'regime_period' => $this->chance(0.15) ? ($this->chance(0.5) ? 0 : $this->randInt(20, 300)) : $this->jitterInt((int) ($base['regime_period'] ?? 100), 0, 300, 30),
            'lookback' => $this->jitterInt((int) ($base['lookback'] ?? 30), 8, 120, 12),
            'entry_z' => $this->jitterFloat((float) ($base['entry_z'] ?? 1.5), 0.5, 3.5, 0.35),
            'exit_z' => $this->jitterFloat((float) ($base['exit_z'] ?? 0.0), -0.5, 1.0, 0.25),
            'risk_pct' => $this->jitterFloat((float) ($base['risk_pct'] ?? 0.2), 0.05, 0.6, 0.08),
            'stop_loss_pct' => $this->jitterFloat((float) ($base['stop_loss_pct'] ?? 0.08), 0.015, 0.25, 0.03),
            'max_hold_bars' => $this->jitterInt((int) ($base['max_hold_bars'] ?? 20), 3, 80, 8),
        ];

        return $this->shapeMeanReversionParamsForIsland($params, $island);
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

    /**
     * @param  array<string,float|int>  $params
     * @return array<string,float|int>
     */
    private function shapeMeanReversionParamsForIsland(array $params, string $island): array
    {
        if ($island === 'conservative') {
            $params['lookback'] = max(35, (int) $params['lookback']);
            $params['entry_z'] = max(1.4, (float) $params['entry_z']);
            $params['exit_z'] = max(0.0, (float) $params['exit_z']);
            $params['risk_pct'] = min(0.18, (float) $params['risk_pct']);
            $params['stop_loss_pct'] = min(0.12, max(0.03, (float) $params['stop_loss_pct']));
            $params['max_hold_bars'] = min(45, max(8, (int) $params['max_hold_bars']));
        } elseif ($island === 'aggressive') {
            $params['lookback'] = min(55, max(8, (int) $params['lookback']));
            $params['entry_z'] = min(2.2, max(0.5, (float) $params['entry_z']));
            $params['exit_z'] = min(0.75, (float) $params['exit_z']);
            $params['risk_pct'] = max(0.12, min(0.6, (float) $params['risk_pct']));
            $params['max_hold_bars'] = min(30, (int) $params['max_hold_bars']);
        } else {
            $params['lookback'] = min(90, max(15, (int) $params['lookback']));
            $params['entry_z'] = min(3.0, max(0.8, (float) $params['entry_z']));
            $params['risk_pct'] = min(0.32, max(0.08, (float) $params['risk_pct']));
            $params['stop_loss_pct'] = min(0.18, max(0.025, (float) $params['stop_loss_pct']));
        }

        return $params;
    }

    /**
     * @param  array<string,float|int>  $params
     * @return array<string,float|int>
     */
    private function shapeMomentumParamsForIsland(array $params, string $island): array
    {
        if ($island === 'conservative') {
            $params['momentum_lookback'] = max(35, (int) $params['momentum_lookback']);
            $params['entry_momentum'] = max(0.035, (float) $params['entry_momentum']);
            $params['exit_momentum'] = min(0.025, (float) $params['exit_momentum']);
            $params['risk_pct'] = min(0.18, (float) $params['risk_pct']);
            $params['stop_loss_pct'] = min(0.14, max(0.03, (float) $params['stop_loss_pct']));
            $params['trailing_stop_pct'] = min(0.18, max(0.04, (float) $params['trailing_stop_pct']));
            $params['max_hold_bars'] = min(90, max(12, (int) $params['max_hold_bars']));
        } elseif ($island === 'aggressive') {
            $params['momentum_lookback'] = min(45, max(5, (int) $params['momentum_lookback']));
            $params['entry_momentum'] = min(0.09, max(0.005, (float) $params['entry_momentum']));
            $params['exit_momentum'] = min(0.06, (float) $params['exit_momentum']);
            $params['risk_pct'] = max(0.12, min(0.6, (float) $params['risk_pct']));
            $params['max_hold_bars'] = min(45, (int) $params['max_hold_bars']);
        } else {
            $params['momentum_lookback'] = min(90, max(10, (int) $params['momentum_lookback']));
            $params['entry_momentum'] = min(0.14, max(0.012, (float) $params['entry_momentum']));
            $params['risk_pct'] = min(0.32, max(0.08, (float) $params['risk_pct']));
            $params['stop_loss_pct'] = min(0.20, max(0.025, (float) $params['stop_loss_pct']));
            $params['trailing_stop_pct'] = min(0.24, max(0.035, (float) $params['trailing_stop_pct']));
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

    /** @param list<float> $equity */
    private function totalReturn(array $equity): float
    {
        if ($equity === []) {
            return 0.0;
        }

        return (float) $equity[count($equity) - 1] - 1.0;
    }

    /**
     * @param  list<array<string,mixed>>  $trades
     */
    private function exposure(array $trades, int $barCount): float
    {
        if ($barCount <= 0) {
            return 0.0;
        }
        $heldBars = 0;
        foreach ($trades as $trade) {
            $heldBars += max(0, (int) ($trade['bars_held'] ?? 0));
        }

        return min(1.0, $heldBars / $barCount);
    }

    /**
     * @param  list<float>  $equity
     * @return list<float>
     */
    private function equityCurveSample(array $equity, int $points = 20): array
    {
        $n = count($equity);
        if ($n === 0) {
            return [];
        }
        $points = max(2, min($points, $n));
        $sample = [];
        for ($i = 0; $i < $points; $i++) {
            $idx = (int) round($i * ($n - 1) / max(1, $points - 1));
            $sample[] = round((float) $equity[$idx], 10);
        }

        return $sample;
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
            'timeframe_profile' => $campaign->campaign['timeframe_profile'] ?? null,
            'feature_set' => $campaign->campaign['feature_set'] ?? null,
            'feature_set_id' => data_get($campaign->campaign, 'feature_set.feature_set_id'),
            'strategy_family' => $family,
            'seed' => $seed,
            'candidates' => $k,
            'campaign_trials' => $campaignTrials,
            'winner_island' => $res['winner_island'] ?? null,
            'winner_strategy' => $res['winner_params'] ?? null,
            'winner_signature' => is_array($res['winner_params'] ?? null)
                ? (new StrategyCandidateSignature)->make($symbol, $interval, $family, $res['winner_params'])
                : null,
            'incoming_elite_size' => $res['incoming_elite_size'] ?? 0,
            'elite_pool_size' => count((array) ($res['elite'] ?? [])),
            'elite_pool' => $res['elite'] ?? [],
            'n_passed' => $res['n_passed'],
            'certified' => $res['certified'],
            'promoted' => $res['promoted'],
            'status' => $res['status'],
            'best_ann_sharpe' => $res['best_ann_sharpe'],
            'deflated_sharpe' => $res['report']['deflated_sharpe'] ?? null,
            'campaign_deflated_sharpe' => $res['campaign_verdict']['report']['deflated_sharpe'] ?? null,
            'pbo' => $res['report']['pbo'] ?? null,
            'holdout_sharpe' => $res['holdout']['ann_sharpe'] ?? null,
            'holdout_total_return' => $res['holdout']['total_return'] ?? null,
            'holdout_exposure' => $res['holdout']['exposure'] ?? null,
            'holdout_equity_curve_sample' => $res['holdout']['equity_curve_sample'] ?? [],
            'holdout_regime_metrics' => $res['holdout']['regime_metrics'] ?? null,
            'cost_stress_multiplier' => data_get($res, 'quarantine.cost_stress.multiplier'),
            'holdout_id' => $campaign->campaign['holdout']['holdout_id'] ?? null,
            'holdout_status' => $holdoutStatus,
            'holdout_reuse_count' => $round,
            'confirmation_holdout_id' => $campaign->campaign['confirmation_holdout']['holdout_id'] ?? null,
            'confirmation_holdout_status' => $campaign->confirmationHoldoutStatus(),
            'confirmation_holdout_used' => (bool) ($res['confirmation_holdout_used'] ?? false),
            'confirmation_holdout_sharpe' => $res['confirmation_holdout']['ann_sharpe'] ?? null,
            'confirmation_holdout_total_return' => $res['confirmation_holdout']['total_return'] ?? null,
            'confirmation_holdout_exposure' => $res['confirmation_holdout']['exposure'] ?? null,
            'confirmation_holdout_equity_curve_sample' => $res['confirmation_holdout']['equity_curve_sample'] ?? [],
            'confirmation_holdout_trades' => $res['confirmation_holdout']['n_trades'] ?? null,
            'confirmation_holdout_regime_metrics' => $res['confirmation_holdout']['regime_metrics'] ?? null,
            'cost_profile_hash' => $campaign->campaign['cost_profile']['cost_profile_hash'] ?? null,
            'data_sha' => $campaign->campaign['data_manifest']['sha256'] ?? null,
            'reasons' => $res['reasons'],
            'round_reasons' => $res['round_reasons'] ?? ($res['round_verdict']['reasons'] ?? []),
            'campaign_reasons' => $res['campaign_reasons'] ?? ($res['campaign_verdict']['reasons'] ?? []),
            'quarantine' => $res['quarantine'],
            'merged_to_main' => false,
        ]);
    }

    /**
     * @return array{max_round:int,best_dsr:float|null,elite:list<array<string,mixed>>}
     */
    private function existingLedgerState(string $ledgerPath): array
    {
        if (! is_file($ledgerPath)) {
            return ['max_round' => 0, 'best_dsr' => null, 'elite' => []];
        }
        $maxRound = 0;
        $bestDsr = null;
        $eliteCandidates = [];
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
            foreach ($this->ledgerEliteCandidates($row) as $candidate) {
                $eliteCandidates[] = $candidate;
            }
        }

        return ['max_round' => $maxRound, 'best_dsr' => $bestDsr, 'elite' => $this->selectLedgerElite($eliteCandidates, 12)];
    }

    /**
     * @param  array<string,mixed>  $row
     * @return list<array{params:array<string,mixed>,island:string,score:float,round:int}>
     */
    private function ledgerEliteCandidates(array $row): array
    {
        $out = [];
        foreach ((array) ($row['elite_pool'] ?? []) as $elite) {
            if (! is_array($elite) || ! is_array($elite['params'] ?? null)) {
                continue;
            }
            $out[] = [
                'params' => $elite['params'],
                'island' => (string) ($elite['island'] ?? $row['winner_island'] ?? 'robustness'),
                'score' => $this->ledgerEliteScore($row),
                'round' => (int) ($row['round'] ?? 0),
            ];
        }

        if (is_array($row['winner_strategy'] ?? null)) {
            $out[] = [
                'params' => $row['winner_strategy'],
                'island' => (string) ($row['winner_island'] ?? 'robustness'),
                'score' => $this->ledgerEliteScore($row),
                'round' => (int) ($row['round'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{params:array<string,mixed>,island:string,score:float,round:int}>  $candidates
     * @return list<array{params:array<string,mixed>,island:string}>
     */
    private function selectLedgerElite(array $candidates, int $limit): array
    {
        $unique = [];
        foreach ($candidates as $candidate) {
            $key = hash('sha256', (string) json_encode($candidate['params'], JSON_UNESCAPED_SLASHES));
            if (! isset($unique[$key]) || $candidate['score'] > $unique[$key]['score'] || ($candidate['score'] === $unique[$key]['score'] && $candidate['round'] > $unique[$key]['round'])) {
                $unique[$key] = $candidate;
            }
        }
        $ranked = array_values($unique);
        usort($ranked, static fn (array $a, array $b): int => [$b['score'], $b['round']] <=> [$a['score'], $a['round']]);

        return array_map(
            static fn (array $candidate): array => ['params' => $candidate['params'], 'island' => $candidate['island']],
            array_slice($ranked, 0, $limit),
        );
    }

    /** @param array<string,mixed> $row */
    private function ledgerEliteScore(array $row): float
    {
        $campaignDsr = is_numeric($row['campaign_deflated_sharpe'] ?? null) ? (float) $row['campaign_deflated_sharpe'] : -1.0;
        $roundDsr = is_numeric($row['deflated_sharpe'] ?? null) ? (float) $row['deflated_sharpe'] : -1.0;
        $holdoutSharpe = is_numeric($row['holdout_sharpe'] ?? null) ? (float) $row['holdout_sharpe'] : -1.0;
        $annSharpe = is_numeric($row['best_ann_sharpe'] ?? null) ? (float) $row['best_ann_sharpe'] : -1.0;
        $pbo = is_numeric($row['pbo'] ?? null) ? max(0.0, (float) $row['pbo']) : 1.0;

        return (2.0 * $campaignDsr) + $roundDsr + (0.5 * $holdoutSharpe) + (0.25 * $annSharpe) - max(0.0, $pbo - 0.2);
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
            'timeframe_profile' => $campaign->campaign['timeframe_profile'] ?? null,
            'strategy_family' => $family,
            'scenarios_explored' => $k,
            'campaign_trials' => $campaignTrials,
            'certified' => true,
            'honesty_report' => $res['campaign_verdict']['report'] ?? $res['report'],
            'round_honesty_report' => $res['report'],
            'campaign_honesty_report' => $res['campaign_verdict']['report'] ?? null,
            'round_deflated_sharpe' => $res['report']['deflated_sharpe'] ?? null,
            'campaign_deflated_sharpe' => $res['campaign_verdict']['report']['deflated_sharpe'] ?? null,
            'round_reasons' => $res['round_reasons'] ?? ($res['round_verdict']['reasons'] ?? []),
            'campaign_reasons' => $res['campaign_reasons'] ?? ($res['campaign_verdict']['reasons'] ?? []),
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
        return (new StrategyTimeframeProfile)->periodsPerYear($interval);
    }

    /** @return array<string,int|float> */
    private function honestyThresholds(): array
    {
        return [
            'dsr_min' => 0.95,
            'pbo_max' => 0.2,
            'holdout_min_sharpe' => 0.5,
            'holdout_min_trades' => $this->holdoutMinTrades,
        ];
    }

    private function optionWasProvided(string $name): bool
    {
        return $this->input->hasParameterOption('--'.$name);
    }
}
