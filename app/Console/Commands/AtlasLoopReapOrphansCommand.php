<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasLoopCampaign;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * §4 · ORPHAN-CAMPAIGN REAPER (preflight) — mark abandoned `running` campaign rows as stopped so arming the
 * loop never RESURRECTS a graveyard. The keepalive's own reaper only fires after 24h
 * (keepalive_reap_after_minutes), so a row whose process was killed hours ago still looks `running` and gets
 * respawned on the next master-ON keepalive pass — exactly the auto-respawn that burned tokens. This is the
 * TIGHT-grace preflight (default 30 min): run it before `atlas:loop:on` for a clean start.
 *
 * SAFE: it only reaps a row whose supervisor PROCESS is dead AND whose heartbeat is older than the grace
 * window (a genuinely-crashed soak with a fresh heartbeat is SPARED — the keepalive resumes it). It never
 * touches a row with a live process.
 */
final class AtlasLoopReapOrphansCommand extends Command
{
    protected $signature = 'atlas:loop:reap-orphans {--grace-minutes=30 : Reap a dead running row whose heartbeat is older than this} {--json}';

    protected $description = 'Reap abandoned running campaign rows (dead process + stale heartbeat) so arming the loop never resurrects a graveyard.';

    public function handle(): int
    {
        $graceMinutes = max(1, (int) $this->option('grace-minutes'));
        $cutoff = time() - $graceMinutes * 60;
        $reaped = [];
        $spared = [];

        foreach (AtlasLoopCampaign::query()->where('status', 'running')->get() as $campaign) {
            $id = (string) $campaign->id;
            $heartbeat = $campaign->heartbeat_at ? strtotime((string) $campaign->heartbeat_at) : 0;
            $rowAge = $campaign->updated_at ? strtotime((string) $campaign->updated_at) : 0;
            // Abandoned = heartbeat older than grace, OR never-beat and the row itself is older than grace.
            $stale = ($heartbeat > 0 && $heartbeat < $cutoff) || ($heartbeat <= 0 && $rowAge > 0 && $rowAge < $cutoff);

            if (! $stale) {
                $spared[] = ['campaign_id' => $id, 'reason' => 'within_grace'];

                continue;
            }
            if ($this->supervisorAlive($id)) {
                $spared[] = ['campaign_id' => $id, 'reason' => 'process_alive'];

                continue;
            }

            DB::table('atlas_loop_campaigns')->where('id', $id)->update([
                'status' => 'stopped',
                'kill_switch' => true,
                'stop_reason' => 'reaped_orphan_preflight',
                'updated_at' => now(),
            ]);
            $reaped[] = ['campaign_id' => $id, 'heartbeat_age_minutes' => $heartbeat > 0 ? (int) floor((time() - $heartbeat) / 60) : null];
        }

        $payload = ['schema_version' => 'atlas.loop.reap_orphans.v1', 'grace_minutes' => $graceMinutes, 'reaped' => $reaped, 'spared' => $spared];
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf('Reaped %d orphan campaign(s); spared %d.', count($reaped), count($spared)));
        }

        return self::SUCCESS;
    }

    /** Is a supervisor process alive for this campaign? pgrep the literal launch pattern (fail-OPEN: spare on doubt). */
    protected function supervisorAlive(string $campaignId): bool
    {
        try {
            $p = new Process(['pgrep', '-f', 'atlas:loop:campaign.*--campaign-id='.$campaignId]);
            $p->run();
            // pgrep exit 0 + non-empty output ⇒ a process matched. Any error ⇒ fail-OPEN (assume alive, never
            // reap a row we couldn't prove dead).
            if (! $p->isSuccessful()) {
                return $p->getExitCode() === 1 ? false : true; // exit 1 = no match (dead); other = error ⇒ alive
            }

            return trim($p->getOutput()) !== '';
        } catch (\Throwable) {
            return true; // fail-OPEN
        }
    }
}
