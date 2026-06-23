<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Closure;

/**
 * THE SOAK PREFLIGHT — turns the operator's intent ("soak the loop on itself for N hours, $B budget") into a
 * verified launch plan, so a soak is ONE command and never a foot-gun. It computes (a) the BRAKE (TTL,
 * spend ceiling, grind cap), (b) the ARM-CHECK (master on? the Fibonacci seams on? proxy work-types OFF?
 * propose-only vs auto-merge?), and (c) the exact `atlas:loop:campaign` launch line. Pure + read-only — it
 * NEVER launches, NEVER mutates config; the command decides whether to dispatch behind --confirm.
 */
final class AtlasLoopSoakPlanService
{
    /** The compounding seams that MUST be armed for a soak to be Fibonacci (not linear). */
    public const FIBONACCI_FLAGS = [
        'origination_on_starvation_enabled',
        'capability_trend_enabled',
        'capability_ambition_enabled',
        'compounding_frontier_enabled',
        'regression_sentinel_enabled',
        'territory_widened_roots_drive_refill',
    ];

    /**
     * The FAXINA magnet — the deterministic cleanup substrate that MUST stay OFF in a material soak. A single
     * flag gates BOTH provider-less cleanup work-types (dead-code AND unused-import run under it in the
     * supervisor), so this one entry covers the whole proxy substrate.
     */
    public const PROXY_WORKTYPE_FLAGS = [
        'deterministic_deadcode_supply_enabled',
    ];

    /**
     * Cyclomatic-refactor SUPPLY lanes — the structurally-abundant proxy FARMS (every method >= CC10 is a
     * target) that flood the queue with behaviour-preserving complexity-reduction work, so the loop never
     * genuinely starves and origination-on-starvation almost never fires. A MATERIAL soak requires these OFF.
     *
     * Live-proven 2026-06-22: with these ON, the loop's first wave was 6/8 refactor_reduce_complexity while
     * the arm-check still reported "proxy OFF / ready" (it only checked the dead-code flag above). The config
     * even labels decompose_supply a "material-supply lane" — but a complexity_proof refactor is cyclomatic
     * faxina laundered as material (canonical loop definition: a behaviour-preserving refactor is melhoria
     * ZERO). So a material self-evolution soak must verify these are OFF, not assume it.
     */
    public const PROXY_SUPPLY_FLAGS = [
        'decompose_supply_enabled',
        'proxy_refactor_supply_enabled',
        'self_improve_grounding_enabled',
    ];

    public function __construct(private readonly ?Closure $masterEnabledResolver = null) {}

    /**
     * @return array<string,mixed> the canonical atlas.loop.soak_plan.v1 payload.
     */
    public function plan(float $hours, float $budgetUsd, int $grindCap, bool $withSelfMerge): array
    {
        $maxSeconds = max(1, min(86400, (int) round($hours * 3600)));
        $maxUsdCents = max(0, (int) round($budgetUsd * 100));
        $maxTasks = max(0, $grindCap);

        $fib = [];
        $fibAllOn = true;
        foreach (self::FIBONACCI_FLAGS as $f) {
            $on = (bool) config('atlas.loop.'.$f, false);
            $fib[$f] = $on;
            $fibAllOn = $fibAllOn && $on;
        }

        $proxyArmed = [];
        foreach (self::PROXY_WORKTYPE_FLAGS as $f) {
            if ((bool) config('atlas.loop.'.$f, false)) {
                $proxyArmed[] = $f;
            }
        }
        $proxyOff = $proxyArmed === [];

        // Cyclomatic-refactor SUPPLY farms — any armed one floods the queue with proxy refactors, so the soak
        // grinds faxina instead of material work. The objective producer is a proxy farm ONLY when it is NOT
        // emitting features (producer_feature_origination off) — armed-without-feature it mints "REDUCE
        // complexity" objectives directly.
        $proxySupplyArmed = [];
        foreach (self::PROXY_SUPPLY_FLAGS as $f) {
            if ((bool) config('atlas.loop.'.$f, false)) {
                $proxySupplyArmed[] = $f;
            }
        }
        if ((bool) config('atlas.loop.objective_producer_enabled', false)
            && ! (bool) config('atlas.loop.producer_feature_origination_enabled', false)) {
            $proxySupplyArmed[] = 'objective_producer_enabled (without producer_feature_origination)';
        }
        $proxySupplyOff = $proxySupplyArmed === [];

        $masterOn = $this->masterEnabled();
        $selfMergeArmed = (bool) config('atlas.loop.self_improvement_auto_merge_enabled', false);
        $obraMergeArmed = (bool) config('atlas.loop.obra_auto_merge_enabled', false);
        $mergeMode = ($selfMergeArmed || $obraMergeArmed) ? 'auto_merge' : 'propose_only';

        $blocking = [];
        if (! $masterOn) {
            $blocking[] = 'master_switch_off (run: php artisan atlas:loop:on)';
        }
        foreach ($fib as $flag => $on) {
            if (! $on) {
                $blocking[] = 'fibonacci_flag_off:'.$flag;
            }
        }
        foreach ($proxyArmed as $flag) {
            $blocking[] = 'proxy_worktype_armed:'.$flag.' (must be OFF — it is the faxina magnet)';
        }
        foreach ($proxySupplyArmed as $flag) {
            $blocking[] = 'proxy_supply_lane_armed:'.$flag.' (cyclomatic-refactor farm — must be OFF for a MATERIAL soak)';
        }
        if ($withSelfMerge && ! $selfMergeArmed) {
            $blocking[] = 'self_merge_requested_but_flag_off (set ATLAS_LOOP_SELF_IMPROVEMENT_AUTO_MERGE_ENABLED=true)';
        }
        if (! $withSelfMerge && $mergeMode === 'auto_merge') {
            $blocking[] = 'auto_merge_armed_but_propose_only_requested (this is NOT a risk-free propose-only soak)';
        }

        return [
            'schema_version' => 'atlas.loop.soak_plan.v1',
            'requested_mode' => $withSelfMerge ? 'auto_merge' : 'propose_only',
            'brake' => [
                'max_seconds' => $maxSeconds,
                'max_usd_cents' => $maxUsdCents,
                'max_tasks' => $maxTasks,
                'idle_on_starvation' => true,
            ],
            'scope' => [
                'discovery_roots' => array_values((array) config('atlas.loop.campaign.discovery_roots', ['app/Services/Ai/AutonomousEvolution'])),
            ],
            'arm_check' => [
                'master_enabled' => $masterOn,
                'fibonacci_flags' => $fib,
                'fibonacci_all_on' => $fibAllOn,
                'proxy_worktypes_off' => $proxyOff,
                'proxy_supply_lanes_off' => $proxySupplyOff,
                'proxy_supply_armed' => $proxySupplyArmed,
                'self_merge_armed' => $selfMergeArmed,
                'merge_mode' => $mergeMode,
                'ready' => $blocking === [],
                'blocking' => $blocking,
            ],
            'launch_command' => $this->launchCommand($maxSeconds, $maxUsdCents, $maxTasks),
            'launch_args' => $this->launchArgs($maxSeconds, $maxUsdCents, $maxTasks),
        ];
    }

    private function masterEnabled(): bool
    {
        return (bool) ($this->masterEnabledResolver ?? static fn (): bool => AtlasLoopMasterSwitch::enabled())();
    }

    /** The exact one-liner the operator can copy-paste (or the command runs behind --confirm). */
    private function launchCommand(int $maxSeconds, int $maxUsdCents, int $maxTasks): string
    {
        $parts = ['php artisan atlas:loop:campaign', '--no-shadow', '--idle-on-starvation', '--max-seconds='.$maxSeconds];
        if ($maxUsdCents > 0) {
            $parts[] = '--max-usd-cents='.$maxUsdCents;
        }
        if ($maxTasks > 0) {
            $parts[] = '--max-tasks='.$maxTasks;
        }
        $parts[] = '--goal="self-evolution soak"';

        return implode(' ', $parts);
    }

    /** @return array<string,mixed> the Artisan::call argv for atlas:loop:campaign. */
    private function launchArgs(int $maxSeconds, int $maxUsdCents, int $maxTasks): array
    {
        $args = ['--no-shadow' => true, '--idle-on-starvation' => true, '--max-seconds' => $maxSeconds, '--goal' => 'self-evolution soak'];
        if ($maxUsdCents > 0) {
            $args['--max-usd-cents'] = $maxUsdCents;
        }
        if ($maxTasks > 0) {
            $args['--max-tasks'] = $maxTasks;
        }

        return $args;
    }
}
