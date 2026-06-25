<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Illuminate\Console\Command;

/**
 * Repairs blocked task-serving packets instead of letting "blocked" become a graveyard. The first repair class
 * is forbidden-self-target scope repair: keep the same task id, remove uncommittable write paths, and reopen.
 */
class AtlasTaskRepairBlockedCommand extends Command
{
    protected $signature = 'atlas:task:repair-blocked
        {--limit=0 : Maximum blocked packets to repair; 0 means no cap}
        {--dry-run : Inspect and report only}
        {--actor=task_repair : actor label recorded in queue metadata/receipts}
        {--json : Print machine-readable JSON}';

    protected $description = 'Repair blocked task-serving packets into claimable, committable packets when safe.';

    public function handle(): int
    {
        $orchestrator = AtlasTaskServingStack::orchestrator();
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');
        $actor = (string) $this->option('actor');

        // Two repair classes, both fail-safe (same task id, no fork). (1) forbidden-self-target still IN
        // allowed_files; (2) scope-repair-doomed — the pétreo target was already moved to forbidden_files but the
        // acceptance still demands it (the dominant jam cause), plus stale self-sufficient quarantines.
        $forbidden = $orchestrator->repairBlockedForbiddenSelfTargetTasks(limit: $limit, dryRun: $dryRun, actor: $actor);
        $scope = $orchestrator->repairScopeBlockedTasks(limit: $limit, dryRun: $dryRun, actor: $actor);

        $result = ['dry_run' => $dryRun, 'forbidden_self_target_repair' => $forbidden, 'scope_repair' => $scope];

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  <fg=cyan>TASK-SERVING BLOCKED REPAIR</>  dry_run='.($dryRun ? 'yes' : 'no'));
        $this->line('  [forbidden-self-target] inspected='.$forbidden['inspected_blocked'].'  repairable='.$forbidden['repairable_count'].'  repaired='.$forbidden['repaired_count'].'  retired='.$forbidden['retired_count'].'  unrepairable='.$forbidden['unrepairable_count']);
        $this->line('  [scope-repair-doomed]   inspected='.$scope['inspected_blocked'].'  reopened='.$scope['reopened_count'].'  retired='.$scope['retired_count'].'  planned='.$scope['planned_count'].'  unrepairable='.$scope['unrepairable_count']);
        foreach (array_slice((array) ($dryRun ? $scope['plan'] : array_merge($scope['reopened'], $scope['retired'])), 0, 30) as $item) {
            $this->line('    - '.($item['action'] ?? '?').'  '.($item['task_packet_id'] ?? '?').'  scrub=['.implode(',', (array) ($item['scrubbed_paths'] ?? [])).']');
        }
        $this->line('');

        return self::SUCCESS;
    }
}
