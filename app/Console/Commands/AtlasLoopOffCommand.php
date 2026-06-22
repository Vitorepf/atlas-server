<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Console\Command;

/**
 * §0 · Operator-only: turn the loop's MASTER switch OFF — the definitive global kill. After this, NOTHING of
 * the loop runs or respawns: keepalive no-ops, campaign launch refuses, the watchdogs self-exit. This is the
 * fix for the auto-respawn token-burn.
 */
final class AtlasLoopOffCommand extends Command
{
    protected $signature = 'atlas:loop:off {--json}';

    protected $description = 'Master switch OFF — globally stop the Atlas Loop from running/respawning (operator only).';

    public function handle(): int
    {
        $ok = AtlasLoopMasterSwitch::off();
        $state = AtlasLoopMasterSwitch::state();
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(['ok' => $ok, 'master' => $state]));
        } else {
            $this->info($ok ? "Atlas Loop master switch is now OFF (state={$state}). Nothing will run or respawn." : 'Failed to write the master switch.');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
