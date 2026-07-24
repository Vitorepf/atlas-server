<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AgentGovernance\AtlasAgentReconciler;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Run the BABÁ once: converge the real fleet toward the operator's desired-state. Starts desired+gated agents
 * that died, stops anything alive that the operator did not sanction, and auto-OFFs runs past their TTL/budget
 * FREIO. Safe by construction — start is hard-gated (default OFF), stop always runs — so a bare invocation can
 * only ever REDUCE unsanctioned spend. Scheduled to run on cadence when atlas.agents.reconciler_enabled is on.
 */
final class AtlasAgentsReconcileCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:agents:reconcile {--json}';

    protected $description = 'The babá: converge the running fleet toward the operator desired-state (start desired, stop unsanctioned).';

    public function handle(AtlasAgentReconciler $reconciler): int
    {
        $report = $reconciler->reconcile();

        if ((bool) $this->option('json')) {
            $this->line($this->encode($report));

            return self::SUCCESS;
        }

        $this->line("  Fleet master: {$report['fleet_master']} · loop master: {$report['loop_master']} · checked: {$report['checked']}");
        foreach (['started', 'stopped', 'auto_off'] as $bucket) {
            foreach ($report[$bucket] as $entry) {
                $this->line("  - {$bucket}: ".(string) ($entry['agent'] ?? '?').(isset($entry['reason']) ? " ({$entry['reason']})" : ''));
            }
        }
        if ($report['started'] === [] && $report['stopped'] === [] && $report['auto_off'] === []) {
            $this->line('  (no changes — the fleet already matches desired-state)');
        }

        return self::SUCCESS;
    }
}
