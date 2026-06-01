<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergePersistenceWriterContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Codex Merge Post-Execution Action Persistence Writer Contract CLI.
 *
 *   php artisan atlas:aaeos:codex-merge-persistence-writer-contract [--json]
 *
 * Read-only, deterministic. Emits the contract template a FUTURE append-only
 * persistence writer must satisfy: the seven required capabilities, the ten
 * required pre-write checks and the eight forbidden implementation-content
 * items, then proves the hard boundary held — execution_allowed /
 * ledger_write_allowed / dispatch_allowed / approval_granted / merge_allowed /
 * signature_valid / receipt_persisted / receipt_signed all stay false. By safe
 * default the empty input leaves every pre-write proof unmet, so
 * implementation_may_be_considered stays false and nothing is implemented,
 * signed, validated, persisted, recorded, approved, merged or dispatched.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-contract.md
 */
class AtlasCodexMergePersistenceWriterContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:codex-merge-persistence-writer-contract {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · codex merge post-execution action persistence writer contract — read-only template (capabilities + pre-write checks + forbidden content + boundary for a future append-only writer).';

    public function handle(AtlasCodexMergePersistenceWriterContractService $service): int
    {
        try {
            // Safe defaults: no proofs furnished, no candidate content => every
            // pre-write check stays unmet, implementation may not be considered,
            // and nothing is implemented, persisted, signed, recorded, approved,
            // merged or dispatched.
            $result = $service->contract([]);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // Success means the boundary held, not that a writer was implemented.
            return ($result['boundary_held'] ?? false) === true
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'codex_merge_persistence_writer_contract_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
