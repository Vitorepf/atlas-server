<?php

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;

/**
 * Builds deterministic local receipts for Runtime Evidence Journal entries.
 *
 * Receipts produced here are review evidence only. They are not signed
 * dispatch receipts and are not Evidence Ledger writes.
 */
final class AgentRuntimeEvidenceReceiptBuilder
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    public const SCHEMA_VERSION = 'atlas.self_construction.agent_runtime_evidence_receipt.v1';

    public const MODE = 'read_only_agent_runtime_evidence_receipt_builder';

    /**
     * Volatile / provider-private fields that must NEVER participate in the
     * proof hash. Stripped before hashing so provider-private data (API keys,
     * session tokens, IPs) and runtime metadata (blockers, counts, status)
     * don't leak into the stable receipt hash.
     */
    private const VOLATILE_FIELDS = [
        'status',
        'blocker_reasons',
        'blocker_count',
        'receipt_kind',
        'schema_version',
        'mode',
        'receipt_hash',
        'proof_hash',
        'provider_private_data',
    ];

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
        $timestamp = (string) ($journalEntry['timestamp'] ?? '');
        $outcome = (string) ($journalEntry['outcome'] ?? '');
        $gateResult = (string) ($journalEntry['gate_result'] ?? '');
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
        if ($timestamp === '') {
            $blockerReasons[] = 'missing_timestamp';
        }
        if ($outcome === '') {
            $blockerReasons[] = 'missing_outcome';
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
        if ($evidenceType === 'gate_result' && $gateResult === '') {
            $blockerReasons[] = 'missing_gate_result';
        }
        if ($evidenceType === 'test_result' && $commandRef === '') {
            $blockerReasons[] = 'missing_command_ref';
        }
        if ($evidenceType === 'gate_result' && $targetPath === '') {
            $blockerReasons[] = 'missing_target_path';
        }

        $proofFields = $this->proofFields($journalEntry);

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
            'timestamp' => $timestamp,
            'outcome' => $outcome,
            'gate_result' => $gateResult,
            'command_ref' => $commandRef,
            'target_path' => $targetPath,
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
        $receipt['proof_hash'] = $this->proofHash($proofFields);
        $receipt['receipt_hash'] = $this->stableHash($receipt);

        return $receipt;
    }

    /**
     * Extract proof-bearing fields from the journal entry — these are the fields
     * that, when they change, should produce a different hash. Volatile and
     * provider-private fields are excluded.
     *
     * @param  array<string, mixed>  $journalEntry
     * @return array<string, mixed>
     */
    private function proofFields(array $journalEntry): array
    {
        $identity = [
            'journal_entry_id' => (string) ($journalEntry['journal_entry_id'] ?? ''),
            'journal_entry_hash' => strtolower((string) ($journalEntry['journal_entry_hash'] ?? '')),
            'task_packet_id' => (string) ($journalEntry['task_packet_id'] ?? ''),
            'agent_id' => (string) ($journalEntry['agent_id'] ?? ''),
            'evidence_type' => (string) ($journalEntry['evidence_type'] ?? ''),
            'evidence_hash' => strtolower((string) ($journalEntry['evidence_hash'] ?? '')),
            'timestamp' => (string) ($journalEntry['timestamp'] ?? ''),
            'outcome' => (string) ($journalEntry['outcome'] ?? ''),
            'gate_result' => (string) ($journalEntry['gate_result'] ?? ''),
            'command_ref' => (string) ($journalEntry['command_ref'] ?? ''),
            'target_path' => (string) ($journalEntry['target_path'] ?? ''),
        ];

        return $this->ksortRecursive($identity);
    }

    /**
     * SHA-256 hash of the proof-bearing fields only — stable regardless of
     * volatile metadata (blocker reasons, status, schema version).
     *
     * @param  array<string, mixed>  $proofFields
     */
    private function proofHash(array $proofFields): string
    {
        $encoded = json_encode($proofFields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \RuntimeException('json_encode failed on proof fields — cannot produce proof hash');
        }

        return 'proof_'.substr(hash('sha256', $encoded), 0, 32);
    }

    /**
     * Stable hash of the full receipt — includes volatile metadata but strips
     * the hash fields themselves (to avoid circular dependency) and provider-private
     * data.
     *
     * @param  array<string, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        // Strip volatile and hash fields before hashing
        foreach (self::VOLATILE_FIELDS as $field) {
            unset($payload[$field]);
        }

        ksort($payload);

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \RuntimeException('json_encode failed on receipt payload — cannot produce stable hash');
        }

        return hash('sha256', $encoded);
    }
}
