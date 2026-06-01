<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergePostExecutionActionPersistenceReceiptsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Codex Merge Post-Execution Action Persistence Receipts CLI.
 *
 *   php artisan atlas:aaeos:codex-merge-post-execution-action-persistence-receipts [--json]
 *
 * Read-only, deterministic. Evaluates the persistence receipt DRAFT (its bound
 * source hashes, future event type and fields, required evidence and forbidden
 * authorities), the readiness rule (the draft is ready only when the signed action
 * receipt persistence template is ready) and the persistence preflight (all ten
 * documented blockers), then proves the hard boundary held: execution_allowed /
 * ledger_write_allowed / dispatch_allowed / approval_granted / merge_allowed /
 * signature_valid / receipt_persisted / receipt_signed all stay false. By safe
 * default the empty input keeps the template-ready flag false (draft blocked) and
 * every persistence blocker active, so the future persistence may not be
 * approached and nothing is signed, validated, persisted, recorded, approved,
 * merged or dispatched.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-receipts.md
 */
class AtlasCodexMergePostExecutionActionPersistenceReceiptsCommand extends Command
{
    protected $signature = 'atlas:aaeos:codex-merge-post-execution-action-persistence-receipts {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · codex merge post-execution action persistence receipts — read-only draft (boundary + readiness rule + persistence preflight for a future append-only persistence surface).';

    public function handle(AtlasCodexMergePostExecutionActionPersistenceReceiptsService $service): int
    {
        try {
            // Safe defaults: template not ready, no persistence evidence => the draft
            // stays blocked, every persistence blocker stays active and nothing is
            // signed, validated, persisted, recorded, approved, merged or dispatched.
            $result = $service->evaluate([]);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // Success means the boundary held, not that anything was persisted or merged.
            return ($result['boundary_held'] ?? false) === true
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'codex_merge_post_execution_action_persistence_receipts_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
