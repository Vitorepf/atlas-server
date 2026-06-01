<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergeWriterPreflightService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Codex Merge Post-Execution Action Persistence Writer Preflight CLI.
 *
 *   php artisan atlas:aaeos:codex-merge-writer-preflight [--json]
 *
 * Read-only, deterministic. Runs the preflight that answers "what still blocks a
 * real append-only persistence writer?" — it reports the readiness gate (the
 * append-only event payload template), the seven writer capabilities still
 * unproven and the six future-release conditions still unmet, then proves the
 * hard boundary held (execution_allowed / ledger_write_allowed / dispatch_allowed
 * / approval_granted / merge_allowed / signature_valid / receipt_persisted /
 * receipt_signed all stay false). By safe default the empty input leaves the
 * payload template not-ready, so status is blocked and writer_authorized stays
 * false — nothing is implemented, signed, validated, persisted, recorded,
 * approved, merged or dispatched.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-preflight.md
 */
class AtlasCodexMergeWriterPreflightCommand extends Command
{
    protected $signature = 'atlas:aaeos:codex-merge-writer-preflight {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · codex merge post-execution action persistence writer preflight — read-only blocker report (readiness gate + unproven capabilities + unmet release conditions + boundary) for a future append-only writer.';

    public function handle(AtlasCodexMergeWriterPreflightService $service): int
    {
        try {
            // Safe defaults: payload template not declared ready, no capabilities
            // proven, no release conditions met => status blocked, every blocker
            // listed, and nothing is implemented, persisted, signed, recorded,
            // approved, merged or dispatched.
            $result = $service->preflight([]);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // Success means the boundary held, not that a writer was authorized.
            return ($result['boundary_held'] ?? false) === true
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'codex_merge_writer_preflight_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
