<?php

namespace App\Services\Ai\SelfConstruction;

/**
 * Builds deterministic local receipts for Runtime Evidence Journal entries.
 *
 * Receipts produced here are review evidence only. They are not signed
 * dispatch receipts and are not Evidence Ledger writes.
 */
final class AgentRuntimeEvidenceReceiptBuilder
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_runtime_evidence_receipt.v1';

    public const MODE = 'read_only_agent_runtime_evidence_receipt_builder';

    /** @return array<string, mixed> */
    public function build(array $journalEntry): array
    {
        $entryHash = strtolower((string) ($journalEntry['journal_entry_hash'] ?? ''));
        $valid = preg_match('/^[a-f0-9]{64}$/', $entryHash) === 1;
        $receipt = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $valid ? 'receipt_ready' : 'blocked',
            'receipt_kind' => 'runtime_evidence_journal_entry',
            'journal_entry_id' => (string) ($journalEntry['journal_entry_id'] ?? ''),
            'journal_entry_hash' => $entryHash,
            'task_packet_id' => (string) ($journalEntry['task_packet_id'] ?? ''),
            'agent_id' => (string) ($journalEntry['agent_id'] ?? ''),
            'evidence_type' => (string) ($journalEntry['evidence_type'] ?? ''),
            'evidence_hash' => strtolower((string) ($journalEntry['evidence_hash'] ?? '')),
            'is_canonical_evidence_ledger_entry' => false,
            'is_signed_dispatch_receipt' => false,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'completion_claim_allowed' => false,
        ];
        $receipt['receipt_hash'] = $this->stableHash($receipt);

        return $receipt;
    }

    private function stableHash(array $payload): string
    {
        ksort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
