<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Repair\AtlasRepairPlaybookLedger;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * T4-S3 (Obra #17) — reader for the repair procedural playbook (the consumer
 * surface of {@see AtlasRepairPlaybookLedger}). Shows the per-domain resolution
 * rate learned from real repair outcomes; `unmeasured` until the corpus fills.
 */
class AtlasRepairPlaybookCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:repair:playbook
        {domain : failure domain (e.g. rag_gate, world_model, compilation)}
        {--json : machine-readable output}';

    protected $description = 'Show the learned repair playbook (resolution rate) for a failure domain (T4-S3).';

    public function handle(AtlasRepairPlaybookLedger $playbook): int
    {
        $report = $playbook->playbookFor((string) $this->argument('domain'));

        if ($this->option('json')) {
            $this->line($this->encode($report));

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '[repair-playbook] domain=%s status=%s attempts=%d resolved=%d resolve_rate=%.2f',
            $report['domain'],
            $report['status'],
            $report['attempts'],
            $report['resolved'],
            $report['resolve_rate'],
        ));
        foreach ($report['by_strategy'] as $s) {
            $this->line(sprintf('  %-24s attempts=%d resolved=%d rate=%.2f', $s['strategy'], $s['attempts'], $s['resolved'], $s['resolve_rate']));
        }

        return self::SUCCESS;
    }
}
