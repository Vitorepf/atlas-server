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
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Polymarket sum-of-legs shadow/sim executor. Live mode is a dormant seam and
 * remains blocked while the canonical Finance no-live-execution policy is on.
 *
 *   preflight  show config, gate state, account readiness, kill-switch. No action.
 *   plan       build the basket plan for live opportunities + gate verdict. No action.
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
        {action=preflight : preflight|plan|run|monitor}
        {--mode=sim : sim|live}
        {--kind=both : both|long|short — which arb direction(s) to run}
        {--max-cesta= : per-basket cap USD (overrides config)}
        {--daily-cap= : daily budget USD (overrides config)}
        {--max-concurrent= : max simultaneous baskets (overrides config)}
        {--max-candidates= : max lifecycle candidates to process in run/monitor or show in plan}
        {--cycles=3 : monitor cycles (sim only)}
        {--interval=30 : seconds between monitor cycles}
        {--slow-cycle-seconds=45 : mark monitor cycles slower than this as degraded}
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
            'run' => $this->runExec($cfg, $mode),
            'monitor' => $this->monitor($cfg, $mode),
            default => $this->fail2('Unknown action. Use: preflight | plan | run | monitor'),
        };
    }

    private function preflight(PolyExecConfig $cfg, string $mode): int
    {
        $gate = new PolyExecGate($cfg);
        $identity = PolyAccountIdentity::detect();
        $runtime = $gate->checkRuntimeCaps($mode);
        $shortFailures = $this->liveShortReadinessFailures($cfg, $identity);

        $report = [
            'schema_version' => 'atlas.finance.poly_exec.preflight.v1',
            'mode' => $mode,
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
            ],
            'short' => [
                'enabled' => $cfg->shortEnabled,
                'est_mint_gas_usd' => $cfg->estMintGasUsd,
                'est_merge_gas_usd' => $cfg->estMergeGasUsd,
                'merge_on_no_sell' => $cfg->shortMergeOnNoSell,
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
            'deployed_today_usd' => $gate->deployedToday($mode),
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
        $this->line(sprintf('deployed today: $%.2f  runtime gate: %s', $gate->deployedToday($mode),
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
        $longPlanner = new BasketPlanner($cfg);
        $shortPlanner = new ShortBasketPlanner($cfg);
        $remaining = max(0.0, $cfg->dailyCapUsd - $gate->deployedToday($mode));

        $plans = [];
        foreach ($candidates as $c) {
            $plan = $c['kind'] === 'short_sum_over'
                ? $shortPlanner->plan($c['event_slug'], $c['legs'], $c['persistence_seconds'], $remaining)
                : $longPlanner->plan($c['event_slug'], $c['kind'], $c['legs'], $c['persistence_seconds'], $remaining);
            if ($plan === null) {
                $plans[] = ['event_slug' => $c['event_slug'], 'kind' => $c['kind'], 'planned' => false, 'reason' => 'no_executable_plan (legs unreadable / sum not crossing $1 / zero depth)'];

                continue;
            }
            $opp = $gate->checkOpportunity($plan->toGateInput(), $mode);
            $plans[] = [
                'event_slug' => $plan->eventSlug,
                'kind' => $plan->kind,
                'planned' => true,
                'gate_allowed' => $opp->allowed,
                'gate_failed' => $opp->failedNames(),
                'plan' => $plan->toArray(),
            ];
        }

        return $this->emit(['action' => 'plan', 'mode' => $mode, 'kinds' => $kinds, 'daily_remaining_usd' => round($remaining, 2), 'plans' => $plans]);
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

        [$exec, $onChain] = $this->makeClients($cfg, $mode);
        $allocator = new ArbAllocator(
            $cfg,
            $gate,
            new BasketPlanner($cfg),
            new ShortBasketPlanner($cfg),
            new BasketStateMachine($cfg, $exec, $gate, null, null, $onChain),
            new MintSellStateMachine($cfg, $exec, $onChain, $gate),
        );
        $sessionId = (string) Str::ulid();

        $candidates = $this->selectCandidates($cfg, $kinds, $this->candidateLimit(200));

        $this->info(sprintf('[poly-exec] session=%s mode=%s kinds=%s candidates=%d %s',
            $sessionId, $mode, implode('+', $kinds), count($candidates),
            $mode === 'sim' ? '(SIM — real books, no signing/minting)' : '(LIVE — policy-open venue path)'));

        $out = $allocator->allocate($mode, $sessionId, $candidates);

        foreach ($out['results'] as $summary) {
            $this->line(sprintf('[poly-exec] %s %s %s -> %s pnl=$%.2f%s',
                $summary['kind'] ?? '?', $summary['basket_id'] ?? '?', $summary['event_slug'] ?? '?',
                $summary['status'] ?? '?', (float) ($summary['realized_pnl_usd'] ?? 0),
                ($summary['pnl_is_locked_at_resolution'] ?? false) ? ' (locked@resolution)' : ''));
        }

        return $this->emit([
            'action' => 'run', 'mode' => $mode, 'kinds' => $kinds, 'session_id' => $sessionId,
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

        [$exec, $onChain] = $this->makeClients($cfg, 'sim');
        $gate = new PolyExecGate($cfg);
        $allocator = new ArbAllocator(
            $cfg,
            $gate,
            new BasketPlanner($cfg),
            new ShortBasketPlanner($cfg),
            new BasketStateMachine($cfg, $exec, $gate, null, null, $onChain),
            new MintSellStateMachine($cfg, $exec, $onChain, $gate),
        );

        $sessionId = (string) Str::ulid();
        $kinds = $this->kindsFor();
        $cycles = max(1, min(100, (int) $this->option('cycles')));
        $interval = max(0, min(3600, (int) $this->option('interval')));
        $maxCandidates = $this->candidateLimit(8);
        $slowCycleSeconds = max(1, min(3600, (int) $this->option('slow-cycle-seconds')));
        $cycleReports = [];

        $this->info(sprintf('[poly-exec] monitor=%s mode=sim cycles=%d interval=%ds max_candidates=%d slow_cycle>%ds (real books, no signing/minting)',
            $sessionId, $cycles, $interval, $maxCandidates, $slowCycleSeconds));

        for ($cycle = 1; $cycle <= $cycles; $cycle++) {
            $cycleStarted = microtime(true);
            $candidates = $this->selectCandidates($cfg, $kinds, $maxCandidates);
            $out = $allocator->allocate('sim', $sessionId.'-'.$cycle, $candidates, $maxCandidates);
            $statuses = array_count_values(array_map(
                fn (array $result): string => (string) ($result['status'] ?? 'unknown'),
                $out['results'],
            ));
            $report = [
                'cycle' => $cycle,
                'candidates' => count($candidates),
                'processed' => $out['processed'] ?? count($out['results']),
                'dispatched' => $out['dispatched'],
                'executed' => $this->executedCount($out['results']),
                'blocked' => $out['blocked'],
                'statuses' => $statuses,
                'duration_seconds' => round(microtime(true) - $cycleStarted, 2),
            ];
            $report['slow'] = $report['duration_seconds'] > $slowCycleSeconds;
            $cycleReports[] = $report;
            $this->line(sprintf('[poly-exec] monitor cycle#%d candidates=%d processed=%d dispatched=%d executed=%d statuses=%s (%.2fs)%s',
                $report['cycle'], $report['candidates'], $report['processed'], $report['dispatched'],
                $report['executed'], json_encode($report['statuses']), $report['duration_seconds'],
                $report['slow'] ? ' SLOW' : ''));

            if ($cycle < $cycles && $interval > 0) {
                sleep($interval);
            }
        }

        return $this->emit([
            'action' => 'monitor',
            'mode' => 'sim',
            'safety' => [
                'shadow_only' => true,
                'real_money_touched' => false,
                'signing' => false,
                'minting' => false,
                'live_policy_blocked' => FinanceDomainCanon::liveTradingBlocked(),
            ],
            'kinds' => $kinds,
            'session_id' => $sessionId,
            'cycles_requested' => $cycles,
            'interval_seconds' => $interval,
            'max_candidates' => $maxCandidates,
            'slow_cycle_seconds' => $slowCycleSeconds,
            'cycles' => $cycleReports,
            'slow_cycles' => count(array_filter($cycleReports, fn (array $report): bool => (bool) ($report['slow'] ?? false))),
            'executed_total' => array_sum(array_column($cycleReports, 'executed')),
        ]);
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
            fn (array $result): bool => in_array((string) ($result['status'] ?? ''), $executedStatuses, true),
        ));
    }

    /**
     * @return array{0: PolyExecClient, 1: PolyOnChainClient}
     */
    private function makeClients(PolyExecConfig $cfg, string $mode): array
    {
        if ($mode === 'live') {
            $identity = PolyAccountIdentity::detect();
            $root = (string) config('atlas.finance_poly_exec.live.runtime_root', 'runtimes/python/poly_exec');

            return [new LivePolyExecClient($cfg, $identity, $root), new LivePolyOnChainClient($cfg, $identity, $root)];
        }

        // Sim: pair the on-chain client to the exec client so a mint credits the
        // shares the exec client then sells (and a merge burns them), keeping the
        // simulated position exact for reconciliation.
        $exec = new SimulatedPolyExecClient;
        $onChain = new SimulatedPolyOnChainClient(
            mintGasUsd: $cfg->estMintGasUsd,
            mergeGasUsd: $cfg->estMergeGasUsd,
            onMint: fn (array $tokens, float $sets) => $exec->creditMinted($tokens, $sets),
            onMerge: fn (array $tokens, float $sets) => $exec->debitMerged($tokens, $sets),
        );

        return [$exec, $onChain];
    }

    /**
     * Pull eligible opportunities (of the requested kinds) from the shadow
     * lifecycle: live book, not dead, persisted long enough, with stored legs.
     * Short legs are the FULL outcome set; the planner partitions sellable vs
     * freeroll. rank_profit_usd lets the allocator order by value.
     *
     * @param  list<string>  $kinds
     * @return list<array{event_slug: string, kind: string, legs: list<array{token: string, question: string}>, persistence_seconds: int, rank_profit_usd: float}>
     */
    private function selectCandidates(PolyExecConfig $cfg, array $kinds, ?int $limit = null): array
    {
        if (! DB::getSchemaBuilder()->hasTable('atlas_poly_arb_opportunities')) {
            return [];
        }

        $q = DB::table('atlas_poly_arb_opportunities')
            ->whereIn('kind', $kinds)
            ->where('dead_book', false)
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

            $legsJson = DB::table('atlas_poly_arb_signals')
                ->where('event_slug', $row->event_slug)->where('kind', $row->kind)
                ->orderByDesc('id')->value('legs');
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

            $out[] = [
                'event_slug' => (string) $row->event_slug,
                'kind' => (string) $row->kind,
                'legs' => $norm,
                'persistence_seconds' => (int) $age,
                'rank_profit_usd' => (float) ($row->max_profit_usd ?? 0.0),
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
