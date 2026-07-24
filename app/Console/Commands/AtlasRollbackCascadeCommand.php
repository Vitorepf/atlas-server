<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Lineage\AtlasRollbackCascadeExecutor;
use Illuminate\Console\Command;
use App\Support\YesNo;

/**
 * ASI-11 — cascade rollback executor CLI.
 *
 * `atlas:rollback:cascade --decision-id=X --dry-run` reports the closure and
 * what WOULD be reverted, without touching disk. `--execute` performs the
 * reversal in reverse topological order (outcomes → apply → memory → commit)
 * and returns one of the four formal states in `state`:
 *
 *   clean_revert · partial_with_receipt · blocked_conflict · containment
 *
 * The default `--dry-run` is the SAFE default: the operator must pass
 * `--execute` explicitly for any writer to fire.
 */
final class AtlasRollbackCascadeCommand extends Command
{
    protected $signature = 'atlas:rollback:cascade
        {--decision-id= : The decision_id whose closure should be reverted}
        {--dry-run : Report the closure without touching disk (default)}
        {--execute : Perform the reversal in reverse topological order}
        {--no-git : Skip git revert steps (memory/outcome/apply only)}
        {--no-memory-archive : Skip memory archive steps}
        {--json : Print machine-readable JSON}';

    protected $description = 'ASI-11 cascade rollback executor: revert the closure of a decision_id (4 formal states).';

    public function handle(AtlasRollbackCascadeExecutor $executor): int
    {
        $decisionId = trim((string) $this->option('decision-id'));
        if ($decisionId === '') {
            $this->error('Missing --decision-id');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute; // safe default

        $result = $executor->execute($decisionId, $dryRun, [
            'allow_git' => ! (bool) $this->option('no-git'),
            'allow_memory_archive' => ! (bool) $this->option('no-memory-archive'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $state = (string) ($result['state'] ?? 'unknown');
        $this->info(sprintf(
            'rollback:cascade decision_id=%s state=%s reversed=%d dry_run=%s',
            $decisionId,
            $state,
            (int) ($result['reversed_count'] ?? 0),
            YesNo::trueFalse($dryRun),
        ));

        return self::SUCCESS;
    }
}
