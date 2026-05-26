<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;
use Illuminate\Console\Command;

class AtlasDecideRoutingTableCommand extends Command
{
    protected $signature = 'atlas:atlas-decide:routing-table {--json : JSON output}';

    protected $description = 'Atlas Decide · show the active routing table (read-only fold of activation receipts).';

    public function handle(AtlasDecideMetaLearningService $svc): int
    {
        $table = $svc->routingTable();
        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => true,
                'action' => 'routing-table',
                'table' => $table,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return 0;
        }
        $this->line('[atlas:atlas-decide:routing-table]');
        $this->line('active='.$table['active_entries'].' shadow='.$table['shadow_entries']);
        foreach ($table['entries'] as $e) {
            $this->line(sprintf(
                '  %s/%s/%s → %s/%s · since %s by %s',
                $e['task_category'], $e['role'], $e['framework'] ?? '*',
                $e['provider'] ?? '—', $e['model'] ?? '—',
                $e['activated_at'] ?? '—', $e['activated_by'] ?? '—'
            ));
        }
        $this->line('hash='.$table['table_hash']);

        return 0;
    }
}
