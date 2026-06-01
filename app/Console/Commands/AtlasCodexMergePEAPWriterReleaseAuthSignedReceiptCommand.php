<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergePEAPWriterReleaseAuthSignedReceiptService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Codex Merge Post-Execution Action Persistence Writer Release AUTHORIZATION
 * SIGNED RECEIPT (template) CLI.
 *
 *   php artisan atlas:aaeos:codex-merge-peap-writer-release-auth-signed-receipt [--json]
 *
 * Read-only, deterministic. Answers "what would a signed writer release
 * authorization receipt need to contain?" by constructing the documented
 * signed-receipt TEMPLATE: it names the six required future evidence fields
 * (present=false), the six future release preconditions (proven=false) and the
 * required selected decision (authorize_writer_release), and computes a stable
 * template hash that is explicitly NOT a signature. It signs nothing, accepts
 * nothing, validates nothing, persists nothing, approves nothing, merges nothing
 * and dispatches nothing — the hard boundary stays false and the writer is never
 * released or persisted.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-authorization-signed-receipt.md
 */
class AtlasCodexMergePEAPWriterReleaseAuthSignedReceiptCommand extends Command
{
    protected $signature = 'atlas:aaeos:codex-merge-peap-writer-release-auth-signed-receipt {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · writer release authorization signed-receipt template — read-only surface that names the future signed-receipt evidence and release preconditions, authorizing/releasing/persisting nothing.';

    public function handle(AtlasCodexMergePEAPWriterReleaseAuthSignedReceiptService $service): int
    {
        try {
            // Safe defaults: no input. The template is constructed, a stable
            // template hash is computed, release readiness stays false and the
            // writer is never released or persisted. Nothing is signed, accepted,
            // validated, persisted, approved, merged or dispatched.
            $result = $service->evaluate();

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // Success means the boundary held, the hash is deterministic, and the
            // template remains non-authorizing (release not ready) — not that any
            // authorization, release or persistence exists.
            return (($result['boundary_held'] ?? false) === true
                && ($result['template_hash_deterministic'] ?? false) === true
                && ($result['release_ready_flag'] ?? true) === false)
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'codex_merge_peap_writer_release_auth_signed_receipt_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
