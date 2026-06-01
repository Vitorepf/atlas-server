<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergePEAPWriterReleaseAuthReceiptService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Codex Merge Post-Execution Action Persistence Writer Release AUTHORIZATION
 * Receipt CLI.
 *
 *   php artisan atlas:aaeos:codex-merge-peap-writer-release-auth-receipt [--json]
 *
 * Read-only, deterministic. Answers "what unsigned receipt would represent a
 * future writer release authorization decision?" by constructing the documented
 * unsigned receipt DRAFT: it names the four allowed future decisions, forces the
 * selected decision to the safe default (request_external_writer_release_evidence)
 * so a missing-evidence receipt is never mistaken for authorization, lists the
 * six future signature inputs (collected=false), and computes a stable receipt
 * hash that is explicitly NOT a signature. It signs nothing, accepts nothing,
 * validates nothing, persists nothing, approves nothing, merges nothing and
 * dispatches nothing — the hard boundary stays false.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-authorization-receipt.md
 */
class AtlasCodexMergePEAPWriterReleaseAuthReceiptCommand extends Command
{
    protected $signature = 'atlas:aaeos:codex-merge-peap-writer-release-auth-receipt {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · writer release authorization receipt draft — read-only surface that builds the unsigned receipt, defaulting to request-external-evidence and authorizing/releasing nothing.';

    public function handle(AtlasCodexMergePEAPWriterReleaseAuthReceiptService $service): int
    {
        try {
            // Safe defaults: empty input => the receipt draft selects the
            // documented default decision and a stable receipt hash is computed.
            // Nothing is signed, accepted, validated, persisted, approved,
            // merged or dispatched.
            $result = $service->evaluate([]);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // Success means the boundary held, the hash is deterministic, and
            // the selected decision is the safe default — not that any
            // authorization or release exists.
            return (($result['boundary_held'] ?? false) === true
                && ($result['receipt_hash_deterministic'] ?? false) === true
                && ($result['selected_decision_is_default'] ?? false) === true)
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'codex_merge_peap_writer_release_auth_receipt_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
