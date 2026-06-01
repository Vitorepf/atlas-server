<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergeReleaseAuthPostSignatureRunbookService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Codex Merge Post-Execution Action Persistence Writer Release Authorization
 * Post-Signature Runbook CLI.
 *
 *   php artisan atlas:aaeos:codex-merge-release-auth-post-signature-runbook [--json]
 *
 * Read-only, deterministic. Answers "what sequence should a future operator
 * follow after external signature evidence exists?" and nothing else. It emits
 * the documented ordered steps (ending in the mandated stop), the forbidden
 * actions, and the future-validator obligations. It signs nothing, accepts
 * nothing, validates nothing, persists nothing, approves nothing, merges
 * nothing and dispatches nothing — the hard boundary (signature_valid /
 * receipt_signed / receipt_persisted / merge_allowed / ...) stays false and the
 * runbook halts before any acceptance, writer creation or ledger write.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-authorization-post-signature-runbook.md
 */
class AtlasCodexMergeReleaseAuthPostSignatureRunbookCommand extends Command
{
    protected $signature = 'atlas:aaeos:codex-merge-release-auth-post-signature-runbook {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · writer release authorization post-signature runbook — read-only step sequence that halts before acceptance, accepting/validating/releasing nothing.';

    public function handle(AtlasCodexMergeReleaseAuthPostSignatureRunbookService $service): int
    {
        try {
            // The runbook is structurally read-only; defaults are sufficient.
            $result = $service->evaluate([]);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // Success means the boundary held AND the runbook ends in its halt,
            // not that any signature exists or any writer was released.
            $ok = ($result['boundary_held'] ?? false) === true
                && ($result['terminal_step_is_last'] ?? false) === true;

            return $ok ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'codex_merge_release_auth_post_signature_runbook_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
