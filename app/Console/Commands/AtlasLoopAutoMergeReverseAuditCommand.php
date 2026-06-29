<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Merge\AtlasLoopAutoMergeReverseAuditor;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopAutoMergeReverseAuditor::audit()} at the operator surface: given the pre-merge
 * and merge SHAs, it re-proves the merge preserved the pre-merge gate and emits the verdict (confirmed /
 * rolled_back / abstain / revert_failed) with diagnostics. Read-only with respect to operator intent — it only
 * runs whatever revert/gate runners are wired (none by default ⇒ abstain).
 */
final class AtlasLoopAutoMergeReverseAuditCommand extends Command
{
    protected $signature = 'atlas:loop:auto-merge-reverse-audit {--pre=} {--merge=} {--json}';

    protected $description = 'Reverse-audit a merge: did it preserve the pre-merge proof? (confirmed/rolled_back/abstain).';

    public function handle(AtlasLoopAutoMergeReverseAuditor $auditor): int
    {
        $pre = trim((string) $this->option('pre'));
        $merge = trim((string) $this->option('merge'));
        if ($pre === '' || $merge === '') {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'auto-merge-reverse-audit requires --pre and --merge SHAs',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->line((string) json_encode($auditor->audit(base_path(), $pre, $merge), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
