<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Campaign;

use App\Models\AtlasLoopCampaign;
use App\Services\Ai\AutonomousEvolution\AtlasLoopDbResilience;
use App\Services\Ai\AutonomousEvolution\AtlasLoopResourceGate;
use App\Services\Ai\AutonomousEvolution\AtlasLoopTaskGrinder;
use App\Services\Ai\AutonomousEvolution\AtlasLoopTransientDbException;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBackService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopQueueRefiller;
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
        $baseWorkspace = (string) ($campaign->base_workspace ?: base_path());
        $restartOnCodeDrift = (bool) ($cfg['restart_on_code_drift'] ?? true);
        $bootHead = $restartOnCodeDrift ? $this->currentGitHead($baseWorkspace) : null;

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

        $cycles = 0;
        $stop = 'completed';
        try {
            // Crash recovery: reclaim any tasks an earlier run left in-flight, sweep the
            // /tmp scenario orphans a SIGKILL could not clean, and resume the ledger.
            $this->guard(fn () => $this->store->rebuildInFlight($campaign->id), 'rebuild_in_flight');
            $this->resourceGate->sweepOrphans(sys_get_temp_dir(), (int) ($cfg['orphan_ttl_seconds'] ?? 1800));
            $this->guard(fn () => $campaign->forceFill(['status' => AtlasLoopCampaign::STATUS_RUNNING, 'started_at' => $campaign->started_at ?? now()])->save(), 'campaign_start');

            $lastTick = $this->now();
            while (true) {
                try {
                    $this->guard(fn () => $campaign->refresh(), 'campaign_refresh');
                    $outageStartedAt = null; // a successful DB read means the outage (if any) is over

                    // Budget / kill — checked every tick against PERSISTED elapsed.
                    if ($this->killFileExists($campaign->id) || $campaign->kill_switch) {
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
                            // Mudança não-pipeline (merge de alvo): absorve e segue sem reiniciar.
                            $bootHead = $head;
                        }
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

                    // Self-feed: keep the queue above the low watermark (discover + generate).
                    if ((int) $this->guard(fn () => $this->store->countPending($campaign->id), 'count_pending') < $watermark) {
                        $refillStart = $this->now();
                        $refill = $this->refiller->refill($campaign, $refillBatch);
                        $this->guard(fn () => $campaign->increment('refills'), 'campaign_refills');
                        $this->beat($campaign, $this->now() - $refillStart);
                        if ((int) $refill['enqueued'] === 0 && (int) $this->guard(fn () => $this->store->countOpen($campaign->id), 'count_open') === 0) {
                            $stop = 'queue_starved_no_refill';
                            break;
                        }
                    }

                    // Claim + grind one task (serial, in-process — the proven v1 default).
                    $task = $this->guard(fn () => $this->store->claimNextTask($campaign->id, $workerId, $taskLease), 'claim_next');
                    if ($task === null) {
                        if ((int) $this->guard(fn () => $this->store->countOpen($campaign->id), 'count_open') === 0) {
                            $stop = 'queue_exhausted';
                            break;
                        }
                        $this->responsiveSleep($campaign->id, max(1, $rateLimit ?: 1));
                        $lastTick = $this->now();

                        continue;
                    }

                    $this->writeHeartbeat($campaign->id);
                    $grindStart = $this->now();
                    // INDEPENDÊNCIA 24h+: o timeout do grind era o BUDGET INTEIRO (7 dias) —
                    // uma chamada de provider que travasse congelaria o supervisor por dias e
                    // o keepalive não pegaria (processo vivo). Capa por-task (default 1800s)
                    // para um único grind nunca segurar o loop; ainda respeita o budget total.
                    $budgetLeft = $campaign->max_seconds > 0 ? max(5, (int) $campaign->max_seconds - (int) $campaign->elapsed_seconds) : null;
                    $remaining = $this->grindTimeout($budgetLeft, (int) ($cfg['task_timeout_seconds'] ?? 1800));
                    $result = $this->grinder->grind($task, $workerId, $scenarios, '', $remaining);
                    $this->beat($campaign, $this->now() - $grindStart);

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
        if ($id !== '') {
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
        );
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

    private function beat(AtlasLoopCampaign $campaign, int $deltaSeconds): void
    {
        $this->guard(fn () => $campaign->beat(max(0, $deltaSeconds)), 'campaign_beat');
    }

    /** Route a supervisor-owned durable write through the transient-DB resilience guard. */
    private function guard(callable $op, string $label): mixed
    {
        return $this->db->run($op, $label);
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

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }

    /**
     * Prefixos de path que compõem o MOTOR do loop — quando um merge toca qualquer um
     * deles, o supervisor vivo está rodando código velho do que importa e precisa
     * reciclar. Arquivos FORA destes prefixos (alvos comuns que o loop melhora) NÃO
     * disparam restart: o supervisor não depende deles em memória.
     *
     * @var list<string>
     */
    private const PIPELINE_PREFIXES = [
        'app/Services/Ai/AutonomousEvolution/',
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AdversarialProofPanelService.php',
        'app/Models/AtlasLoop',
        'config/atlas.php',
        'database/migrations/2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
        'database/migrations/2026_06_12_000100_governed_merge_door_atlas_loop_proposals.php',
    ];

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

        $pipeline = [];
        foreach ($changed as $file) {
            $file = trim((string) $file);
            if ($file === '') {
                continue;
            }
            foreach (self::PIPELINE_PREFIXES as $prefix) {
                if (str_starts_with($file, $prefix)) {
                    $pipeline[] = $file;
                    break;
                }
            }
        }

        return array_values(array_unique($pipeline));
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
