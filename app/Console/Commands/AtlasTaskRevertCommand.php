<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Governance\AtlasTaskMergeActuator;
use Illuminate\Console\Command;

/**
 * Thin operator surface over {@see AtlasTaskMergeActuator::revert()} — the Governor's
 * revert leg. Dry-run (plan only) is the DEFAULT; pass --live to actually run
 * `git revert`. No canary logic, auto-trigger, or scheduling here — this command exposes
 * exactly one method call.
 */
class AtlasTaskRevertCommand extends Command
{
    protected $signature = 'atlas:task:revert
        {--task= : task_packet_id whose landed commit should be reverted}
        {--live : Actually run git revert instead of planning only}
        {--json : Print machine-readable JSON}';

    protected $description = 'Revert the commit a task landed (dry-run plan by default; --live to execute).';

    public function handle(): int
    {
        $taskPacketId = (string) $this->option('task');
        $live = (bool) $this->option('live');

        if ($taskPacketId === '') {
            $this->error('--task=<task_packet_id> is required.');

            return self::FAILURE;
        }

        $actuator = new AtlasTaskMergeActuator;
        $result = $actuator->revert($taskPacketId, ! $live);

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  <fg=cyan>TASK REVERT</>  task='.$taskPacketId.'  mode='.($live ? 'live' : 'dry_run'));
        if (! empty($result['refused'])) {
            $this->line('  <fg=red>refused</>  reason='.($result['reason'] ?? 'unknown'));
        } elseif (! empty($result['would_revert'])) {
            $this->line('  plan  sha='.($result['sha'] ?? '').'  files='.implode(',', (array) ($result['files'] ?? [])));
        } elseif (! empty($result['reverted'])) {
            $this->line('  <fg=green>reverted</>  sha='.($result['sha'] ?? '').'  revert_sha='.($result['revert_sha'] ?? ''));
        }
        $this->line('');

        return self::SUCCESS;
    }
}
