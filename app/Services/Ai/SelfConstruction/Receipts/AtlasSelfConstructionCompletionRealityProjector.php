<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Receipts;

/**
 * Projects the COMPLETION REALITY of a Self-Construction attempt from BOUND task/verification/merge/
 * rollback/knowledge-sync receipt FACTS. Worker-only completion claims (without a bound verification
 * receipt) are marked UNVERIFIED — NEVER completed.
 *
 * INPUT (each receipt may be null / missing — interpreted explicitly):
 *   { task_receipt?, verification_receipt?:{verdict:string},
 *     merge_receipt?:{decision:string},
 *     rollback_receipt?:{performed:bool},
 *     knowledge_sync_receipt?:{conformant:bool}, worker_claim?:string }
 *
 * COMPLETION CLASSES:
 *   completed     — verification.verdict='passed' AND merge.decision='admitted'
 *   rejected      — verification.verdict='failed' OR merge.decision='rejected'
 *   rolled_back   — rollback.performed=true
 *   unverified    — worker_claim present AND verification_receipt missing/incomplete
 *   unknown       — no qualifying receipt
 *
 * RESIDUAL RISK / MISSING PROOF CLASSES (additive):
 *   missing_proof:verification | missing_proof:merge | missing_proof:knowledge_sync | residual_risk:rollback_pending
 *
 * INVARIANTS:
 *   - DETERMINISTIC envelope.
 *   - PURE.
 */
final class AtlasSelfConstructionCompletionRealityProjector
{
    public const SCHEMA = 'atlas.selfconstruction.completion_reality.v1';

    public const REALITY_COMPLETED = 'completed';

    public const REALITY_REJECTED = 'rejected';

    public const REALITY_ROLLED_BACK = 'rolled_back';

    public const REALITY_UNVERIFIED = 'unverified';

    public const REALITY_UNKNOWN = 'unknown';

    /**
     * @param  array{
     *     task_receipt?:array<string,mixed>|null,
     *     verification_receipt?:array{verdict?:string}|null,
     *     merge_receipt?:array{decision?:string}|null,
     *     rollback_receipt?:array{performed?:bool}|null,
     *     knowledge_sync_receipt?:array{conformant?:bool}|null,
     *     worker_claim?:string
     * }  $receipts
     * @return array{schema:string, reality:string, final_state:string, residual_risks:list<string>, missing_proofs:list<string>, deltas:list<array{kind:string,ref:string}>}
     */
    public function project(array $receipts): array
    {
        $verification = is_array($receipts['verification_receipt'] ?? null) ? $receipts['verification_receipt'] : null;
        $merge = is_array($receipts['merge_receipt'] ?? null) ? $receipts['merge_receipt'] : null;
        $rollback = is_array($receipts['rollback_receipt'] ?? null) ? $receipts['rollback_receipt'] : null;
        $knowledge = is_array($receipts['knowledge_sync_receipt'] ?? null) ? $receipts['knowledge_sync_receipt'] : null;
        $workerClaim = (string) ($receipts['worker_claim'] ?? '');

        $verdict = (string) ($verification['verdict'] ?? '');
        $decision = (string) ($merge['decision'] ?? '');
        $rolledBack = (bool) ($rollback['performed'] ?? false);

        $reality = self::REALITY_UNKNOWN;
        if ($rolledBack) {
            $reality = self::REALITY_ROLLED_BACK;
        } elseif ($verdict === 'failed' || $decision === 'rejected') {
            $reality = self::REALITY_REJECTED;
        } elseif ($verdict === 'passed' && $decision === 'admitted') {
            $reality = self::REALITY_COMPLETED;
        } elseif ($workerClaim !== '' && ($verification === null || $verdict === '')) {
            $reality = self::REALITY_UNVERIFIED;
        }

        $missingProofs = [];
        if ($verification === null) {
            $missingProofs[] = 'missing_proof:verification';
        }
        if ($merge === null) {
            $missingProofs[] = 'missing_proof:merge';
        }
        if ($knowledge === null) {
            $missingProofs[] = 'missing_proof:knowledge_sync';
        } elseif (($knowledge['conformant'] ?? null) === false) {
            $missingProofs[] = 'knowledge_sync_not_conformant';
        }

        $residualRisks = [];
        if ($rolledBack && $verdict === 'passed') {
            $residualRisks[] = 'residual_risk:passed_but_rolled_back';
        }
        if ($reality === self::REALITY_COMPLETED && $knowledge === null) {
            $residualRisks[] = 'residual_risk:knowledge_sync_proof_absent';
        }

        sort($missingProofs, SORT_STRING);
        sort($residualRisks, SORT_STRING);

        // final_state: explicit verdict on how far this completion claim can be trusted.
        // blocked = actively rejected/unsafe; stale = completed but evidence outdated or absent;
        // ready = fully proven with no outstanding proofs; hold = waiting for proofs.
        $finalState = match (true) {
            $reality === self::REALITY_REJECTED => 'blocked',
            $reality === self::REALITY_ROLLED_BACK
                && in_array('residual_risk:passed_but_rolled_back', $residualRisks, true) => 'blocked',
            $reality === self::REALITY_COMPLETED && $missingProofs === [] && $residualRisks === [] => 'ready',
            $reality === self::REALITY_COMPLETED => 'stale',
            default => 'hold',
        };

        // deltas: one missing_evidence entry per missing or non-conformant proof.
        $deltas = array_map(
            static fn (string $proof): array => ['kind' => 'missing_evidence', 'ref' => $proof],
            $missingProofs,
        );

        return [
            'schema' => self::SCHEMA,
            'reality' => $reality,
            'final_state' => $finalState,
            'residual_risks' => $residualRisks,
            'missing_proofs' => $missingProofs,
            'deltas' => $deltas,
        ];
    }
}
