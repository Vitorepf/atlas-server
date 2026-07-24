<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Maintenance front door for old malformed task-serving backlog. It uses the same packet-quality inspector as
 * `atlas:task next`, but sweeps claimable doomed packets into blocked before any worker has to trip over them.
 */
class AtlasTaskSweepMalformedCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:task:sweep-malformed
        {--limit=0 : Maximum malformed packets to quarantine; 0 uses the safety window}
        {--dry-run : Inspect and report only}
        {--actor=task_sweep : actor label recorded in queue metadata/receipts}
        {--json : Print machine-readable JSON}';

    protected $description = 'Quarantine claimable task-serving packets that are not self-sufficient before workers pull them.';

    public function handle(): int
    {
        $result = AtlasTaskServingStack::orchestrator()->sweepMalformedClaimableTasks(
            limit: (int) $this->option('limit'),
            dryRun: (bool) $this->option('dry-run'),
            actor: (string) $this->option('actor'),
        );

        if ($this->option('json')) {
            $this->line($this->encode($result));

            return (string) ($result['status'] ?? '') === 'blocked' ? self::FAILURE : self::SUCCESS;
        }

        if ((string) ($result['status'] ?? '') === 'blocked') {
            $this->error('Malformed sweep blocked: '.(string) ($result['reason'] ?? 'unknown_reason'));

            return self::FAILURE;
        }

        $this->line('');
        $this->line('  <fg=cyan>TASK-SERVING MALFORMED SWEEP</>');
        $this->line('  dry_run='.(YesNo::format($result['dry_run'])).'  inspected='.$result['inspected_claimable'].'  blocked='.$result['blocked_count'].'  would_block='.$result['would_block_count']);
        $items = array_slice((array) ($result['dry_run'] ? $result['would_block'] : $result['blocked']), 0, 25);
        foreach ($items as $item) {
            $this->line('    - '.$item['task_packet_id'].' ['.implode(',', (array) ($item['blocking_deficiencies'] ?? [])).']');
        }
        $this->line('');

        return self::SUCCESS;
    }
}
