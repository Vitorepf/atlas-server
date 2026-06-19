<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopCampaignSupervisor;
use Illuminate\Console\Command;

/**
 * Read-only observability — the `tail -f` for a running 24h campaign (no web dashboard).
 * Surfaces heartbeat age + lock liveness + remaining budget + rolling totals + the
 * ledger tail + the certified-for-review proposal stack. These are exactly the fields a
 * thin external launchd/cron watchdog needs to detect a hung-but-alive supervisor.
 */
final class AtlasLoopCampaignStatusCommand extends Command
{
    protected $signature = 'atlas:loop:campaign:status
        {--campaign-id= : The campaign to inspect (default: the most recent)}
        {--proposals : List the certified-for-review proposal stack}
        {--json : Print the canonical JSON status}';

    protected $description = 'Inspect an Atlas Evolution Loop campaign: heartbeat, lock, budget, totals, proposal stack (read-only).';

    public function handle(AtlasLoopCampaignSupervisor $supervisor): int
    {
        $id = trim((string) ($this->option('campaign-id') ?: ''));
        $campaign = $id !== ''
            ? AtlasLoopCampaign::query()->find($id)
            : AtlasLoopCampaign::query()->orderByDesc('created_at')->first();

        if (! $campaign instanceof AtlasLoopCampaign) {
            $this->error('No campaign found.');

            return self::FAILURE;
        }

        $heartbeat = $supervisor->heartbeatStatus($campaign->id);
        $lock = $supervisor->lockStatus($campaign->id);
        $remaining = $campaign->max_seconds > 0 ? max(0, (int) $campaign->max_seconds - (int) $campaign->elapsed_seconds) : null;

        $status = [
            'schema_version' => 'atlas.loop.campaign_status.v1',
            'campaign_id' => $campaign->id,
            'status' => $campaign->status,
            'stop_reason' => $campaign->stop_reason,
            'goal' => $campaign->goal,
            'provider' => $campaign->provider ?: '(loop default)',
            'budget' => [
                'max_seconds' => (int) $campaign->max_seconds,
                'elapsed_seconds' => (int) $campaign->elapsed_seconds,
                'remaining_seconds' => $remaining,
                'max_proposals' => (int) $campaign->max_proposals,
                'max_tasks' => (int) $campaign->max_tasks,
            ],
            'totals' => [
                'tasks_processed' => (int) $campaign->tasks_processed,
                'proposals_certified' => (int) $campaign->proposals_count,
                'scenarios_explored' => (int) $campaign->scenarios_explored,
                'refills' => (int) $campaign->refills,
                'loopbacks' => (int) $campaign->loopbacks,
                'spend_usd_cents' => (int) $campaign->spend_usd_cents,
            ],
            'heartbeat' => $heartbeat,
            'lock' => $lock,
            'merged_to_main' => false,
            'ledger_tail' => $supervisor->readLedger($campaign->id, 10),
            'observability' => (bool) config('atlas.loop.observability_digest_enabled', true)
                ? app(\App\Services\Ai\AutonomousEvolution\AtlasLoopObservabilityDigest::class)->section($campaign->id)
                : ['status' => 'disabled', 'reason' => 'observability_digest_disabled'],
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Campaign</>', (string) $campaign->id);
        $this->components->twoColumnDetail('Status', (string) $campaign->status.($campaign->stop_reason ? ' ('.$campaign->stop_reason.')' : ''));
        $this->components->twoColumnDetail('Budget', $remaining === null ? 'time: unbounded' : ((int) $campaign->elapsed_seconds).'s / '.((int) $campaign->max_seconds).'s ('.$remaining.'s left)');
        $this->components->twoColumnDetail('Heartbeat age', $heartbeat['age_seconds'] === null ? '—' : $heartbeat['age_seconds'].'s ago');
        $this->components->twoColumnDetail('Lock', $lock['locked'] ? ('held'.($lock['alive'] === false ? ' (DEAD pid — resumable)' : '')) : 'free');
        $this->components->twoColumnDetail('Tasks processed', (string) $campaign->tasks_processed);
        $this->components->twoColumnDetail('Proposals (certified-for-review)', (string) $campaign->proposals_count);
        $this->components->twoColumnDetail('Merged to main (must be no)', 'no');

        if ((bool) $this->option('proposals')) {
            $this->line('');
            $this->line('  <options=bold>Certified-for-review proposal stack:</>');
            foreach (AtlasLoopProposal::query()->where('campaign_id', $campaign->id)->orderByDesc('created_at')->limit(25)->get() as $p) {
                $this->line(sprintf('   • %s  [%s]  %s', mb_substr((string) $p->objective, 0, 60), substr((string) $p->proposal_hash, 0, 8), (string) $p->target_path));
            }
        }

        return self::SUCCESS;
    }
}
