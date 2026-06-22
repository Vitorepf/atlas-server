<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AgentGovernance\AtlasAgentDesiredStateStore;
use App\Services\Ai\AgentGovernance\AtlasFleetCatalog;
use App\Services\Ai\AgentGovernance\AtlasFleetMasterSwitch;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Console\Command;

/**
 * Operator: declare an agent DESIRED-OFF (the babá then stops it), or `--all` for the PANIC kill — desired
 * OFF for the whole fleet AND both master switches OFF. This is the DESLIGAR-TUDO the apps' panic button maps
 * to: after it, nothing is permitted to run or respawn.
 */
final class AtlasAgentsOffCommand extends Command
{
    protected $signature = 'atlas:agents:off
        {key? : The agent to turn off (omit with --all)}
        {--all : PANIC — desired-OFF for the whole fleet + both master switches OFF}
        {--reason= : Why (audit)}
        {--json}';

    protected $description = 'Operator: declare an Atlas agent desired-OFF (the babá stops it); --all is the global panic kill.';

    public function handle(AtlasAgentDesiredStateStore $store): int
    {
        $reason = ($this->option('reason') ?: null);

        if ((bool) $this->option('all')) {
            foreach (AtlasFleetCatalog::keys() as $key) {
                $store->setOff($key, by: 'operator', reason: $reason ?? 'panic_off_all');
            }
            AtlasFleetMasterSwitch::off();
            AtlasLoopMasterSwitch::off();

            $this->emit(['ok' => true, 'scope' => 'all', 'fleet_master' => 'off', 'loop_master' => 'off'],
                '🛑 FLEET OFF — every agent desired-OFF; fleet + loop master switches OFF. Nothing runs or respawns.');

            return self::SUCCESS;
        }

        $key = (string) ($this->argument('key') ?: '');
        if ($key === '' || ! AtlasFleetCatalog::has($key)) {
            $this->error($key === '' ? 'Pass an agent key, or --all for the panic kill.' : "Unknown agent '{$key}'. Known: ".implode(', ', AtlasFleetCatalog::keys()));

            return self::FAILURE;
        }

        $store->setOff($key, by: 'operator', reason: $reason);
        $this->emit(['ok' => true, 'agent' => $key, 'desired' => 'off'],
            "Agent '{$key}' is now DESIRED-OFF. The babá will stop it on the next tick (or run atlas:agents:reconcile).");

        return self::SUCCESS;
    }

    /** @param array<string,mixed> $json */
    private function emit(array $json, string $human): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($json));
        } else {
            $this->info($human);
        }
    }
}
