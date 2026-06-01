<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergePEAPWriterImplementationPreflightService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Codex Merge PEAP Writer IMPLEMENTATION Preflight CLI.
 *
 *   php artisan atlas:aaeos:codex-merge-peap-writer-implementation-preflight [--json]
 *
 * Read-only, deterministic. Runs the preflight that answers "what would block
 * implementing the writer safely?" — it NAMES the two future writer files
 * (without creating them), lists the eight required tests still missing and the
 * six release conditions still unmet, then proves the hard boundary held
 * (execution_allowed / writer_file_creation_allowed / ledger_write_allowed /
 * dispatch_allowed / approval_granted / merge_allowed / signature_valid /
 * receipt_persisted all stay false). By safe default the empty input leaves every
 * test missing and every condition unmet, so status is blocked and
 * implementation_authorized stays false — nothing is implemented, no file
 * created, nothing signed, validated, persisted, recorded, approved, merged or
 * dispatched.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-implementation-preflight.md
 */
class AtlasCodexMergePEAPWriterImplementationPreflightCommand extends Command
{
    protected $signature = 'atlas:aaeos:codex-merge-peap-writer-implementation-preflight {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · codex merge PEAP writer implementation preflight — read-only blocker report (future files named only + missing required tests + unmet release conditions + boundary) for safely implementing a future append-only writer.';

    public function handle(AtlasCodexMergePEAPWriterImplementationPreflightService $service): int
    {
        try {
            // Safe defaults: no tests proven present, no release conditions met =>
            // status blocked, every blocker listed, the two future files named but
            // not created, and nothing is implemented, persisted, signed, recorded,
            // approved, merged or dispatched.
            $result = $service->preflight([]);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // Success means the boundary held, not that an implementation was authorized.
            return ($result['boundary_held'] ?? false) === true
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'codex_merge_peap_writer_implementation_preflight_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
