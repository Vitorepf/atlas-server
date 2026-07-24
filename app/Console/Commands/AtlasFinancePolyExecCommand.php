<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Finance\Kernel\FinanceDomainCanon;
use App\Services\Ai\Finance\PolymarketExec\ArbAllocator;
use App\Services\Ai\Finance\PolymarketExec\BasketPlanner;
use App\Services\Ai\Finance\PolymarketExec\BasketStateMachine;
use App\Services\Ai\Finance\PolymarketExec\LivePolyExecClient;
use App\Services\Ai\Finance\PolymarketExec\MintSellStateMachine;
use App\Services\Ai\Finance\PolymarketExec\OnChain\LivePolyOnChainClient;
use App\Services\Ai\Finance\PolymarketExec\OnChain\PolyOnChainClient;
use App\Services\Ai\Finance\PolymarketExec\OnChain\SimulatedPolyOnChainClient;
use App\Services\Ai\Finance\PolymarketExec\PolyAccountIdentity;
use App\Services\Ai\Finance\PolymarketExec\PolyExecClient;
use App\Services\Ai\Finance\PolymarketExec\PolyExecConfig;
use App\Services\Ai\Finance\PolymarketExec\PolyExecGate;
use App\Services\Ai\Finance\PolymarketExec\ShortBasketPlanner;
use App\Services\Ai\Finance\PolymarketExec\SimulatedPolyExecClient;
use App\Services\Ai\Finance\PolymarketShadow\PolymarketArbScanner;
use App\Services\Ai\Finance\PolymarketShadow\PolymarketPinnedHttp;
use App\Services\Ai\Finance\PolymarketShadow\PolymarketShadowFeed;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\YesNo;

/**
 * Polymarket sum-of-legs shadow/sim executor. Live mode is a dormant seam and
 * remains blocked while the canonical Finance no-live-execution policy is on.
 *
 *   preflight  show config, gate state, account readiness, kill-switch. No action.
 *   plan       build the basket plan for live opportunities + gate verdict. No action.
 *   status     read the latest monitor JSONL heartbeat. No action.
 *   qualify    aggregate monitor logs into an explicit real-money readiness verdict.
 *   run        execute one bounded pass. mode=sim signs NOTHING; mode=live signs
 *              NOTHING while FinanceDomainCanon::liveTradingBlocked() is true.
 *   monitor    repeat bounded sim passes against real books; live monitor is refused.
 *
 * Honest scope: sim is the default and live remains fail-closed unless every
 * code gate passes. Expect cents-to-a-few-dollars — this validates capture, it
 * is not income. Measured, never promised.
 */
final class AtlasFinancePolyExecCommand extends Command
{
    protected $signature = 'atlas:finance:poly-exec
        {action=preflight : preflight|plan|status|qualify|run|monitor}
        {--mode=sim : sim|live}
        {--kind=both : both|long|short — which arb direction(s) to run}
        {--max-cesta= : per-basket cap USD (overrides config)}
        {--daily-cap= : daily budget USD (overrides config)}
        {--max-concurrent= : max simultaneous baskets (overrides config)}
        {--max-candidates= : max lifecycle candidates to process in run/monitor or show in plan}
        {--market-read-timeout=5 : max seconds for each public Polymarket book/metadata read}
        {--candidate-time-budget=75 : max seconds spent evaluating candidates per plan/run/monitor cycle}
        {--max-legs-per-candidate=12 : skip baskets with too many outcome legs for bounded shadow/live readiness}
        {--max-signal-age-seconds=900 : skip lifecycle opportunities not refreshed within this TTL}
        {--cycles=3 : monitor cycles (sim only)}
        {--interval=30 : seconds between monitor cycles}
        {--scan-before-cycle : monitor only: refresh the poly-arb lifecycle with a bounded shadow scan before each exec cycle}
        {--scan-pages=4 : monitor scan-before-cycle Gamma pages per scan}
        {--scan-per-page=50 : monitor scan-before-cycle events per Gamma page}
        {--scan-time-budget=120 : monitor scan-before-cycle max seconds per scan}
        {--scan-min-profit=0.002 : monitor scan-before-cycle minimum profit per set to record}
        {--scan-max-clob-verifications= : monitor scan-before-cycle max shortlist candidates to verify; defaults to finance_poly_arb config}
        {--slow-cycle-seconds=45 : mark monitor cycles slower than this as degraded}
        {--monitor-log-dir= : write monitor JSONL audit logs here (default: storage/app/atlas-finance/poly-exec-monitor)}
        {--sim-scope= : sim-only idempotency namespace for a fresh shadow/monitor qualification window}
        {--qualification-min-cycles=12 : minimum monitor cycles required before real-money qualification}
        {--qualification-min-executed=1 : minimum simulated executions required in monitor logs}
        {--qualification-max-slow-ratio=0.05 : maximum accepted slow-cycle ratio}
        {--qualification-lookback-logs=20 : monitor JSONL files to aggregate for qualification}
        {--event-slug= : target one specific opportunity instead of the best}
        {--confirm : required only after Finance policy explicitly allows live mode}
        {--json : Emit JSON}';

    protected $description = 'Run Polymarket sum-of-legs arbitrage in shadow/sim — long (buy all legs) and short (mint+sell simulation); live is blocked by Finance policy.';

    public function handle(): int
    {
        $this->raiseMemoryFloor('256M');

        $mode = strtolower((string) $this->option('mode')) === 'live' ? 'live' : 'sim';
        $cfg = PolyExecConfig::fromConfig([
            'max_basket_usd' => $this->floatOpt('max-cesta'),
            'daily_cap_usd' => $this->floatOpt('daily-cap'),
            'max_concurrent' => $this->intOpt('max-concurrent'),
        ]);

        return match ((string) $this->argument('action')) {
            'preflight' => $this->preflight($cfg, $mode),
            'plan' => $this->plan($cfg, $mode),
            'status' => $this->status($mode),
            'qualify' => $this->qualify($cfg, $mode),
            'run' => $this->runExec($cfg, $mode),
            'monitor' => $this->monitor($cfg, $mode),
            default => $this->fail2('Unknown action. Use: preflight | plan | status | qualify | run | monitor'),
        };
    }

    private function preflight(PolyExecConfig $cfg, string $mode): int
    {
        $gate = new PolyExecGate($cfg);
        $identity = PolyAccountIdentity::detect();
        $simScope = $mode === 'sim' ? $this->simScope() : null;
        $ledgerMode = $mode === 'sim' ? $this->simLedgerMode($simScope) : $mode;
        $runtime = $gate->checkRuntimeCaps($ledgerMode);
        $shortFailures = $this->liveShortReadinessFailures($cfg, $identity);

        $report = [
            'schema_version' => 'atlas.finance.poly_exec.preflight.v1',
            'mode' => $mode,
            'sim_scope' => $simScope,
            'sim_ledger_mode' => $mode === 'sim' ? $ledgerMode : null,
            'live_enabled' => $cfg->liveEnabled,
            'finance_policy' => [
                'live_trading_blocked' => FinanceDomainCanon::liveTradingBlocked(),
                'quality_gate' => 'no_live_execution',
            ],
            'kill_switch_engaged' => $cfg->killSwitchEngaged(),
            'kill_switch_path' => $cfg->killSwitchPath,
            'caps' => [
                'max_basket_usd' => $cfg->maxBasketUsd,
                'daily_cap_usd' => $cfg->dailyCapUsd,
                'max_concurrent_baskets' => $cfg->maxConcurrentBaskets,
                'min_depth_multiple' => $cfg->minDepthMultiple,
                'min_persistence_seconds' => $cfg->minPersistenceSeconds,
                'min_net_edge_per_set' => $cfg->minNetEdgePerSet,
                'max_resolution_hours' => $cfg->maxResolutionHours,
                'slippage_bps' => $cfg->slippageBps,
                'market_read_timeout_seconds' => $this->marketReadTimeout(),
                'candidate_time_budget_seconds' => $this->candidateTimeBudget(),
                'max_legs_per_candidate' => $this->maxLegsPerCandidate(),
                'max_signal_age_seconds' => $this->maxSignalAgeSeconds(),
            ],
            'short' => [
                'enabled' => $cfg->shortEnabled,
                'est_mint_gas_usd' => $cfg->estMintGasUsd,
                'est_merge_gas_usd' => $cfg->estMergeGasUsd,
                'merge_on_no_sell' => $cfg->shortMergeOnNoSell,
                'max_resolution_hours' => $cfg->shortMaxResolutionHours,
                // Live short minting needs an EOA holding USDC.e; a proxy/magic wallet
                // routes funds through a proxy contract and is fail-closed on-chain.
                'live_onchain_ready' => $mode === 'live' && $cfg->liveEnabled && $shortFailures === [],
                'live_onchain_blockers' => $mode === 'live' ? $shortFailures : [],
                'onchain_note' => $identity->kind() === PolyAccountIdentity::KIND_EOA
                    ? 'EOA: on-chain mint/merge path available (UNPROVEN until one minimal real mint)'
                    : 'proxy/unknown wallet: on-chain mint is fail-closed; short live needs an EOA with USDC.e',
            ],
            'long_realize_method' => $cfg->longRealizeMethod,
            'runtime_caps' => ['allowed' => $runtime->allowed, 'checks' => $runtime->checks],
            'deployed_today_usd' => $gate->deployedToday($ledgerMode),
            'account' => $identity->readiness(),
            'live_ready' => $mode === 'live'
                && ! FinanceDomainCanon::liveTradingBlocked()
                && $cfg->liveEnabled
                && $identity->readiness()['ready'],
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('=== Polymarket Executor — Preflight ('.$mode.') ===');
        $this->line(sprintf('live_enabled=%s  kill_switch=%s', $cfg->liveEnabled ? 'YES' : 'no',
            $cfg->killSwitchEngaged() ? 'ENGAGED' : 'clear'));
        $this->line(sprintf('caps: basket=$%.2f daily=$%.2f concurrent=%d depth>=%.1fx persist>=%ds edge>=%.4f resolve<=%.0fh slip=%dbps',
            $cfg->maxBasketUsd, $cfg->dailyCapUsd, $cfg->maxConcurrentBaskets, $cfg->minDepthMultiple,
            $cfg->minPersistenceSeconds, $cfg->minNetEdgePerSet, $cfg->maxResolutionHours, $cfg->slippageBps));
        $this->line(sprintf('readiness bounds: market_read_timeout=%ds candidate_budget=%ds max_legs=%d',
            $this->marketReadTimeout(), $this->candidateTimeBudget(), $this->maxLegsPerCandidate()));
        $this->line(sprintf('lifecycle freshness: max_signal_age=%ds', $this->maxSignalAgeSeconds()));
        if ($mode === 'sim' && $simScope !== null) {
            $this->line(sprintf('sim scope: %s ledger=%s', $simScope, $ledgerMode));
        }
        $this->line(sprintf('deployed today: $%.2f  runtime gate: %s', $gate->deployedToday($ledgerMode),
            $runtime->allowed ? 'OPEN' : 'BLOCKED ('.$runtime->blockingReasons().')'));
        $a = $identity->readiness();
        $this->line(sprintf('account: kind=%s sig_type=%d ready=%s missing=[%s]',
            $a['kind'], $a['signature_type'], $a['ready'] ? 'YES' : 'no', implode(',', $a['missing'])));
        if ($mode === 'live' && ! $report['live_ready']) {
            $this->warn('LIVE not ready — see runtimes/python/poly_exec/SETUP.md. Sim mode is unaffected.');
        }

        return self::SUCCESS;
    }

    private function plan(PolyExecConfig $cfg, string $mode): int
    {
        $kinds = $this->kindsFor();
        $candidates = $this->selectCandidates($cfg, $kinds);
        if ($candidates === []) {
            return $this->emit(['action' => 'plan', 'kinds' => $kinds, 'candidates' => 0, 'note' => 'no eligible opportunity in the lifecycle right now (honest: 0 is a valid result)']);
        }
        // Rank by value, like the allocator would.
        usort($candidates, fn (array $a, array $b) => ($b['rank_profit_usd'] ?? 0.0) <=> ($a['rank_profit_usd'] ?? 0.0));
        $candidates = array_slice($candidates, 0, $this->candidateLimit($this->option('event-slug') ? 1 : 8));

        $gate = new PolyExecGate($cfg);
        $bookSource = $this->realBookSource($this->marketReadTimeout());
        $eventMetaSource = $this->eventMetaSource($this->marketReadTimeout());
        $longPlanner = new BasketPlanner($cfg, $bookSource, $eventMetaSource);
        $shortPlanner = new ShortBasketPlanner($cfg, $bookSource, $eventMetaSource);
        $simScope = $mode === 'sim' ? $this->simScope() : null;
        $ledgerMode = $mode === 'sim' ? $this->simLedgerMode($simScope) : $mode;
        $remaining = max(0.0, $cfg->dailyCapUsd - $gate->deployedToday($ledgerMode));
        $deadlineAt = microtime(true) + $this->candidateTimeBudget();
        $budgetExhausted = false;

        $plans = [];
        foreach ($candidates as $c) {
            if ($this->candidateBudgetExceeded($deadlineAt)) {
                $budgetExhausted = true;
                break;
            }
            $plan = $c['kind'] === 'short_sum_over'
                ? $shortPlanner->plan($c['event_slug'], $c['legs'], $c['persistence_seconds'], $remaining)
                : $longPlanner->plan($c['event_slug'], $c['kind'], $c['legs'], $c['persistence_seconds'], $remaining);
            if ($plan === null) {
                $plans[] = ['event_slug' => $c['event_slug'], 'kind' => $c['kind'], 'planned' => false, 'reason' => 'no_executable_plan (legs unreadable / sum not crossing $1 / zero depth)'];

                continue;
            }
            $resolutionCeiling = $plan->kind === 'short_sum_over'
                ? $cfg->shortMaxResolutionHours
                : null;
            $opp = $gate->checkOpportunity($plan->toGateInput(), $ledgerMode, $resolutionCeiling);
            $plans[] = [
                'event_slug' => $plan->eventSlug,
                'kind' => $plan->kind,
                'planned' => true,
                'gate_allowed' => $opp->allowed,
                'gate_failed' => $opp->failedNames(),
                'plan' => $plan->toArray(),
            ];
        }

        return $this->emit([
            'action' => 'plan',
            'mode' => $mode,
            'kinds' => $kinds,
            'sim_scope' => $simScope,
            'sim_ledger_mode' => $mode === 'sim' ? $ledgerMode : null,
            'daily_remaining_usd' => round($remaining, 2),
            'candidate_time_budget_seconds' => $this->candidateTimeBudget(),
            'max_signal_age_seconds' => $this->maxSignalAgeSeconds(),
            'candidate_budget_exhausted' => $budgetExhausted,
            'plans' => $plans,
        ]);
    }

    private function status(string $mode): int
    {
        $dir = $this->monitorLogDir(create: false);
        $latest = $this->latestMonitorLogPath($dir);
        $safety = $this->monitorSafety();

        if ($latest === null) {
            return $this->emit([
                'action' => 'status',
                'mode' => $mode,
                'safety' => $safety,
                'monitor_log_dir' => $dir,
                'latest_log_path' => null,
                'note' => 'no monitor audit log found yet',
            ]);
        }

        $events = $this->readMonitorLog($latest);
        $summary = $this->lastMonitorEvent($events, 'summary');
        $cycle = $this->lastMonitorEvent($events, 'cycle');
        $start = $this->lastMonitorEvent($events, 'start');

        $payload = [
            'action' => 'status',
            'mode' => $mode,
            'safety' => $safety,
            'monitor_log_dir' => $dir,
            'latest_log_path' => $latest,
            'latest_session_id' => (string) ($summary['session_id'] ?? $cycle['session_id'] ?? $start['session_id'] ?? ''),
            'latest_event_at' => (string) ($summary['created_at'] ?? $cycle['created_at'] ?? $start['created_at'] ?? ''),
            'cycles_recorded' => count(array_filter($events, fn (array $event): bool => ($event['event'] ?? null) === 'cycle')),
            'latest_cycle' => $cycle,
            'latest_summary' => $summary,
        ];

        if (! $this->option('json')) {
            $statuses = is_array($cycle['statuses'] ?? null) ? json_encode($cycle['statuses']) : '{}';
            $this->line(sprintf('[poly-exec] status log=%s session=%s cycles=%d latest_cycle=%s executed_total=%s statuses=%s safety=shadow',
                $latest,
                $payload['latest_session_id'] !== '' ? $payload['latest_session_id'] : '-',
                $payload['cycles_recorded'],
                (string) ($cycle['cycle'] ?? '-'),
                (string) ($summary['executed_total'] ?? '-'),
                $statuses ?: '{}',
            ));
        }

        return $this->emit($payload);
    }

    private function qualify(PolyExecConfig $cfg, string $mode): int
    {
        $dir = $this->monitorLogDir(create: false);
        $lookback = max(1, min(200, (int) $this->option('qualification-lookback-logs')));
        $minCycles = max(1, (int) $this->option('qualification-min-cycles'));
        $minExecuted = max(0, (int) $this->option('qualification-min-executed'));
        $maxSlowRatio = max(0.0, min(1.0, (float) $this->option('qualification-max-slow-ratio')));
        $paths = $this->monitorLogPaths($dir, $lookback);
        $observed = $this->aggregateMonitorLogs($paths);

        $gate = new PolyExecGate($cfg);
        $identity = PolyAccountIdentity::detect();
        $account = $identity->readiness();
        $liveRuntime = $gate->checkRuntimeCaps('live');
        $shortFailures = $this->liveShortReadinessFailures($cfg, $identity);
        $needsShort = in_array('short_sum_over', $this->kindsFor(), true);
        $slowRatio = $observed['cycles_observed'] > 0
            ? round($observed['slow_cycles'] / $observed['cycles_observed'], 4)
            : 1.0;

        $checks = [
            [
                'name' => 'monitor_logs_present',
                'ok' => $observed['logs_considered'] > 0,
                'reason' => $observed['logs_considered'] > 0
                    ? 'monitor JSONL found'
                    : 'no monitor JSONL found; run monitor first',
            ],
            [
                'name' => 'shadow_cycles_min',
                'ok' => $observed['cycles_observed'] >= $minCycles,
                'reason' => sprintf('observed=%d required>=%d', $observed['cycles_observed'], $minCycles),
            ],
            [
                'name' => 'shadow_executed_min',
                'ok' => $observed['executed_total'] >= $minExecuted,
                'reason' => sprintf('observed=%d required>=%d', $observed['executed_total'], $minExecuted),
            ],
            [
                'name' => 'shadow_settled_min',
                'ok' => (int) ($observed['settled_total'] ?? 0) >= $minExecuted,
                'reason' => sprintf('settled=%d required>=%d',
                    (int) ($observed['settled_total'] ?? 0),
                    $minExecuted,
                ),
            ],
            [
                'name' => 'shadow_unsafe_terminal_absent',
                'ok' => (int) ($observed['unsafe_terminal_total'] ?? 0) === 0,
                'reason' => sprintf('unsafe_terminal_total=%d statuses=%s',
                    (int) ($observed['unsafe_terminal_total'] ?? 0),
                    json_encode($observed['unsafe_terminal_statuses'] ?? [], JSON_UNESCAPED_SLASHES) ?: '{}',
                ),
            ],
            [
                'name' => 'slow_cycle_ratio',
                'ok' => $observed['cycles_observed'] > 0 && $slowRatio <= $maxSlowRatio,
                'reason' => sprintf('observed=%.4f max=%.4f slow=%d cycles=%d',
                    $slowRatio, $maxSlowRatio, $observed['slow_cycles'], $observed['cycles_observed']),
            ],
            [
                'name' => 'scan_before_cycle_evidence',
                'ok' => $observed['scan_cycles'] > 0,
                'reason' => sprintf('observed=%d skipped=%d', $observed['scan_cycles'], $observed['scan_skipped_cycles']),
            ],
            [
                'name' => 'scan_budget_exhaustion',
                'ok' => $observed['scan_cycles'] > 0 && $observed['scan_budget_exhausted_cycles'] === 0,
                'reason' => sprintf('exhausted=%d scan_cycles=%d verified=%d signals=%d',
                    $observed['scan_budget_exhausted_cycles'],
                    $observed['scan_cycles'],
                    $observed['scan_verified'],
                    $observed['scan_signals'],
                ),
            ],
            [
                'name' => 'scan_coverage_floor',
                'ok' => $observed['scan_cycles'] > 0
                    && ((int) ($observed['scan_expected_events'] ?? 0) === 0
                        || (int) ($observed['scan_undercovered_cycles'] ?? 0) === 0),
                'reason' => sprintf('undercovered=%d scan_cycles=%d min_scanned=%s expected=%d floor=%d',
                    (int) ($observed['scan_undercovered_cycles'] ?? 0),
                    (int) $observed['scan_cycles'],
                    $observed['scan_min_scanned'] === null ? 'n/a' : (string) $observed['scan_min_scanned'],
                    (int) ($observed['scan_expected_events'] ?? 0),
                    (int) ceil(((int) ($observed['scan_expected_events'] ?? 0)) * 0.8),
                ),
            ],
            [
                'name' => 'finance_policy_allows_live',
                'ok' => ! FinanceDomainCanon::liveTradingBlocked(),
                'reason' => FinanceDomainCanon::liveTradingBlocked()
                    ? 'Atlas Finance canonical policy blocks live market execution'
                    : 'Atlas Finance canonical policy allows live market execution',
            ],
            [
                'name' => 'live_flag_enabled',
                'ok' => $cfg->liveEnabled,
                'reason' => $cfg->liveEnabled ? 'live flag enabled' : 'ATLAS_POLY_EXEC_LIVE_ENABLED is false',
            ],
            [
                'name' => 'account_ready',
                'ok' => (bool) ($account['ready'] ?? false),
                'reason' => (bool) ($account['ready'] ?? false)
                    ? 'account credentials present'
                    : 'missing ['.implode(',', array_map('strval', $account['missing'] ?? [])).']',
            ],
            [
                'name' => 'runtime_caps_live',
                'ok' => $liveRuntime->allowed,
                'reason' => $liveRuntime->allowed ? 'live runtime caps open' : $liveRuntime->blockingReasons(),
            ],
            [
                'name' => 'short_onchain_ready_if_needed',
                'ok' => ! $needsShort || $shortFailures === [],
                'reason' => ! $needsShort
                    ? 'short side not requested'
                    : ($shortFailures === [] ? 'short on-chain path ready' : implode(',', $shortFailures)),
            ],
        ];
        $failed = array_values(array_map(
            fn (array $check): string => (string) $check['name'],
            array_filter($checks, fn (array $check): bool => ! (bool) ($check['ok'] ?? false)),
        ));
        $qualified = $failed === [];

        $payload = [
            'action' => 'qualify',
            'mode_requested' => $mode,
            'kinds' => $this->kindsFor(),
            'decision' => $qualified ? 'qualified' : 'not_qualified',
            'qualified_for_real_money' => $qualified,
            'real_money_test_possible_now' => $qualified,
            'safety' => $this->monitorSafety(),
            'thresholds' => [
                'min_cycles' => $minCycles,
                'min_executed' => $minExecuted,
                'max_slow_ratio' => $maxSlowRatio,
                'lookback_logs' => $lookback,
            ],
            'monitor_log_dir' => $dir,
            'observed' => $observed + ['slow_ratio' => $slowRatio],
            'live_preflight' => [
                'finance_policy_live_blocked' => FinanceDomainCanon::liveTradingBlocked(),
                'live_enabled' => $cfg->liveEnabled,
                'account_ready' => (bool) ($account['ready'] ?? false),
                'account_missing' => array_values(array_map('strval', $account['missing'] ?? [])),
                'runtime_allowed' => $liveRuntime->allowed,
                'runtime_checks' => $liveRuntime->checks,
                'short_live_blockers' => $needsShort ? $shortFailures : [],
            ],
            'checks' => $checks,
            'failed_checks' => $failed,
            'next_required_evidence' => $this->qualificationNextEvidence($failed, $minCycles, $minExecuted),
        ];

        if (! $this->option('json')) {
            $line = sprintf('[poly-exec] qualify decision=%s cycles=%d executed=%d slow_ratio=%.4f failed=[%s]',
                $payload['decision'],
                $observed['cycles_observed'],
                $observed['executed_total'],
                $slowRatio,
                implode(',', $failed),
            );
            $qualified ? $this->info($line) : $this->warn($line);
        }

        return $this->emit($payload);
    }

    private function runExec(PolyExecConfig $cfg, string $mode): int
    {
        $gate = new PolyExecGate($cfg);
        $kinds = $this->kindsFor();

        // Live is triple-gated: flag + --confirm + a fully resolvable account.
        if ($mode === 'live') {
            if (FinanceDomainCanon::liveTradingBlocked()) {
                return $this->fail2('LIVE refused: Atlas Finance policy blocks live market execution; run shadow/sim only.');
            }
            if (! $cfg->liveEnabled) {
                return $this->fail2('LIVE refused: ATLAS_POLY_EXEC_LIVE_ENABLED is false.');
            }
            if (! $this->option('confirm')) {
                return $this->fail2('LIVE refused: pass --confirm after explicit Finance live-policy approval. (Default mode=sim signs nothing.)');
            }
            $identity = PolyAccountIdentity::detect();
            if (! $identity->readiness()['ready']) {
                return $this->fail2('LIVE refused: account not ready — missing ['.implode(',', $identity->readiness()['missing']).']. See runtimes/python/poly_exec/SETUP.md');
            }
            if (in_array('short_sum_over', $kinds, true)) {
                $shortFailures = $this->liveShortReadinessFailures($cfg, $identity);
                if ($shortFailures !== []) {
                    return $this->fail2('LIVE refused: short execution is not on-chain ready — '.implode(',', $shortFailures).'. Use --kind=long or stay in --mode=sim.');
                }
            }
        }

        $bookSource = $this->realBookSource($this->marketReadTimeout());
        $eventMetaSource = $this->eventMetaSource($this->marketReadTimeout());
        $simScope = $mode === 'sim' ? $this->simScope() : null;
        $simLedgerMode = $mode === 'sim' ? $this->simLedgerMode($simScope) : null;
        [$exec, $onChain] = $this->makeClients($cfg, $mode, $bookSource, $simLedgerMode);
        $allocator = new ArbAllocator(
            $cfg,
            $gate,
            new BasketPlanner($cfg, $bookSource, $eventMetaSource),
            new ShortBasketPlanner($cfg, $bookSource, $eventMetaSource),
            new BasketStateMachine($cfg, $exec, $gate, $bookSource, null, $onChain),
            new MintSellStateMachine($cfg, $exec, $onChain, $gate, $bookSource),
            $simScope,
        );
        $sessionId = (string) Str::ulid();

        $candidates = $this->selectCandidates($cfg, $kinds, $this->candidateLimit(200));

        $this->info(sprintf('[poly-exec] session=%s mode=%s kinds=%s candidates=%d %s',
            $sessionId, $mode, implode('+', $kinds), count($candidates),
            $mode === 'sim' ? '(SIM — real books, no signing/minting)' : '(LIVE — policy-open venue path)'));

        $allocatorMode = $mode === 'sim' ? (string) $simLedgerMode : $mode;
        $out = $allocator->allocate($allocatorMode, $sessionId, $candidates, null, microtime(true) + $this->candidateTimeBudget());

        foreach ($out['results'] as $summary) {
            $this->line(sprintf('[poly-exec] %s %s %s -> %s pnl=$%.2f%s',
                $summary['kind'] ?? '?', $summary['basket_id'] ?? '?', $summary['event_slug'] ?? '?',
                $summary['status'] ?? '?', (float) ($summary['realized_pnl_usd'] ?? 0),
                ($summary['pnl_is_locked_at_resolution'] ?? false) ? ' (locked@resolution)' : ''));
        }

        return $this->emit([
            'action' => 'run', 'mode' => $mode, 'kinds' => $kinds, 'session_id' => $sessionId,
            'sim_scope' => $simScope,
            'sim_ledger_mode' => $simLedgerMode,
            'processed' => $out['processed'] ?? count($out['results']),
            'dispatched' => $out['dispatched'],
            'executed' => $this->executedCount($out['results']),
            'blocked' => $out['blocked'],
            'results' => $out['results'],
        ]);
    }

    private function monitor(PolyExecConfig $cfg, string $mode): int
    {
        if ($mode !== 'sim') {
            return $this->fail2('MONITOR refused: monitor is sim-only; use preflight/plan for live readiness.');
        }

        $bookSource = $this->realBookSource($this->marketReadTimeout());
        $eventMetaSource = $this->eventMetaSource($this->marketReadTimeout());
        $simScope = $this->simScope();
        $simLedgerMode = $this->simLedgerMode($simScope);
        [$exec, $onChain] = $this->makeClients($cfg, 'sim', $bookSource, $simLedgerMode);
        $gate = new PolyExecGate($cfg);
        $allocator = new ArbAllocator(
            $cfg,
            $gate,
            new BasketPlanner($cfg, $bookSource, $eventMetaSource),
            new ShortBasketPlanner($cfg, $bookSource, $eventMetaSource),
            new BasketStateMachine($cfg, $exec, $gate, $bookSource, null, $onChain),
            new MintSellStateMachine($cfg, $exec, $onChain, $gate, $bookSource),
            $simScope,
        );

        $sessionId = (string) Str::ulid();
        $kinds = $this->kindsFor();
        $cycles = max(1, min(100, (int) $this->option('cycles')));
        $interval = max(0, min(3600, (int) $this->option('interval')));
        $maxCandidates = $this->candidateLimit(8);
        $slowCycleSeconds = max(1, min(3600, (int) $this->option('slow-cycle-seconds')));
        $marketReadTimeout = $this->marketReadTimeout();
        $candidateTimeBudget = $this->candidateTimeBudget();
        $maxSignalAgeSeconds = $this->maxSignalAgeSeconds();
        $scanBeforeCycle = (bool) $this->option('scan-before-cycle');
        $scanOptions = $this->monitorScanOptions();
        $safety = $this->monitorSafety();
        $logPath = $this->monitorLogPath($sessionId);
        $cycleReports = [];

        $this->info(sprintf('[poly-exec] monitor=%s mode=sim cycles=%d interval=%ds max_candidates=%d slow_cycle>%ds market_read_timeout=%ds candidate_budget=%ds max_signal_age=%ds scan_before_cycle=%s (real books, no signing/minting)',
            $sessionId, $cycles, $interval, $maxCandidates, $slowCycleSeconds, $marketReadTimeout, $candidateTimeBudget, $maxSignalAgeSeconds, YesNo::format($scanBeforeCycle)));
        $this->line('[poly-exec] monitor audit log: '.$logPath);
        $this->appendMonitorLog($logPath, [
            'event' => 'start',
            'session_id' => $sessionId,
            'mode' => 'sim',
            'sim_scope' => $simScope,
            'sim_ledger_mode' => $simLedgerMode,
            'kinds' => $kinds,
            'cycles_requested' => $cycles,
            'interval_seconds' => $interval,
            'max_candidates' => $maxCandidates,
            'slow_cycle_seconds' => $slowCycleSeconds,
            'market_read_timeout_seconds' => $marketReadTimeout,
            'candidate_time_budget_seconds' => $candidateTimeBudget,
            'max_signal_age_seconds' => $maxSignalAgeSeconds,
            'scan_before_cycle' => $scanBeforeCycle,
            'scan_options' => $scanOptions,
            'safety' => $safety,
            'created_at' => now()->toIso8601String(),
        ]);

        for ($cycle = 1; $cycle <= $cycles; $cycle++) {
            $cycleStarted = microtime(true);
            $scanReport = null;
            if ($scanBeforeCycle) {
                $scanReport = $this->runMonitorScanCycle($sessionId, $cycle, $marketReadTimeout, $scanOptions);
                $this->appendMonitorLog($logPath, $scanReport + [
                    'event' => 'scan',
                    'session_id' => $sessionId,
                    'cycle' => $cycle,
                    'created_at' => now()->toIso8601String(),
                ]);
                $this->line(sprintf('[poly-exec] monitor scan#%d scanned=%d eligible=%d shortlisted=%d verified=%d signals=%d budget_exhausted=%s skipped_too_many_legs=%d (%.2fs)%s',
                    $cycle,
                    (int) ($scanReport['scanned'] ?? 0),
                    (int) ($scanReport['eligible'] ?? 0),
                    (int) ($scanReport['shortlisted'] ?? 0),
                    (int) ($scanReport['verified'] ?? 0),
                    (int) ($scanReport['signals'] ?? 0),
                    YesNo::format((bool) ($scanReport['budget_exhausted'] ?? false)),
                    (int) ($scanReport['skipped_too_many_legs'] ?? 0),
                    (float) ($scanReport['duration_seconds'] ?? 0.0),
                    ($scanReport['skipped'] ?? false) ? ' SKIPPED: '.(string) ($scanReport['skip_reason'] ?? 'unknown') : ''
                ));
            }

            $candidates = $this->selectCandidates($cfg, $kinds, $maxCandidates);
            $out = $allocator->allocate($simLedgerMode, $sessionId.'-'.$cycle, $candidates, $maxCandidates, microtime(true) + $candidateTimeBudget);
            $freshResults = array_values(array_filter(
                $out['results'],
                fn (array $result): bool => ! (bool) ($result['idempotent_replay'] ?? false),
            ));
            $replayResults = array_values(array_filter(
                $out['results'],
                fn (array $result): bool => (bool) ($result['idempotent_replay'] ?? false),
            ));
            $statuses = array_count_values(array_map(
                fn (array $result): string => (string) ($result['status'] ?? 'unknown'),
                $freshResults,
            ));
            $replayStatuses = array_count_values(array_map(
                fn (array $result): string => (string) ($result['status'] ?? 'unknown'),
                $replayResults,
            ));
            $resultSamples = $this->monitorResultSamples($out['results']);
            $report = [
                'cycle' => $cycle,
                'candidates' => count($candidates),
                'processed' => $out['processed'] ?? count($out['results']),
                'dispatched' => $out['dispatched'],
                'executed' => $this->executedCount($out['results']),
                'idempotent_replays' => count($replayResults),
                'idempotent_replay_statuses' => $replayStatuses,
                'blocked' => $out['blocked'],
                'statuses' => $statuses,
                'reason_counts' => $this->monitorReasonCounts($resultSamples),
                'result_samples' => $resultSamples,
                'scan' => $scanReport,
                'duration_seconds' => round(microtime(true) - $cycleStarted, 2),
            ];
            $report['slow'] = $report['duration_seconds'] > $slowCycleSeconds;
            $cycleReports[] = $report;
            $this->appendMonitorLog($logPath, $report + [
                'event' => 'cycle',
                'session_id' => $sessionId,
                'created_at' => now()->toIso8601String(),
            ]);
            $this->line(sprintf('[poly-exec] monitor cycle#%d candidates=%d processed=%d dispatched=%d executed=%d replays=%d statuses=%s replay_statuses=%s reasons=%s (%.2fs)%s',
                $report['cycle'], $report['candidates'], $report['processed'], $report['dispatched'],
                $report['executed'], $report['idempotent_replays'], json_encode($report['statuses']), json_encode($report['idempotent_replay_statuses']),
                json_encode($report['reason_counts']), $report['duration_seconds'],
                $report['slow'] ? ' SLOW' : ''));

            if ($cycle < $cycles && $interval > 0) {
                sleep($interval);
            }
        }

        $summary = [
            'action' => 'monitor',
            'mode' => 'sim',
            'safety' => $safety,
            'kinds' => $kinds,
            'session_id' => $sessionId,
            'sim_scope' => $simScope,
            'sim_ledger_mode' => $simLedgerMode,
            'monitor_log_path' => $logPath,
            'cycles_requested' => $cycles,
            'interval_seconds' => $interval,
            'max_candidates' => $maxCandidates,
            'slow_cycle_seconds' => $slowCycleSeconds,
            'market_read_timeout_seconds' => $marketReadTimeout,
            'candidate_time_budget_seconds' => $candidateTimeBudget,
            'max_signal_age_seconds' => $maxSignalAgeSeconds,
            'scan_before_cycle' => $scanBeforeCycle,
            'scan_options' => $scanOptions,
            'cycles' => $cycleReports,
            'slow_cycles' => count(array_filter($cycleReports, fn (array $report): bool => (bool) ($report['slow'] ?? false))),
            'executed_total' => array_sum(array_column($cycleReports, 'executed')),
            'idempotent_replays_total' => array_sum(array_column($cycleReports, 'idempotent_replays')),
        ];
        $this->appendMonitorLog($logPath, $summary + [
            'event' => 'summary',
            'created_at' => now()->toIso8601String(),
        ]);

        return $this->emit($summary);
    }

    /**
     * @return array{pages: int, per_page: int, time_budget_seconds: int, min_profit_per_set: float, max_clob_verifications: int}
     */
    private function monitorScanOptions(): array
    {
        $arbConfig = (array) config('atlas.finance_poly_arb', []);
        $maxVerify = $this->intOpt('scan-max-clob-verifications');

        return [
            'pages' => max(1, min(50, (int) $this->option('scan-pages'))),
            'per_page' => max(10, min(100, (int) $this->option('scan-per-page'))),
            'time_budget_seconds' => max(1, min(900, (int) $this->option('scan-time-budget'))),
            'min_profit_per_set' => max(0.0, (float) $this->option('scan-min-profit')),
            'max_clob_verifications' => max(1, min(200, (int) ($maxVerify ?? ($arbConfig['max_clob_verifications'] ?? 12)))),
        ];
    }

    /**
     * @param  array{pages: int, per_page: int, time_budget_seconds: int, min_profit_per_set: float, max_clob_verifications: int}  $scanOptions
     * @return array<string, mixed>
     */
    private function runMonitorScanCycle(string $sessionId, int $cycle, int $marketReadTimeout, array $scanOptions): array
    {
        $started = microtime(true);
        if (! $this->polyArbLifecycleTablesReady()) {
            return [
                'skipped' => true,
                'skip_reason' => 'poly_arb_lifecycle_tables_missing',
                'scanned' => 0,
                'eligible' => 0,
                'shortlisted' => 0,
                'verified' => 0,
                'signals' => 0,
                'budget_exhausted' => false,
                'skipped_too_many_legs' => 0,
                'duration_seconds' => round(microtime(true) - $started, 2),
            ];
        }

        $arbConfig = (array) config('atlas.finance_poly_arb', []);
        $scanner = new PolymarketArbScanner;
        $scanSessionId = $sessionId.'-scan-'.$cycle;

        $result = $scanner->scanOnce(
            pages: $scanOptions['pages'],
            perPage: $scanOptions['per_page'],
            preFilterMargin: (float) ($arbConfig['pre_filter_margin'] ?? 0.02),
            minProfitPerSet: $scanOptions['min_profit_per_set'],
            feePerSet: (float) ($arbConfig['fee_per_set'] ?? 0.0),
            maxClobVerifications: $scanOptions['max_clob_verifications'],
            marketReadTimeoutSeconds: $marketReadTimeout,
            scanTimeBudgetSeconds: $scanOptions['time_budget_seconds'],
            maxLegsPerCandidate: $this->maxLegsPerCandidate(),
            onSignal: fn (array $signal) => $this->recordPolyArbSignal($scanSessionId, $signal),
        );

        $this->recordPolyArbScan($scanSessionId, $result, recordSignals: false);

        return [
            'skipped' => false,
            'scan_session_id' => $scanSessionId,
            'scanned' => (int) $result['scanned_events'],
            'eligible' => (int) $result['eligible_events'],
            'shortlisted' => (int) $result['shortlisted'],
            'verified' => (int) $result['verified'],
            'signals' => count($result['signals']),
            'budget_exhausted' => (bool) ($result['budget_exhausted'] ?? false),
            'skipped_too_many_legs' => (int) ($result['skipped_too_many_legs'] ?? 0),
            'best_long_sum' => $result['best_long_sum'],
            'best_short_sum' => $result['best_short_sum'],
            'duration_seconds' => round(microtime(true) - $started, 2),
        ];
    }

    private function polyArbLifecycleTablesReady(): bool
    {
        $schema = DB::getSchemaBuilder();

        return $schema->hasTable('atlas_poly_arb_scans')
            && $schema->hasTable('atlas_poly_arb_signals')
            && $schema->hasTable('atlas_poly_arb_opportunities')
            && $schema->hasColumn('atlas_poly_arb_opportunities', 'volume_24hr')
            && $schema->hasColumn('atlas_poly_arb_opportunities', 'dead_book');
    }

    /**
     * @param  array{
     *     scanned_events: int, eligible_events: int, shortlisted: int, verified: int,
     *     signals: list<array<string, mixed>>, best_long_sum: float|null, best_short_sum: float|null
     * }  $result
     */
    private function recordPolyArbScan(string $scanSessionId, array $result, bool $recordSignals = true): void
    {
        DB::table('atlas_poly_arb_scans')->insert([
            'session_id' => $scanSessionId,
            'scanned_events' => $result['scanned_events'],
            'eligible_events' => $result['eligible_events'],
            'shortlisted' => $result['shortlisted'],
            'verified' => $result['verified'],
            'signals_found' => count($result['signals']),
            'best_long_sum' => $result['best_long_sum'],
            'best_short_sum' => $result['best_short_sum'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! $recordSignals) {
            return;
        }

        foreach ($result['signals'] as $signal) {
            $this->recordPolyArbSignal($scanSessionId, $signal);
        }
    }

    /**
     * @param  array<string, mixed>  $signal
     */
    private function recordPolyArbSignal(string $scanSessionId, array $signal): void
    {
        $this->upsertPolyArbOpportunity($signal);
        DB::table('atlas_poly_arb_signals')->insert([
            'session_id' => $scanSessionId,
            'event_slug' => $signal['event_slug'],
            'event_title' => mb_substr((string) $signal['event_title'], 0, 300),
            'kind' => $signal['kind'],
            'execution_class' => $signal['execution_class'],
            'n_legs' => $signal['n_legs'],
            'sum' => $signal['sum'],
            'profit_per_set' => $signal['profit_per_set'],
            'sets' => $signal['sets'],
            'profit_usd' => $signal['profit_usd'],
            'cost_usd' => $signal['cost_usd'],
            'legs' => json_encode($signal['legs']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $signal
     */
    private function upsertPolyArbOpportunity(array $signal): void
    {
        $volume = isset($signal['volume_24hr']) ? (float) $signal['volume_24hr'] : null;
        $activity = [
            'volume_24hr' => $volume,
            'liquidity' => $signal['liquidity'] ?? null,
            'dead_book' => $volume === null
                || $volume < (float) config('atlas.finance_poly_arb.min_volume_24hr', 50.0),
        ];

        $existing = DB::table('atlas_poly_arb_opportunities')
            ->where('event_slug', $signal['event_slug'])
            ->where('kind', $signal['kind'])
            ->first();

        if ($existing === null) {
            DB::table('atlas_poly_arb_opportunities')->insert($activity + [
                'event_slug' => $signal['event_slug'],
                'kind' => $signal['kind'],
                'event_title' => mb_substr((string) $signal['event_title'], 0, 300),
                'execution_class' => $signal['execution_class'],
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'observations' => 1,
                'last_sum' => $signal['sum'],
                'last_profit_per_set' => $signal['profit_per_set'],
                'last_sets' => $signal['sets'],
                'last_profit_usd' => $signal['profit_usd'],
                'max_sets' => $signal['sets'],
                'max_profit_usd' => $signal['profit_usd'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('atlas_poly_arb_opportunities')->where('id', $existing->id)->update($activity + [
            'last_seen_at' => now(),
            'observations' => (int) $existing->observations + 1,
            'last_sum' => $signal['sum'],
            'last_profit_per_set' => $signal['profit_per_set'],
            'last_sets' => $signal['sets'],
            'last_profit_usd' => $signal['profit_usd'],
            'max_sets' => max((float) $existing->max_sets, (float) $signal['sets']),
            'max_profit_usd' => max((float) $existing->max_profit_usd, (float) $signal['profit_usd']),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{shadow_only: true, real_money_touched: false, signing: false, minting: false, live_policy_blocked: bool}
     */
    private function monitorSafety(): array
    {
        return [
            'shadow_only' => true,
            'real_money_touched' => false,
            'signing' => false,
            'minting' => false,
            'live_policy_blocked' => FinanceDomainCanon::liveTradingBlocked(),
        ];
    }

    private function simScope(): ?string
    {
        $raw = trim((string) ($this->option('sim-scope') ?? ''));
        if ($raw === '') {
            return null;
        }

        $scope = preg_replace('/[^A-Za-z0-9._:-]+/', '-', $raw) ?? '';
        $scope = trim($scope, '-._:');
        if ($scope === '') {
            return null;
        }

        return mb_substr($scope, 0, 80);
    }

    private function simLedgerMode(?string $simScope): string
    {
        if ($simScope === null || $simScope === '') {
            return 'sim';
        }

        return 's'.substr(hash('sha256', $simScope), 0, 7);
    }

    private function monitorLogPath(string $sessionId): string
    {
        return $this->monitorLogDir(create: true).DIRECTORY_SEPARATOR.$sessionId.'.jsonl';
    }

    private function monitorLogDir(bool $create): string
    {
        $dir = trim((string) ($this->option('monitor-log-dir') ?? ''));
        if ($dir === '') {
            $dir = storage_path('app/atlas-finance/poly-exec-monitor');
        }
        $dir = rtrim($dir, DIRECTORY_SEPARATOR);
        if ($create && ! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Unable to create monitor log directory: '.$dir);
        }

        return $dir;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function appendMonitorLog(string $path, array $payload): void
    {
        $encoded = json_encode($payload + ['schema_version' => 'atlas.finance.poly_exec.monitor_log.v1'], JSON_UNESCAPED_SLASHES);
        if ($encoded === false || file_put_contents($path, $encoded.PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Unable to append monitor log: '.$path);
        }
    }

    private function latestMonitorLogPath(string $dir): ?string
    {
        if (! is_dir($dir)) {
            return null;
        }

        $paths = glob($dir.DIRECTORY_SEPARATOR.'*.jsonl') ?: [];
        if ($paths === []) {
            return null;
        }
        usort($paths, static fn (string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));

        return $paths[0];
    }

    /**
     * @return list<string>
     */
    private function monitorLogPaths(string $dir, int $limit): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $paths = glob($dir.DIRECTORY_SEPARATOR.'*.jsonl') ?: [];
        usort($paths, static fn (string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));

        return array_values(array_slice($paths, 0, max(1, $limit)));
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    private function aggregateMonitorLogs(array $paths): array
    {
        $durations = [];
        $scanDurations = [];
        $aggregate = [
            'logs_considered' => count($paths),
            'latest_log_path' => $paths[0] ?? null,
            'sessions' => [],
            'sim_scopes' => [],
            'sim_ledger_modes' => [],
            'cycles_observed' => 0,
            'candidates' => 0,
            'processed' => 0,
            'dispatched' => 0,
            'executed_total' => 0,
            'idempotent_replays' => 0,
            'blocked' => 0,
            'slow_cycles' => 0,
            'scan_cycles' => 0,
            'scan_skipped_cycles' => 0,
            'scan_budget_exhausted_cycles' => 0,
            'scan_expected_events' => 0,
            'scan_scanned' => 0,
            'scan_min_scanned' => null,
            'scan_undercovered_cycles' => 0,
            'scan_undercoverage_samples' => [],
            'scan_verified' => 0,
            'scan_signals' => 0,
            'statuses' => [],
            'idempotent_replay_statuses' => [],
            'reason_counts' => [],
        ];

        foreach ($paths as $path) {
            $events = $this->readMonitorLog($path);
            $summary = $this->lastMonitorEvent($events, 'summary');
            $start = $this->lastMonitorEvent($events, 'start');
            $simScope = (string) ($summary['sim_scope'] ?? $start['sim_scope'] ?? '');
            $simLedgerMode = (string) ($summary['sim_ledger_mode'] ?? $start['sim_ledger_mode'] ?? '');
            if ($simScope !== '') {
                $aggregate['sim_scopes'][] = $simScope;
            }
            if ($simLedgerMode !== '') {
                $aggregate['sim_ledger_modes'][] = $simLedgerMode;
            }
            $scanExpectedEvents = $this->scanExpectedEvents($start);
            if ($scanExpectedEvents > 0) {
                $aggregate['scan_expected_events'] = max((int) $aggregate['scan_expected_events'], $scanExpectedEvents);
            }
            $cycles = array_values(array_filter(
                $events,
                fn (array $event): bool => ($event['event'] ?? null) === 'cycle',
            ));
            $aggregate['sessions'][] = [
                'session_id' => (string) ($summary['session_id'] ?? $start['session_id'] ?? ''),
                'log_path' => $path,
                'sim_scope' => $simScope !== '' ? $simScope : null,
                'sim_ledger_mode' => $simLedgerMode !== '' ? $simLedgerMode : null,
                'cycles_recorded' => count($cycles),
                'executed_total' => (int) ($summary['executed_total'] ?? array_sum(array_map(
                    fn (array $cycle): int => (int) ($cycle['executed'] ?? 0),
                    $cycles,
                ))),
                'created_at' => (string) ($summary['created_at'] ?? $start['created_at'] ?? ''),
            ];

            foreach ($cycles as $cycle) {
                $aggregate['cycles_observed']++;
                $aggregate['candidates'] += (int) ($cycle['candidates'] ?? 0);
                $aggregate['processed'] += (int) ($cycle['processed'] ?? 0);
                $aggregate['dispatched'] += (int) ($cycle['dispatched'] ?? 0);
                $aggregate['executed_total'] += (int) ($cycle['executed'] ?? 0);
                $aggregate['idempotent_replays'] += (int) ($cycle['idempotent_replays'] ?? 0);
                $aggregate['blocked'] += (int) ($cycle['blocked'] ?? 0);
                if ((bool) ($cycle['slow'] ?? false)) {
                    $aggregate['slow_cycles']++;
                }
                if (isset($cycle['duration_seconds']) && is_numeric($cycle['duration_seconds'])) {
                    $durations[] = (float) $cycle['duration_seconds'];
                }
                $scan = $cycle['scan'] ?? null;
                if (is_array($scan)) {
                    if ((bool) ($scan['skipped'] ?? false)) {
                        $aggregate['scan_skipped_cycles']++;
                    } else {
                        $aggregate['scan_cycles']++;
                        $scanned = (int) ($scan['scanned'] ?? 0);
                        $aggregate['scan_scanned'] += $scanned;
                        $aggregate['scan_min_scanned'] = $aggregate['scan_min_scanned'] === null
                            ? $scanned
                            : min((int) $aggregate['scan_min_scanned'], $scanned);
                        if ($scanExpectedEvents > 0 && $scanned < (int) ceil($scanExpectedEvents * 0.8)) {
                            $aggregate['scan_undercovered_cycles']++;
                            if (count($aggregate['scan_undercoverage_samples']) < 5) {
                                $aggregate['scan_undercoverage_samples'][] = [
                                    'session_id' => (string) ($cycle['session_id'] ?? $start['session_id'] ?? ''),
                                    'cycle' => (int) ($cycle['cycle'] ?? 0),
                                    'scanned' => $scanned,
                                    'expected' => $scanExpectedEvents,
                                    'floor' => (int) ceil($scanExpectedEvents * 0.8),
                                ];
                            }
                        }
                        $aggregate['scan_verified'] += (int) ($scan['verified'] ?? 0);
                        $aggregate['scan_signals'] += (int) ($scan['signals'] ?? 0);
                        if ((bool) ($scan['budget_exhausted'] ?? false)) {
                            $aggregate['scan_budget_exhausted_cycles']++;
                        }
                        if (isset($scan['duration_seconds']) && is_numeric($scan['duration_seconds'])) {
                            $scanDurations[] = (float) $scan['duration_seconds'];
                        }
                    }
                }
                $this->addCounterMap($aggregate['statuses'], $cycle['statuses'] ?? []);
                $this->addCounterMap($aggregate['idempotent_replay_statuses'], $cycle['idempotent_replay_statuses'] ?? []);
                $this->addCounterMap($aggregate['reason_counts'], $cycle['reason_counts'] ?? []);
            }
        }
        $aggregate['sim_scopes'] = array_values(array_unique(array_map('strval', $aggregate['sim_scopes'])));
        $aggregate['sim_ledger_modes'] = array_values(array_unique(array_map('strval', $aggregate['sim_ledger_modes'])));
        ksort($aggregate['statuses']);
        ksort($aggregate['idempotent_replay_statuses']);
        ksort($aggregate['reason_counts']);
        $unsafeTerminalStatuses = [];
        foreach (['unwound', 'failed', 'halted'] as $status) {
            $count = (int) ($aggregate['statuses'][$status] ?? 0);
            if ($count > 0) {
                $unsafeTerminalStatuses[$status] = $count;
            }
        }
        $aggregate['settled_total'] = (int) ($aggregate['statuses']['settled'] ?? 0);
        $aggregate['unsafe_terminal_statuses'] = $unsafeTerminalStatuses;
        $aggregate['unsafe_terminal_total'] = array_sum($unsafeTerminalStatuses);
        $aggregate['cycle_duration_seconds'] = $this->durationStats($durations);
        $aggregate['scan_duration_seconds'] = $this->durationStats($scanDurations);

        return $aggregate;
    }

    /**
     * @param  array<string, mixed>  $start
     */
    private function scanExpectedEvents(array $start): int
    {
        if (! (bool) ($start['scan_before_cycle'] ?? false)) {
            return 0;
        }

        $scanOptions = $start['scan_options'] ?? [];
        if (! is_array($scanOptions)) {
            return 0;
        }

        $pages = (int) ($scanOptions['pages'] ?? 0);
        $perPage = (int) ($scanOptions['per_page'] ?? 0);
        if ($pages <= 0 || $perPage <= 0) {
            return 0;
        }

        return $pages * $perPage;
    }

    /**
     * @param  list<float>  $durations
     * @return array{count: int, avg: float|null, p95: float|null, max: float|null}
     */
    private function durationStats(array $durations): array
    {
        $durations = array_values(array_filter($durations, fn (float $value): bool => is_finite($value) && $value >= 0.0));
        sort($durations);
        $count = count($durations);
        if ($count === 0) {
            return ['count' => 0, 'avg' => null, 'p95' => null, 'max' => null];
        }

        $p95Index = min($count - 1, (int) ceil($count * 0.95) - 1);

        return [
            'count' => $count,
            'avg' => round(array_sum($durations) / $count, 2),
            'p95' => round($durations[$p95Index], 2),
            'max' => round($durations[$count - 1], 2),
        ];
    }

    /**
     * @param  array<string, int>  $target
     */
    private function addCounterMap(array &$target, mixed $counts): void
    {
        if (! is_array($counts)) {
            return;
        }

        foreach ($counts as $key => $value) {
            $name = (string) $key;
            if ($name === '') {
                continue;
            }
            $target[$name] = (int) ($target[$name] ?? 0) + (int) $value;
        }
    }

    /**
     * @param  list<string>  $failed
     * @return list<string>
     */
    private function qualificationNextEvidence(array $failed, int $minCycles, int $minExecuted): array
    {
        $items = [];
        if (array_intersect($failed, ['monitor_logs_present', 'shadow_cycles_min', 'shadow_executed_min', 'shadow_settled_min']) !== []) {
            $items[] = sprintf('Run monitor long enough to capture at least %d cycles and %d successful simulated executions against real books.',
                $minCycles, $minExecuted);
        }
        if (in_array('shadow_unsafe_terminal_absent', $failed, true)) {
            $items[] = 'Investigate unwound/failed/halted simulated baskets and rerun monitor until the qualification window has no unsafe terminal execution statuses.';
        }
        if (in_array('slow_cycle_ratio', $failed, true)) {
            $items[] = 'Reduce slow monitor cycles before relying on the executor for time-sensitive fills.';
        }
        if (in_array('scan_before_cycle_evidence', $failed, true)) {
            $items[] = 'Run monitor with --scan-before-cycle so qualification proves discovery plus execution against fresh real books.';
        }
        if (in_array('scan_budget_exhaustion', $failed, true)) {
            $items[] = 'Tune scan coverage, CLOB verification count, timeouts, or candidate pruning until monitor scan budget_exhausted=0 throughout the qualification window.';
        }
        if (in_array('scan_coverage_floor', $failed, true)) {
            $items[] = 'Investigate partial Gamma scans and rerun monitor until scan-before-cycle coverage stays above the qualification floor throughout the window.';
        }
        if (in_array('finance_policy_allows_live', $failed, true)) {
            $items[] = 'Change the canonical Finance policy through governance before any live market execution.';
        }
        if (in_array('live_flag_enabled', $failed, true)) {
            $items[] = 'Enable the explicit live flag only after policy approval and dry-run evidence are complete.';
        }
        if (in_array('account_ready', $failed, true)) {
            $items[] = 'Configure the live Polymarket account credentials and re-run preflight.';
        }
        if (in_array('runtime_caps_live', $failed, true)) {
            $items[] = 'Clear live runtime blockers reported by preflight.';
        }
        if (in_array('short_onchain_ready_if_needed', $failed, true)) {
            $items[] = 'Verify the EOA/Polygon/NegRisk mint-merge path before any short-side live test.';
        }

        return array_values(array_unique($items));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readMonitorLog(string $path): array
    {
        $lines = is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
        $events = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        return $events;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<array<string, mixed>>
     */
    private function monitorResultSamples(array $results): array
    {
        return array_map(function (array $result): array {
            $basketId = (string) ($result['basket_id'] ?? '');
            $failed = is_array($result['failed'] ?? null) ? array_values($result['failed']) : [];
            if ($failed === [] && $basketId !== '') {
                $failed = $this->latestGateFailures($basketId);
            }

            return array_filter([
                'event_slug' => (string) ($result['event_slug'] ?? ''),
                'kind' => (string) ($result['kind'] ?? ''),
                'basket_id' => $basketId !== '' ? $basketId : null,
                'status' => (string) ($result['status'] ?? 'unknown'),
                'status_reason' => isset($result['status_reason']) ? (string) $result['status_reason'] : null,
                'idempotent_replay' => (bool) ($result['idempotent_replay'] ?? false) ? true : null,
                'failed' => $failed !== [] ? $failed : null,
                'error' => isset($result['error']) && $result['error'] !== null ? (string) $result['error'] : null,
                'realized_pnl_usd' => isset($result['realized_pnl_usd']) ? (float) $result['realized_pnl_usd'] : null,
                'est_profit_usd' => isset($result['est_profit_usd']) ? (float) $result['est_profit_usd'] : null,
            ], static fn ($value): bool => $value !== null && $value !== '');
        }, array_slice($results, 0, 25));
    }

    /**
     * @param  list<array<string, mixed>>  $samples
     * @return array<string, int>
     */
    private function monitorReasonCounts(array $samples): array
    {
        $counts = [];
        foreach ($samples as $sample) {
            $reasons = is_array($sample['failed'] ?? null) && $sample['failed'] !== []
                ? $sample['failed']
                : [(string) ($sample['status_reason'] ?? $sample['status'] ?? 'unknown')];
            foreach ($reasons as $reason) {
                $reason = (string) $reason;
                if ($reason === '') {
                    continue;
                }
                $counts[$reason] = ($counts[$reason] ?? 0) + 1;
            }
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @return list<string>
     */
    private function latestGateFailures(string $basketId): array
    {
        $detail = DB::table('atlas_poly_exec_events')
            ->where('basket_id', $basketId)
            ->where('kind', 'gate_block')
            ->orderByDesc('seq')
            ->value('detail');
        $decoded = is_string($detail) ? json_decode($detail, true) : null;
        $failed = is_array($decoded) && is_array($decoded['failed'] ?? null) ? $decoded['failed'] : [];

        return array_values(array_map('strval', $failed));
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    private function lastMonitorEvent(array $events, string $kind): array
    {
        for ($i = count($events) - 1; $i >= 0; $i--) {
            if (($events[$i]['event'] ?? null) === $kind) {
                return $events[$i];
            }
        }

        return [];
    }

    /** @return list<string> */
    private function kindsFor(): array
    {
        return match (strtolower((string) $this->option('kind'))) {
            'long' => ['long_sum_under'],
            'short' => ['short_sum_over'],
            default => ['long_sum_under', 'short_sum_over'],
        };
    }

    /**
     * Live short is the only path that needs on-chain CTF split/merge, but the
     * Finance domain policy blocks all live market execution before that seam.
     * Keep both policy and on-chain prerequisites visible in preflight.
     *
     * @return list<string>
     */
    private function liveShortReadinessFailures(PolyExecConfig $cfg, PolyAccountIdentity $identity): array
    {
        $failures = [];
        if (FinanceDomainCanon::liveTradingBlocked()) {
            $failures[] = 'finance_policy_blocked';
        }
        if (! $cfg->shortEnabled) {
            $failures[] = 'short_disabled';
        }
        if ($identity->kind() !== PolyAccountIdentity::KIND_EOA) {
            $failures[] = 'onchain_requires_eoa';
        }
        if (! $identity->readiness()['ready']) {
            $failures[] = 'account_not_ready';
        }
        if (! $this->truthyEnv('ATLAS_POLY_ONCHAIN_ARMED')) {
            $failures[] = 'onchain_disarmed';
        }
        $rpcEnv = (string) config('atlas.finance_poly_exec.live.polygon_rpc_url_env', 'ATLAS_POLY_POLYGON_RPC_URL');
        if (! is_string(env($rpcEnv)) || trim((string) env($rpcEnv)) === '') {
            $failures[] = 'missing_polygon_rpc';
        }
        // Sum-of-legs short opportunities are multi-outcome NegRisk markets. Keep
        // live fail-closed until the exact NegRiskAdapter split/merge path has one
        // minimal operator-verified transaction.
        if (! $this->truthyEnv('ATLAS_POLY_ONCHAIN_NEGRISK_VERIFIED')) {
            $failures[] = 'negrisk_unverified';
        }

        return array_values(array_unique($failures));
    }

    private function truthyEnv(string $key): bool
    {
        return in_array(strtolower(trim((string) env($key, ''))), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function executedCount(array $results): int
    {
        $executedStatuses = ['filled', 'unwound', 'settled', 'failed', 'halted'];

        return count(array_filter(
            $results,
            fn (array $result): bool => ! (bool) ($result['idempotent_replay'] ?? false)
                && in_array((string) ($result['status'] ?? ''), $executedStatuses, true),
        ));
    }

    /**
     * @return array{0: PolyExecClient, 1: PolyOnChainClient}
     */
    private function makeClients(PolyExecConfig $cfg, string $mode, ?callable $bookSource = null, ?string $simLedgerMode = null): array
    {
        if ($mode === 'live') {
            $identity = PolyAccountIdentity::detect();
            $root = (string) config('atlas.finance_poly_exec.live.runtime_root', 'runtimes/python/poly_exec');

            return [new LivePolyExecClient($cfg, $identity, $root), new LivePolyOnChainClient($cfg, $identity, $root)];
        }

        // Sim: pair the on-chain client to the exec client so a mint credits the
        // shares the exec client then sells (and a merge burns them), keeping the
        // simulated position exact for reconciliation.
        $simMode = $simLedgerMode !== null && $simLedgerMode !== '' ? $simLedgerMode : 'sim';
        $exec = new SimulatedPolyExecClient($bookSource, null, $simMode);
        $onChain = new SimulatedPolyOnChainClient(
            mintGasUsd: $cfg->estMintGasUsd,
            mergeGasUsd: $cfg->estMergeGasUsd,
            onMint: fn (array $tokens, float $sets) => $exec->creditMinted($tokens, $sets),
            onMerge: fn (array $tokens, float $sets) => $exec->debitMerged($tokens, $sets),
            mode: $simMode,
        );

        return [$exec, $onChain];
    }

    private function marketReadTimeout(): int
    {
        $raw = $this->intOpt('market-read-timeout');

        return max(1, min(30, $raw ?? 5));
    }

    private function candidateTimeBudget(): int
    {
        $raw = $this->intOpt('candidate-time-budget');

        return max(1, min(3600, $raw ?? 75));
    }

    private function candidateBudgetExceeded(float $deadlineAt): bool
    {
        return microtime(true) >= $deadlineAt;
    }

    private function maxLegsPerCandidate(): int
    {
        $raw = $this->intOpt('max-legs-per-candidate');

        return max(2, min(200, $raw ?? 12));
    }

    private function maxSignalAgeSeconds(): int
    {
        $raw = $this->intOpt('max-signal-age-seconds');

        return max(1, min(86400, $raw ?? 900));
    }

    /**
     * @return callable(string): ?array{asks: list<array{price: float, size: float}>, bids: list<array{price: float, size: float}>}
     */
    private function realBookSource(int $timeoutSeconds): callable
    {
        $feed = new PolymarketShadowFeed;

        return fn (string $token): ?array => $feed->bookLevels($token, $timeoutSeconds);
    }

    /**
     * @return callable(string): ?array<string, mixed>
     */
    private function eventMetaSource(int $timeoutSeconds): callable
    {
        $http = new PolymarketPinnedHttp;

        return function (string $slug) use ($http, $timeoutSeconds): ?array {
            $events = $http->getJson('https://gamma-api.polymarket.com/events?slug='.urlencode($slug), $timeoutSeconds);

            return is_array($events) ? ($events[0] ?? null) : null;
        };
    }

    /**
     * Pull eligible opportunities (of the requested kinds) from the shadow
     * lifecycle: live book, not dead, persisted long enough, with stored legs.
     * Short legs are the FULL outcome set; the planner partitions sellable vs
     * freeroll. rank_profit_usd lets the allocator order by value.
     *
     * @param  list<string>  $kinds
     * @return list<array{event_slug: string, kind: string, legs: list<array{token: string, question: string}>, persistence_seconds: int, rank_profit_usd: float, attempt_key: string}>
     */
    private function selectCandidates(PolyExecConfig $cfg, array $kinds, ?int $limit = null): array
    {
        if (! DB::getSchemaBuilder()->hasTable('atlas_poly_arb_opportunities')) {
            return [];
        }

        $q = DB::table('atlas_poly_arb_opportunities')
            ->whereIn('kind', $kinds)
            ->where('dead_book', false)
            ->where('last_seen_at', '>=', Carbon::now()->subSeconds($this->maxSignalAgeSeconds()))
            ->whereNotNull('volume_24hr')
            ->where('volume_24hr', '>=', (float) config('atlas.finance_poly_arb.min_volume_24hr', 50.0));
        if ($slug = $this->option('event-slug')) {
            $q->where('event_slug', (string) $slug);
        }
        $rows = $q->orderByDesc('max_profit_usd')->limit(max(1, min(200, $limit ?? 200)))->get();

        $out = [];
        foreach ($rows as $row) {
            $age = Carbon::parse($row->first_seen_at)->diffInSeconds(Carbon::now());
            if ($age < $cfg->minPersistenceSeconds && ! $this->option('event-slug')) {
                continue; // not persisted long enough (the gate would block it anyway)
            }

            $signal = DB::table('atlas_poly_arb_signals')
                ->where('event_slug', $row->event_slug)->where('kind', $row->kind)
                ->orderByDesc('id')->first(['id', 'legs', 'updated_at', 'created_at']);
            $legsJson = $signal !== null ? $signal->legs : null;
            $legs = is_string($legsJson) ? json_decode($legsJson, true) : null;
            if (! is_array($legs) || $legs === []) {
                continue;
            }
            $norm = [];
            foreach ($legs as $leg) {
                $token = (string) ($leg['token'] ?? '');
                if ($token !== '') {
                    $norm[] = ['token' => $token, 'question' => (string) ($leg['question'] ?? '')];
                }
            }
            // Long needs >=2 sellable; short needs the full outcome set (>=3) to mint.
            $minLegs = $row->kind === 'short_sum_over' ? 3 : 2;
            if (count($norm) < $minLegs) {
                continue;
            }
            if (count($norm) > $this->maxLegsPerCandidate()) {
                continue;
            }

            $out[] = [
                'event_slug' => (string) $row->event_slug,
                'kind' => (string) $row->kind,
                'legs' => $norm,
                'persistence_seconds' => (int) $age,
                'rank_profit_usd' => (float) ($row->max_profit_usd ?? 0.0),
                'attempt_key' => implode(':', array_filter([
                    (string) ($signal->id ?? ''),
                    (string) ($signal->updated_at ?? $signal->created_at ?? ''),
                    (string) ($row->updated_at ?? $row->last_seen_at ?? ''),
                ], static fn (string $part): bool => $part !== '')),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(array $payload): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload + ['schema_version' => 'atlas.finance.poly_exec.v1'], JSON_PRETTY_PRINT));
        }

        return self::SUCCESS;
    }

    private function floatOpt(string $name): ?float
    {
        $v = $this->option($name);

        return $v === null || $v === '' ? null : (float) $v;
    }

    private function intOpt(string $name): ?int
    {
        $v = $this->option($name);

        return $v === null || $v === '' ? null : (int) $v;
    }

    private function candidateLimit(int $default): int
    {
        $raw = $this->intOpt('max-candidates');

        return max(1, min(200, $raw ?? $default));
    }

    private function fail2(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }

    private function raiseMemoryFloor(string $floor): void
    {
        $current = (string) ini_get('memory_limit');
        $toBytes = static function (string $value): int {
            $value = trim($value);
            if ($value === '-1') {
                return PHP_INT_MAX;
            }
            $unit = strtolower(substr($value, -1));
            $n = (int) $value;

            return match ($unit) {
                'g' => $n * 1024 ** 3,
                'm' => $n * 1024 ** 2,
                'k' => $n * 1024,
                default => (int) $value,
            };
        };
        if ($toBytes($current) < $toBytes($floor)) {
            ini_set('memory_limit', $floor);
        }
    }
}
