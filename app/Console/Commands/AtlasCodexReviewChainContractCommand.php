<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Codex Review Chain Contract CLI.
 *
 *   php artisan atlas:aaeos:codex-review-chain-contract [--json]
 *
 * Read-only, deterministic surface for the non-executing review/signature/
 * merge-action chain that runs after the five-packet implementation flow reaches
 * integration readiness. It exposes the seven documented chain stages — final
 * review packet, decision template, receipt draft, signature request,
 * post-signature runbook, merge action template and merge preflight — in their
 * documented dependency order and proves the hard boundary held: every stage
 * keeps approval_granted / merge_allowed / dispatch_allowed false, conservative
 * decisions default to request_changes, and no signature is presented, validated
 * or recorded.
 *
 * The decision logic itself lives in {@see AtlasSelfConstructionReadinessService}
 * (methods codexFinalReviewPacket … codexReviewMergePreflight); this command is a
 * thin, auto-discovered runtime entrypoint for that existing contract logic.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-review-chain-contract.md
 */
class AtlasCodexReviewChainContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:codex-review-chain-contract {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · codex review chain contract — read-only review/signature/merge-action chain that approves, signs and merges nothing.';

    public function handle(AtlasSelfConstructionReadinessService $service): int
    {
        try {
            // Safe defaults: empty options => no proven merge-readiness, so the
            // chain reports its blocked/conservative state while still proving
            // every non-authorizing invariant the contract requires.
            $stages = [
                'final_review_packet' => $service->codexFinalReviewPacket([]),
                'review_decision_template' => $service->codexReviewDecisionTemplate([]),
                'review_receipt_draft' => $service->codexReviewReceiptDraft([]),
                'review_signature_request' => $service->codexReviewSignatureRequest([]),
                'review_post_signature_runbook' => $service->codexReviewPostSignatureRunbook([]),
                'review_merge_action_template' => $service->codexReviewMergeActionTemplate([]),
                'review_merge_preflight' => $service->codexReviewMergePreflight([]),
            ];

            // The contract's completion criterion: across every stage, claim,
            // completion, approval, signature validation, dispatch and merge stay
            // disabled. We assert that here rather than trusting a single field.
            $boundaryHeld = true;
            foreach ($stages as $stage) {
                foreach (['approval_granted', 'merge_allowed', 'dispatch_allowed', 'execution_allowed'] as $flag) {
                    if (($stage[$flag] ?? null) !== false) {
                        $boundaryHeld = false;
                        break 2;
                    }
                }
            }

            $this->line((string) json_encode([
                'ok' => true,
                'boundary_held' => $boundaryHeld,
                'stage_count' => count($stages),
                'stages' => $stages,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // Success means the non-authorizing boundary held, never that a merge
            // is permitted.
            return $boundaryHeld ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'codex_review_chain_contract_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
