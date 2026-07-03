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
        $journalEntryId = (string) ($journalEntry['journal_entry_id'] ?? '');
        $taskPacketId = (string) ($journalEntry['task_packet_id'] ?? '');
        $agentId = (string) ($journalEntry['agent_id'] ?? '');
        $evidenceType = (string) ($journalEntry['evidence_type'] ?? '');
        $evidenceHash = strtolower((string) ($journalEntry['evidence_hash'] ?? ''));
        $entryHash = strtolower((string) ($journalEntry['journal_entry_hash'] ?? ''));
        $commandRef = (string) ($journalEntry['command_ref'] ?? '');
        $targetPath = (string) ($journalEntry['target_path'] ?? '');
        // \A...\z (not ^...$) so a trailing newline on an otherwise-64-char hash cannot slip past
        // the check — ^/$ alone match immediately before a trailing "\n".
        $entryHashValid = preg_match('/\A[a-f0-9]{64}\z/', $entryHash) === 1;
        $evidenceHashValid = preg_match('/\A[a-f0-9]{64}\z/', $evidenceHash) === 1;

        $blockerReasons = [];
        if ($journalEntryId === '') {
            $blockerReasons[] = 'missing_journal_entry_id';
        }
        if ($taskPacketId === '') {
            $blockerReasons[] = 'missing_task_packet_id';
        }
        if ($agentId === '') {
            $blockerReasons[] = 'missing_agent_id';
        }
        if ($evidenceType === '') {
            $blockerReasons[] = 'missing_evidence_type';
        }
        if ($evidenceHash === '') {
            $blockerReasons[] = 'missing_evidence_hash';
        } elseif (! $evidenceHashValid) {
            $blockerReasons[] = 'malformed_evidence_hash';
        }
        if ($entryHash === '') {
            $blockerReasons[] = 'missing_journal_entry_hash';
        } elseif (! $entryHashValid) {
            $blockerReasons[] = 'malformed_journal_entry_hash';
        }
        if ($evidenceType === 'test_result' && $commandRef === '') {
            $blockerReasons[] = 'missing_command_ref';
        }
        if ($evidenceType === 'gate_result' && $targetPath === '') {
            $blockerReasons[] = 'missing_target_path';
        }

        $receipt = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $blockerReasons === [] ? 'receipt_ready' : 'blocked',
            'blocker_reasons' => $blockerReasons,
            'blocker_count' => count($blockerReasons),
            'receipt_kind' => 'runtime_evidence_journal_entry',
            'journal_entry_id' => $journalEntryId,
            'journal_entry_hash' => $entryHash,
            'task_packet_id' => $taskPacketId,
            'agent_id' => $agentId,
            'evidence_type' => $evidenceType,
            'evidence_hash' => $evidenceHash,
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

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \RuntimeException('json_encode failed on receipt payload — cannot produce stable hash');
        }

        return hash('sha256', $encoded);
    }
}
