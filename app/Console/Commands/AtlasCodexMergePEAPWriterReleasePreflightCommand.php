<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergePEAPWriterReleasePreflightService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Codex Merge Post-Execution Action Persistence Writer RELEASE Preflight CLI.
 *
 *   php artisan atlas:aaeos:codex-merge-peap-writer-release-preflight [--json]
 *
 * Read-only, deterministic. Answers "what still blocks releasing a receipt
 * persistence writer?" and nothing else. By safe default (no upstream signed
 * receipt template ready, no proof signals present) it short-circuits on the
 * upstream gate and returns the literal
 * `writer_release_authorization_signed_receipt_template_not_ready`, keeping
 * writer_released false. It creates no writer files, writes no ledger events,
 * persists no receipts, accepts/validates no signatures, records no decisions,
 * approves nothing, merges nothing and dispatches nothing — the hard nine-key
 * boundary stays false.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-preflight.md
 */
class AtlasCodexMergePEAPWriterReleasePreflightCommand extends Command
{
    protected $signature = 'atlas:aaeos:codex-merge-peap-writer-release-preflight {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · writer release preflight — read-only blocker report, releasing and authorizing nothing.';

    public function handle(AtlasCodexMergePEAPWriterReleasePreflightService $service): int
    {
        try {
            // Safe defaults: upstream template not proven ready and no proof
            // signals present => the upstream gate short-circuits and nothing is
            // released, authorized, created or dispatched.
            $result = $service->evaluate([]);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // Success means the boundary held, not that the writer is released.
            return ($result['boundary_held'] ?? false) === true
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'codex_merge_peap_writer_release_preflight_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
