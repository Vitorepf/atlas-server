<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergePEAPWriterReleaseAuthorizationService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Codex Merge Post-Execution Action Persistence Writer Release Authorization
 * (bare template) CLI.
 *
 *   php artisan atlas:aaeos:codex-merge-peap-writer-release-authorization [--json]
 *
 * Read-only, deterministic. Answers "what evidence would be required before
 * releasing a receipt persistence writer?" and nothing else. It emits the nine
 * named required-evidence items, evaluates the nine required checks, and names
 * the six-action future-authorized writer scope. It creates nothing, signs
 * nothing, accepts nothing, validates nothing, persists nothing, records
 * nothing, approves nothing, merges nothing and dispatches nothing — the hard
 * boundary (execution_allowed / writer_file_creation_allowed / ledger_write_allowed
 * / dispatch_allowed / approval_granted / merge_allowed / signature_valid /
 * receipt_persisted) stays false and authorization_granted stays false on every
 * path. It never answers "can the writer be implemented or executed now?".
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-authorization.md
 */
class AtlasCodexMergePEAPWriterReleaseAuthorizationCommand extends Command
{
    protected $signature = 'atlas:aaeos:codex-merge-peap-writer-release-authorization {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · writer release authorization template — read-only evidence/checks/scope surface that authorizes nothing and creates/persists/merges nothing.';

    public function handle(AtlasCodexMergePEAPWriterReleaseAuthorizationService $service): int
    {
        try {
            // The template is structurally read-only; safe defaults are enough.
            $result = $service->evaluate([]);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // Success means the boundary held AND nothing was authorized — not
            // that any writer was released. With safe defaults, no required check
            // is proven, so the template must stay un-eligible too.
            $ok = ($result['boundary_held'] ?? false) === true
                && ($result['authorization_granted'] ?? true) === false;

            return $ok ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'codex_merge_peap_writer_release_authorization_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
