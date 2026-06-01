<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasHandoffChecklistService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Legacy Cleanup Handoff Checklist decider CLI.
 *
 *   php artisan atlas:aaeos:handoff-checklist [--json]
 *
 * Read-only and deterministic. Evaluates a legacy-cleanup handoff and emits the
 * verdict (ready | incomplete | stop), the required next action and an audit
 * receipt. With safe defaults (an empty handoff — nothing checked, nothing
 * validated) it demonstrates the contract: an unfinished cleanup is `incomplete`
 * and may NOT emit a final "done" report.
 *
 * @see docs/engineering-knowledge-base/legacy-cleanup/handoff-checklist.md
 */
class AtlasHandoffChecklistCommand extends Command
{
    protected $signature = 'atlas:aaeos:handoff-checklist {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas legacy-cleanup · handoff checklist decider (ready|incomplete|stop) before the final message.';

    public function handle(AtlasHandoffChecklistService $service): int
    {
        try {
            // Safe default handoff: nothing satisfied yet — the checklist is not
            // complete and no validation ran, so the verdict must be incomplete
            // and the session may NOT emit its final report.
            $decision = $service->evaluate([
                'checklist' => [],
                'validation' => [],
                'stop_conditions' => [],
                'split_required_count' => 0,
            ]);

            $this->line((string) json_encode(
                ['ok' => true, 'decision' => $decision],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'handoff_checklist_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
