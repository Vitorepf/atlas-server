<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Receipts;

/**
 * Projects the COMPLETION REALITY of a Self-Construction attempt from BOUND task/verification/merge/
 * rollback/knowledge-sync receipt FACTS. Worker-only completion claims (without a bound verification
 * receipt) are marked UNVERIFIED — NEVER completed.
 *
 * INPUT (each receipt may be null / missing — interpreted explicitly):
 *   { task_receipt?, verification_receipt?:{verdict:string, proof_run_id?:string, stale?:bool},
 *     merge_receipt?:{decision:string, proof_run_id?:string, stale?:bool},
 *     rollback_receipt?:{performed:bool, proof_run_id?:string},
 *     knowledge_sync_receipt?:{conformant:bool, proof_run_id?:string, stale?:bool},
 *     decision_binding_receipt?:{status:string, proof_run_id?:string, stale?:bool},
 *     worker_claim?:string }
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
 *   mismatched_receipts:verification_passed_merge_rejected | mismatched_receipts:verification_failed_merge_admitted
 *   mismatched_proof_run | stale_receipt:<receipt_class>
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
     *     verification_receipt?:array{verdict?:string, proof_run_id?:string, stale?:bool}|null,
     *     merge_receipt?:array{decision?:string, proof_run_id?:string, stale?:bool}|null,
     *     rollback_receipt?:array{performed?:bool, proof_run_id?:string, stale?:bool}|null,
     *     knowledge_sync_receipt?:array{conformant?:bool, proof_run_id?:string, stale?:bool}|null,
     *     decision_binding_receipt?:array{status?:string, proof_run_id?:string, stale?:bool}|null,
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
        $decisionBinding = is_array($receipts['decision_binding_receipt'] ?? null) ? $receipts['decision_binding_receipt'] : null;
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
        if ($decisionBinding !== null && (string) ($decisionBinding['status'] ?? '') !== 'bound') {
            $missingProofs[] = 'missing_proof:decision_binding';
        }

        $residualRisks = [];
        if ($rolledBack && $verdict === 'passed') {
            $residualRisks[] = 'residual_risk:passed_but_rolled_back';
        }
        if ($reality === self::REALITY_COMPLETED && $knowledge === null) {
            $residualRisks[] = 'residual_risk:knowledge_sync_proof_absent';
        }

        // Mismatched receipts: verification and merge disagree on the outcome.
        if ($verification !== null && $merge !== null && $verdict !== '' && $decision !== '') {
            if ($verdict === 'passed' && $decision === 'rejected') {
                $residualRisks[] = 'mismatched_receipts:verification_passed_merge_rejected';
            } elseif ($verdict === 'failed' && $decision === 'admitted') {
                $residualRisks[] = 'mismatched_receipts:verification_failed_merge_admitted';
            }
        }

        // Mismatched proof_run_id: receipts that declare a proof_run_id must all agree.
        $proofRunIds = [];
        $receiptByClass = [
            'verification' => $verification,
            'merge' => $merge,
            'rollback' => $rollback,
            'knowledge_sync' => $knowledge,
            'decision_binding' => $decisionBinding,
        ];
        foreach ($receiptByClass as $receipt) {
            if ($receipt !== null) {
                $runId = trim((string) ($receipt['proof_run_id'] ?? ''));
                if ($runId !== '') {
                    $proofRunIds[$runId] = true;
                }
            }
        }
        if (count($proofRunIds) > 1) {
            $residualRisks[] = 'mismatched_proof_run';
        }

        // Stale receipts: any receipt flagged stale prevents ready — evidence is outdated.
        foreach ($receiptByClass as $class => $receipt) {
            if ($receipt !== null && (bool) ($receipt['stale'] ?? false)) {
                $residualRisks[] = 'stale_receipt:'.$class;
            }
        }

        sort($missingProofs, SORT_STRING);
        sort($residualRisks, SORT_STRING);

        // final_state: explicit verdict on how far this completion claim can be trusted.
        // blocked = actively rejected/unsafe; stale = completed but evidence outdated or absent;
        // ready = fully proven with no outstanding proofs or mismatches; hold = waiting for proofs.
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

    /**
     * Aggregates project() over a BATCH of completion attempts into a fleet-level truth surface:
     * how much real (green) impact landed, how much drag give-backs added, what residue is still
     * blocked, and the net capability delta (ready completions minus blocked/rolled-back
     * regressions) — never inflated by raw attempt count alone.
     *
     * @param  list<array<string,mixed>>  $receiptSets  each element is a project() input bundle,
     *                                                    optionally with an extra `give_back:bool`
     * @return array{schema:string, green_impact:float, give_back_drag:float, blocked_residue:list<string>, capability_delta:int, confidence:string, batch_size:int}
     */
    public function projectBatch(array $receiptSets): array
    {
        $sets = array_values(array_filter($receiptSets, 'is_array'));
        $total = count($sets);

        $greenCount = 0;
        $giveBackCount = 0;
        $readyCount = 0;
        $blockedResidue = [];

        foreach ($sets as $set) {
            $projection = $this->project($set);

            if ($projection['reality'] === self::REALITY_COMPLETED) {
                $greenCount++;
            }
            if ($projection['final_state'] === 'ready') {
                $readyCount++;
            }
            if ($projection['final_state'] === 'blocked') {
                $blockedResidue[] = $projection['reality'];
            }
            if ((bool) ($set['give_back'] ?? false)) {
                $giveBackCount++;
            }
        }

        sort($blockedResidue, SORT_STRING);

        $confidence = match (true) {
            $total >= 5 => 'high',
            $total >= 1 => 'medium',
            default => 'low',
        };

        return [
            'schema' => self::SCHEMA,
            'green_impact' => $total > 0 ? round($greenCount / $total, 4) : 0.0,
            'give_back_drag' => $total > 0 ? round($giveBackCount / $total, 4) : 0.0,
            'blocked_residue' => $blockedResidue,
            'capability_delta' => $readyCount - count($blockedResidue),
            'confidence' => $confidence,
            'batch_size' => $total,
        ];
    }
}
