<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroQueueHealthInterventionRunner;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * PART 2 · axis 10 — the read-only coordination health front door. `atlas:task:health [--json]` prints the
 * live cross-cut of the serving state (queue distribution, leases, recoverable backlog, serve rate) and the
 * integrity FLAGS (dry queue, quarantined packets, lease leak, R2 breach). Observe-only: never mutates.
 *
 * When the queue health intervention runner detects actionable conditions (starvation, poison risk,
 * stale claimable backlog, quarantine backlogs), the command also surfaces a ranked intervention plan.
 */
class AtlasTaskHealthCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:task:health {--json : Print machine-readable JSON}';

    protected $description = 'Coordination health of the task-serving stack (read-only): queue distribution, leases, recoverable backlog, integrity flags, and ranked interventions.';

    public function handle(): int
    {
        // Read the DEDICATED serving queue's health (not the polluted Agent Control Plane queue).
        $snapshot = \App\Services\Ai\SelfConstruction\AtlasTaskServingStack::coordinationHealth()->snapshot();

        // Run the health intervention runner against the snapshot.
        $runner = new AtlasMaestroQueueHealthInterventionRunner;
        $interventionPlan = $runner->run($snapshot);

        if ($this->option('json')) {
            $output = array_merge($snapshot, ['interventions' => $interventionPlan]);
            $this->line($this->encode($output));

            return self::SUCCESS;
        }

        $d = (array) $snapshot['queue_status_distribution'];
        $sv = (array) ($snapshot['servability'] ?? []);
        $this->line('');
        $this->line('  <fg=cyan>TASK-SERVING COORDINATION HEALTH</>');
        $this->line('  claimable='.$snapshot['claimable_depth'].'  claimed='.$snapshot['claimed_records'].'  blocked='.$snapshot['quarantined_count'].'  released='.($d['released'] ?? 0).'  completed='.($d['completed_dry_run'] ?? 0));
        $this->line('  servable_now='.($snapshot['servable_now'] ?? 0).'  waiting_on_deps='.($sv['waiting_on_inflight_deps'] ?? 0).'  blocked_by_dead_prereq='.($sv['blocked_by_dead_prereq'] ?? 0).'  probe_excluded='.($sv['certification_probe_excluded'] ?? 0));
        $this->line('  active_leases='.$snapshot['active_leases'].'  leases_match_claimed='.($snapshot['leases_match_claimed'] ? 'yes' : 'NO').'  recoverable='.$snapshot['recoverable']['total']);
        $this->line('  serve_success_rate='.$snapshot['serving']['serve_success_rate'].'  r2_breach='.($snapshot['serving']['r2_breach'] ? 'YES' : 'no'));
        $flags = array_keys(array_filter((array) $snapshot['health_flags']));
        $this->line('  flags: '.($flags === [] ? 'none' : implode(', ', $flags)));
        $this->line($snapshot['healthy']
            ? '  <fg=black;bg=green> HEALTHY </> no integrity breach'
            : '  <fg=white;bg=red> DEGRADED </> integrity breach — see flags');

        // ── Intervention plan ─────────────────────────────────────────────────
        $interventions = $interventionPlan['interventions'] ?? [];
        if ($interventions !== []) {
            $this->line('');
            $this->line('  <fg=cyan>QUEUE INTERVENTIONS (ranked)</>');
            foreach ($interventions as $i => $iv) {
                $idx = $i + 1;
                $type = $iv['type'] ?? '?';
                $score = $iv['priority_score'] ?? 0;
                $summary = $iv['summary'] ?? '';
                $this->line(sprintf('  %d. [%s] (score=%.1f) %s', $idx, $type, $score, $summary));
            }
        }

        if ($interventionPlan['healthy'] ?? false) {
            $this->line('  <fg=green> no interventions — queue health is clean </>');
        }
        $this->line('');

        return self::SUCCESS;
    }
}
