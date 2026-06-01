<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergeReleaseExecContractPreflightService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Codex Merge Post-Execution Action Persistence Writer Release EXECUTION CONTRACT
 * Preflight CLI.
 *
 *   php artisan atlas:aaeos:codex-merge-release-exec-contract-preflight [--json]
 *
 * Read-only, deterministic. Answers "what blocks a future writer release
 * execution contract?" and nothing else. By safe default (no upstream template
 * ready, no proof signals present) it short-circuits on the upstream gate and
 * returns the literal `writer_release_signed_receipt_template_not_ready`, keeping
 * execution_contract_allowed false. It creates no writer files, writes no ledger
 * events, persists no receipts, accepts/validates no signatures, records no
 * decisions, approves nothing, merges nothing and dispatches nothing — the hard
 * nine-key boundary stays false.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-execution-contract-preflight.md
 */
class AtlasCodexMergeReleaseExecContractPreflightCommand extends Command
{
    protected $signature = 'atlas:aaeos:codex-merge-release-exec-contract-preflight {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · writer release execution contract preflight — read-only blocker report, authorizing and creating nothing.';

    public function handle(AtlasCodexMergeReleaseExecContractPreflightService $service): int
    {
        try {
            // Safe defaults: upstream template not proven ready and no proof
            // signals present => the upstream gate short-circuits and nothing is
            // allowed, created, authorized or executed.
            $result = $service->evaluate([]);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // Success means the boundary held, not that any contract is allowed.
            return ($result['boundary_held'] ?? false) === true
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'codex_merge_release_exec_contract_preflight_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
