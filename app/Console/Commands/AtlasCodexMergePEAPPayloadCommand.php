<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergePEAPPayloadService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Codex Merge Post-Execution Action Persistence PAYLOAD CLI.
 *
 *   php artisan atlas:aaeos:codex-merge-peap-payload [--json]
 *
 * Read-only, deterministic. Builds the append-only event PAYLOAD TEMPLATE (the
 * future event type plus all fifteen required fields, every value null), the
 * readiness rule (ready only when the post-preflight persistence runbook is
 * ready, and ready still permits inspection only — never writing) and the
 * before-write gate (all six "Before Write Conditions"), then proves the hard
 * boundary held: execution_allowed / ledger_write_allowed / dispatch_allowed /
 * approval_granted / merge_allowed / signature_valid / receipt_persisted /
 * receipt_signed all stay false. By safe default the empty input keeps the
 * post-preflight runbook flag false (template blocked) and every before-write
 * condition unmet, so a write may not proceed and nothing is signed, validated,
 * persisted, recorded, approved, merged or dispatched.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-payload.md
 */
class AtlasCodexMergePEAPPayloadCommand extends Command
{
    protected $signature = 'atlas:aaeos:codex-merge-peap-payload {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · codex merge post-execution action persistence payload — read-only append-only event payload template (boundary + fifteen required fields + readiness rule + before-write gate for a future writer surface).';

    public function handle(AtlasCodexMergePEAPPayloadService $service): int
    {
        try {
            // Safe defaults: post-preflight runbook not ready, no before-write
            // conditions met => the template stays blocked, a write may not proceed,
            // and nothing is signed, validated, persisted, recorded, approved,
            // merged or dispatched.
            $result = $service->evaluate([]);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // Success means the boundary held, not that anything was persisted or merged.
            return ($result['boundary_held'] ?? false) === true
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'codex_merge_peap_payload_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
