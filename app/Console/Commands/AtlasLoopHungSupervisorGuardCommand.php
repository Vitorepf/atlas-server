<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTarget;
use App\Models\AtlasLoopTask;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Guard against a HUNG-BUT-HEARTBEATING supervisor.
 *
 * A campaign supervisor can wedge: alive at 0% CPU, not grinding and not refilling, while real work waits
 * (pending tasks + candidate targets). The keepalive can't catch it because the campaign heartbeat stays
 * fresh — the babysit watchdog's own backlog-feed touches it, so "fresh heartbeat" is NOT a reliable
 * liveness signal for the supervisor. This guard uses the only honest signal: COMPLETED-WORK PROGRESS
 * (done+failed task count). If that count is frozen for `stall-seconds` WHILE claimable work exists, the
 * supervisor is wedged and gets killed — the watchdog's keepalive then respawns it (resume-by-id +
 * reclaimAllInFlight + fresh pool), which is the only thing that reliably un-wedges it.
 *
 * Self-contained + idempotent: progress is tracked in a small JSON marker; the kill only fires on a real
 * freeze-with-work, and the marker resets after a kill so it never storm-kills a slow-but-live supervisor.
 */
final class AtlasLoopHungSupervisorGuardCommand extends Command
{
    protected $signature = 'atlas:loop:hung-supervisor-guard
        {--campaign-id= : Campaign to guard (default: most recent)}
        {--stall-seconds=360 : Completed-work frozen this long WHILE work waits => supervisor is hung}
        {--json : Emit machine JSON only}';

    protected $description = 'Detect a hung-but-heartbeating loop supervisor (completed-work frozen while work waits) and kill it so the watchdog respawns a fresh one.';

    public function handle(): int
    {
        $cid = (string) ($this->option('campaign-id') ?: $this->latestCampaignId());
        $stall = max(60, (int) $this->option('stall-seconds'));
        $nowTs = time();
        $out = ['schema_version' => 'atlas.loop.hung_supervisor_guard.v1', 'campaign_id' => $cid, 'verdict' => 'no_campaign', 'killed' => false];

        if ($cid === '') {
            $this->emit($out);

            return self::SUCCESS;
        }

        try {
            $progress = (int) AtlasLoopTask::query()->where('campaign_id', $cid)
                ->whereIn('status', [AtlasLoopTask::STATUS_DONE, AtlasLoopTask::STATUS_FAILED])->count();
            $hasWork = AtlasLoopTask::query()->where('campaign_id', $cid)
                ->whereIn('status', [AtlasLoopTask::STATUS_PENDING, AtlasLoopTask::STATUS_CLAIMED, AtlasLoopTask::STATUS_RUNNING])->exists()
                || AtlasLoopTarget::query()->where('campaign_id', $cid)->where('status', AtlasLoopTarget::STATUS_CANDIDATE)->exists();
        } catch (Throwable $e) {
            $out['verdict'] = 'error';
            $out['error'] = $e->getMessage();
            $this->emit($out);

            return self::SUCCESS;
        }

        $marker = $this->readMarker($cid);
        $decision = $this->decideVerdict($progress, $marker, $hasWork, $stall, $nowTs);
        $out['verdict'] = $decision['verdict'];
        $out['progress'] = $progress;
        $out['has_work'] = $hasWork;
        if ($decision['marker'] !== null) {
            $this->writeMarker($cid, $decision['marker']);
        }

        if ($decision['verdict'] === 'hung') {
            // CRITICAL safety: frozen completed-work is NOT a wedge if grind workers are actually running.
            // Under heavy swap thrashing a single grind can take far longer than the stall window, so progress
            // legitimately stalls while the loop crawls forward with live workers. Only a freeze with NO live
            // worker for this campaign (and an alive supervisor) is a true wedge worth restarting — otherwise a
            // false kill would throw away a slow-but-working grind. (Learned the hard way: a broken `pgrep -fc`
            // once read live grinds as zero.)
            $live = $this->liveGrindWorkerCount($cid);
            $out['live_workers'] = $live;
            if ($live > 0) {
                $out['verdict'] = 'grinding_slowly';
            } elseif ($this->supervisorAlive($cid)) {
                $out['killed'] = $this->killSupervisor($cid);
            }
        }

        $this->emit($out);

        return self::SUCCESS;
    }

    /**
     * PURE decision: when has completed work been frozen long enough, with work still waiting, to call the
     * supervisor hung? Marker advances (and the clock resets) only on real progress, so a slow-but-live
     * supervisor that keeps completing tasks is never killed.
     *
     * @param  array{progress:int, ts:int}|null  $marker
     * @return array{verdict:string, marker:array{progress:int, ts:int}|null}
     */
    public function decideVerdict(int $progress, ?array $marker, bool $hasWork, int $stallSeconds, int $nowTs): array
    {
        if ($marker === null || $progress > (int) $marker['progress']) {
            // first sighting, or real progress => (re)start the clock at the new high-water mark.
            return ['verdict' => 'progressing', 'marker' => ['progress' => $progress, 'ts' => $nowTs]];
        }
        // progress is frozen at the marker's high-water mark.
        if (! $hasWork) {
            // nothing to do => a quiet supervisor is NOT hung; hold the clock (don't reset) but never kill.
            return ['verdict' => 'idle_no_work', 'marker' => null];
        }
        $frozenFor = $nowTs - (int) $marker['ts'];
        if ($frozenFor >= $stallSeconds) {
            // frozen WITH work for too long => wedged. Reset the clock so the next cycle gives the respawn room.
            return ['verdict' => 'hung', 'marker' => ['progress' => $progress, 'ts' => $nowTs]];
        }

        return ['verdict' => 'watching', 'marker' => null]; // frozen-with-work but within grace; keep the clock running.
    }

    /**
     * @return array{progress:int, ts:int}|null
     */
    private function readMarker(string $cid): ?array
    {
        try {
            $path = $this->markerPath($cid);
            if (! Storage::disk('local')->exists($path)) {
                return null;
            }
            $raw = json_decode((string) Storage::disk('local')->get($path), true);

            return is_array($raw) && isset($raw['progress'], $raw['ts'])
                ? ['progress' => (int) $raw['progress'], 'ts' => (int) $raw['ts']]
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array{progress:int, ts:int}  $marker
     */
    private function writeMarker(string $cid, array $marker): void
    {
        try {
            Storage::disk('local')->put($this->markerPath($cid), (string) json_encode($marker));
        } catch (Throwable) {
            // best-effort: a marker write failure just delays detection one cycle, never breaks the guard.
        }
    }

    private function markerPath(string $cid): string
    {
        return 'atlas/loop/hung-guard-'.preg_replace('/[^a-z0-9-]/i', '', $cid).'.json';
    }

    private function supervisorAlive(string $cid): bool
    {
        $raw = @shell_exec('pgrep -f '.escapeshellarg('atlas:loop:campaign').' 2>/dev/null');

        return is_string($raw) && trim($raw) !== '';
    }

    /** Count grind-task worker processes for THIS campaign (their cmdline carries the campaign id). */
    private function liveGrindWorkerCount(string $cid): int
    {
        $raw = @shell_exec('pgrep -fl grind-task 2>/dev/null');
        if (! is_string($raw) || trim($raw) === '') {
            return 0;
        }
        $count = 0;
        foreach (explode("\n", $raw) as $line) {
            if (trim($line) !== '' && str_contains($line, $cid)) {
                $count++;
            }
        }

        return $count;
    }

    private function killSupervisor(string $cid): bool
    {
        // scope the kill to THIS campaign's supervisor via its --campaign-id= argument.
        $pattern = 'atlas:loop:campaign.*'.preg_quote($cid, '/');
        @shell_exec('pkill -TERM -f '.escapeshellarg($pattern).' 2>/dev/null');

        return true;
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
            'hung-supervisor-guard: %s (progress=%s has_work=%s killed=%s)',
            $out['verdict'] ?? '?',
            (string) ($out['progress'] ?? '-'),
            ! empty($out['has_work']) ? 'yes' : 'no',
            ! empty($out['killed']) ? 'YES' : 'no',
        ));
    }
}
