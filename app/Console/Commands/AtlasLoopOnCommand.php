<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AgentGovernance\AtlasAgentDesiredStateStore;
use App\Services\Ai\AgentGovernance\AtlasFleetCatalog;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Console\Command;
use Throwable;

/**
 * §0 · Operator-only: turn the loop's MASTER switch ON. Until this is run, every auto-start vector
 * (keepalive/campaign/watchdog/schedule) is a no-op. The loop can never run this itself — the switch class is
 * pétreo in the constitution.
 *
 * Also records the loop's DESIRED-STATE (control plane) so the registry/apps show the loop as desired and the
 * keepalive will keep alive ONLY campaigns launched at/after this moment (the set_at floor) — never the orphan
 * graveyard. --ttl / --budget arm the FREIO so an unattended run auto-stops.
 */
final class AtlasLoopOnCommand extends Command
{
    protected $signature = 'atlas:loop:on
        {--ttl= : FREIO — auto-OFF after N seconds (unset = no time cap)}
        {--budget= : FREIO — auto-OFF after this USD spend (unset = no budget cap)}
        {--reason= : Why the loop is being turned on (audit)}
        {--json}';

    protected $description = 'Master switch ON — permit the Atlas Loop to run/respawn (operator only).';

    public function handle(): int
    {
        $ok = AtlasLoopMasterSwitch::on();
        $state = AtlasLoopMasterSwitch::state();

        $ttl = $this->intOption('ttl');
        $budget = $this->floatOption('budget');
        $desiredOk = $this->recordDesiredOn($ttl, $budget);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(['ok' => $ok, 'master' => $state, 'desired_recorded' => $desiredOk, 'ttl_seconds' => $ttl, 'budget_usd' => $budget]));
        } else {
            $this->info($ok ? "Atlas Loop master switch is now ON (state={$state})." : 'Failed to write the master switch.');
            if ($ttl !== null) {
                $this->line('  FREIO: auto-OFF after '.$ttl.'s.');
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function recordDesiredOn(?int $ttl, ?float $budget): bool
    {
        try {
            (new AtlasAgentDesiredStateStore())->setOn(
                AtlasFleetCatalog::LOOP,
                by: 'operator',
                ttlSeconds: $ttl,
                budgetUsd: $budget,
                reason: ($this->option('reason') ?: null),
            );

            return true;
        } catch (Throwable) {
            // The master switch (the hard gate) is already ON; desired-state is secondary and fails CLOSED
            // (no floor ⇒ the keepalive authorizes nothing). Never block the operator's ON on a DB blip.
            return false;
        }
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
