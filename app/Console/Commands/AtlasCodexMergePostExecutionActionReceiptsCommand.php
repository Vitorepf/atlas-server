<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergePostExecutionActionReceiptsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Codex Merge Post-Execution Action Receipts CLI.
 *
 *   php artisan atlas:aaeos:codex-merge-post-execution-action-receipts [--json]
 *
 * Read-only, deterministic. Reports the three future-merge surfaces (receipt
 * draft, signature request, post-signature runbook) under safe defaults: with no
 * upstream readiness signal proven, every stage is blocked, the draft defaults to
 * `do_not_merge`, and nothing is bound, signed, requested or sequenced. It signs
 * nothing, accepts nothing, validates nothing, persists nothing, approves
 * nothing, merges nothing and dispatches nothing — the hard boundary
 * (execution / ledger_write / dispatch / approval / merge / receipt_signed /
 * signature_valid / receipt_persisted) stays false.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-receipts.md
 */
class AtlasCodexMergePostExecutionActionReceiptsCommand extends Command
{
    protected $signature = 'atlas:aaeos:codex-merge-post-execution-action-receipts {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · post-execution merge action receipts — read-only draft/signature-request/runbook surfaces that authorize nothing.';

    public function handle(AtlasCodexMergePostExecutionActionReceiptsService $service): int
    {
        try {
            // Safe defaults: no upstream readiness proven => every stage blocked
            // and nothing is bound, signed, requested, sequenced or merged.
            $result = $service->evaluate([]);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // Success means the boundary held, not that any merge is authorized.
            return ($result['boundary_held'] ?? false) === true
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'codex_merge_post_execution_action_receipts_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
