<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergeExecutorContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Codex Merge Executor Contract CLI.
 *
 *   php artisan atlas:aaeos:codex-merge-executor-contract [--json]
 *
 * Read-only, deterministic. Evaluates the three future-executor surfaces
 * (release preflight, executor contract template, execution receipt template)
 * in their documented dependency order and proves the hard boundary held:
 * execution_allowed / executor_allowed / patch_executed / merge_allowed and the
 * rest stay false. It releases no executor, executes no patch and merges
 * nothing — by safe default the empty input keeps the whole chain not-ready.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-executor-contract.md
 */
class AtlasCodexMergeExecutorContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:codex-merge-executor-contract {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · codex merge executor contract — read-only gate for the future merge executor (preflight + contract + receipt templates).';

    public function handle(AtlasCodexMergeExecutorContractService $service): int
    {
        try {
            // Safe defaults: no proven hashes, no cleared gates => the chain
            // stays not-ready and nothing is released, executed or merged.
            $result = $service->contract([]);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // Success means the boundary held, not that the executor is ready.
            return ($result['boundary_held'] ?? false) === true
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'codex_merge_executor_contract_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
