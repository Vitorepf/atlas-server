<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergePEAPWriterReleaseReceiptService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Codex Merge Post-Execution Action Persistence Writer Release RECEIPT CLI.
 *
 *   php artisan atlas:aaeos:codex-merge-peap-writer-release-receipt [--json]
 *
 * Read-only, deterministic. Answers "what would the writer release receipt need
 * to contain?" by constructing the documented unsigned receipt DRAFT. It honors
 * the upstream contract first: with the writer release preflight not ready (the
 * safe default here), it returns the documented blocked_before_writer_release_preflight
 * status and binds no fields. It signs nothing, accepts nothing, validates
 * nothing, persists nothing, approves nothing, merges nothing and dispatches
 * nothing — the hard boundary stays all-false. The writer is never released.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-receipt.md
 */
class AtlasCodexMergePEAPWriterReleaseReceiptCommand extends Command
{
    protected $signature = 'atlas:aaeos:codex-merge-peap-writer-release-receipt {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · writer release receipt draft — read-only surface that names the eleven future receipt fields, inherits the writer release preflight blocker, and releases nothing.';

    public function handle(AtlasCodexMergePEAPWriterReleaseReceiptService $service): int
    {
        try {
            // Safe defaults: empty input => the writer release preflight is treated
            // as NOT ready, so the surface returns the documented blocked status and
            // binds no receipt fields. Nothing is signed, accepted, validated,
            // persisted, approved, merged or dispatched.
            $result = $service->evaluate([]);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // Success means the boundary held and the (null) hash check was
            // consistent — not that any release exists. The writer stays unreleased.
            return (($result['boundary_held'] ?? false) === true
                && ($result['receipt_hash_deterministic'] ?? false) === true)
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'codex_merge_peap_writer_release_receipt_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
