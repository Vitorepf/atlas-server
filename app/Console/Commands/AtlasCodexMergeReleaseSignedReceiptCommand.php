<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergeReleaseSignedReceiptService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Codex Merge Writer Release Signed Receipt CLI.
 *
 *   php artisan atlas:aaeos:codex-merge-release-signed-receipt [--json]
 *
 * Read-only, deterministic. Evaluates the writer release signed receipt template
 * against its upstream dependency (the writer release post-signature runbook) and
 * the eight future execution preconditions, then proves the hard boundary held:
 * execution_allowed / writer_file_creation_allowed / ledger_write_allowed /
 * dispatch_allowed / approval_granted / merge_allowed / signature_valid /
 * receipt_signed / receipt_persisted all stay false. By safe default the empty
 * input keeps the runbook un-proven, so the template returns the mandated
 * `blocked_before_writer_release_post_signature_runbook` status and nothing is
 * signed, persisted, approved, merged or dispatched.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-signed-receipt.md
 */
class AtlasCodexMergeReleaseSignedReceiptCommand extends Command
{
    protected $signature = 'atlas:aaeos:codex-merge-release-signed-receipt {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · codex merge writer release signed receipt — read-only template (boundary + upstream runbook gate + future execution preconditions).';

    public function handle(AtlasCodexMergeReleaseSignedReceiptService $service): int
    {
        try {
            // Safe defaults: runbook not proven ready, no preconditions cleared =>
            // the template stays blocked and nothing is signed, persisted,
            // approved, merged or dispatched.
            $result = $service->contract([]);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // Success means the boundary held, not that the writer was released.
            return ($result['boundary_held'] ?? false) === true
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'codex_merge_release_signed_receipt_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
