<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergeReleaseSignatureRequestService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Codex Merge Post-Execution Action Persistence Writer Release Signature
 * Request CLI.
 *
 *   php artisan atlas:aaeos:codex-merge-release-signature-request [--json]
 *
 * Read-only, deterministic. Answers "what exactly must be signed before a
 * writer release can continue?" and nothing else. By safe default (no upstream
 * receipt draft proven ready) it returns the documented blocked status
 * `blocked_before_writer_release_receipt_draft` and lists no signable spec.
 * It signs nothing, accepts nothing, validates nothing, persists nothing,
 * approves nothing, merges nothing and dispatches nothing — the hard boundary
 * (signature_valid / receipt_signed / receipt_persisted / merge_allowed / ...)
 * stays false.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-signature-request.md
 */
class AtlasCodexMergeReleaseSignatureRequestCommand extends Command
{
    protected $signature = 'atlas:aaeos:codex-merge-release-signature-request {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · writer release signature request — read-only surface that states what must be signed, accepting/validating nothing.';

    public function handle(AtlasCodexMergeReleaseSignatureRequestService $service): int
    {
        try {
            // Safe defaults: no upstream receipt draft proven ready => the
            // request stays blocked and nothing is signed, accepted or released.
            $result = $service->evaluate([]);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // Success means the boundary held, not that any signature exists.
            return ($result['boundary_held'] ?? false) === true
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'codex_merge_release_signature_request_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
