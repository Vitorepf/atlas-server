<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reclaim tasks orphaned by a DEAD grind worker — process-liveness reclaim, the fast counterpart to the
 * supervisor's lease-based reclaim.
 *
 * A grind worker that crashes / OOMs / hangs-then-dies leaves its task "running" until the full task lease
 * (90min here) elapses. That holds a worker slot hostage and throttles the loop to a trickle — the exact
 * zombie-stall seen repeatedly. The supervisor only reclaims on lease expiry, so nothing frees the slot for
 * up to 90 minutes. This command proves liveness directly (is a `grind-task --task-id=<id>` process actually
 * alive?) and reclaims the genuinely-dead ones in seconds.
 *
 * SAFE BY CONSTRUCTION:
 *   - fail-closed if `pgrep` is unavailable (returns null) — never reclaims on an inconclusive process scan;
 *   - a grace floor on heartbeat_at means a just-claimed task whose worker has not yet appeared in the
 *     process table is never reclaimed (no double-grind race);
 *   - even a false reclaim is bounded to a wasteful double-grind, never corruption (completeTask is
 *     claimed_by-scoped and proposals dedupe on hash — see AtlasLoopStore::reclaimAllInFlight).
 * Designed to be run on a short cadence by the babysit watchdog alongside the campaign.
 */
final class AtlasLoopReclaimOrphanedGrindsCommand extends Command
{
    protected $signature = 'atlas:loop:reclaim-orphaned-grinds
        {--campaign-id= : Campaign to scan (default: most recent campaign)}
        {--grace-seconds=180 : Min heartbeat age before a process-less task is eligible (anti-race floor)}
        {--json : Emit machine JSON only}';

    protected $description = 'Reclaim loop tasks whose grind worker process is dead, freeing the worker slot in seconds instead of waiting out the 90min lease.';

    public function handle(AtlasLoopStore $store): int
    {
        $cid = (string) ($this->option('campaign-id') ?: $this->latestCampaignId());
        $grace = max(30, (int) $this->option('grace-seconds'));
        $out = ['schema_version' => 'atlas.loop.reclaim_orphaned_grinds.v1', 'campaign_id' => $cid, 'reclaimed' => 0, 'status' => 'ok'];

        if ($cid === '') {
            $out['status'] = 'no_campaign';
            $this->emit($out);

            return self::SUCCESS;
        }

        $alive = $this->aliveGrindTaskIds();
        if ($alive === null) {
            // pgrep unavailable / inconclusive scan => fail-closed, never reclaim on a guess.
            $out['status'] = 'process_scan_unavailable';
            $this->emit($out);

            return self::SUCCESS;
        }

        try {
            $inFlight = AtlasLoopTask::query()
                ->where('campaign_id', $cid)
                ->whereIn('status', [AtlasLoopTask::STATUS_CLAIMED, AtlasLoopTask::STATUS_RUNNING])
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->all();
            $dead = array_values(array_diff($inFlight, $alive));
            $out['in_flight'] = count($inFlight);
            $out['alive_workers'] = count($alive);
            $out['dead_candidates'] = count($dead);
            $out['reclaimed'] = $store->reclaimDeadWorkerTasks($cid, $dead, $grace);
        } catch (Throwable $e) {
            $out['status'] = 'error';
            $out['error'] = $e->getMessage();
        }

        $this->emit($out);

        return self::SUCCESS;
    }

    /**
     * Task-ids that currently have a LIVE `grind-task --task-id=<id>` process. Returns null when the process
     * table cannot be read (pgrep missing) so the caller fails closed rather than treating every task as dead.
     *
     * @return list<string>|null
     */
    private function aliveGrindTaskIds(): ?array
    {
        $raw = @shell_exec('pgrep -af "grind-task" 2>/dev/null');
        if ($raw === null || $raw === false) {
            return null; // pgrep unavailable => inconclusive
        }
        $ids = [];
        foreach (explode("\n", (string) $raw) as $line) {
            if (preg_match('/--task-id=(\S+)/', $line, $m) === 1) {
                $ids[] = trim($m[1]);
            }
        }

        return array_values(array_unique($ids));
    }

    private function latestCampaignId(): string
    {
        try {
            return (string) (AtlasLoopCampaign::query()->latest('created_at')->value('id') ?? '');
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @param  array<string,mixed>  $out
     */
    private function emit(array $out): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($out, JSON_UNESCAPED_SLASHES));

            return;
        }
        $this->info(sprintf(
            'reclaim-orphaned-grinds: %s — reclaimed=%d (in_flight=%d alive_workers=%d dead=%d)',
            $out['status'] ?? '?',
            (int) ($out['reclaimed'] ?? 0),
            (int) ($out['in_flight'] ?? 0),
            (int) ($out['alive_workers'] ?? 0),
            (int) ($out['dead_candidates'] ?? 0),
        ));
    }
}
