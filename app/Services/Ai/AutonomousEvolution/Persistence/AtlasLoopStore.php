<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Persistence;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopExploration;
use App\Models\AtlasLoopProposal;
use App\Models\AtlasLoopTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The atomic repository over the durable loop tables — the single durability +
 * crash-resume seam the supervisor depends on. ALL parallel-safety lives here:
 * {@see claimNextTask} is a single atomic statement (`FOR UPDATE SKIP LOCKED` on
 * pgsql) with lease-reclaim folded in, so multiple workers can grind the same
 * campaign without any in-PHP coordination and a crashed worker's task is reclaimed
 * the instant its lease expires. The propose-only invariant is preserved end-to-end:
 * proposals are only ever written as certified-for-review (the model + DB guard both
 * reject merged_to_main=true).
 */
final class AtlasLoopStore
{
    /**
     * Open a fresh 24h campaign.
     *
     * @param  array{max_seconds?:int,max_tasks?:int,max_proposals?:int,max_usd_cents?:int}  $caps
     * @param  array<string,mixed>  $config
     */
    public function openCampaign(string $goal, string $baseWorkspace, array $caps = [], array $config = [], string $provider = ''): AtlasLoopCampaign
    {
        // SCHEME FREEZE (decision-priority): snapshot the next-work pricing scheme at creation so a
        // single campaign NEVER mixes the legacy score*100 scale and the banded decider scale in one
        // priority column (claimNextTask is campaign-scoped, so freezing per campaign is sufficient).
        // Flipping the flag mid-flight only affects the NEXT campaign — an in-flight campaign keeps
        // the scheme it started with. Explicit config wins (tests/operator override).
        if (! array_key_exists('decision_priority_enabled', $config)) {
            $config['decision_priority_enabled'] = (bool) config('atlas.loop.decision_priority_enabled', false);
        }

        return AtlasLoopCampaign::query()->create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => $goal,
            'base_workspace' => $baseWorkspace,
            'provider' => $provider, // '' => loop default / Atlas Decide (provider-agnostic)
            'max_seconds' => max(0, (int) ($caps['max_seconds'] ?? 0)),
            'max_tasks' => max(0, (int) ($caps['max_tasks'] ?? 0)),
            'max_proposals' => max(0, (int) ($caps['max_proposals'] ?? 0)),
            'max_usd_cents' => max(0, (int) ($caps['max_usd_cents'] ?? 0)),
            'config' => $config,
            'started_at' => Carbon::now(),
            'heartbeat_at' => Carbon::now(),
        ]);
    }

    /**
     * Idempotently enqueue a metric-shaped task. Re-enqueuing the same (campaign,
     * dedupe_key) is a no-op — the loop-back/discovery refill never queues a task twice.
     *
     * @param  array<string,mixed>  $payload  the durable task spec (see AtlasLoopWorkspaceMaterializer)
     * @return AtlasLoopTask|null  the row (existing or new); null only on a race we lost
     */
    public function enqueueTask(
        string $campaignId,
        string $objective,
        array $payload,
        string $source,
        ?string $targetPath = null,
        int $priority = 100,
        bool $selfContained = true,
        ?string $acceptanceHash = null,
    ): ?AtlasLoopTask {
        $dedupeKey = hash('sha256', $campaignId.'|'.($targetPath ?? '').'|'.($acceptanceHash ?? '').'|'.$objective);

        $existing = AtlasLoopTask::query()->where('campaign_id', $campaignId)->where('dedupe_key', $dedupeKey)->first();
        if ($existing instanceof AtlasLoopTask) {
            return $existing;
        }

        $attributes = [
            'id' => (string) Str::uuid(),
            'campaign_id' => $campaignId,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => AtlasLoopTask::STATUS_PENDING,
            'source' => $source,
            'self_contained' => $selfContained,
            'target_path' => $targetPath,
            'objective' => $objective,
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'priority' => $priority,
            'attempts' => 0,
            'max_attempts' => 2,
            'dedupe_key' => $dedupeKey,
            'acceptance_hash' => $acceptanceHash,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];

        // insertOrIgnore wins the unique(campaign_id, dedupe_key) race without throwing.
        AtlasLoopTask::query()->insertOrIgnore($attributes);
        AtlasLoopCampaign::query()->whereKey($campaignId)->increment('tasks_processed', 0); // touch (no-op)

        return AtlasLoopTask::query()->where('campaign_id', $campaignId)->where('dedupe_key', $dedupeKey)->first();
    }

    /**
     * THE atomic claim. A single statement that selects the highest-priority claimable
     * task — pending, OR a claimed/running task whose lease has expired (crash reclaim
     * folded in) — bumps attempts, and stamps the lease, all without a race. Only
     * self-contained tasks under the attempt cap are eligible (the honest grind gate).
     */
    public function claimNextTask(string $campaignId, string $workerId, int $leaseSeconds): ?AtlasLoopTask
    {
        $leaseSeconds = max(30, $leaseSeconds);

        if (DB::connection()->getDriverName() === 'pgsql') {
            $rows = DB::select(
                <<<'SQL'
                UPDATE atlas_loop_tasks
                   SET status = 'claimed',
                       claimed_by = ?,
                       claimed_at = NOW(),
                       lease_expires_at = NOW() + (? * INTERVAL '1 second'),
                       heartbeat_at = NOW(),
                       attempts = attempts + 1,
                       updated_at = NOW()
                 WHERE id = (
                       SELECT id FROM atlas_loop_tasks
                        WHERE campaign_id = ?
                          AND (status = 'pending' OR (status IN ('claimed','running') AND lease_expires_at < NOW()))
                          AND attempts < max_attempts
                          AND (self_contained = true OR payload->>'materializer' = 'framework')
                        ORDER BY priority DESC, created_at ASC
                        FOR UPDATE SKIP LOCKED
                        LIMIT 1
                 )
                 RETURNING *
                SQL,
                [$workerId, $leaseSeconds, $campaignId],
            );

            if ($rows === []) {
                return null;
            }

            return AtlasLoopTask::query()->find($rows[0]->id);
        }

        // sqlite / other (tests, single-process): a serialized transaction is sufficient.
        return DB::transaction(function () use ($campaignId, $workerId, $leaseSeconds): ?AtlasLoopTask {
            $task = AtlasLoopTask::query()
                ->where('campaign_id', $campaignId)
                ->where(function ($q): void {
                    $q->where('status', AtlasLoopTask::STATUS_PENDING)
                        ->orWhere(function ($q2): void {
                            $q2->whereIn('status', [AtlasLoopTask::STATUS_CLAIMED, AtlasLoopTask::STATUS_RUNNING])
                                ->where('lease_expires_at', '<', Carbon::now());
                        });
                })
                ->whereColumn('attempts', '<', 'max_attempts')
                ->where(function ($q): void {
                    $q->where('self_contained', true)
                        ->orWhere('payload->materializer', 'framework');
                })
                ->orderByDesc('priority')->orderBy('created_at')
                ->lockForUpdate()
                ->first();

            if (! $task instanceof AtlasLoopTask) {
                return null;
            }

            $task->forceFill([
                'status' => AtlasLoopTask::STATUS_CLAIMED,
                'claimed_by' => $workerId,
                'claimed_at' => Carbon::now(),
                'lease_expires_at' => Carbon::now()->addSeconds($leaseSeconds),
                'heartbeat_at' => Carbon::now(),
                'attempts' => (int) $task->attempts + 1,
            ])->save();

            return $task;
        });
    }

    /** Transition a claimed task to running (lease-checked). */
    public function markRunning(string $taskId, string $workerId): bool
    {
        return AtlasLoopTask::query()
            ->whereKey($taskId)
            ->where('claimed_by', $workerId)
            ->whereIn('status', [AtlasLoopTask::STATUS_CLAIMED, AtlasLoopTask::STATUS_RUNNING])
            ->update(['status' => AtlasLoopTask::STATUS_RUNNING]) > 0;
    }

    /** Release a claim back to pending (e.g. disk backpressure) so it is retried later. */
    public function releaseClaim(string $taskId, string $workerId): bool
    {
        return AtlasLoopTask::query()
            ->whereKey($taskId)
            ->where('claimed_by', $workerId)
            ->update([
                'status' => AtlasLoopTask::STATUS_PENDING,
                'claimed_by' => null,
                'lease_expires_at' => null,
            ]) > 0;
    }

    /**
     * Claim ONE specific task by id (the pool/worker path) if it is claimable — pending,
     * or a claimed/running task whose lease expired — and under the attempt cap. Atomic;
     * returns null if someone else holds a live lease or the task is exhausted/terminal.
     */
    public function claimSpecific(string $taskId, string $workerId, int $leaseSeconds): ?AtlasLoopTask
    {
        $leaseSeconds = max(30, $leaseSeconds);
        $affected = AtlasLoopTask::query()
            ->whereKey($taskId)
            ->where(function ($q): void {
                $q->where('status', AtlasLoopTask::STATUS_PENDING)
                    ->orWhere(function ($q2): void {
                        $q2->whereIn('status', [AtlasLoopTask::STATUS_CLAIMED, AtlasLoopTask::STATUS_RUNNING])
                            ->where('lease_expires_at', '<', Carbon::now());
                    });
            })
            ->whereColumn('attempts', '<', 'max_attempts')
            ->where(function ($q): void {
                $q->where('self_contained', true)
                    ->orWhere('payload->materializer', 'framework');
            })
            ->update([
                'status' => AtlasLoopTask::STATUS_CLAIMED,
                'claimed_by' => $workerId,
                'claimed_at' => Carbon::now(),
                'lease_expires_at' => Carbon::now()->addSeconds($leaseSeconds),
                'heartbeat_at' => Carbon::now(),
                'attempts' => DB::raw('attempts + 1'),
            ]);

        return $affected > 0 ? AtlasLoopTask::query()->find($taskId) : null;
    }

    /** A live worker renews its lease mid-grind so a slow-but-alive scenario is not reclaimed. */
    public function renewLease(string $taskId, string $workerId, int $leaseSeconds): bool
    {
        return AtlasLoopTask::query()
            ->whereKey($taskId)
            ->where('claimed_by', $workerId)
            ->update([
                'lease_expires_at' => Carbon::now()->addSeconds(max(30, $leaseSeconds)),
                'heartbeat_at' => Carbon::now(),
            ]) > 0;
    }

    /** Reclaim every task whose lease expired (mid-run crash recovery) back to pending. */
    public function reclaimExpiredTasks(string $campaignId): int
    {
        return AtlasLoopTask::query()
            ->where('campaign_id', $campaignId)
            ->whereIn('status', [AtlasLoopTask::STATUS_CLAIMED, AtlasLoopTask::STATUS_RUNNING])
            ->where('lease_expires_at', '<', Carbon::now())
            ->update([
                'status' => AtlasLoopTask::STATUS_PENDING,
                'claimed_by' => null,
                'lease_expires_at' => null,
                // INVARIANT: claim increments attempts; an incomplete attempt (still claimed/running
                // at reclaim time => never reached completeTask) must REVERSE that increment, else
                // attempts leak upward on every orphaning and the task zombies (pending @ max => never
                // claimable). Portable CASE works on pgsql + sqlite. Real failures finalize via
                // completeTask(STATUS_FAILED) and never pass through here, so this cannot resurrect them.
                'attempts' => DB::raw('CASE WHEN attempts > 0 THEN attempts - 1 ELSE 0 END'),
            ]);
    }

    /**
     * Reclaim ALL in-flight tasks (claimed/running) back to pending — for SUPERVISOR STARTUP only,
     * where the predecessor that claimed them is DEAD, so every in-flight task is orphaned REGARDLESS
     * of lease. The old lease-only reclaim left a respawned supervisor's predecessor tasks "running"
     * for up to the full (90min) lease, which occupied the worker slots and STALLED the fresh
     * supervisor (no grinding, heartbeat growing). Integrity is safe even if a posix_setsid orphan
     * grind child outlives its supervisor: completeTask is claimed_by-scoped (a stale worker cannot
     * torn-write a re-grinded task) and proposals dedupe on proposal_hash — worst case is a wasteful
     * double-grind, never corruption.
     */
    public function reclaimAllInFlight(string $campaignId): int
    {
        return AtlasLoopTask::query()
            ->where('campaign_id', $campaignId)
            ->whereIn('status', [AtlasLoopTask::STATUS_CLAIMED, AtlasLoopTask::STATUS_RUNNING])
            ->update([
                'status' => AtlasLoopTask::STATUS_PENDING,
                'claimed_by' => null,
                'claimed_at' => null,
                'lease_expires_at' => null,
                // Same invariant as reclaimExpiredTasks: a supervisor death orphans in-flight tasks
                // mid-attempt. Reverse the claim-time increment so the orphaned (never-completed)
                // attempt is given back — otherwise repeated supervisor restarts (e.g. during a
                // crash-loop or stall-debugging) march attempts to max and zombie the task.
                'attempts' => DB::raw('CASE WHEN attempts > 0 THEN attempts - 1 ELSE 0 END'),
            ]);
    }

    /**
     * Complete a task — lease-checked so a stale worker cannot torn-write over a task
     * that was already reclaimed and re-grinded by someone else.
     */
    public function completeTask(string $taskId, string $workerId, array $result, bool $success): bool
    {
        return AtlasLoopTask::query()
            ->whereKey($taskId)
            ->where('claimed_by', $workerId)
            ->whereIn('status', [AtlasLoopTask::STATUS_CLAIMED, AtlasLoopTask::STATUS_RUNNING])
            ->update([
                'status' => $success ? AtlasLoopTask::STATUS_DONE : AtlasLoopTask::STATUS_FAILED,
                'result' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'lease_expires_at' => null,
            ]) > 0;
    }

    public function recordExploration(AtlasLoopTask $task, array $exploration): AtlasLoopExploration
    {
        $attributes = [
            'campaign_id' => $task->campaign_id,
            'task_id' => $task->id,
            'schema_version' => 'atlas.loop.exploration.v1',
            'objective' => (string) ($exploration['objective'] ?? $task->objective),
            'provider' => ((string) ($exploration['provider'] ?? '')) ?: null,
            'scenarios_explored' => (int) ($exploration['scenarios_explored'] ?? 0),
            'scenarios_accepted' => (int) ($exploration['scenarios_accepted'] ?? 0),
            'has_winner' => (bool) ($exploration['has_winner'] ?? false),
            'rejected_reasons' => array_values((array) ($exploration['rejected_reasons'] ?? [])),
        ];

        // Per-attempt metric audit (AP-820 S3) — only when the column exists, so a
        // pre-migration DB keeps working; the runner emits lean records only
        // (never stdout/stderr/diff_text), and this seam persists them as-is.
        if (is_array($exploration['attempt_metrics'] ?? null)
            && Schema::hasColumn('atlas_loop_explorations', 'attempt_metrics')) {
            $attributes['attempt_metrics'] = array_values($exploration['attempt_metrics']);
        }

        return AtlasLoopExploration::query()->create($attributes);
    }

    /**
     * Certify a proposal idempotently on (campaign_id, proposal_hash) — the engine's
     * own hash is the identity, so re-grinding the same winner never duplicates a row.
     * The model + DB guard guarantee it lands as certified-for-review, never merged.
     */
    public function certifyProposal(AtlasLoopTask $task, array $proposal): AtlasLoopProposal
    {
        $hash = (string) ($proposal['proposal_hash'] ?? hash('sha256', (string) ($proposal['diff_text'] ?? '').$task->id));

        $existing = AtlasLoopProposal::query()
            ->where('campaign_id', $task->campaign_id)
            ->where('proposal_hash', $hash)
            ->first();
        if ($existing instanceof AtlasLoopProposal) {
            return $existing;
        }

        $attributes = [
            'campaign_id' => $task->campaign_id,
            'task_id' => $task->id,
            'schema_version' => 'atlas.loop.proposal.v1',
            'objective' => (string) ($proposal['objective'] ?? $task->objective),
            'provider' => ((string) ($proposal['provider'] ?? '')) ?: null,
            'target_path' => ((string) $task->target_path) ?: null,
            'diff_text' => (string) ($proposal['diff_text'] ?? ''),
            'proposal_hash' => $hash,
            'metric' => $proposal['metric'] ?? null,
            'acceptance_hash' => ((string) ($proposal['acceptance_hash'] ?? '')) ?: null,
            'scenarios_explored' => (int) ($proposal['scenarios_explored'] ?? 0),
            'scenarios_accepted' => (int) ($proposal['scenarios_accepted'] ?? 0),
            'winning_scenario' => ((string) ($proposal['winning_scenario'] ?? '')) ?: null,
        ];

        // Graded quality verdict (AP-820 S3) — optional key, persisted only when the
        // column exists (pre-migration DBs stay healthy). Absence = not graded.
        // O-3: the full frozen acceptance contract rides inside the quality json under a
        // reserved key so the promotion gate can re-run the REAL test before merge
        // (no migration needed; metric stays the numeric verdict). Absence => reproof
        // fails closed, never promotes what it cannot re-verify.
        if (Schema::hasColumn('atlas_loop_proposals', 'quality')) {
            $quality = is_array($proposal['quality'] ?? null) ? $proposal['quality'] : [];
            if (is_array($proposal['acceptance_contract'] ?? null) && $proposal['acceptance_contract'] !== []) {
                $quality['_acceptance_contract'] = $proposal['acceptance_contract'];
            }
            if ($quality !== []) {
                $attributes['quality'] = $quality;
            }
        }

        return AtlasLoopProposal::query()->create($attributes);
    }

    /**
     * Resume: on supervisor (re)start, reclaim everything an earlier crash left
     * in-flight and return the open-queue snapshot so the loop continues, not restarts.
     *
     * @return array{reclaimed:int, pending:int}
     */
    public function rebuildInFlight(string $campaignId): array
    {
        // STARTUP reclaim: the predecessor supervisor is dead, so reclaim ALL in-flight (not just
        // lease-expired). The lease-only reclaim used to leave fresh-leased orphans "running",
        // stalling the respawned supervisor (worker slots occupied, no grinding). Runs once before
        // the work loop, so no in-flight task belongs to the current supervisor yet.
        $reclaimed = $this->reclaimAllInFlight($campaignId);

        return ['reclaimed' => $reclaimed, 'pending' => $this->countPending($campaignId)];
    }

    public function countPending(string $campaignId): int
    {
        return AtlasLoopTask::query()
            ->where('campaign_id', $campaignId)
            ->where('status', AtlasLoopTask::STATUS_PENDING)
            ->whereColumn('attempts', '<', 'max_attempts')
            ->count();
    }

    /**
     * Open = WORKABLE, not-yet-terminal: claimable pending (attempts < max) + claimed + running.
     * Drives starvation detection. A pending task at attempts >= max_attempts is NOT workable
     * (claimNextTask can never take it) — it is a zombie, not "open". Counting it would falsely
     * keep the loop out of the clean queue_starved_no_refill stop and hold it in produce-nothing
     * limbo (alive, heartbeat fresh, refilling, but 0 claimable and never stopping).
     */
    public function countOpen(string $campaignId): int
    {
        return AtlasLoopTask::query()
            ->where('campaign_id', $campaignId)
            ->where(function ($q): void {
                $q->where(function ($q2): void {
                    $q2->where('status', AtlasLoopTask::STATUS_PENDING)
                        ->whereColumn('attempts', '<', 'max_attempts');
                })->orWhereIn('status', [AtlasLoopTask::STATUS_CLAIMED, AtlasLoopTask::STATUS_RUNNING]);
            })
            ->count();
    }
}
