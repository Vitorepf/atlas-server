<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergePostExecutionContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Codex Merge Post-Execution Contract CLI.
 *
 *   php artisan atlas:aaeos:codex-merge-post-execution-contract [--json]
 *
 * Read-only, deterministic. Reports the two future-merge surfaces (post-execution
 * preflight + action template) under safe defaults: with no preflight readiness
 * proven, the action template is not ready and lists nothing, while the preflight
 * declares its required inputs, checks, blocking conditions and future-action
 * obligations. It accepts no execution receipt evidence, persists nothing,
 * approves nothing, merges nothing and dispatches nothing — the hard boundary
 * (execution / execution_receipt_persisted / approval / merge / ledger_write /
 * dispatch) stays false.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-contract.md
 */
class AtlasCodexMergePostExecutionContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:codex-merge-post-execution-contract {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · post-execution merge preflight + action template — read-only surfaces that authorize nothing.';

    public function handle(AtlasCodexMergePostExecutionContractService $service): int
    {
        try {
            // Safe defaults: no preflight readiness proven => the action template
            // is not ready and lists nothing; nothing is approved or merged.
            $result = $service->evaluate([]);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // Success means the boundary held, not that any merge is authorized.
            return ($result['boundary_held'] ?? false) === true
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'codex_merge_post_execution_contract_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
