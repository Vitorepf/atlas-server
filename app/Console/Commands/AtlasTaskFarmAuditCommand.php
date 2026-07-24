<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskClaimableFarmAuditor;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Thin CLI surface over {@see AtlasTaskClaimableFarmAuditor}. Dry mode (default) reports
 * template-farm clusters in the live claimable pool without mutating anything; --apply moves
 * every retired_candidate to blocked (reason=template_farm_cluster) via the queue repo.
 */
class AtlasTaskFarmAuditCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:task:farm-audit
        {--apply : Move retired_candidate packets to blocked (default: dry-run, mutates nothing)}
        {--json : Print machine-readable JSON}';

    protected $description = 'Audit the claimable queue for template-farm near-duplicate clusters and optionally block the losers.';

    public function handle(): int
    {
        $result = (new AtlasTaskClaimableFarmAuditor)->audit((bool) $this->option('apply'));

        if ($this->option('json')) {
            $this->line($this->encode($result));

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  <fg=cyan>TASK FARM AUDIT</>  dry_run='.(YesNo::format($result['dry_run'])).'  clusters='.count($result['clusters']));
        foreach ($result['clusters'] as $cluster) {
            $this->line('    kept='.$cluster['kept'].'  retired=['.implode(', ', $cluster['retired_candidates']).']');
        }
        $this->line('');

        return self::SUCCESS;
    }
}
