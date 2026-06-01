<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergeAuthorizationContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Codex Merge Authorization Contract CLI.
 *
 *   php artisan atlas:aaeos:codex-merge-authorization-contract [--json]
 *
 * Read-only, deterministic. Evaluates the six final non-authorizing surfaces
 * (execution checklist, authorization template, receipt draft, signature
 * request, post-signature runbook, final authorization preflight) in their
 * documented dependency order and proves the hard boundary held:
 * signature_valid / decision_recorded / approval_granted / merge_allowed and
 * the rest stay false. It validates no signature, records no decision, grants
 * no approval and merges nothing — by safe default the empty input keeps the
 * whole chain not-ready.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-authorization-contract.md
 */
class AtlasCodexMergeAuthorizationContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:codex-merge-authorization-contract {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · codex merge authorization contract — read-only gate for the six non-authorizing pre-authorization surfaces.';

    public function handle(AtlasCodexMergeAuthorizationContractService $service): int
    {
        try {
            // Safe defaults: no satisfied prerequisites, no cleared gates => the
            // chain stays not-ready and nothing is validated, approved or merged.
            $result = $service->contract([]);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // Success means the boundary held, not that authorization is ready.
            return ($result['boundary_held'] ?? false) === true
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'codex_merge_authorization_contract_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
