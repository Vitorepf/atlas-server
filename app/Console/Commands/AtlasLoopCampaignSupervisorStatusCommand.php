<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopCampaignSupervisor;
use Illuminate\Console\Command;

/**
 * Arms the read-only status surface of {@see AtlasLoopCampaignSupervisor} at the operator surface: emits a
 * campaign's heartbeat status, lock status, and recent ledger tail as deterministic facts.
 *
 * STATUS-ONLY: it calls heartbeatStatus()/lockStatus()/readLedger() and NEVER run()s the loop, acquires a lock,
 * or mutates anything.
 */
final class AtlasLoopCampaignSupervisorStatusCommand extends Command
{
    protected $signature = 'atlas:loop:campaign-supervisor-status {--campaign=} {--tail=20} {--json}';

    protected $description = 'Read-only campaign supervisor status (heartbeat + lock + ledger tail); never starts the loop.';

    public function handle(): int
    {
        $campaign = trim((string) $this->option('campaign'));
        if ($campaign === '') {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'campaign-supervisor-status requires --campaign=<id>',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
        $tail = max(1, (int) $this->option('tail'));

        $supervisor = app(AtlasLoopCampaignSupervisor::class);
        $ledger = $supervisor->readLedger($campaign, $tail);

        $facts = [
            'schema' => 'atlas.loop.campaign_supervisor_status.v1',
            'campaign_id' => $campaign,
            'heartbeat' => $supervisor->heartbeatStatus($campaign),
            'lock' => $supervisor->lockStatus($campaign),
            'ledger_count' => count($ledger),
            'ledger_tail' => $ledger,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('campaign: '.$campaign);
            $this->line('heartbeat_age_seconds: '.($facts['heartbeat']['age_seconds'] ?? '-'));
            $this->line('locked: '.($facts['lock']['locked'] ? 'yes' : 'no').'  ledger_entries: '.$facts['ledger_count']);
        }

        return self::SUCCESS;
    }
}
