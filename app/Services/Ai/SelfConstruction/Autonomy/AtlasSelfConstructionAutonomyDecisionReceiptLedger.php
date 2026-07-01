<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Autonomy;

/**
 * Pure, in-memory receipt ledger. Produces canonical, provider-free receipts
 * for stop/go decisions — no file writes, no DB rows, no provider messages.
 *
 * Input facts:
 *   decision       — 'stop' | 'go'.
 *   reasons        — list of reason strings (non-empty for valid receipt).
 *   input_facts    — arbitrary key-value map (normalized for hash).
 *   context_id     — optional identifier string.
 *   authority      — who/what made the decision (default 'autonomous').
 *   sealed_at      — optional explicit timestamp; if absent, derived from hash.
 *   decision_class — optional free-form class of the decision (default '').
 *   evidence_refs  — optional list of evidence reference strings (default []).
 *   autonomy_level — optional autonomy level this decision concerns (default '').
 *   risk           — optional free-form risk descriptor (default '').
 *   rollback_path  — optional description of how this decision can be undone (default '').
 *
 * AC2 — record() returns:
 *   receipt_hash    — sha256 of canonical (ksort-normalized) input, now covering decision_class,
 *                      evidence_refs, autonomy_level, risk and rollback_path too, so the hash
 *                      commits to the full decision record and a later audit can replay it (AC4).
 *   sealed_at       — provided value or first 16 hex chars of hash.
 *   decision        — stop|go.
 *   reasons         — list of reasons.
 *   decision_class  — echoed.
 *   evidence_refs   — echoed.
 *   autonomy_level  — echoed.
 *   risk            — echoed.
 *   rollback_path   — echoed.
 *   input_summary   — normalized input_facts (ksorted).
 *   provenance      — {context_id, authority, input_key_count}.
 *   is_valid        — decision in {stop,go} AND reasons non-empty (UNCHANGED — self-declared
 *                      progress alone still resolves to a receipt; see autonomy_evidence_verdict).
 *   is_evidence_backed     — evidence_refs non-empty.
 *   has_rollback_context   — rollback_path non-empty.
 *   autonomy_evidence_verdict — AC3: accepted | flagged_missing_evidence | flagged_missing_rollback |
 *                      flagged_missing_both. A decision lacking objective evidence or rollback
 *                      context is FLAGGED here without weakening the pre-existing is_valid contract.
 *
 * AC3 — same normalized input → same receipt_hash regardless of key order.
 *
 * AC4 — pure in-memory; no mutations.
 */
final class AtlasSelfConstructionAutonomyDecisionReceiptLedger
{
    public const SCHEMA = 'atlas.self_construction.autonomy.decision_receipt_ledger.v1';

    private const VALID_DECISIONS = ['stop', 'go'];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function record(array $facts): array
    {
        $decision   = (string) ($facts['decision']   ?? '');
        $reasons    = is_array($facts['reasons'] ?? null) ? array_values(array_map('strval', $facts['reasons'])) : [];
        $inputFacts = is_array($facts['input_facts'] ?? null) ? $facts['input_facts'] : [];
        $contextId  = (string) ($facts['context_id'] ?? '');
        $authority  = (string) ($facts['authority']  ?? 'autonomous');
        $sealedAtIn = array_key_exists('sealed_at', $facts) ? (string) $facts['sealed_at'] : null;
        $decisionClass = (string) ($facts['decision_class'] ?? '');
        $evidenceRefs  = is_array($facts['evidence_refs'] ?? null) ? array_values(array_map('strval', $facts['evidence_refs'])) : [];
        $autonomyLevel = (string) ($facts['autonomy_level'] ?? '');
        $risk          = (string) ($facts['risk'] ?? '');
        $rollbackPath  = (string) ($facts['rollback_path'] ?? '');

        // AC3: normalize by ksort recursively, then JSON-encode for hash.
        $normalizedFacts = $this->normalizeRecursive($inputFacts);
        // AC4: the hash covers the full decision record — including the new evidence/actor/risk/
        // rollback fields — so a later audit can replay the exact decision chain from stored facts.
        $hashPayload     = json_encode([
            'decision'       => $decision,
            'reasons'        => $reasons,
            'input_facts'    => $normalizedFacts,
            'context_id'     => $contextId,
            'authority'      => $authority,
            'decision_class' => $decisionClass,
            'evidence_refs'  => $evidenceRefs,
            'autonomy_level' => $autonomyLevel,
            'risk'           => $risk,
            'rollback_path'  => $rollbackPath,
        ]);
        $receiptHash = hash('sha256', (string) $hashPayload);

        $sealedAt = $sealedAtIn ?? substr($receiptHash, 0, 16);

        $isValid = in_array($decision, self::VALID_DECISIONS, true) && ! empty($reasons);

        // AC3: flag (never silently accept) an autonomy decision that lacks objective evidence
        // refs or rollback context, without weakening the pre-existing is_valid contract above.
        $isEvidenceBacked = $evidenceRefs !== [];
        $hasRollbackContext = $rollbackPath !== '';
        $autonomyEvidenceVerdict = match (true) {
            $isEvidenceBacked && $hasRollbackContext => 'accepted',
            ! $isEvidenceBacked && $hasRollbackContext => 'flagged_missing_evidence',
            $isEvidenceBacked && ! $hasRollbackContext => 'flagged_missing_rollback',
            default => 'flagged_missing_both',
        };

        return [
            'schema_version' => self::SCHEMA,
            'receipt_hash'   => $receiptHash,
            'sealed_at'      => $sealedAt,
            'decision'       => $decision,
            'reasons'        => $reasons,
            'decision_class' => $decisionClass,
            'evidence_refs'  => $evidenceRefs,
            'autonomy_level' => $autonomyLevel,
            'risk'           => $risk,
            'rollback_path'  => $rollbackPath,
            'input_summary'  => $normalizedFacts,
            'provenance'     => [
                'context_id'     => $contextId,
                'authority'      => $authority,
                'input_key_count' => count($inputFacts),
            ],
            'is_valid'       => $isValid,
            'is_evidence_backed' => $isEvidenceBacked,
            'has_rollback_context' => $hasRollbackContext,
            'autonomy_evidence_verdict' => $autonomyEvidenceVerdict,
        ];
    }

    private function normalizeRecursive(array $data): array
    {
        ksort($data);
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = $this->normalizeRecursive($v);
            }
        }

        return $data;
    }
}
