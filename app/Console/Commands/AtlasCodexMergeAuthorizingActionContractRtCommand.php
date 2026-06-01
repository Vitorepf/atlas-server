<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergeAuthorizingActionContractRtService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Codex Merge Authorizing Action Contract CLI.
 *
 *   php artisan atlas:aaeos:codex-merge-authorizing-action-contract-rt [--json]
 *
 * Read-only, deterministic. Emits the four documented read-only surfaces — the
 * authorizing-action template, the unsigned final merge receipt draft, the final
 * merge signature request and the final post-signature runbook — plus a
 * non-authorizing decision preview. It accepts no evidence, validates no
 * signature, records no decision, signs no receipt, approves no code, merges
 * nothing and dispatches nothing: every documented gate stays false, the runbook
 * halts before signing, and the merge executor stays a separate stage.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-authorizing-action-contract.md
 */
class AtlasCodexMergeAuthorizingActionContractRtCommand extends Command
{
    protected $signature = 'atlas:aaeos:codex-merge-authorizing-action-contract-rt {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · codex merge authorizing action contract — read-only template, receipt draft, signature request and post-signature runbook that authorize, sign, merge and dispatch nothing.';

    public function handle(AtlasCodexMergeAuthorizingActionContractRtService $service): int
    {
        try {
            // Read-only surfaces; safe defaults are sufficient. The decision
            // preview defaults to the safe 'request_changes' with no inputs.
            $result = $service->evaluate([]);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // Success = boundary held AND runbook halts last AND executor stays
            // separate. It never means anything was authorized, signed or merged.
            $ok = ($result['boundary_held'] ?? false) === true
                && ($result['runbook_terminal_is_last'] ?? false) === true
                && ($result['executor_is_separate'] ?? false) === true;

            return $ok ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'codex_merge_authorizing_action_contract_rt_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
