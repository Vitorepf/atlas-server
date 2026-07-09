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

            $this->emit(['ok' => true, 'scope' => 'all', 'fleet_master' => 'off', 'autonomos_master' => 'off', 'loop_master' => 'off'],
                'FLEET OFF — every agent desired-OFF; fleet + Autônomos master switches OFF. Nothing runs or respawns.');

            return self::SUCCESS;
        }

        $rawKey = (string) ($this->argument('key') ?: '');
        if ($rawKey === '' || ! AtlasFleetCatalog::has($rawKey)) {
            $this->error($rawKey === '' ? 'Pass an agent key, or --all for the panic kill.' : "Unknown agent '{$rawKey}'. Known: ".implode(', ', AtlasFleetCatalog::keys()).' (alias: loop → autonomos)');

            return self::FAILURE;
        }
        $key = AtlasFleetCatalog::normalizeKey($rawKey);

        $store->setOff($key, by: 'operator', reason: $reason);
        // Also clear legacy desired-state row if operator used the old key.
        if ($rawKey === AtlasFleetCatalog::LEGACY_LOOP_ALIAS) {
            $store->setOff(AtlasFleetCatalog::LEGACY_LOOP_ALIAS, by: 'operator', reason: $reason ?? 'legacy_loop_alias_off');
        }
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
