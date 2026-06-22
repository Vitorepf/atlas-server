<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AgentGovernance\AtlasAgentDesiredStateStore;
use App\Services\Ai\AgentGovernance\AtlasFleetCatalog;
use App\Services\Ai\AgentGovernance\AtlasFleetMasterSwitch;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Console\Command;

/**
 * Operator-only RUN-ON-REQUEST: declare an agent DESIRED-ON. Sets the desired-state (with optional TTL/budget
 * FREIO + a target pin) AND turns on that agent's HARD GATE (loop → §0 master switch; everyone else → fleet
 * master switch). The babá ({@see App\Services\Ai\AgentGovernance\AtlasAgentReconciler}) then starts and keeps
 * it alive. Declarative by design: this command never spawns a process itself — run `atlas:agents:reconcile`
 * (or let the scheduled babá tick) to converge.
 */
final class AtlasAgentsOnCommand extends Command
{
    protected $signature = 'atlas:agents:on
        {key : The agent to turn on (e.g. loop, finance.strategy-loop, ai-worker.codex)}
        {--ttl= : FREIO — auto-OFF after N seconds}
        {--budget= : FREIO — auto-OFF after this USD spend}
        {--target= : Pin respawn authority to exactly this target ref (e.g. a campaign id)}
        {--reason= : Why (audit)}
        {--json}';

    protected $description = 'Operator: declare an Atlas agent desired-ON (+ TTL/budget FREIO). The babá then runs and watches it.';

    public function handle(AtlasAgentDesiredStateStore $store): int
    {
        $key = (string) $this->argument('key');
        if (! AtlasFleetCatalog::has($key)) {
            $this->error("Unknown agent '{$key}'. Known: ".implode(', ', AtlasFleetCatalog::keys()));

            return self::FAILURE;
        }

        // Turn on the agent's HARD GATE so the babá is permitted to start it.
        if ($key === AtlasFleetCatalog::LOOP) {
            AtlasLoopMasterSwitch::on();
        } else {
            AtlasFleetMasterSwitch::on();
        }

        $ttl = $this->intOption('ttl');
        $budget = $this->floatOption('budget');
        $store->setOn(
            $key,
            by: 'operator',
            ttlSeconds: $ttl,
            budgetUsd: $budget,
            targetRef: ($this->option('target') ?: null),
            reason: ($this->option('reason') ?: null),
        );

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(['ok' => true, 'agent' => $key, 'desired' => 'on', 'ttl_seconds' => $ttl, 'budget_usd' => $budget]));
        } else {
            $this->info("Agent '{$key}' is now DESIRED-ON (account: ".(AtlasFleetCatalog::get($key)?->account ?? '—').').');
            if ($ttl !== null) {
                $this->line('  FREIO: auto-OFF after '.$ttl.'s.');
            }
            $this->line('  The babá will start + watch it. Apply now with: php artisan atlas:agents:reconcile');
        }

        return self::SUCCESS;
    }

    private function intOption(string $key): ?int
    {
        $raw = trim((string) ($this->option($key) ?: ''));

        return $raw === '' || ! ctype_digit($raw) ? null : (int) $raw;
    }

    private function floatOption(string $key): ?float
    {
        $raw = trim((string) ($this->option($key) ?: ''));

        return $raw === '' || ! is_numeric($raw) ? null : (float) $raw;
    }
}
