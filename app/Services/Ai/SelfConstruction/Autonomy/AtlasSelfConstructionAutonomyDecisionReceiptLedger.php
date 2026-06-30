<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Autonomy;

/**
 * Pure, in-memory receipt ledger. Produces canonical, provider-free receipts
 * for stop/go decisions — no file writes, no DB rows, no provider messages.
 *
 * Input facts:
 *   decision    — 'stop' | 'go'.
 *   reasons     — list of reason strings (non-empty for valid receipt).
 *   input_facts — arbitrary key-value map (normalized for hash).
 *   context_id  — optional identifier string.
 *   authority   — who/what made the decision (default 'autonomous').
 *   sealed_at   — optional explicit timestamp; if absent, derived from hash.
 *
 * AC2 — record() returns:
 *   receipt_hash   — sha256 of canonical (ksort-normalized) input.
 *   sealed_at      — provided value or first 16 hex chars of hash.
 *   decision       — stop|go.
 *   reasons        — list of reasons.
 *   input_summary  — normalized input_facts (ksorted).
 *   provenance     — {context_id, authority, input_key_count}.
 *   is_valid       — decision in {stop,go} AND reasons non-empty.
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

        // AC3: normalize by ksort recursively, then JSON-encode for hash.
        $normalizedFacts = $this->normalizeRecursive($inputFacts);
        $hashPayload     = json_encode([
            'decision'   => $decision,
            'reasons'    => $reasons,
            'input_facts' => $normalizedFacts,
            'context_id' => $contextId,
            'authority'  => $authority,
        ]);
        $receiptHash = hash('sha256', (string) $hashPayload);

        $sealedAt = $sealedAtIn ?? substr($receiptHash, 0, 16);

        $isValid = in_array($decision, self::VALID_DECISIONS, true) && ! empty($reasons);

        return [
            'schema_version' => self::SCHEMA,
            'receipt_hash'   => $receiptHash,
            'sealed_at'      => $sealedAt,
            'decision'       => $decision,
            'reasons'        => $reasons,
            'input_summary'  => $normalizedFacts,
            'provenance'     => [
                'context_id'     => $contextId,
                'authority'      => $authority,
                'input_key_count' => count($inputFacts),
            ],
            'is_valid'       => $isValid,
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
