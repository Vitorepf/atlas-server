<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionScenarioExplorer;
use App\Services\Ai\Finance\StrategyLoop\TradingHonestyGate;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Drives the PROVEN Atlas Evolution Loop engine to search for a trading strategy, then
 * applies the finance honesty gate and persists a PROPOSE-ONLY proposal (or an honest
 * null). The engine is reused verbatim: the candidate is a strategy.json, the frozen
 * judge is the backtest acceptance command.
 *
 * Flow: build an inert base workspace (risk_pct=0 ⇒ RED) → explorer runs N scenarios
 * through the provider → frozen judge maximizes ATLAS_METRIC + revert-recheck proves the
 * diff earned it → the winner is re-run for its full scoring returns and the sealed
 * holdout → the honesty gate injects N (=scenarios_explored) to deflate the Sharpe and
 * decides certify-or-null. Nothing is ever merged; nothing ever trades.
 */
final class AtlasFinanceStrategyEvolveCommand extends Command
{
    protected $signature = 'atlas:finance:strategy-evolve
        {--symbol=BTCUSDT : Market symbol}
        {--interval=1d : Bar interval}
        {--scenarios=6 : Scenarios to explore (0 = engine deep-search)}
        {--provider= : Provider override (default: loop default / Atlas Decide)}
        {--dry-run : Build the workspace + prove the RED baseline without calling a provider}
        {--json : Print the canonical JSON result}';

    protected $description = 'Evolve a trading strategy through the proven loop under the finance honesty gate (propose-only, no live trading).';

    public function handle(AtlasEvolutionScenarioExplorer $explorer, TradingHonestyGate $gate): int
    {
        $symbol = (string) $this->option('symbol');
        $interval = (string) $this->option('interval');
        $provider = trim((string) $this->option('provider'));

        [$baseWs, $cleanup] = $this->buildBaseWorkspace();

        try {
            $acceptance = $this->acceptance($symbol, $interval);

            if ((bool) $this->option('dry-run')) {
                return $this->dryRun($baseWs, $acceptance);
            }

            $task = [
                'objective' => $this->objective($symbol, $interval),
                'base_workspace' => $baseWs,
                'acceptance' => $acceptance,
                'provider' => $provider !== '' ? $provider : null,
                'allowed_files' => ['strategy.json'],
                'surface_id' => 'atlas_finance_strategy_evolution',
                'keep_workspaces' => true, // we read the winner's strategy.json after
            ];
            $scenarios = (int) $this->option('scenarios');

            $this->info("Evolving {$symbol}-{$interval} strategy — exploring scenarios through the proven engine…");
            $result = $explorer->explore($task, $scenarios > 0 ? $scenarios : null);

            $attemptWorkspaces = array_values(array_filter(array_map(
                static fn (array $a): ?string => is_string($a['workspace'] ?? null) ? $a['workspace'] : null,
                $result['attempts'] ?? [],
            )));

            $verdict = $this->judgeResult($result, $symbol, $interval, $gate);
            $attemptsSummary = $this->summarizeAttempts($result); // read workspaces BEFORE cleanup
            $path = $this->persist($result, $verdict, $symbol, $interval, $provider, $attemptsSummary);

            $this->report($result, $verdict, $path);

            foreach ($attemptWorkspaces as $ws) {
                (new Process(['rm', '-rf', $ws]))->run();
            }

            if ((bool) $this->option('json')) {
                $this->line('ATLAS_EVOLVE_RESULT='.json_encode([
                    'symbol' => $symbol,
                    'scenarios_explored' => $result['scenarios_explored'] ?? 0,
                    'certified' => $verdict['certified'] ?? false,
                    'reasons' => $verdict['reasons'] ?? [],
                    'report' => $verdict['report'] ?? [],
                    'proposal_path' => $path,
                    'merged_to_main' => false,
                ], JSON_UNESCAPED_SLASHES));
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('strategy-evolve failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $cleanup();
        }
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function judgeResult(array $result, string $symbol, string $interval, TradingHonestyGate $gate): array
    {
        $winner = $result['winner'] ?? null;
        if (! is_array($winner) || ! is_string($winner['workspace'] ?? null)) {
            return [
                'certified' => false,
                'reasons' => ['no_winner (no candidate passed the sanity gate)'],
                'report' => ['n_trials' => (int) ($result['scenarios_explored'] ?? 0)],
            ];
        }

        $winnerStrategy = $winner['workspace'].'/strategy.json';
        $scoring = $this->rerunWinner($winnerStrategy, $symbol, $interval, 'scoring');
        $holdout = $this->rerunWinner($winnerStrategy, $symbol, $interval, 'holdout');
        $siblings = $this->collectSiblings($result, $interval);

        return $gate->evaluate([
            'winner_daily_returns' => $scoring['daily_returns'] ?? [],
            'sibling_windows' => $siblings['windows'],
            'sibling_sharpes' => $siblings['sharpes'],
            'scenarios_explored' => (int) ($result['scenarios_explored'] ?? 1),
            'holdout_sharpe' => (float) ($holdout['sharpe'] ?? -INF),
            'holdout_trades' => (int) ($holdout['n_trades'] ?? 0),
        ]);
    }

    /**
     * Re-run the backtest on the winner's strategy.json with --json to capture the FULL
     * report (uncapped, unlike the judge's excerpt).
     *
     * @return array<string,mixed>
     */
    private function rerunWinner(string $strategyPath, string $symbol, string $interval, string $region): array
    {
        $proc = new Process([
            'php', base_path('artisan'), 'atlas:finance:strategy-backtest',
            '--strategy='.$strategyPath, '--symbol='.$symbol, '--interval='.$interval,
            '--region='.$region, '--fee-bps=10', '--slippage-bps=5', '--holdout-frac=0.25', '--json',
        ], base_path(), null, null, 240.0);
        $proc->run();

        return $this->parseReport($proc->getOutput());
    }

    /**
     * Build the cross-sibling PBO matrix (per-window returns) + the trial Sharpes from the
     * passing attempts' captured stdout.
     *
     * @param  array<string,mixed>  $result
     * @return array{windows: list<list<float>>, sharpes: list<float>}
     */
    private function collectSiblings(array $result, string $interval): array
    {
        // ATLAS_METRIC (verdict.metric) is the ANNUALIZED Sharpe; the DSR is defined in
        // PER-PERIOD units, so de-annualize each sibling Sharpe by 1/sqrt(periodsPerYear)
        // before the gate takes their variance. Mixing units here was the 365x bug.
        $annToPerPeriod = 1.0 / sqrt(max(1.0, $this->periodsPerYear($interval)));
        $windows = [];
        $sharpes = [];
        foreach ($result['attempts'] ?? [] as $a) {
            if (! (bool) ($a['verdict']['passed'] ?? false)) {
                continue;
            }
            $sharpes[] = (float) ($a['verdict']['metric'] ?? 0.0) * $annToPerPeriod;
            $report = $this->extractAttemptReport($a);
            $w = $report['windows'] ?? null;
            if (is_array($w) && $w !== []) {
                $windows[] = array_map('floatval', array_values($w));
            }
        }

        return ['windows' => $windows, 'sharpes' => $sharpes];
    }

    /**
     * @param  array<string,mixed>  $attempt
     * @return array<string,mixed>
     */
    private function extractAttemptReport(array $attempt): array
    {
        foreach ($attempt['verdict']['details']['command_results'] ?? [] as $cr) {
            $report = $this->parseReport((string) ($cr['stdout'] ?? ''));
            if ($report !== []) {
                return $report;
            }
        }

        return [];
    }

    /**
     * Capture WHAT each scenario produced (the candidate the provider wrote) and WHY it
     * passed/failed — the audit trail for an honest null, and the diagnostic for whether the
     * provider is tuning the strategy meaningfully. Reads workspaces before they are cleaned.
     *
     * @param  array<string,mixed>  $result
     * @return list<array<string,mixed>>
     */
    private function summarizeAttempts(array $result): array
    {
        $out = [];
        foreach ($result['attempts'] ?? [] as $a) {
            $ws = is_string($a['workspace'] ?? null) ? $a['workspace'] : null;
            $strategy = ($ws !== null && is_file($ws.'/strategy.json'))
                ? json_decode((string) file_get_contents($ws.'/strategy.json'), true)
                : null;
            $out[] = [
                'scenario_id' => $a['scenario_id'] ?? null,
                'loop_status' => $a['loop_status'] ?? null,
                'passed' => (bool) ($a['verdict']['passed'] ?? false),
                'metric' => $a['verdict']['metric'] ?? null,
                'reject_reason' => $a['verdict']['details']['reason'] ?? null,
                'gate' => $this->gateReason($a),
                'strategy' => is_array($strategy) ? $strategy : null,
            ];
        }

        return $out;
    }

    /** @param  array<string,mixed>  $attempt */
    private function gateReason(array $attempt): ?string
    {
        foreach ($attempt['verdict']['details']['command_results'] ?? [] as $cr) {
            $stdout = (string) ($cr['stdout'] ?? '');
            if (preg_match('/GATE_FAILED=([^\r\n]+)/', $stdout, $m) === 1) {
                return trim($m[1]);
            }
            $report = $this->parseReport($stdout);
            if (isset($report['n_trades'])) {
                return 'n_trades='.$report['n_trades'].' sharpe='.($report['sharpe'] ?? '?');
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function parseReport(string $stdout): array
    {
        if (preg_match('/ATLAS_TRADING_REPORT=(\{.*\})/', $stdout, $m) === 1) {
            $decoded = json_decode($m[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /** Must match AtlasFinanceStrategyBacktestCommand::periodsPerYear so units stay consistent. */
    private function periodsPerYear(string $interval): float
    {
        return match ($interval) {
            '1h' => 24 * 365,
            '4h' => 6 * 365,
            '1w' => 52,
            default => 365, // 1d
        };
    }

    /**
     * @param  array<string,mixed>  $acceptance
     */
    private function dryRun(string $baseWs, array $acceptance): int
    {
        $this->info('DRY RUN — proving the inert baseline is RED (the diff-earned anti-fake contract)…');
        $command = $acceptance['commands'][0];
        $proc = Process::fromShellCommandline($command, $baseWs, null, null, 240.0);
        $proc->run();
        $red = $proc->getExitCode() !== 0;

        $this->components->twoColumnDetail('Base workspace', $baseWs);
        $this->components->twoColumnDetail('Acceptance command', $command);
        $this->components->twoColumnDetail('Baseline exit code', (string) $proc->getExitCode());
        $this->components->twoColumnDetail('Baseline is RED (required)', $red ? '<info>yes</info>' : '<error>NO — anti-fake broken</error>');
        $this->line(trim($proc->getOutput()));

        return $red ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $verdict
     */
    private function report(array $result, array $verdict, string $path): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('Scenarios explored (N)', (string) ($result['scenarios_explored'] ?? 0));
        $this->components->twoColumnDetail('Scenarios accepted (sanity gate)', (string) ($result['scenarios_accepted'] ?? 0));
        $rep = $verdict['report'] ?? [];
        if (isset($rep['deflated_sharpe'])) {
            $this->components->twoColumnDetail('Deflated Sharpe (N-adjusted)', (string) $rep['deflated_sharpe']);
            $this->components->twoColumnDetail('PBO', (string) ($rep['pbo'] ?? 'n/a'));
            $this->components->twoColumnDetail('Holdout Sharpe (sealed)', (string) ($rep['holdout_sharpe'] ?? 'n/a'));
        }
        $certified = (bool) ($verdict['certified'] ?? false);
        $this->components->twoColumnDetail(
            'Outcome',
            $certified ? '<info>CERTIFIED for review (propose-only)</info>' : '<comment>honest null: '.implode('; ', $verdict['reasons'] ?? []).'</comment>',
        );
        $this->components->twoColumnDetail('Proposal record', $path);
        $this->components->twoColumnDetail('Merged to main', '<info>never (propose-only)</info>');
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $verdict
     */
    private function persist(array $result, array $verdict, string $symbol, string $interval, string $provider, array $attemptsSummary = []): string
    {
        $dir = storage_path('atlas/finance/proposals');
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        $winner = $result['winner'] ?? null;
        $strategy = null;
        if (is_array($winner) && is_string($winner['workspace'] ?? null) && is_file($winner['workspace'].'/strategy.json')) {
            $strategy = json_decode((string) file_get_contents($winner['workspace'].'/strategy.json'), true);
        }

        $payload = [
            'created_at' => date('c'),
            'flow' => 'finance.strategy_evolution',
            'classification' => 'sensitive',
            'symbol' => $symbol,
            'interval' => $interval,
            'provider' => $provider !== '' ? $provider : '(loop_default)',
            'scenarios_explored' => (int) ($result['scenarios_explored'] ?? 0),
            'certified' => (bool) ($verdict['certified'] ?? false),
            'reasons' => $verdict['reasons'] ?? [],
            'honesty_report' => $verdict['report'] ?? [],
            'attempts' => $attemptsSummary,
            'winner_strategy' => $strategy,
            'status' => ($verdict['certified'] ?? false) ? 'certified_for_review' : 'rejected_honest_null',
            'merged_to_main' => false, // propose-only invariant
            'live_trading' => 'forbidden',
        ];
        $file = $dir.'/'.date('Ymd-His').'-'.substr(hash('sha256', json_encode($payload) ?: ''), 0, 8).'.json';
        file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $file;
    }

    /**
     * @return array{0:string,1:callable():void}
     */
    private function buildBaseWorkspace(): array
    {
        $dir = sys_get_temp_dir().'/atlas-finance-evolve-'.bin2hex(random_bytes(4));
        @mkdir($dir, 0o755, true);
        // INERT baseline: every param sane EXCEPT risk_pct=0 ⇒ 0 trades ⇒ gate RED. The
        // provider must earn an edge; reverting to this baseline must go RED (anti-fake).
        file_put_contents($dir.'/strategy.json', json_encode([
            'type' => 'trend_breakout',
            'regime_period' => 100,
            'entry_lookback' => 50,
            'exit_lookback' => 25,
            'atr_period' => 14,
            'atr_mult' => 4.0,
            'risk_pct' => 0.0,
            'fee_bps' => 10,
            'slippage_bps' => 5,
            'min_hold_bars' => 3,
        ], JSON_PRETTY_PRINT)."\n");

        $cleanup = function () use ($dir): void {
            if (is_dir($dir)) {
                (new Process(['rm', '-rf', $dir]))->run();
            }
        };

        return [$dir, $cleanup];
    }

    /**
     * @return array<string,mixed>
     */
    private function acceptance(string $symbol, string $interval): array
    {
        // Every gate/cost knob is PINNED here, in the frozen acceptance — not in the
        // editable strategy.json — so the candidate can tune the strategy, never the rules.
        $cmd = sprintf(
            'php %s atlas:finance:strategy-backtest --strategy=strategy.json --symbol=%s --interval=%s --region=scoring --fee-bps=10 --slippage-bps=5 --min-trades=20 --max-dd=0.6 --holdout-frac=0.25',
            escapeshellarg(base_path('artisan')),
            escapeshellarg($symbol),
            escapeshellarg($interval),
        );

        return [
            'commands' => [$cmd],
            'allowed_globs' => ['strategy.json'],
            'frozen_globs' => [],                       // the real harness lives outside the workspace
            'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_MAXIMIZE,
            'metric_pattern' => '/ATLAS_METRIC=([-0-9.]+)/',
            'revert_recheck' => true,                   // reverting to the inert baseline must go RED
            'strict_untracked' => true,                 // no hiding sibling files behind .gitignore/.git/info/exclude
            'timeout_seconds' => 240,
        ];
    }

    private function objective(string $symbol, string $interval): string
    {
        return <<<TXT
        Evolve the trading strategy in `strategy.json` to MAXIMIZE the printed
        `ATLAS_METRIC` (annualized out-of-sample Sharpe ratio, AFTER fees and slippage) on
        real {$symbol} {$interval} history. Edit ONLY `strategy.json`; it must stay valid JSON.

        It is a long-only spot trend/breakout strategy. Tunable params (and sane ranges):
          - regime_period   (20..300): only go long when price > SMA(regime_period). 0 disables.
          - entry_lookback  (5..100):  enter on a breakout above the prior-N-bar high.
          - exit_lookback   (0..100):  exit on a breakdown below the prior-N-bar low. 0 disables.
          - atr_period      (5..50):   ATR window for the trailing stop + position sizing.
          - atr_mult        (1.0..8.0):trailing-stop distance in ATRs.
          - risk_pct        (0.0..1.0):fraction of equity risked per trade. MUST be > 0 to trade.
          - min_hold_bars   (0..20):   minimum bars to hold (reduces churn/costs).
          - fee_bps, slippage_bps: costs — leave as given (they model real Binance costs).

        The sanity gate requires at least ~20 realized trades over the history and drawdown
        under 60%, or the candidate is rejected (exit 1). Do NOT chase win-rate or trade
        count for its own sake — only risk-adjusted return after costs counts, and a final
        anti-overfit gate will deflate your Sharpe by how many scenarios were explored. The
        baseline has risk_pct=0 (it trades nothing); your first job is to make it trade, then
        tune. The backtest and the market data are frozen — you cannot change how you are scored.
        TXT;
    }
}
