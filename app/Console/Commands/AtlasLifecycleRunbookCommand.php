<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasLifecycleRunbookService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Engineering Blueprint Lifecycle Runbook decider CLI.
 *
 *   php artisan atlas:aaeos:lifecycle-runbook [--json]
 *
 * Read-only and deterministic. Evaluates the ordered 11-step Product Lifecycle,
 * the AI Start Checklist and the Failure Rule, then emits an audit receipt.
 * With safe defaults (an empty state — nothing started yet) it demonstrates the
 * contract: the start checklist is not ready, the lifecycle is in progress at
 * the first step (prepare_blueprint), and the work may NOT claim completion.
 *
 * @see docs/engineering-knowledge-base/engineering-blueprint/lifecycle-runbook.md
 */
class AtlasLifecycleRunbookCommand extends Command
{
    protected $signature = 'atlas:aaeos:lifecycle-runbook {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Engineering Blueprint lifecycle decider (ordered 11-step pipeline + start checklist + failure rule).';

    public function handle(AtlasLifecycleRunbookService $service): int
    {
        try {
            // Safe default state: nothing proven yet — no lifecycle step carries
            // evidence, no start-checklist item is satisfied, no failure signal
            // is raised. The lifecycle is in progress at the first step and the
            // work may not claim completion.
            $decision = $service->assess([
                'steps' => [],
                'start_checklist' => [],
                'failure_signals' => [],
            ]);

            $this->line((string) json_encode(
                ['ok' => true, 'decision' => $decision],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'lifecycle_runbook_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
