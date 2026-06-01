<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergePostExecutionActionSignedReceiptService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Codex Merge Post-Execution Action Signed Receipt CLI.
 *
 *   php artisan atlas:aaeos:codex-merge-post-execution-action-signed-receipt [--json]
 *
 * Read-only, deterministic. Evaluates the signed action receipt template, the
 * persistence preflight (all fourteen documented blockers), the release
 * conditions and the persistence template (event type + fields), then proves the
 * hard boundary held: execution_allowed / ledger_write_allowed / dispatch_allowed
 * / approval_granted / merge_allowed / signature_valid / receipt_persisted all
 * stay false. By safe default the empty input keeps every persistence blocker
 * active, so persistence may not be approached and nothing is signed, validated,
 * persisted, approved, merged or dispatched.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-signed-receipt.md
 */
class AtlasCodexMergePostExecutionActionSignedReceiptCommand extends Command
{
    protected $signature = 'atlas:aaeos:codex-merge-post-execution-action-signed-receipt {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · codex merge post-execution action signed receipt — read-only template (boundary + persistence preflight + release conditions + persistence event template).';

    public function handle(AtlasCodexMergePostExecutionActionSignedReceiptService $service): int
    {
        try {
            // Safe defaults: no external signature evidence, hashes unbound, scope
            // drift unproven clean => every persistence blocker stays active and
            // nothing is signed, validated, persisted, approved, merged or dispatched.
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
                'error' => 'codex_merge_post_execution_action_signed_receipt_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
