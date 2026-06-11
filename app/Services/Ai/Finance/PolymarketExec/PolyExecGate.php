<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketExec;

use App\Services\Ai\Finance\Kernel\FinanceDomainCanon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The structural safety gates — IN CODE, never prompt. Nothing executes unless
 * every one of these passes. Two layers:
 *
 *  - Runtime caps (DB/file/flag backed): kill-switch, live flag, daily budget +
 *    halt latch, max concurrent baskets.
 *  - Opportunity quality (pure): net edge floor, depth >= N x stake, minimum
 *    persistence, resolution horizon, per-basket cap, sane prices.
 *
 * Fail-closed: a missing/ambiguous input blocks rather than allows.
 */
final class PolyExecGate
{
    // In-flight = holds or is about to hold a position. 'gated'/'halted'/'failed'
    // are terminal-without-live-position and must NOT occupy a concurrency slot.
    // 'minting'/'selling' are the short side's in-flight states.
    private const ACTIVE_STATUSES = ['planning', 'verifying', 'filling', 'aborting', 'minting', 'selling'];

    public function __construct(private readonly PolyExecConfig $cfg) {}

    /**
     * Runtime caps that gate WHETHER we may open another basket at all.
     */
    public function checkRuntimeCaps(string $mode): GateDecision
    {
        $checks = [];

        $checks[] = $this->check('kill_switch', ! $this->cfg->killSwitchEngaged(),
            $this->cfg->killSwitchEngaged() ? 'kill-switch file present at '.$this->cfg->killSwitchPath : 'clear');

        if ($mode === 'live') {
            $checks[] = $this->check('finance_policy', ! FinanceDomainCanon::liveTradingBlocked(),
                FinanceDomainCanon::liveTradingBlocked()
                    ? 'Atlas Finance no_live_execution policy blocks live market execution'
                    : 'finance live-trading policy open');
            $checks[] = $this->check('live_flag', $this->cfg->liveEnabled,
                $this->cfg->liveEnabled ? 'enabled' : 'ATLAS_POLY_EXEC_LIVE_ENABLED is false');
        } else {
            $checks[] = $this->check('live_flag', true, 'sim mode — flag not required');
        }

        $halted = $this->isDayHalted($mode);
        $checks[] = $this->check('daily_halt', ! $halted, $halted ? 'day is halted' : 'not halted');

        $deployed = $this->deployedToday($mode);
        $remaining = round($this->cfg->dailyCapUsd - $deployed, 4);
        $checks[] = $this->check('daily_budget', $remaining > 0.0,
            sprintf('deployed=$%.2f cap=$%.2f remaining=$%.2f', $deployed, $this->cfg->dailyCapUsd, $remaining));

        $active = $this->activeBaskets($mode);
        $checks[] = $this->check('concurrency', $active < $this->cfg->maxConcurrentBaskets,
            sprintf('active=%d max=%d', $active, $this->cfg->maxConcurrentBaskets));

        return new GateDecision(! in_array(false, array_column($checks, 'ok'), true), $checks);
    }

    /**
     * Opportunity-quality gate. Pure: every input is supplied, no I/O — so the
     * thresholds are unit-testable in isolation.
     *
     * @param  array{
     *     net_edge_per_set: float, executable_depth_shares: float, target_sets: float,
     *     target_cost_usd: float, persistence_seconds: int, resolution_hours: float|null,
     *     min_leg_price: float, max_leg_price: float
     * }  $o
     */
    public function checkOpportunity(array $o, string $mode, ?float $maxResolutionHoursOverride = null): GateDecision
    {
        $checks = [];

        $checks[] = $this->check('net_edge', $o['net_edge_per_set'] + 1e-5 >= $this->cfg->minNetEdgePerSet,
            sprintf('net_edge/set=%.4f floor=%.4f', $o['net_edge_per_set'], $this->cfg->minNetEdgePerSet));

        $needDepth = $o['target_sets'] * $this->cfg->minDepthMultiple;
        $checks[] = $this->check('depth_multiple', $o['executable_depth_shares'] + 1e-6 >= $needDepth && $o['target_sets'] > 0.0,
            sprintf('depth=%.2f need>=%.2f (%.1fx of %.2f sets)',
                $o['executable_depth_shares'], $needDepth, $this->cfg->minDepthMultiple, $o['target_sets']));

        $checks[] = $this->check('persistence', $o['persistence_seconds'] >= $this->cfg->minPersistenceSeconds,
            sprintf('age=%ds floor=%ds', $o['persistence_seconds'], $this->cfg->minPersistenceSeconds));

        // The short side passes its own (generous) ceiling — it banks now, so it does
        // not need a fast resolution the way the carry-to-resolution long does.
        $resCeiling = $maxResolutionHoursOverride ?? $this->cfg->maxResolutionHours;
        $res = $o['resolution_hours'];
        $checks[] = $this->check('resolution_horizon', $res !== null && $res >= 0.0 && $res <= $resCeiling,
            $res === null ? 'resolution time unknown (fail-closed)' : sprintf('resolves in %.1fh ceiling %.1fh', $res, $resCeiling));

        $checks[] = $this->check('basket_cap', $o['target_cost_usd'] <= $this->cfg->maxBasketUsd + 1e-6 && $o['target_cost_usd'] > 0.0,
            sprintf('cost=$%.2f cap=$%.2f', $o['target_cost_usd'], $this->cfg->maxBasketUsd));

        $remaining = round($this->cfg->dailyCapUsd - $this->deployedToday($mode), 4);
        $checks[] = $this->check('fits_daily_budget', $o['target_cost_usd'] <= $remaining + 1e-6,
            sprintf('cost=$%.2f remaining=$%.2f', $o['target_cost_usd'], $remaining));

        $checks[] = $this->check('sane_prices',
            $o['min_leg_price'] > 0.0 && $o['max_leg_price'] < 1.0,
            sprintf('legs in (%.4f, %.4f)', $o['min_leg_price'], $o['max_leg_price']));

        return new GateDecision(! in_array(false, array_column($checks, 'ok'), true), $checks);
    }

    public function deployedToday(string $mode): float
    {
        if (! $this->tableReady()) {
            return 0.0;
        }

        return (float) DB::table('atlas_poly_exec_daily')
            ->whereDate('trade_date', Carbon::now()->toDateString())
            ->where('mode', $mode)
            ->sum('deployed_usd');
    }

    public function isDayHalted(string $mode): bool
    {
        if (! $this->tableReady()) {
            return false;
        }

        return (bool) DB::table('atlas_poly_exec_daily')
            ->whereDate('trade_date', Carbon::now()->toDateString())
            ->where('mode', $mode)
            ->value('halted');
    }

    public function activeBaskets(string $mode): int
    {
        if (! DB::getSchemaBuilder()->hasTable('atlas_poly_exec_baskets')) {
            return 0;
        }

        return (int) DB::table('atlas_poly_exec_baskets')
            ->where('mode', $mode)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->count();
    }

    private function tableReady(): bool
    {
        return DB::getSchemaBuilder()->hasTable('atlas_poly_exec_daily');
    }

    /**
     * @return array{name: string, ok: bool, reason: string}
     */
    private function check(string $name, bool $ok, string $reason): array
    {
        return ['name' => $name, 'ok' => $ok, 'reason' => $reason];
    }
}
