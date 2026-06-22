<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Console\Command;

/**
 * §0 · Operator-only: turn the loop's MASTER switch ON. Until this is run, every auto-start vector
 * (keepalive/campaign/watchdog/schedule) is a no-op. The loop can never run this itself — the switch class is
 * pétreo in the constitution.
 */
final class AtlasLoopOnCommand extends Command
{
    protected $signature = 'atlas:loop:on {--json}';

    protected $description = 'Master switch ON — permit the Atlas Loop to run/respawn (operator only).';

    public function handle(): int
    {
        $ok = AtlasLoopMasterSwitch::on();
        $state = AtlasLoopMasterSwitch::state();
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(['ok' => $ok, 'master' => $state]));
        } else {
            $this->info($ok ? "Atlas Loop master switch is now ON (state={$state})." : 'Failed to write the master switch.');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
