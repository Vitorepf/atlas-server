<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Illuminate\Console\Command;

/**
 * PART 2 — REPAIR: rebuild the serving queue's registry index from the task files on disk (the source of
 * truth). The old FIFO registry cap could evict claimable tasks once the queue passed 200 entries, making real
 * servable work invisible to next()/health. This reindexes the DEDICATED serving disk so no claimable task is
 * lost. Read-only w.r.t. task content (only the derived index is rewritten); atomic under the queue lock.
 */
class AtlasTaskReindexCommand extends Command
{
    protected $signature = 'atlas:task:reindex {--json : Print machine-readable JSON}';

    protected $description = 'Rebuild the task-serving registry index from disk (recovers claimable tasks dropped by the old cap).';

    public function handle(): int
    {
        $result = AtlasTaskServingStack::queueRepo()->rebuildRegistryFromDisk();

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  <fg=cyan>TASK-SERVING REGISTRY REINDEX</>');
        $this->line('  task_files_scanned='.($result['task_files_scanned'] ?? 0).'  entries_before='.($result['entries_before'] ?? 0).'  entries_after='.($result['entries_after'] ?? 0).'  recovered='.($result['recovered'] ?? 0));
        foreach ((array) ($result['status_counts'] ?? []) as $status => $count) {
            $this->line('    '.str_pad((string) $status, 20).$count);
        }
        $this->line('');

        return self::SUCCESS;
    }
}
