<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasLoopCampaign;
use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopCampaignSupervisor;
use Illuminate\Console\Command;

/**
 * Operator graceful-stop surface. Touches the .KILL file (or .PAUSE with --pause) that
 * the running supervisor polls at the top of every iteration AND inside its chunked
 * responsive sleep, so a stop is honored within seconds: in-flight finished work is
 * already persisted, unfinished tasks requeue on lease expiry, and the lock releases
 * cleanly. Also sets campaigns.kill_switch=true so the budget check trips on the next
 * tick. No SIGKILL needed; no orphaned lock.
 */
final class AtlasLoopCampaignStopCommand extends Command
{
    protected $signature = 'atlas:loop:campaign:stop
        {--campaign-id= : The campaign to stop}
        {--pause : Pause (freeze budget) instead of stopping}';

    protected $description = 'Gracefully stop (or --pause) a running Atlas Evolution Loop campaign via its kill/pause switch.';

    public function handle(AtlasLoopCampaignSupervisor $supervisor): int
    {
        $id = trim((string) ($this->option('campaign-id') ?: ''));
        if ($id === '') {
            $this->error('--campaign-id is required.');

            return self::FAILURE;
        }

        $path = (bool) $this->option('pause') ? $supervisor->pausePath($id) : $supervisor->killSwitchPath($id);
        @mkdir(dirname($path), 0o755, true);
        @file_put_contents($path, (string) time());

        if (! (bool) $this->option('pause')) {
            AtlasLoopCampaign::query()->whereKey($id)->update(['kill_switch' => true]);
        }

        $this->info(((bool) $this->option('pause') ? 'Pause' : 'Kill').' switch set for campaign '.$id.' — the supervisor will honor it within seconds.');

        return self::SUCCESS;
    }
}
