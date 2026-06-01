<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergeReleaseAuthPreflightService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Codex Merge Post-Execution Action Persistence Writer Release Authorization
 * Preflight CLI.
 *
 *   php artisan atlas:aaeos:codex-merge-release-auth-preflight [--json]
 *
 * Read-only, deterministic. Answers "what still blocks a future release
 * authorization for the persistence writer?" and nothing else. By safe default
 * (no clearing evidence proven present) it lists all twelve documented blocking
 * conditions as still blocking and keeps release_authorization_granted false.
 * It creates no writer files, writes no ledger events, persists no receipts,
 * accepts/validates no signatures, records no decisions, approves nothing,
 * merges nothing and dispatches nothing — the hard boundary stays false.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-authorization-preflight.md
 */
class AtlasCodexMergeReleaseAuthPreflightCommand extends Command
{
    protected $signature = 'atlas:aaeos:codex-merge-release-auth-preflight {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · writer release authorization preflight — read-only surface listing what still blocks a future release, authorizing nothing.';

    public function handle(AtlasCodexMergeReleaseAuthPreflightService $service): int
    {
        try {
            // Safe defaults: no clearing evidence proven present => every
            // documented blocking condition stays a blocker and nothing is
            // authorized or released.
            $result = $service->evaluate([]);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // Success means the boundary held, not that any release is authorized.
            return ($result['boundary_held'] ?? false) === true
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'codex_merge_release_auth_preflight_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
