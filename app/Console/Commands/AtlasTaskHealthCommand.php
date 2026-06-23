<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasTaskCoordinationHealthService;
use Illuminate\Console\Command;

/**
 * PART 2 · axis 10 — the read-only coordination health front door. `atlas:task:health [--json]` prints the
 * live cross-cut of the serving state (queue distribution, leases, recoverable backlog, serve rate) and the
 * integrity FLAGS (dry queue, quarantined packets, lease leak, R2 breach). Observe-only: never mutates.
 */
class AtlasTaskHealthCommand extends Command
{
    protected $signature = 'atlas:task:health {--json : Print machine-readable JSON}';

    protected $description = 'Coordination health of the task-serving stack (read-only): queue distribution, leases, recoverable backlog, integrity flags.';

    public function handle(AtlasTaskCoordinationHealthService $health): int
    {
        $snapshot = $health->snapshot();

        if ($this->option('json')) {
            $this->line((string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $d = (array) $snapshot['queue_status_distribution'];
        $this->line('');
        $this->line('  <fg=cyan>TASK-SERVING COORDINATION HEALTH</>');
        $this->line('  claimable='.$snapshot['claimable_depth'].'  claimed='.$snapshot['claimed_records'].'  blocked='.$snapshot['quarantined_count'].'  released='.($d['released'] ?? 0).'  completed='.($d['completed_dry_run'] ?? 0));
        $this->line('  active_leases='.$snapshot['active_leases'].'  leases_match_claimed='.($snapshot['leases_match_claimed'] ? 'yes' : 'NO').'  recoverable='.$snapshot['recoverable']['total']);
        $this->line('  serve_success_rate='.$snapshot['serving']['serve_success_rate'].'  r2_breach='.($snapshot['serving']['r2_breach'] ? 'YES' : 'no'));
        $flags = array_keys(array_filter((array) $snapshot['health_flags']));
        $this->line('  flags: '.($flags === [] ? 'none' : implode(', ', $flags)));
        $this->line($snapshot['healthy']
            ? '  <fg=black;bg=green> HEALTHY </> no integrity breach'
            : '  <fg=white;bg=red> DEGRADED </> integrity breach — see flags');
        $this->line('');

        return self::SUCCESS;
    }
}
