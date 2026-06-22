<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Campaign;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTarget;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasLoopDbResilience;
use App\Services\Ai\AutonomousEvolution\AtlasLoopObraBridgeService;
use App\Services\Ai\AutonomousEvolution\AtlasLoopFleetGovernor;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProviderCircuitBreaker;
use App\Services\Ai\AutonomousEvolution\AtlasLoopResourceGate;
use App\Services\Ai\AutonomousEvolution\AtlasLoopTaxa2DialOverlayService;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\AtlasLoopTaskGrinder;
use App\Services\Ai\AutonomousEvolution\AtlasLoopTerritoryLadder;
use App\Services\Ai\AutonomousEvolution\AtlasLoopTransientDbException;
use Illuminate\Support\Str;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBackService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopQueueRefiller;
use App\Services\Ai\AutonomousEvolution\Parallel\LoopWorkerCountPlanner;
use App\Services\Ai\AutonomousEvolution\Parallel\LoopWorkerPool;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopDeliveryPipeline;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use Closure;
use Throwable;

/**
 * The 24h wall-clock loop — the Evolution Loop as an autonomous product.
 *
 *   refill -> claim -> grind (propose-only) -> stream-persist -> loop-back
 *
 * under a wall-clock budget, an exclusive file lock with lease + orphan reclaim, the
 * kill/pause switch, a heartbeat, and crash recovery. It mirrors the PROVEN AP-790
 * Reliable24hLoopRunnerService FILE primitives (lock, kill/pause files, JSONL ledger,
 * chunked responsive sleep) — but the body is PROPOSE-ONLY: it wraps the proven
 * {@see AtlasEvolutionLoopRunner} via the grinder, NEVER invokes a provider directly,
 * and NEVER merges. It deliberately does NOT route through AP-790's merge-capable
 * session (that stack's execution core is a fixture and its terminal action is
 * merge-to-main — both incompatible with this propose-only loop).
 *
 * elapsed_seconds is accrued only while ACTIVELY working (grind + refill), so paused
 * time never burns budget and a crash-resumed campaign resumes against REMAINING
 * budget. Test seams (clock/sleeper/storage) make the budget + crash-resume provable
 * without a real 24h wait.
 */
final class AtlasLoopCampaignSupervisor
{
    private const STOP_CODE_DRIFT_RESTART = 'code_drift_restart';

    private ?Closure $clock = null;

    private ?Closure $sleeper = null;

    private ?Closure $gitHeadResolver = null;

    private ?Closure $changedFilesResolver = null;

    private ?string $storageRoot = null;

    public function __construct(
        private readonly AtlasLoopStore $store,
        private readonly AtlasLoopTaskGrinder $grinder,
        private readonly AtlasLoopQueueRefiller $refiller,
        private readonly AtlasLoopBackService $loopBack,
        private readonly AtlasLoopResourceGate $resourceGate,
        private readonly AtlasLoopDbResilience $db,
        private readonly AtlasLoopTaxa2DialOverlayService $taxa2Dials,
        private readonly LoopWorkerPool $workerPool,
        private readonly LoopWorkerCountPlanner $workerPlanner,
        private readonly AtlasLoopObraBridgeService $obraBridge,
        private readonly ?AtlasLoopTerritoryLadder $territoryLadder = null,
        private readonly ?AtlasLoopDeliveryPipeline $pipeline = null,
        private readonly ?\App\Services\Ai\AutonomousEvolution\AtlasLoopProjectionWorker $projectionWorker = null,
    ) {}

    public function setClockForTesting(Closure $clock): void
    {
        $this->clock = $clock;
    }

    public function setSleeperForTesting(Closure $sleeper): void
    {
        $this->sleeper = $sleeper;
    }

    public function setGitHeadResolverForTesting(Closure $resolver): void
    {
        $this->gitHeadResolver = $resolver;
    }

    public function setChangedFilesResolverForTesting(Closure $resolver): void
    {
        $this->changedFilesResolver = $resolver;
    }

    public function setStorageRootForTesting(string $root): void
    {
        $this->storageRoot = rtrim($root, '/');
    }

    /**
     * @param  array<string,mixed>  $input  { campaign_id?, goal?, base_workspace?, caps..., scenarios?, workers?, shadow?, provider? }
     * @return array<string,mixed> atlas.loop.campaign_run.v1
     */
    public function run(array $input): array
    {
        $campaign = $this->resolveCampaign($input);
        $cfg = (array) config('atlas.loop.campaign', []);
        $workerId = 'supervisor-'.$campaign->id;
        $scenarios = isset($input['scenarios']) && (int) $input['scenarios'] > 0 ? (int) $input['scenarios'] : null;
        $taskLease = max(60, (int) ($cfg['task_lease_seconds'] ?? 1800));
        $watermark = max(1, (int) ($cfg['queue_low_watermark'] ?? 4));
        $refillBatch = max(1, (int) ($cfg['refill_batch'] ?? 6));
        $rateLimit = max(0, (int) ($input['sleep_seconds'] ?? ($cfg['sleep_seconds'] ?? 0)));
        $campaignConfig = is_array($campaign->config) ? $campaign->config : [];
        $idleOnStarvation = (bool) ($input['idle_on_starvation'] ?? ($campaignConfig['idle_on_starvation'] ?? ($cfg['idle_on_starvation'] ?? false)));
        $starvationIdleSeconds = max(5, (int) ($input['starvation_idle_seconds'] ?? ($campaignConfig['starvation_idle_seconds'] ?? ($cfg['starvation_idle_seconds'] ?? 60))));
        $baseWorkspace = (string) (trim((string) ($input['base_workspace'] ?? '')) ?: ($campaign->base_workspace ?: base_path()));
        $requestedWorkers = max(1, (int) ($input['workers'] ?? ($cfg['workers'] ?? 1)));
        $parallelEnabled = (bool) config('atlas.loop.parallel.enabled', false);
        $effectiveWorkers = $parallelEnabled ? $this->workerPlanner->plan($requestedWorkers) : 1;
        // §4 FLEET GOVERNOR — cap this campaign's worker pool by the GLOBAL in-flight grind headroom across all
        // campaigns, so a respawn storm or many concurrent campaigns never swamp the Mac (a soft cap: always
        // ≥1 so a campaign makes progress, but bounded by fleet headroom). Flag/cap<=0 ⇒ unlimited (byte-identical).
        $fleetCap = (int) config('atlas.loop.fleet_global_worker_cap', 0);
        if ($fleetCap > 0) {
            $effectiveWorkers = max(1, (new AtlasLoopFleetGovernor)->admit($effectiveWorkers, $fleetCap)['admitted']);
        }
        $pool = $parallelEnabled && $effectiveWorkers > 1 ? $this->workerPool : null;
        $parallelClaimSeq = 0;
        $restartOnCodeDrift = (bool) ($cfg['restart_on_code_drift'] ?? true);
        $bootHead = $restartOnCodeDrift ? $this->currentGitHead($baseWorkspace) : null;
        $taxa2 = $this->taxa2Overlay($scenarios);
        if (is_array($taxa2)) {
            $effectiveDials = is_array($taxa2['effective_dials'] ?? null) ? $taxa2['effective_dials'] : [];
            $scenarios = max(1, (int) ($effectiveDials['scenarios_per_task'] ?? ($scenarios ?? config('atlas.loop.scenarios_per_task', 3))));
            $watermark = max(1, (int) ($effectiveDials['queue_low_watermark'] ?? $watermark));
            $refillBatch = max(1, (int) ($effectiveDials['refill_batch'] ?? $refillBatch));
        }

        // Transient-DB resilience policy: absorb a brief Postgres blip during the 24h run
        // instead of dying. Inner bounded retry+reconnect heals sub-window blips in place;
        // the per-cycle catch parks longer outages; only a SUSTAINED outage aborts.
        $this->db->setPolicy(
            max(1, (int) ($cfg['db_retry_attempts'] ?? 5)),
            max(10, (int) ($cfg['db_retry_base_ms'] ?? 500)),
            max(100, (int) ($cfg['db_retry_max_ms'] ?? 30000)),
        );
        $outageAbortSeconds = max(30, (int) ($cfg['db_outage_abort_seconds'] ?? 180));
        $outagePollSeconds = max(1, (int) ($cfg['db_outage_poll_seconds'] ?? 15));
        $outageStartedAt = null;

        $this->ensureStorage($campaign->id);

        if ($this->killFileExists($campaign->id)) {
            return $this->finish($campaign, 'kill_switch', 0);
        }
        if (! $this->acquireLock($campaign->id, (int) ($cfg['lock_lease_seconds'] ?? 3600))) {
            return ['schema_version' => 'atlas.loop.campaign_run.v1', 'campaign_id' => $campaign->id, 'stop_reason' => 'lock_held', 'cycles' => 0, 'proposals_total' => (int) $campaign->proposals_count, 'merged_to_main' => false];
        }
        if ($bootHead !== null) {
            $this->appendLedger($campaign->id, [
                'event' => 'boot_git_head',
                'head' => $bootHead,
                'workspace' => $baseWorkspace,
            ]);
        }
        $this->appendLedger($campaign->id, [
            'event' => 'parallel_fleet_boot',
            'enabled' => $parallelEnabled,
            'requested_workers' => $requestedWorkers,
            'effective_workers' => $effectiveWorkers,
            'max_workers' => (int) config('atlas.loop.parallel.max_workers', 4),
            'mode' => $pool instanceof LoopWorkerPool ? 'parallel_pool' : 'serial_supervisor',
        ]);
        if (is_array($taxa2)) {
            $this->appendLedger($campaign->id, [
                'event' => 'taxa2_dials',
                'status' => $taxa2['status'] ?? null,
                'changed' => (bool) ($taxa2['changed'] ?? false),
                'base_dials' => $taxa2['base_dials'] ?? [],
                'effective_dials' => $taxa2['effective_dials'] ?? [],
                'reasons' => $taxa2['reasons'] ?? [],
                'receipt_path' => data_get($taxa2, 'receipt.receipt_path'),
                'operator_scenarios_override' => (bool) data_get($taxa2, 'adjustments.scenarios_per_task.operator_override', false),
            ]);
        }

        $cycles = 0;
        $stop = 'completed';
        try {
            // Crash recovery: reclaim any tasks an earlier run left in-flight, sweep the
            // /tmp scenario orphans a SIGKILL could not clean, and resume the ledger.
            $this->guard(fn () => $this->store->rebuildInFlight($campaign->id), 'rebuild_in_flight');
            $this->resourceGate->sweepOrphans(sys_get_temp_dir(), (int) ($cfg['orphan_ttl_seconds'] ?? 1800));
            // GAP-2 (24h endurance) — sweepOrphans rm -rf's the DIRS, but a SIGKILL'd materialization leaks the
            // INDEXED code-symbol rows forever (the ~11.9M-row OOM). Reap leaked atlas-loop-scn-* symbols at
            // boot, before any worker indexes. Flag-default-OFF (byte-identical) + fail-open; runs once per boot.
            if ((bool) config('atlas.loop.symbol_gc_on_boot', false)) {
                $this->guard(fn () => $this->resourceGate->reapLeakedCodeSymbols(
                    (int) ($cfg['symbol_gc_older_than_seconds'] ?? 7200),
                ), 'symbol_gc_on_boot');
            }
            $this->guard(fn () => $this->markCampaignRunning($campaign, $input, $baseWorkspace), 'campaign_start');
            $this->guard(fn () => $this->reconcileUnreflectedParallelTargets($campaign), 'parallel_loop_back_reconcile');

            $lastTick = $this->now();
            while (true) {
                try {
                    $this->guard(fn () => $campaign->refresh(), 'campaign_refresh');
                    $outageStartedAt = null; // a successful DB read means the outage (if any) is over

                    // Budget / kill — checked every tick against PERSISTED elapsed.
                    if ($this->stopRequested($campaign)) {
                        $stop = 'kill_switch';
                        break;
                    }
                    if ($campaign->isOverBudget()) {
                        $stop = (string) $campaign->budgetStopReason();
                        break;
                    }
                    // L4-5: a long-lived supervisor must not keep evolving Atlas with stale
                    // PIPELINE code. Mas o drain mergeia arquivos-ALVO em main toda cadência —
                    // reiniciar a cada HEAD novo seria churn (5min de downtime por merge). Só
                    // reinicia quando o merge tocou o PRÓPRIO motor do loop (o supervisor está
                    // rodando código velho do que importa). Merges de alvo: absorve o HEAD novo
                    // e segue, sem downtime.
                    if ($restartOnCodeDrift && $bootHead !== null) {
                        $head = $this->currentGitHead($baseWorkspace);
                        if ($head !== null && ! hash_equals($bootHead, $head)) {
                            $pipelineChanged = $this->changedPipelineFiles($bootHead, $head, $baseWorkspace);
                            if ($pipelineChanged !== []) {
                                // IN-FLIGHT GUARD: a code-drift restart is NOT urgent — restarting
                                // mid-grind abandons minutes of async in-flight provider work (a long
                                // iterate-to-green refactor in the parallel pool) and the grind never
                                // certifies. DEFER while any grind is claimed/running, keeping bootHead
                                // UNCHANGED so the next IDLE cycle re-detects the drift and restarts
                                // cleanly. (The keepalive's out-of-process recycle has the same guard.)
                                $inFlight = (int) $this->guard(fn () => $this->store->countRunning($campaign->id), 'count_running_for_drift');
                                if ($inFlight > 0) {
                                    $this->appendLedger($campaign->id, [
                                        'event' => 'code_drift_restart_deferred_in_flight',
                                        'running' => $inFlight,
                                        'cycle' => $cycles + 1,
                                    ]);
                                } else {
                                    $this->appendLedger($campaign->id, [
                                        'event' => self::STOP_CODE_DRIFT_RESTART,
                                        'boot_head' => $bootHead,
                                        'current_head' => $head,
                                        'pipeline_files' => array_slice($pipelineChanged, 0, 20),
                                        'cycle' => $cycles + 1,
                                    ]);
                                    $stop = self::STOP_CODE_DRIFT_RESTART;
                                    break;
                                }
                            } else {
                                // Mudança não-pipeline (merge de alvo): absorve e segue sem reiniciar.
                                $bootHead = $head;
                            }
                        }
                    }

                    if ($this->stopRequested($campaign)) {
                        $stop = 'kill_switch';
                        break;
                    }

                    // §4 PROVIDER CIRCUIT-BREAKER (flag-OFF default ⇒ never trips). The authoring provider has
                    // been DOWN for N consecutive grinds (no winner, 0 scenarios explored) — stop grinding into
                    // a void: alert + pause + clean-stop, so an unattended soak never burns CPU for hours on a
                    // dead provider. A paused campaign is NOT keepalive-respawned (keepalive only revives running).
                    if ($this->breakerWantsPause($campaign)) {
                        $this->appendLedger($campaign->id, [
                            'event' => 'provider_circuit_open',
                            'consecutive_provider_failures' => (new AtlasLoopProviderCircuitBreaker)->streak((string) $campaign->id),
                            'action' => 'pause_and_alert',
                        ]);
                        $this->guard(fn () => $campaign->forceFill(['status' => AtlasLoopCampaign::STATUS_PAUSED, 'paused_at' => now()])->save(), 'campaign_circuit_pause');
                        $stop = 'provider_circuit_open';
                        break;
                    }

                    // Pause — freeze budget (do not accrue elapsed) and idle responsively.
                    if ($this->pauseFileExists($campaign->id)) {
                        $this->guard(fn () => $campaign->forceFill(['status' => AtlasLoopCampaign::STATUS_PAUSED, 'paused_at' => now()])->save(), 'campaign_pause');
                        $this->writeHeartbeat($campaign->id);
                        $this->responsiveSleep($campaign->id, max(5, (int) ($cfg['heartbeat_seconds'] ?? 30)));
                        $lastTick = $this->now();

                        continue;
                    }
                    if ($campaign->status === AtlasLoopCampaign::STATUS_PAUSED) {
                        $this->guard(fn () => $campaign->forceFill(['status' => AtlasLoopCampaign::STATUS_RUNNING, 'paused_at' => null])->save(), 'campaign_resume');
                    }

                    // Self-feed: keep total schedulable work above the low watermark.
                    // Parallel in-flight work counts toward the watermark, but a single
                    // long worker should not starve otherwise-idle slots.
                    $pendingTasks = (int) $this->guard(fn () => $this->store->countPending($campaign->id), 'count_pending');
                    $refillWatermark = $this->refillWatermark($watermark, $effectiveWorkers, $pool);
                    if ($this->shouldRefillQueue($pendingTasks, $refillWatermark, $pool)) {
                        if ($this->stopRequested($campaign)) {
                            $stop = 'kill_switch';
                            break;
                        }
                        $refillStart = $this->now();
                        $refill = $this->refiller->refill($campaign, $refillBatch);
                        $this->guard(fn () => $campaign->increment('refills'), 'campaign_refills');
                        $refillEnd = $this->now();
                        $this->beat($campaign, $refillEnd - $refillStart);
                        $lastTick = $refillEnd;
                        if ($this->stopRequested($campaign)) {
                            $stop = 'kill_switch';
                            break;
                        }
                        // S2 — drain any DISPATCHED projections this tick (severed designer↔critic engine):
                        // a converged projection mints its task here, a non-converged one parks. Gated by
                        // flag + non-null pipeline; a no-op when projection_stage is OFF.
                        $this->drainProjections($campaign);
                        if ($this->stopRequested($campaign)) {
                            $stop = 'kill_switch';
                            break;
                        }
                        $openTasks = (int) $this->guard(fn () => $this->store->countOpen($campaign->id), 'count_open');
                        $openProjections = $this->openProjections($campaign->id);
                        if ([(int) $refill['enqueued'], $openTasks, $openProjections] === [0, 0, 0]) {
                            // SLICE C-territory-ladder — at supply exhaustion, the most DANGEROUS loop act:
                            // widen the discovery scope. CONSERVATIVE by design: the gate is always LIVE +
                            // evaluated + logged, but it only ACTUATES into an OPERATOR-DEFINED rung. With no
                            // rung configured (the default — there is no atlas.loop.territory_ladder_rungs),
                            // it is a logged no-op and the loop stops exactly as before. A widening is NEVER
                            // allowed into a root without a frozen judge under it (the canPromote no-blinder
                            // invariant), so an unprotected scope can never open.
                            if (! ((bool) config('atlas.loop.territory_ladder_enabled', true) && $this->maybeClimbTerritory($campaign))) {
                                if (! $idleOnStarvation) {
                                    $stop = 'queue_starved_no_refill';
                                    break;
                                }

                                $this->appendLedger($campaign->id, [
                                    'event' => 'starvation_idle',
                                    'reason' => 'queue_starved_no_refill',
                                    'sleep_seconds' => $starvationIdleSeconds,
                                    'open_tasks' => $openTasks,
                                    'open_projections' => $openProjections,
                                    'refill' => [
                                        'discovered' => (int) ($refill['discovered'] ?? 0),
                                        'reopened' => (int) ($refill['reopened'] ?? 0),
                                        'claimed' => (int) ($refill['claimed'] ?? 0),
                                        'enqueued' => (int) ($refill['enqueued'] ?? 0),
                                        'quarantined' => (int) ($refill['quarantined'] ?? 0),
                                        'deferred' => (int) ($refill['deferred'] ?? 0),
                                    ],
                                    'elapsed_seconds' => (int) $campaign->elapsed_seconds,
                                    'max_seconds' => (int) $campaign->max_seconds,
                                ]);

                                $idleStart = $this->now();
                                $this->writeHeartbeat($campaign->id);
                                $this->guard(fn () => $campaign->forceFill(['heartbeat_at' => now()])->save(), 'campaign_starvation_idle_heartbeat');
                                $this->responsiveSleep($campaign->id, $starvationIdleSeconds);
                                if ($this->stopRequested($campaign)) {
                                    $stop = 'kill_switch';
                                    break;
                                }
                                $this->writeHeartbeat($campaign->id);
                                $this->beat($campaign, max(1, $this->now() - $idleStart));
                                $lastTick = $this->now();

                                continue;
                            }
                            // else: territory widened into a PROVEN-safe rung — fall through and keep
                            // grinding the new scope (no break); the next refill discovers the new roots.
                        }
                    }

                    if ($this->stopRequested($campaign)) {
                        $stop = 'kill_switch';
                        break;
                    }

                    if ($pool instanceof LoopWorkerPool) {
                        $stopRequestedDuringParallelClaim = false;
                        $preTickInFlight = $pool->inFlight();
                        $tickStart = $this->now();
                        $budgetLeft = $campaign->max_seconds > 0 ? max(5, (int) $campaign->max_seconds - (int) $campaign->elapsed_seconds) : null;
                        $remaining = $this->grindTimeout($budgetLeft, (int) ($cfg['task_timeout_seconds'] ?? 1800));
                        $costGovernor = $this->costGovernorDecision($campaign, $scenarios);
                        if (($costGovernor['status'] ?? null) === 'throttled') {
                            $this->appendLedger($campaign->id, [
                                'event' => 'cost_governor_throttle',
                                'action' => $costGovernor['action'] ?? null,
                                'spend_usd_cents' => $costGovernor['spend_usd_cents'] ?? null,
                                'max_usd_cents' => $costGovernor['max_usd_cents'] ?? null,
                                'spend_pct' => $costGovernor['spend_pct'] ?? null,
                                'base_scenarios_per_task' => $costGovernor['base_scenarios_per_task'] ?? null,
                                'effective_scenarios_per_task' => $costGovernor['effective_scenarios_per_task'] ?? null,
                            ]);
                        }
                        $effectiveScenarios = max(1, (int) ($costGovernor['effective_scenarios_per_task'] ?? ($scenarios ?? config('atlas.loop.scenarios_per_task', 3))));
                        $tick = $pool->tick(
                            $effectiveWorkers,
                            $campaign->id,
                            function () use ($campaign, $taskLease, &$parallelClaimSeq, &$stopRequestedDuringParallelClaim): mixed {
                                if ($this->stopRequested($campaign)) {
                                    $stopRequestedDuringParallelClaim = true;

                                    return null;
                                }
                                $parallelClaimSeq++;
                                $worker = 'pool-'.$campaign->id.'-'.getmypid().'-'.$parallelClaimSeq;

                                $claimed = $this->guard(fn () => $this->store->claimNextTask($campaign->id, $worker, $taskLease), 'parallel_claim_next');
                                // L5-2: same parked-for-review Obra escalation on the parallel
                                // claim path. Fail-open, never blocks the worker dispatch.
                                if ($claimed instanceof AtlasLoopTask) {
                                    $this->maybeAutoEscalateToObra($campaign->id, $claimed);
                                }

                                return $claimed;
                            },
                            $taskLease,
                            $remaining,
                            $this->parallelWorkspaceRoot($campaign->id),
                            $effectiveScenarios,
                        );
                        $settled = array_values((array) ($tick['settled'] ?? []));
                        $this->settleTimedOutParallelWorkers($settled);
                        $this->reflectParallelSettledTargets($campaign, $settled);
                        $cycles += count($settled);
                        $settledSpendCents = $this->spendCentsFromWorkerSummaries($settled);
                        $tickEnd = $this->now();
                        $parallelHadActiveWork = $preTickInFlight > 0
                            || (int) ($tick['spawned'] ?? 0) > 0
                            || (int) ($tick['in_flight'] ?? 0) > 0
                            || $settled !== [];
                        $this->beat(
                            $campaign,
                            $parallelHadActiveWork ? max(0, $tickEnd - $lastTick) : max(0, $tickEnd - $tickStart),
                            $settledSpendCents,
                        );
                        $this->writeHeartbeat($campaign->id);
                        $this->appendLedger($campaign->id, [
                            'event' => 'parallel_pool_tick',
                            'cycle' => $cycles,
                            'requested_workers' => $requestedWorkers,
                            'effective_workers' => $effectiveWorkers,
                            'spawned' => (int) ($tick['spawned'] ?? 0),
                            'in_flight' => (int) ($tick['in_flight'] ?? 0),
                            'settled_count' => count($settled),
                            'settled' => $settled,
                            'backpressured' => (bool) ($tick['backpressured'] ?? false),
                            'effective_scenarios_per_task' => $effectiveScenarios,
                            'spend_usd_cents' => $settledSpendCents,
                            'cost_governor' => $costGovernor,
                            'elapsed_seconds' => $this->guard(fn () => $campaign->fresh()?->elapsed_seconds, 'campaign_fresh'),
                        ]);
                        $this->guard(fn () => $this->store->reclaimExpiredTasks($campaign->id), 'reclaim_cycle');
                        if ($stopRequestedDuringParallelClaim || $this->stopRequested($campaign)) {
                            $stop = 'kill_switch';
                            break;
                        }
                        if ((int) ($tick['spawned'] ?? 0) === 0 && (int) ($tick['in_flight'] ?? 0) === 0
                            && (int) $this->guard(fn () => $this->store->countOpen($campaign->id), 'count_open') === 0
                            && $this->openProjections($campaign->id) === 0) {
                            if ($idleOnStarvation) {
                                $this->appendLedger($campaign->id, [
                                    'event' => 'starvation_idle',
                                    'reason' => 'queue_exhausted',
                                    'sleep_seconds' => $starvationIdleSeconds,
                                    'open_tasks' => 0,
                                    'open_projections' => 0,
                                    'refill' => null,
                                    'elapsed_seconds' => (int) $campaign->elapsed_seconds,
                                    'max_seconds' => (int) $campaign->max_seconds,
                                ]);

                                $idleStart = $this->now();
                                $this->writeHeartbeat($campaign->id);
                                $this->guard(fn () => $campaign->forceFill(['heartbeat_at' => now()])->save(), 'campaign_parallel_starvation_idle_heartbeat');
                                $this->responsiveSleep($campaign->id, $starvationIdleSeconds);
                                if ($this->stopRequested($campaign)) {
                                    $stop = 'kill_switch';
                                    break;
                                }
                                $this->writeHeartbeat($campaign->id);
                                $this->beat($campaign, max(1, $this->now() - $idleStart));
                                $lastTick = $this->now();

                                continue;
                            }

                            $stop = 'queue_exhausted';
                            break;
                        }
                        $lastTick = $tickEnd;
                        $this->responsiveSleep($campaign->id, max(1, $rateLimit ?: 1));

                        continue;
                    }

                    // Claim + grind one task (serial, in-process — the proven v1 default).
                    if ($this->stopRequested($campaign)) {
                        $stop = 'kill_switch';
                        break;
                    }
                    $task = $this->guard(fn () => $this->store->claimNextTask($campaign->id, $workerId, $taskLease), 'claim_next');
                    if ($task === null) {
                        if ((int) $this->guard(fn () => $this->store->countOpen($campaign->id), 'count_open') === 0
                            && $this->openProjections($campaign->id) === 0) {
                            $stop = 'queue_exhausted';
                            break;
                        }
                        $this->responsiveSleep($campaign->id, max(1, $rateLimit ?: 1));
                        $lastTick = $this->now();

                        continue;
                    }

                    $this->writeHeartbeat($campaign->id);
                    // L5-2: a multi-file intent is too big for a propose-only micro-diff —
                    // package it for a governed Forge/Obra handoff (operator-reviewed, never
                    // auto-merged) instead of silently grinding it. Flag-gated, default OFF,
                    // fail-open: a bridge error is logged and the normal grind proceeds.
                    $this->maybeAutoEscalateToObra($campaign->id, $task);
                    $grindStart = $this->now();
                    // INDEPENDÊNCIA 24h+: o timeout do grind era o BUDGET INTEIRO (7 dias) —
                    // uma chamada de provider que travasse congelaria o supervisor por dias e
                    // o keepalive não pegaria (processo vivo). Capa por-task (default 1800s)
                    // para um único grind nunca segurar o loop; ainda respeita o budget total.
                    $budgetLeft = $campaign->max_seconds > 0 ? max(5, (int) $campaign->max_seconds - (int) $campaign->elapsed_seconds) : null;
                    $remaining = $this->grindTimeout($budgetLeft, (int) ($cfg['task_timeout_seconds'] ?? 1800));
                    $costGovernor = $this->costGovernorDecision($campaign, $scenarios);
                    if (($costGovernor['status'] ?? null) === 'throttled') {
                        $this->appendLedger($campaign->id, [
                            'event' => 'cost_governor_throttle',
                            'action' => $costGovernor['action'] ?? null,
                            'spend_usd_cents' => $costGovernor['spend_usd_cents'] ?? null,
                            'max_usd_cents' => $costGovernor['max_usd_cents'] ?? null,
                            'spend_pct' => $costGovernor['spend_pct'] ?? null,
                            'base_scenarios_per_task' => $costGovernor['base_scenarios_per_task'] ?? null,
                            'effective_scenarios_per_task' => $costGovernor['effective_scenarios_per_task'] ?? null,
                        ]);
                    }
                    $effectiveScenarios = max(1, (int) ($costGovernor['effective_scenarios_per_task'] ?? ($scenarios ?? config('atlas.loop.scenarios_per_task', 3))));
                    $progress = function () use ($campaign, $task, $workerId, $taskLease): void {
                        $this->guard(fn () => $this->store->renewLease($task->id, $workerId, $taskLease), 'grind_progress_renew_lease');
                        $this->writeHeartbeat($campaign->id);
                    };
                    $result = $this->grinder->grind($task, $workerId, $effectiveScenarios, '', $remaining, $progress);
                    $spendCents = $this->spendCentsFromResult($result);
                    $this->beat($campaign, $this->now() - $grindStart, $spendCents);
                    $this->recordBreaker((string) $campaign->id, is_array($result) ? $result : []); // §4 provider health tick

                    // Results -> Sources, so the queue self-sustains.
                    $targetId = (string) (is_array($task->payload) ? ($task->payload['_target_id'] ?? '') : '');
                    if ($targetId !== '') {
                        $this->guard(fn () => $this->loopBack->reflect($campaign->id, ['target_id' => $targetId, 'status' => (string) $result['status'], 'reason' => (string) ($result['reason'] ?? '')]), 'loop_back');
                        $this->guard(fn () => $campaign->increment('loopbacks'), 'campaign_loopbacks');
                    }

                    $cycles++;
                    $this->appendLedger($campaign->id, [
                        'cycle' => $cycles,
                        'task_id' => $task->id,
                        'status' => $result['status'],
                        'proposals' => $result['proposals'] ?? 0,
                        'cost_estimate_usd' => $result['cost_estimate_usd'] ?? null,
                        'spend_usd_cents' => $spendCents,
                        'cost_governor' => $costGovernor,
                        'elapsed_seconds' => $this->guard(fn () => $campaign->fresh()?->elapsed_seconds, 'campaign_fresh'),
                    ]);

                    $this->guard(fn () => $this->store->reclaimExpiredTasks($campaign->id), 'reclaim_cycle');
                    if ($rateLimit > 0) {
                        $this->responsiveSleep($campaign->id, $rateLimit);
                    }
                    $lastTick = $this->now();
                } catch (Throwable $e) {
                    // A transient DB hiccup is a recoverable park, not a crash. Anything else
                    // (a real bug) still propagates to the fatal handler below, unmasked.
                    if (! ($e instanceof AtlasLoopTransientDbException) && ! AtlasLoopDbResilience::isTransient($e)) {
                        throw $e;
                    }
                    $outageStartedAt ??= $this->now();
                    $outageFor = max(0, $this->now() - $outageStartedAt);
                    $this->appendLedger($campaign->id, [
                        'event' => 'db_outage',
                        'for_seconds' => $outageFor,
                        'abort_after' => $outageAbortSeconds,
                        'detail' => mb_substr($e->getMessage(), 0, 160),
                    ]);
                    $this->writeHeartbeat($campaign->id); // stay "alive, parked" for the watchdog
                    if ($outageFor >= $outageAbortSeconds) {
                        $stop = 'db_unavailable';
                        break;
                    }
                    $this->responsiveSleep($campaign->id, $outagePollSeconds);
                    $lastTick = $this->now();
                }
            }
        } catch (Throwable $e) {
            $stop = 'crashed: '.mb_substr($e->getMessage(), 0, 120);
        } finally {
            if ($pool instanceof LoopWorkerPool && $pool->inFlight() > 0) {
                $drained = $pool->drain();
                try {
                    $this->settleDrainedParallelWorkers($drained, $stop);
                } catch (Throwable $e) {
                    $this->appendLedger($campaign->id, [
                        'event' => 'parallel_pool_drain_settle_failed',
                        'stop_reason' => $stop,
                        'detail' => mb_substr($e->getMessage(), 0, 160),
                    ]);
                }
                $this->appendLedger($campaign->id, [
                    'event' => 'parallel_pool_drain',
                    'drained' => $drained,
                ]);
            }
            // Terminal cleanup MUST NOT throw — otherwise the lock leaks and the campaign
            // row is stranded in status=running (the exact historical failure: a still-down
            // DB re-threw from this reclaim, skipping releaseLock + finish). Best-effort.
            try {
                $this->guard(fn () => $this->store->reclaimExpiredTasks($campaign->id), 'reclaim_finally');
            } catch (Throwable) {
                // swallowed: a still-down DB at shutdown cannot block lock release
            }
            $this->releaseLock($campaign->id);
        }

        return $this->finish($this->safeFresh($campaign), $stop, $cycles);
    }

    /**
     * S2 — PROJECTION-STAGE drainer. Each tick, claim up to projection_drain_per_tick dispatched projection
     * rows and delegate each to the {@see \App\Services\Ai\AutonomousEvolution\AtlasLoopProjectionWorker}
     * (the severed designer↔critic engine), which either MINTS a task (converged) or PARKS (non-converged).
     * reclaimExpired runs first so a worker that died mid-projection frees its lease. Gated by flag +
     * non-null pipeline; a no-op (no claims, no reclaim) when projection_stage is OFF or the pipeline is
     * unwired, so the OLD path is byte-identical. Fail-OPEN: a drain hiccup is swallowed (the loop never
     * stalls on the projection stage). The claim owner is per-tick unique so two ticks never collide.
     */
    private function drainProjections(AtlasLoopCampaign $campaign): void
    {
        $pipeline = $this->deliveryPipeline();
        if ($pipeline === null || ! (bool) config('atlas.loop.projection_stage_enabled', true)) {
            return;
        }
        try {
            $this->guard(fn () => $pipeline->reclaimExpired($campaign->id), 'projection_reclaim_expired');
            $worker = $this->projectionWorker ?? new \App\Services\Ai\AutonomousEvolution\AtlasLoopProjectionWorker($this->store, $pipeline);
            $perTick = max(1, (int) config('atlas.loop.projection_drain_per_tick', 2));
            $owner = 'projection-'.$campaign->id.'-'.getmypid().'-'.$this->now();
            for ($i = 0; $i < $perTick; $i++) {
                $row = $this->guard(fn () => $pipeline->claimNextProjection($campaign->id, $owner, max(60, (int) config('atlas.loop.projection_lease_seconds', 300))), 'projection_claim_next');
                if (! is_array($row)) {
                    break;
                }
                $outcome = $worker->process($row);
                $this->appendLedger($campaign->id, [
                    'event' => 'projection_drained',
                    'objective_id' => (string) ($outcome['objective_id'] ?? ''),
                    'outcome' => (string) ($outcome['outcome'] ?? 'unknown'),
                    'status' => $outcome['status'] ?? null,
                    'reason' => $outcome['reason'] ?? null,
                ]);
                $this->writeHeartbeat($campaign->id); // a long projection keeps the campaign "alive"
            }
        } catch (Throwable) {
            // fail-open: the projection drain must never be able to stall the 24h loop.
        }
    }

    /**
     * S2 — the supervisor's anti-starvation signal for the projection stage: in-flight (dispatched,
     * not-yet-parked/completed) projections count as OPEN work, so severing produce() into a µs-returning
     * dispatch never trips queue_starved/queue_exhausted while a projection is still being run. Returns 0
     * when the flag is OFF or the pipeline is unwired — so the three starvation clauses stay byte-identical
     * (… && 0 === 0 ⇒ … && true) on the OLD path. Best-effort: a read failure conservatively yields 0
     * (never wedges the loop open on a DB blip).
     */
    private function openProjections(string $campaignId): int
    {
        $pipeline = $this->deliveryPipeline();
        if ($pipeline === null || ! (bool) config('atlas.loop.projection_stage_enabled', true)) {
            return 0;
        }
        try {
            return (int) $this->guard(fn () => $pipeline->countOpenProjections($campaignId), 'count_open_projections');
        } catch (Throwable) {
            return 0;
        }
    }

    private function deliveryPipeline(): ?AtlasLoopDeliveryPipeline
    {
        if ($this->pipeline instanceof AtlasLoopDeliveryPipeline) {
            return $this->pipeline;
        }

        try {
            return app(AtlasLoopDeliveryPipeline::class);
        } catch (Throwable) {
            return null;
        }
    }

    // --- read-only observability (for the status command + an external watchdog) ---

    public function heartbeatStatus(string $campaignId): array
    {
        $path = $this->storageDir($campaignId).'/heartbeat';
        $ts = is_file($path) ? (int) trim((string) @file_get_contents($path)) : null;

        return ['heartbeat_at' => $ts, 'age_seconds' => $ts !== null ? max(0, $this->now() - $ts) : null];
    }

    public function lockStatus(string $campaignId): array
    {
        $lock = $this->readLock($campaignId);

        return ['locked' => $lock !== null, 'pid' => $lock['pid'] ?? null, 'alive' => isset($lock['pid']) ? ! $this->lockProcessIsDead((int) $lock['pid']) : null];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function readLedger(string $campaignId, int $tail = 20): array
    {
        $path = $this->ledgerPath($campaignId);
        if (! is_file($path)) {
            return [];
        }
        $lines = array_values(array_filter(explode("\n", (string) @file_get_contents($path)), static fn (string $l): bool => trim($l) !== ''));
        $lines = array_slice($lines, -max(1, $tail));

        return array_values(array_filter(array_map(static fn (string $l): mixed => json_decode($l, true), $lines), 'is_array'));
    }

    // --- internals ---

    private function resolveCampaign(array $input): AtlasLoopCampaign
    {
        $id = trim((string) ($input['campaign_id'] ?? ''));
        // Only a VALID uuid may touch the uuid column: find() on a non-uuid raised a QueryException
        // (a garbage --campaign-id crashed the whole launch). A valid-but-unknown id is honoured on
        // CREATE below so the launched id == the campaign id (the keepalive finds the supervisor).
        $validId = $id !== '' && Str::isUuid($id);
        if ($validId) {
            $existing = AtlasLoopCampaign::query()->find($id);
            if ($existing instanceof AtlasLoopCampaign) {
                return $existing;
            }
        }

        return $this->store->openCampaign(
            (string) ($input['goal'] ?? 'Atlas Evolution Loop campaign'),
            (string) ($input['base_workspace'] ?? base_path()),
            [
                'max_seconds' => (int) ($input['max_seconds'] ?? config('atlas.loop.campaign.max_seconds', 86400)),
                'max_tasks' => (int) ($input['max_tasks'] ?? 0),
                'max_proposals' => (int) ($input['max_proposals'] ?? 0),
                'max_usd_cents' => (int) ($input['max_usd_cents'] ?? 0),
            ],
            ['scenarios_per_task' => $input['scenarios'] ?? null, 'shadow' => (bool) ($input['shadow'] ?? true), 'workers' => (int) ($input['workers'] ?? 1)],
            (string) ($input['provider'] ?? ''),
            $validId ? $id : null,
        );
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function markCampaignRunning(AtlasLoopCampaign $campaign, array $input, string $baseWorkspace): void
    {
        $attributes = [
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'stop_reason' => null,
            'paused_at' => null,
            'finished_at' => null,
            'completed_at' => null,
            'started_at' => $campaign->started_at ?? now(),
            'heartbeat_at' => now(),
        ];

        $goal = trim((string) ($input['goal'] ?? ''));
        if ($goal !== '') {
            $attributes['goal'] = $goal;
        }
        if ($baseWorkspace !== '') {
            $attributes['base_workspace'] = $baseWorkspace;
        }
        foreach (['max_seconds', 'max_tasks', 'max_proposals', 'max_usd_cents'] as $key) {
            if (array_key_exists($key, $input)) {
                $attributes[$key] = max(0, (int) $input[$key]);
            }
        }
        if (array_key_exists('provider', $input)) {
            $attributes['provider'] = (string) $input['provider'];
        }

        $config = is_array($campaign->config) ? $campaign->config : [];
        foreach (['scenarios' => 'scenarios_per_task', 'shadow' => 'shadow', 'workers' => 'workers'] as $inputKey => $configKey) {
            if (array_key_exists($inputKey, $input)) {
                $config[$configKey] = $inputKey === 'shadow' ? (bool) $input[$inputKey] : (int) $input[$inputKey];
            }
        }
        foreach (['idle_on_starvation' => 'idle_on_starvation'] as $inputKey => $configKey) {
            if (array_key_exists($inputKey, $input)) {
                $config[$configKey] = (bool) $input[$inputKey];
            }
        }
        foreach (['starvation_idle_seconds' => 'starvation_idle_seconds'] as $inputKey => $configKey) {
            if (array_key_exists($inputKey, $input)) {
                $config[$configKey] = max(5, (int) $input[$inputKey]);
            }
        }
        if ($config !== []) {
            $attributes['config'] = $config;
        }

        $campaign->forceFill($attributes)->save();
    }

    private function finish(AtlasLoopCampaign $campaign, string $stop, int $cycles): array
    {
        if ($stop === self::STOP_CODE_DRIFT_RESTART) {
            try {
                // A code-drift exit is intentionally restartable: keep the campaign running
                // so the external keepalive resumes it under the fresh HEAD.
                $this->guard(fn () => $campaign->forceFill([
                    'status' => AtlasLoopCampaign::STATUS_RUNNING,
                    'stop_reason' => $stop,
                ])->save(), 'campaign_code_drift_restart');
            } catch (Throwable) {
                $this->appendLedger($campaign->id, ['event' => 'restart_unpersisted', 'stop_reason' => $stop]);
            }

            return [
                'schema_version' => 'atlas.loop.campaign_run.v1',
                'campaign_id' => $campaign->id,
                'stop_reason' => $stop,
                'cycles' => $cycles,
                'tasks_processed' => (int) $campaign->tasks_processed,
                'proposals_total' => (int) $campaign->proposals_count,
                'scenarios_explored' => (int) $campaign->scenarios_explored,
                'elapsed_seconds' => (int) $campaign->elapsed_seconds,
                'restartable' => true,
                'merged_to_main' => false,
            ];
        }

        $terminal = (str_starts_with($stop, 'crashed') || $stop === 'kill_switch' || $stop === 'db_unavailable')
            ? AtlasLoopCampaign::STATUS_ABORTED
            : AtlasLoopCampaign::STATUS_COMPLETED;
        $attributes = [
            'status' => $terminal,
            'stop_reason' => $stop,
            'finished_at' => now(),
            'completed_at' => now(),
        ];
        try {
            // Retry the terminal write through the guard so a blip at shutdown still moves
            // the row OFF status=running rather than stranding it.
            $this->guard(fn () => $campaign->forceFill($attributes)->save(), 'campaign_finish');
        } catch (Throwable) {
            // A sustained outage can outlast even this. The lock is already released, so a
            // restart's rebuildInFlight resumes cleanly; record the intended terminal state.
            $campaign->forceFill($attributes);
            $this->appendLedger($campaign->id, ['event' => 'finish_unpersisted', 'stop_reason' => $stop, 'status' => $terminal]);
        }

        return [
            'schema_version' => 'atlas.loop.campaign_run.v1',
            'campaign_id' => $campaign->id,
            'stop_reason' => $stop,
            'cycles' => $cycles,
            'tasks_processed' => (int) $campaign->tasks_processed,
            'proposals_total' => (int) $campaign->proposals_count,
            'scenarios_explored' => (int) $campaign->scenarios_explored,
            'elapsed_seconds' => (int) $campaign->elapsed_seconds,
            'merged_to_main' => false,
        ];
    }

    private function shouldRefillQueue(int $pendingTasks, int $watermark, ?LoopWorkerPool $pool): bool
    {
        $pendingTasks = max(0, $pendingTasks);
        $watermark = max(1, $watermark);

        if (! $pool instanceof LoopWorkerPool) {
            return $pendingTasks < $watermark;
        }

        return ($pendingTasks + $pool->inFlight()) < $watermark;
    }

    private function refillWatermark(int $watermark, int $effectiveWorkers, ?LoopWorkerPool $pool): int
    {
        $watermark = max(1, $watermark);
        if (! $pool instanceof LoopWorkerPool) {
            return $watermark;
        }

        return max($watermark, max(1, $effectiveWorkers));
    }

    private function beat(AtlasLoopCampaign $campaign, int $deltaSeconds, int $addSpendCents = 0): void
    {
        $this->guard(fn () => $campaign->beat(max(0, $deltaSeconds), max(0, $addSpendCents)), 'campaign_beat');
    }

    /**
     * The L5-7 governor is not a separate router/runtime. It is the campaign's
     * existing cost budget interpreted before each grind: near the cap, reduce
     * scenarios; at/over the cap, the existing budgetStopReason() stops the loop.
     *
     * @return array<string,mixed>
     */
    private function costGovernorDecision(AtlasLoopCampaign $campaign, ?int $scenarios): array
    {
        $baseScenarios = max(1, (int) ($scenarios ?? config('atlas.loop.scenarios_per_task', 3)));
        $cfg = (array) config('atlas.loop.cost_governor', []);
        $enabled = (bool) ($cfg['enabled'] ?? false);
        $minScenarios = max(1, (int) ($cfg['min_scenarios_per_task'] ?? 1));
        $maxCents = max(0, (int) $campaign->max_usd_cents);
        $spendCents = max(0, (int) $campaign->spend_usd_cents);

        $base = [
            'schema_version' => 'atlas.loop.cost_governor_decision.v1',
            'enabled' => $enabled,
            'status' => $enabled ? 'monitoring' : 'disabled',
            'action' => 'none',
            'spend_usd_cents' => $spendCents,
            'max_usd_cents' => $maxCents,
            'spend_pct' => $maxCents > 0 ? round(($spendCents / $maxCents) * 100, 2) : null,
            'base_scenarios_per_task' => $baseScenarios,
            'effective_scenarios_per_task' => $baseScenarios,
            'min_scenarios_per_task' => $minScenarios,
        ];

        if (! $enabled) {
            return $base;
        }
        if ($maxCents <= 0) {
            return array_replace($base, [
                'status' => 'no_cost_cap_configured',
                'reason' => 'campaign_max_usd_cents_zero',
            ]);
        }

        $throttlePct = max(0.0, min(100.0, (float) ($cfg['throttle_at_pct'] ?? 80.0)));
        $spendPct = (float) $base['spend_pct'];
        if ($spendPct >= 100.0) {
            return array_replace($base, [
                'status' => 'over_cap',
                'action' => 'pause_on_cost_cap',
                'effective_scenarios_per_task' => $minScenarios,
                'reason' => 'budget_stop_reason_cost_cap_will_apply',
            ]);
        }
        if ($spendPct >= $throttlePct && $baseScenarios > $minScenarios) {
            return array_replace($base, [
                'status' => 'throttled',
                'action' => 'reduce_scenarios_per_task',
                'effective_scenarios_per_task' => $minScenarios,
                'threshold_pct' => $throttlePct,
            ]);
        }

        return array_replace($base, [
            'threshold_pct' => $throttlePct,
        ]);
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function spendCentsFromResult(array $result): int
    {
        if (is_numeric($result['cost_cents'] ?? null) && (int) $result['cost_cents'] > 0) {
            return (int) $result['cost_cents'];
        }
        if (is_numeric($result['cost_estimate_usd'] ?? null) && (float) $result['cost_estimate_usd'] > 0.0) {
            return max(1, (int) ceil((float) $result['cost_estimate_usd'] * 100));
        }

        return 0;
    }

    /**
     * @param  list<array<string,mixed>>  $settled
     */
    private function spendCentsFromWorkerSummaries(array $settled): int
    {
        $spend = 0;
        foreach ($settled as $summary) {
            $result = is_array($summary['result'] ?? null) ? $summary['result'] : [];
            $spend += $this->spendCentsFromResult($result);
        }

        return $spend;
    }

    /**
     * @param  list<array<string,mixed>>  $settled
     */
    private function settleTimedOutParallelWorkers(array $settled): void
    {
        foreach ($settled as $summary) {
            if (! (bool) ($summary['timed_out'] ?? false)) {
                continue;
            }

            $taskId = trim((string) ($summary['task_id'] ?? ''));
            $workerId = trim((string) ($summary['worker_id'] ?? ''));
            if ($taskId === '' || $workerId === '') {
                continue;
            }

            $this->guard(fn () => $this->store->completeTask($taskId, $workerId, [
                'status' => 'failed',
                'reason' => 'parallel_worker_timeout',
                'exit_code' => $summary['exit_code'] ?? null,
                'duration_ms' => (int) ($summary['duration_ms'] ?? 0),
            ], false), 'parallel_worker_timeout_complete');
        }
    }

    /**
     * @param  list<array<string,mixed>>  $settled
     */
    /**
     * §4 Record one grind outcome's provider-health into the circuit-breaker. Flag-OFF ⇒ no-op (the breaker is
     * never written, so it can never open ⇒ byte-identical). A provider-down grind (no winner, 0 scenarios)
     * increments the streak; a healthy grind resets it.
     *
     * @param  array<string,mixed>  $outcome
     */
    private function recordBreaker(string $campaignId, array $outcome): void
    {
        if (! (bool) config('atlas.loop.provider_circuit_breaker_enabled', false)) {
            return;
        }
        (new AtlasLoopProviderCircuitBreaker)->record($campaignId, $outcome);
    }

    /** §4 Should the loop pause NOW because the provider has been down for >= threshold consecutive grinds? */
    private function breakerWantsPause(AtlasLoopCampaign $campaign): bool
    {
        if (! (bool) config('atlas.loop.provider_circuit_breaker_enabled', false)) {
            return false;
        }
        $threshold = max(1, (int) config('atlas.loop.provider_circuit_breaker_threshold', 5));

        return (new AtlasLoopProviderCircuitBreaker)->isOpen((string) $campaign->id, $threshold);
    }

    private function reflectParallelSettledTargets(AtlasLoopCampaign $campaign, array $settled): void
    {
        foreach ($settled as $summary) {
            $taskId = trim((string) ($summary['task_id'] ?? ''));
            if ($taskId === '') {
                continue;
            }

            $task = AtlasLoopTask::query()->find($taskId);
            $targetId = (string) (is_array($task?->payload) ? ($task->payload['_target_id'] ?? '') : '');
            if ($targetId === '') {
                continue;
            }

            $result = is_array($summary['result'] ?? null) ? $summary['result'] : [];
            $status = trim((string) ($result['status'] ?? ''));
            $reason = trim((string) ($result['reason'] ?? ''));
            if ($status === '' && (bool) ($summary['timed_out'] ?? false)) {
                $status = 'failed';
                $reason = 'parallel_worker_timeout';
            }
            if ($status === '' && array_key_exists('has_winner', $result)) {
                $status = (bool) $result['has_winner'] ? 'winner' : 'no_winner';
            }
            if ($status === '') {
                continue;
            }

            $this->recordBreaker((string) $campaign->id, $result + ['status' => $status]); // §4 provider health tick

            $this->guard(fn () => $this->loopBack->reflect($campaign->id, [
                'target_id' => $targetId,
                'status' => $status,
                'reason' => $reason,
            ]), 'parallel_loop_back');
            $this->guard(fn () => $campaign->increment('loopbacks'), 'campaign_parallel_loopbacks');
        }
    }

    private function reconcileUnreflectedParallelTargets(AtlasLoopCampaign $campaign): void
    {
        $limit = max(1, (int) config('atlas.loop.parallel_loop_back_reconcile_limit', 64));
        $scanned = 0;
        $reflected = 0;

        $tasks = AtlasLoopTask::query()
            ->where('campaign_id', $campaign->id)
            ->whereIn('status', [AtlasLoopTask::STATUS_DONE, AtlasLoopTask::STATUS_FAILED])
            ->whereRaw("payload->>'_target_id' IS NOT NULL")
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        foreach ($tasks as $task) {
            $scanned++;
            $targetId = (string) (is_array($task->payload) ? ($task->payload['_target_id'] ?? '') : '');
            if ($targetId === '') {
                continue;
            }
            $target = AtlasLoopTarget::query()
                ->where('campaign_id', $campaign->id)
                ->whereKey($targetId)
                ->first();
            if (! $target instanceof AtlasLoopTarget || $target->status !== AtlasLoopTarget::STATUS_QUEUED) {
                continue;
            }

            $result = is_array($task->result) ? $task->result : [];
            $status = trim((string) ($result['status'] ?? ''));
            $reason = trim((string) ($result['reason'] ?? ''));
            if ($status === '' && $task->status === AtlasLoopTask::STATUS_DONE) {
                $status = ((bool) ($result['has_winner'] ?? false) || (int) ($result['proposals'] ?? 0) > 0) ? 'winner' : 'no_winner';
            }
            if ($status === '' && $task->status === AtlasLoopTask::STATUS_FAILED) {
                $status = 'failed';
                $reason = $reason !== '' ? $reason : 'parallel_task_failed';
            }
            if ($status === '') {
                continue;
            }

            $this->loopBack->reflect($campaign->id, [
                'target_id' => $targetId,
                'status' => $status,
                'reason' => $reason,
            ]);
            $campaign->increment('loopbacks');
            $reflected++;
        }

        if ($scanned > 0 || $reflected > 0) {
            $this->appendLedger($campaign->id, [
                'event' => 'parallel_loop_back_reconcile',
                'scanned' => $scanned,
                'reflected' => $reflected,
            ]);
        }
    }

    /**
     * @param  list<array<string,mixed>>  $drained
     */
    private function settleDrainedParallelWorkers(array $drained, string $stopReason): void
    {
        foreach ($drained as $summary) {
            $taskId = trim((string) ($summary['task_id'] ?? ''));
            $workerId = trim((string) ($summary['worker_id'] ?? ''));
            if ($taskId === '' || $workerId === '') {
                continue;
            }

            $reason = match ($stopReason) {
                'time_budget_reached' => 'parallel_worker_budget_stop',
                'kill_switch' => 'parallel_worker_kill_switch',
                self::STOP_CODE_DRIFT_RESTART => 'parallel_worker_code_drift_restart',
                default => 'parallel_worker_drained',
            };

            $this->guard(fn () => $this->store->completeTask($taskId, $workerId, [
                'status' => 'failed',
                'reason' => $reason,
                'stop_reason' => $stopReason,
                'exit_code' => $summary['exit_code'] ?? null,
                'duration_ms' => (int) ($summary['duration_ms'] ?? 0),
            ], false), 'parallel_worker_drain_complete');
        }
    }

    private function parallelWorkspaceRoot(string $campaignId): string
    {
        $root = $this->storageDir($campaignId).'/workers';
        if (! is_dir($root)) {
            @mkdir($root, 0o755, true);
        }

        return $root;
    }

    /** Route a supervisor-owned durable write through the transient-DB resilience guard. */
    private function guard(callable $op, string $label): mixed
    {
        return $this->db->run($op, $label);
    }

    private function stopRequested(AtlasLoopCampaign $campaign): bool
    {
        if ($this->killFileExists($campaign->id)) {
            return true;
        }

        $this->guard(fn () => $campaign->refresh(), 'campaign_refresh_stop_requested');

        return $this->killFileExists($campaign->id) || (bool) $campaign->kill_switch;
    }

    /** A DB read that never throws — falls back to the in-memory snapshot if the DB is down at shutdown. */
    private function safeFresh(AtlasLoopCampaign $campaign): AtlasLoopCampaign
    {
        try {
            return $this->guard(fn () => $campaign->fresh(), 'campaign_fresh_final') ?? $campaign;
        } catch (Throwable) {
            return $campaign;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function taxa2Overlay(?int $explicitScenarios): ?array
    {
        if (! (bool) config('atlas.loop.taxa2_dials.enabled', false)) {
            return null;
        }

        return $this->taxa2Dials->evaluate([
            'hours' => (int) config('atlas.loop.taxa2_dials.window_hours', 24),
            'explicit_scenarios' => $explicitScenarios,
            'write_receipt' => (bool) config('atlas.loop.taxa2_dials.receipt_on_supervisor_boot', true),
            'source' => 'atlas:loop:campaign.supervisor_boot',
        ]);
    }

    /**
     * L5-2: the orphaned Loop -> Obra bridge, finally wired into the live loop.
     *
     * When a claimed task's intent spans >= obra_bridge.min_files DISTINCT files it is a
     * poor fit for the propose-only single-diff grind; package it for a governed Forge/Obra
     * handoff (operator-reviewed) instead. This NEVER dispatches a provider and NEVER merges:
     * {@see AtlasLoopObraBridgeService::bridge()} is preflight-only (no create_proposal here,
     * write_receipt OFF), and we assert the packet's operator gate stays closed before
     * recording it. A single-file intent (the normal Loop case) is logged as skipped and
     * grinds as usual.
     *
     * Flag-gated (atlas.loop.obra_bridge.auto_escalate, default OFF) and fail-OPEN: any error
     * is captured to the ledger and the grind proceeds — auto-escalation must never be able to
     * stall the 24h loop.
     */
    private function maybeAutoEscalateToObra(string $campaignId, AtlasLoopTask $task): void
    {
        if (! (bool) config('atlas.loop.obra_bridge.auto_escalate', false)) {
            return;
        }

        try {
            $minFiles = max(2, (int) config('atlas.loop.obra_bridge.min_files', 2));
            $files = $this->intentFiles($task);
            if (count($files) < $minFiles) {
                $this->appendLedger($campaignId, [
                    'event' => 'obra_auto_escalation_skipped',
                    'task_id' => $task->id,
                    'reason' => 'single_file_intent_below_min_files',
                    'file_count' => count($files),
                    'min_files' => $minFiles,
                ]);

                return;
            }

            $intent = trim((string) $task->objective) !== ''
                ? (string) $task->objective
                : 'Atlas Loop multi-file intent '.$task->id;

            // Preflight ONLY: no provider dispatch, no Obra creation, no backlog proposal,
            // no receipt write. The bridge composes the governed handoff packet and we record
            // it; the operator authorizes any real Forge execution out-of-band.
            $packet = $this->obraBridge->bridge([
                'intent' => $intent,
                'files' => $files,
                'min_files' => $minFiles,
                'create_proposal' => false,
                'write_receipt' => false,
            ]);

            // Never-merge / operator-gated invariant (defense-in-depth, independent of the
            // bridge's own asserts): if the packet ever claimed auto-execute or a merge, we
            // refuse to record it as an escalation and flag the violation instead.
            $autoExecuteAllowed = (bool) data_get($packet, 'operator_approval.auto_execute_allowed', false);
            $autoExecutionStarted = (bool) data_get($packet, 'claim_policy.auto_execution_started', false);
            $providerDispatched = (bool) data_get($packet, 'claim_policy.provider_dispatches_now', false);
            if ($autoExecuteAllowed || $autoExecutionStarted || $providerDispatched) {
                $this->appendLedger($campaignId, [
                    'event' => 'obra_auto_escalation_refused',
                    'task_id' => $task->id,
                    'reason' => 'bridge_packet_violated_operator_gate',
                    'auto_execute_allowed' => $autoExecuteAllowed,
                    'auto_execution_started' => $autoExecutionStarted,
                    'provider_dispatches_now' => $providerDispatched,
                ]);

                return;
            }

            $this->appendLedger($campaignId, [
                'event' => 'obra_auto_escalation',
                'task_id' => $task->id,
                'bridge_status' => (string) ($packet['status'] ?? 'unknown'),
                'file_count' => count($files),
                'min_files' => $minFiles,
                'target_files' => array_slice($files, 0, 20),
                'multi_file_detected' => (bool) data_get($packet, 'bridge_packet.multi_file_detected', false),
                'packet_hash' => (string) data_get($packet, 'bridge_packet.packet_hash', ''),
                'operator_approval_required' => (bool) data_get($packet, 'operator_approval.required', true),
                'auto_execute_allowed' => $autoExecuteAllowed,
                'next_safe_action' => data_get($packet, 'operator_approval.next_safe_action'),
                'blockers' => array_slice((array) ($packet['blockers'] ?? []), 0, 20),
            ]);
        } catch (Throwable $e) {
            // Fail-OPEN: an escalation hiccup must never stall the live loop.
            $this->appendLedger($campaignId, [
                'event' => 'obra_auto_escalation_error',
                'task_id' => $task->id,
                'detail' => mb_substr($e->getMessage(), 0, 200),
            ]);
        }
    }

    /**
     * The DISTINCT real-repo files a claimed task's intent touches, drawn from the durable
     * payload's scope signals (in priority of specificity) plus the target_path column. This
     * is what decides single- vs multi-file; it never reaches outside the task's own record.
     *
     * @return list<string>
     */
    private function intentFiles(AtlasLoopTask $task): array
    {
        $payload = is_array($task->payload) ? $task->payload : [];
        $files = [];

        // Explicit multi-file lists first (the authoritative scope), then single-file pointers.
        foreach (['allowed_files', 'target_files', 'expected_files'] as $key) {
            foreach ((array) ($payload[$key] ?? []) as $value) {
                $this->collectPath($files, $value);
            }
        }
        foreach (['target_relative_path', 'target_repo_path'] as $key) {
            $this->collectPath($files, $payload[$key] ?? null);
        }
        $this->collectPath($files, $task->target_path);

        // Keys hold the deduped paths; values are the presence sentinel.
        return array_keys($files);
    }

    /**
     * @param  array<string,true>  $files
     */
    private function collectPath(array &$files, mixed $value): void
    {
        if (! is_string($value)) {
            return;
        }
        $path = ltrim(trim($value), '/');
        if ($path !== '') {
            $files[$path] = true;
        }
    }

    /**
     * SLICE C-territory-ladder — the loop's scope-widener, wired LIVE but CONSERVATIVE.
     *
     * Called ONLY at supply exhaustion (the queue is starved AND no refill produced work). It always
     * EVALUATES the territory ladder and LOGS the verdict, but it only ACTUATES a widening into an
     * OPERATOR-DEFINED rung. With no rung configured (the default — there is no
     * atlas.loop.territory_ladder_rungs today) the decision is widen=false reason='no_rung_defined'
     * and this is a pure logged no-op: the loop stops at queue_starved exactly as it did before.
     *
     * The {@see AtlasLoopTerritoryLadder::canPromote()} no-blinder invariant is LOAD-BEARING: a rung
     * whose new root has no FROZEN safety file under it is REJECTED, so the loop can NEVER widen into a
     * territory whose judge it could then edit. Honoring the operator principle "scope released gradually
     * by the operator, starting with the loop itself", actuation requires BOTH a deliberate rung config
     * AND a passing safety+promotion verdict.
     *
     * @return bool true iff the territory was actually widened (scope grew); false = stop as before.
     */
    private function maybeClimbTerritory(AtlasLoopCampaign $campaign): bool
    {
        $rungs = (array) config('atlas.loop.territory_ladder_rungs', []);
        $currentRoots = (array) config(
            'atlas.loop.campaign.discovery_roots',
            ['app/Services/Ai/AutonomousEvolution'],
        );

        $decision = $this->territoryClimbDecision($currentRoots, $rungs, $campaign->id);

        $this->appendLedger($campaign->id, [
            'event' => 'territory_ladder_eval',
            'widen' => (bool) $decision['widen'],
            'reason' => (string) $decision['reason'],
            'current_roots' => array_values($currentRoots),
            'next_roots' => $decision['next_roots'],
            'violations' => array_slice((array) $decision['violations'], 0, 20),
        ]);

        if (! (bool) $decision['widen'] || ! is_array($decision['next_roots'])) {
            return false;
        }

        // Persist the widened discovery roots onto the campaign's config (the array-cast `config`
        // attribute is the existing per-campaign scheme-freeze mechanism). The widened scope is durable
        // + logged. NOTE: the discovery service currently sources roots from the GLOBAL
        // config('atlas.loop.campaign.discovery_roots'), not from $campaign->config['discovery_roots'];
        // making the persisted scope actually drive the next refill is a deliberately-deferred follow-up
        // (no operator rung exists today, so this branch is unreachable until the operator defines one).
        // Guarded so a DB blip degrades to "did not widen" (stop as before), never a crash.
        $config = is_array($campaign->config) ? $campaign->config : [];
        $config['discovery_roots'] = array_values($decision['next_roots']);
        $config['territory_widened_at'] = $this->now();

        try {
            $this->guard(fn () => $campaign->forceFill(['config' => $config])->save(), 'territory_widen_persist');
        } catch (Throwable $e) {
            $this->appendLedger($campaign->id, [
                'event' => 'territory_ladder_widen_unpersisted',
                'detail' => mb_substr($e->getMessage(), 0, 160),
            ]);

            return false;
        }

        $this->appendLedger($campaign->id, [
            'event' => 'territory_ladder_widened',
            'next_roots' => array_values($decision['next_roots']),
        ]);

        return true;
    }

    /**
     * The PURE, testable territory-climb decision — no DB write, no provider, no break logic. Given the
     * loop's CURRENT discovery roots, the OPERATOR-DEFINED rungs and the campaign id (for the certified
     * count), it returns whether widening is allowed and into which roots.
     *
     * The safety check is the {@see AtlasLoopTerritoryLadder} canPromote no-blinder invariant: the
     * descriptor's frozen_safety_files is the REAL pétreo list {@see AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS},
     * so a rung that introduces a root with NO frozen judge under it yields an 'unprotected_root:...'
     * violation and widen=false — the loop can never blind itself by widening into an unprotected scope.
     *
     * @param  list<string>|array<int,string>  $current
     * @param  list<string>|array<int,string>  $rungs
     * @return array{widen:bool, reason:string, next_roots:?list<string>, violations:list<string>}
     */
    private function territoryClimbDecision(array $current, array $rungs, string $campaignId): array
    {
        $current = $this->normalizeRoots($current);
        $rungs = $this->normalizeRoots($rungs);

        // GRADUAL-RELEASE DEFAULT: with no operator-defined rung, the ladder is a logged no-op.
        if ($rungs === []) {
            return ['widen' => false, 'reason' => 'no_rung_defined', 'next_roots' => null, 'violations' => []];
        }

        $nextRoots = array_values(array_unique(array_merge($current, $rungs)));

        $ladder = $this->territoryLadder ?? new AtlasLoopTerritoryLadder;
        $verdict = $ladder->canPromote([
            'name' => 'campaign:'.$campaignId,
            'discovery_roots' => $nextRoots,
            // The REAL frozen-safety-file list (pétreo): every widened root MUST have one under it.
            'frozen_safety_files' => AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS,
            'robustness_cases' => 1,
            'certified_leaps' => $this->certifiedLeapsFor($campaignId),
            'red_main_in_window' => 0,
            'compounding_trend_up' => true,
        ]);

        $promotable = (bool) ($verdict['promotable'] ?? false);

        return [
            'widen' => $promotable,
            'reason' => $promotable ? 'promotable' : 'blocked',
            'next_roots' => $promotable ? $nextRoots : null,
            'violations' => array_values((array) ($verdict['violations'] ?? [])),
        ];
    }

    /**
     * The campaign's certified-leaps count for the promotion rule. Every persisted Atlas Loop proposal
     * is forced to status certified_for_review by the model + DB guard, so the campaign's rolling
     * proposals_count IS the certified-leaps count — no extra query. Best-effort: a read failure yields
     * 0 (conservatively short of the K threshold, so the promotion rule simply does not fire).
     */
    private function certifiedLeapsFor(string $campaignId): int
    {
        // A single-row read; wrap in try/catch (a DB blip yields 0 — conservatively short of K, so the
        // promotion rule simply does not fire) rather than the resilience guard, so the decision is
        // testable in isolation without the full ctor wiring.
        try {
            $campaign = AtlasLoopCampaign::query()->find($campaignId);

            return $campaign instanceof AtlasLoopCampaign ? max(0, (int) $campaign->proposals_count) : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param  array<int,mixed>  $roots
     * @return list<string>
     */
    private function normalizeRoots(array $roots): array
    {
        $out = [];
        foreach ($roots as $root) {
            if (! is_string($root)) {
                continue;
            }
            $root = trim(str_replace('\\', '/', $root));
            if ($root !== '') {
                $out[$root] = true;
            }
        }

        return array_keys($out);
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }

    // Pipeline-engine path prefixes live in AtlasLoopPipelineDrift::PREFIXES (single source,
    // shared with the keepalive backstop) so the two drift checks can never diverge.

    /**
     * Os arquivos do MOTOR do loop alterados entre dois commits (bootHead..currentHead).
     * Lista vazia = o merge tocou só arquivos-alvo → sem restart. Best-effort: erro de git
     * ⇒ [] (degrada para "sem drift de pipeline", o keepalive ainda cobre morte real).
     *
     * @return list<string>
     */
    private function changedPipelineFiles(string $bootHead, string $currentHead, string $workspace): array
    {
        if ($workspace === '' || ! is_dir($workspace)) {
            return [];
        }
        if ($this->changedFilesResolver !== null) {
            $changed = ($this->changedFilesResolver)($bootHead, $currentHead, $workspace);
        } else {
            $lines = [];
            $exitCode = 1;
            @exec(
                'git -C '.escapeshellarg($workspace).' diff --name-only '
                .escapeshellarg($bootHead).' '.escapeshellarg($currentHead).' 2>/dev/null',
                $lines,
                $exitCode,
            );
            $changed = $exitCode === 0 ? $lines : [];
        }

        // Single source of truth (shared with the out-of-process keepalive backstop) so the
        // in-process and watchdog drift definitions can never diverge.
        return AtlasLoopPipelineDrift::pipelineFiles($changed);
    }

    /**
     * INDEPENDÊNCIA 24h+: o teto de tempo de UM grind. Sem cap, o grind herdava o budget
     * inteiro (até 7 dias) e uma chamada de provider travada congelaria o supervisor por
     * dias (o keepalive não pega processo vivo). Retorna o MENOR entre o budget restante e o
     * cap por-task; sem budget definido, só o cap. Cap mínimo de 60s (segurança).
     */
    private function grindTimeout(?int $budgetLeft, int $taskCap): int
    {
        $taskCap = max(60, $taskCap);

        return $budgetLeft === null ? $taskCap : max(5, min($budgetLeft, $taskCap));
    }

    private function currentGitHead(string $workspace): ?string
    {
        if ($this->gitHeadResolver !== null) {
            return $this->normalizeGitHead((string) ($this->gitHeadResolver)($workspace));
        }

        if ($workspace === '' || ! is_dir($workspace)) {
            return null;
        }

        $lines = [];
        $exitCode = 1;
        @exec('git -C '.escapeshellarg($workspace).' rev-parse HEAD 2>/dev/null', $lines, $exitCode);
        if ($exitCode !== 0) {
            return null;
        }

        return $this->normalizeGitHead(implode("\n", $lines));
    }

    private function normalizeGitHead(string $head): ?string
    {
        $head = trim($head);

        return preg_match('/\A[0-9a-f]{40}\z/i', $head) === 1 ? strtolower($head) : null;
    }

    private function responsiveSleep(string $campaignId, int $seconds): void
    {
        $remaining = max(0, $seconds);
        while ($remaining > 0) {
            if ($this->killFileExists($campaignId)) {
                return; // kill takes precedence over pause/idle
            }
            $chunk = min(5, $remaining);
            if ($this->sleeper !== null) {
                ($this->sleeper)($chunk);
            } else {
                sleep($chunk);
            }
            $remaining -= $chunk;
        }
    }

    // --- file primitives (mirroring AP-790 conventions, propose-only scope) ---

    private function storageDir(string $campaignId): string
    {
        $root = $this->storageRoot ?? storage_path('atlas-loop/campaign');

        return rtrim($root, '/').'/'.$campaignId;
    }

    private function ensureStorage(string $campaignId): void
    {
        $dir = $this->storageDir($campaignId);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
    }

    private function ledgerPath(string $campaignId): string
    {
        return $this->storageDir($campaignId).'/ledger.jsonl';
    }

    public function killSwitchPath(string $campaignId): string
    {
        return $this->storageDir($campaignId).'/KILL';
    }

    public function pausePath(string $campaignId): string
    {
        return $this->storageDir($campaignId).'/PAUSE';
    }

    private function killFileExists(string $campaignId): bool
    {
        return is_file($this->killSwitchPath($campaignId));
    }

    private function pauseFileExists(string $campaignId): bool
    {
        return is_file($this->pausePath($campaignId));
    }

    private function writeHeartbeat(string $campaignId): void
    {
        @file_put_contents($this->storageDir($campaignId).'/heartbeat', (string) $this->now());
    }

    private function appendLedger(string $campaignId, array $record): void
    {
        $line = json_encode($record, JSON_UNESCAPED_SLASHES);
        AppendOnlyJsonlStore::appendEncodedLineSilently(
            $this->ledgerPath($campaignId),
            $line === false ? '' : $line,
            FILE_APPEND,
            0o755,
        );
    }

    private function acquireLock(string $campaignId, int $leaseSeconds): bool
    {
        $existing = $this->readLock($campaignId);
        if ($existing !== null) {
            $alive = isset($existing['pid']) && ! $this->lockProcessIsDead((int) $existing['pid']);
            $fresh = (int) ($existing['expires_at'] ?? 0) > $this->now();
            if ($alive && $fresh) {
                return false; // genuinely held by a live supervisor
            }
        }
        @file_put_contents($this->storageDir($campaignId).'/lock.json', json_encode([
            'pid' => function_exists('getmypid') ? getmypid() : 0,
            'token' => $campaignId,
            'expires_at' => $this->now() + max(60, $leaseSeconds),
        ], JSON_UNESCAPED_SLASHES));

        return true;
    }

    private function releaseLock(string $campaignId): void
    {
        @unlink($this->storageDir($campaignId).'/lock.json');
    }

    private function readLock(string $campaignId): ?array
    {
        $path = $this->storageDir($campaignId).'/lock.json';
        if (! is_file($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    private function lockProcessIsDead(int $pid): bool
    {
        if ($pid <= 0) {
            return true;
        }
        if (! function_exists('posix_kill')) {
            return false; // cannot tell -> assume alive (conservative: do not steal the lock)
        }

        return ! @posix_kill($pid, 0);
    }
}
