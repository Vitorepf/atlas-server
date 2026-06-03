<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Campaign;

use App\Models\AtlasLoopCampaign;
use App\Services\Ai\AutonomousEvolution\AtlasLoopResourceGate;
use App\Services\Ai\AutonomousEvolution\AtlasLoopTaskGrinder;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBackService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopQueueRefiller;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
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
    private ?Closure $clock = null;

    private ?Closure $sleeper = null;

    private ?string $storageRoot = null;

    public function __construct(
        private readonly AtlasLoopStore $store,
        private readonly AtlasLoopTaskGrinder $grinder,
        private readonly AtlasLoopQueueRefiller $refiller,
        private readonly AtlasLoopBackService $loopBack,
        private readonly AtlasLoopResourceGate $resourceGate,
    ) {}

    public function setClockForTesting(Closure $clock): void
    {
        $this->clock = $clock;
    }

    public function setSleeperForTesting(Closure $sleeper): void
    {
        $this->sleeper = $sleeper;
    }

    public function setStorageRootForTesting(string $root): void
    {
        $this->storageRoot = rtrim($root, '/');
    }

    /**
     * @param  array<string,mixed>  $input  { campaign_id?, goal?, base_workspace?, caps..., scenarios?, workers?, shadow?, provider? }
     * @return array<string,mixed>  atlas.loop.campaign_run.v1
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

        $this->ensureStorage($campaign->id);

        if ($this->killFileExists($campaign->id)) {
            return $this->finish($campaign, 'kill_switch', 0);
        }
        if (! $this->acquireLock($campaign->id, (int) ($cfg['lock_lease_seconds'] ?? 3600))) {
            return ['schema_version' => 'atlas.loop.campaign_run.v1', 'campaign_id' => $campaign->id, 'stop_reason' => 'lock_held', 'cycles' => 0, 'proposals_total' => (int) $campaign->proposals_count, 'merged_to_main' => false];
        }

        $cycles = 0;
        $stop = 'completed';
        try {
            // Crash recovery: reclaim any tasks an earlier run left in-flight, sweep the
            // /tmp scenario orphans a SIGKILL could not clean, and resume the ledger.
            $this->store->rebuildInFlight($campaign->id);
            $this->resourceGate->sweepOrphans(sys_get_temp_dir(), (int) ($cfg['orphan_ttl_seconds'] ?? 1800));
            $campaign->forceFill(['status' => AtlasLoopCampaign::STATUS_RUNNING, 'started_at' => $campaign->started_at ?? now()])->save();

            $lastTick = $this->now();
            while (true) {
                $campaign->refresh();

                // Budget / kill — checked every tick against PERSISTED elapsed.
                if ($this->killFileExists($campaign->id) || $campaign->kill_switch) {
                    $stop = 'kill_switch';
                    break;
                }
                if ($campaign->isOverBudget()) {
                    $stop = (string) $campaign->budgetStopReason();
                    break;
                }

                // Pause — freeze budget (do not accrue elapsed) and idle responsively.
                if ($this->pauseFileExists($campaign->id)) {
                    $campaign->forceFill(['status' => AtlasLoopCampaign::STATUS_PAUSED, 'paused_at' => now()])->save();
                    $this->writeHeartbeat($campaign->id);
                    $this->responsiveSleep($campaign->id, max(5, (int) ($cfg['heartbeat_seconds'] ?? 30)));
                    $lastTick = $this->now();

                    continue;
                }
                if ($campaign->status === AtlasLoopCampaign::STATUS_PAUSED) {
                    $campaign->forceFill(['status' => AtlasLoopCampaign::STATUS_RUNNING, 'paused_at' => null])->save();
                }

                // Self-feed: keep the queue above the low watermark (discover + generate).
                if ($this->store->countPending($campaign->id) < $watermark) {
                    $refillStart = $this->now();
                    $refill = $this->refiller->refill($campaign, $refillBatch);
                    $campaign->increment('refills');
                    $this->beat($campaign, $this->now() - $refillStart);
                    if ((int) $refill['enqueued'] === 0 && $this->store->countOpen($campaign->id) === 0) {
                        $stop = 'queue_starved_no_refill';
                        break;
                    }
                }

                // Claim + grind one task (serial, in-process — the proven v1 default).
                $task = $this->store->claimNextTask($campaign->id, $workerId, $taskLease);
                if ($task === null) {
                    if ($this->store->countOpen($campaign->id) === 0) {
                        $stop = 'queue_exhausted';
                        break;
                    }
                    $this->responsiveSleep($campaign->id, max(1, $rateLimit ?: 1));
                    $lastTick = $this->now();

                    continue;
                }

                $this->writeHeartbeat($campaign->id);
                $grindStart = $this->now();
                $remaining = $campaign->max_seconds > 0 ? max(5, (int) $campaign->max_seconds - (int) $campaign->elapsed_seconds) : null;
                $result = $this->grinder->grind($task, $workerId, $scenarios, '', $remaining);
                $this->beat($campaign, $this->now() - $grindStart);

                // Results -> Sources, so the queue self-sustains.
                $targetId = (string) (is_array($task->payload) ? ($task->payload['_target_id'] ?? '') : '');
                if ($targetId !== '') {
                    $this->loopBack->reflect($campaign->id, ['target_id' => $targetId, 'status' => (string) $result['status'], 'reason' => (string) ($result['reason'] ?? '')]);
                    $campaign->increment('loopbacks');
                }

                $cycles++;
                $this->appendLedger($campaign->id, [
                    'cycle' => $cycles,
                    'task_id' => $task->id,
                    'status' => $result['status'],
                    'proposals' => $result['proposals'] ?? 0,
                    'elapsed_seconds' => $campaign->fresh()?->elapsed_seconds,
                ]);

                $this->store->reclaimExpiredTasks($campaign->id);
                if ($rateLimit > 0) {
                    $this->responsiveSleep($campaign->id, $rateLimit);
                }
                $lastTick = $this->now();
            }
        } catch (Throwable $e) {
            $stop = 'crashed: '.mb_substr($e->getMessage(), 0, 120);
        } finally {
            $this->store->reclaimExpiredTasks($campaign->id);
            $this->releaseLock($campaign->id);
        }

        return $this->finish($campaign->fresh() ?? $campaign, $stop, $cycles);
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
        $terminal = str_starts_with($stop, 'crashed') ? AtlasLoopCampaign::STATUS_ABORTED
            : ($stop === 'kill_switch' ? AtlasLoopCampaign::STATUS_ABORTED : AtlasLoopCampaign::STATUS_COMPLETED);
        $campaign->forceFill([
            'status' => $terminal,
            'stop_reason' => $stop,
            'finished_at' => now(),
            'completed_at' => now(),
        ])->save();

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
        $campaign->beat(max(0, $deltaSeconds));
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
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
        @file_put_contents($this->ledgerPath($campaignId), json_encode($record, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
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
