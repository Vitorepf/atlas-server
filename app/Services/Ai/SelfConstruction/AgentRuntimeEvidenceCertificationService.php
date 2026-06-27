<?php

namespace App\Services\Ai\SelfConstruction;


use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use Carbon\CarbonImmutable;

/**
 * Certifies the local Runtime Evidence Journal layer without writing the
 * canonical ledger or executing any provider/runtime action.
 */
final class AgentRuntimeEvidenceCertificationService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    public const SCHEMA_VERSION = 'atlas.self_construction.agent_runtime_evidence_certification.v1';

    public const MODE = 'read_only_agent_runtime_evidence_certification';

    public function __construct(
        private readonly AgentRuntimeEvidenceJournalRepository $journal = new AgentRuntimeEvidenceJournalRepository,
        private readonly AgentRuntimeEvidenceReceiptBuilder $receiptBuilder = new AgentRuntimeEvidenceReceiptBuilder,
        private readonly AgentRuntimeEvidenceContinuityIndexer $indexer = new AgentRuntimeEvidenceContinuityIndexer,
    ) {}

    /** @return array<string, mixed> */
    public function certify(array $options = []): array
    {
        $sampleEntries = $this->sampleEntries();
        $receiptA = $this->receiptBuilder->build($sampleEntries[0]);
        $receiptB = $this->receiptBuilder->build($sampleEntries[0]);
        $completeIndex = $this->indexer->build($sampleEntries);
        $incompleteIndex = $this->indexer->build(array_slice($sampleEntries, 0, 2));
        $summary = $this->journal->summary(['limit' => 25]);

        $invariants = [
            $this->inv('journal_repository_available', $this->journal->isAvailable(), 'journal storage prefix must be writable for local dry-run evidence'),
            $this->inv('receipt_builder_emits_stable_hash', $receiptA['receipt_hash'] === $receiptB['receipt_hash'], 'same journal entry must produce same receipt hash'),
            $this->inv('receipt_is_not_ledger_entry', ($receiptA['is_canonical_evidence_ledger_entry'] ?? true) === false, 'receipt must not masquerade as Evidence Ledger entry'),
            $this->inv('continuity_index_detects_complete_required_set', ($completeIndex['status'] ?? '') === 'continuity_index_complete', 'complete sample evidence set should be complete'),
            $this->inv('continuity_index_detects_missing_required_set', ($incompleteIndex['status'] ?? '') === 'continuity_index_incomplete', 'partial sample evidence set should stay incomplete'),
            $this->inv('journal_summary_emits_hash', preg_match('/^[a-f0-9]{64}$/', (string) ($summary['journal_summary_hash'] ?? '')) === 1, 'journal summary must be hashable'),
            $this->inv('runtime_safety_all_false', $this->runtimeSafetyAllFalse($receiptA, $completeIndex), 'runtime flags must stay false across the evidence layer'),
        ];

        $violations = array_values(array_filter($invariants, static fn (array $i): bool => $i['ok'] === false));
        $allTrue = $violations === [];
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $allTrue ? 'available' : 'blocked',
            'certified_at' => CarbonImmutable::now()->toIso8601String(),
            'invariants' => $invariants,
            'invariants_all_true' => $allTrue,
            'violation_count' => count($violations),
            'violations' => $violations,
            'journal_summary' => $summary,
            'sample_receipt_hash' => (string) ($receiptA['receipt_hash'] ?? ''),
            'complete_continuity_index_hash' => (string) ($completeIndex['continuity_index_hash'] ?? ''),
            'runtime_safety' => [
                'runtime_safety_all_false' => true,
                'runtime_execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
                'ledger_write_allowed' => false,
                'completion_claim_allowed' => false,
            ],
            'next_action' => $allTrue
                ? 'keep_runtime_evidence_journal_local_until_signed_ledger_promotion_gate'
                : 'repair_runtime_evidence_journal_invariants_before_runtime_pilot_promotion',
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'completion_claim_allowed' => false,
        ];
        $payload['certification_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @return list<array<string, mixed>> */
    private function sampleEntries(): array
    {
        $base = [
            'schema_version' => AgentRuntimeEvidenceJournalRepository::SCHEMA_VERSION,
            'mode' => AgentRuntimeEvidenceJournalRepository::MODE,
            'journal_entry_id' => 'journal-sample',
            'task_packet_id' => 'task-sample',
            'agent_id' => 'agent-sample',
            'evidence_hash' => hash('sha256', 'sample'),
            'journal_entry_hash' => hash('sha256', 'journal-sample'),
            'is_canonical_evidence_ledger_entry' => false,
        ];

        return array_map(
            static fn (string $type, int $i): array => array_merge($base, [
                'journal_entry_id' => 'journal-sample-'.$i,
                'evidence_type' => $type,
                'journal_entry_hash' => hash('sha256', 'journal-sample-'.$i.'-'.$type),
            ]),
            AgentRuntimeEvidenceContinuityIndexer::REQUIRED_TYPES,
            array_keys(AgentRuntimeEvidenceContinuityIndexer::REQUIRED_TYPES),
        );
    }

    private function inv(string $name, bool $ok, string $observation): array
    {
        return ['name' => $name, 'ok' => $ok, 'observation' => $observation];
    }

    private function runtimeSafetyAllFalse(array $receipt, array $index): bool
    {
        foreach (['runtime_execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'self_programming_allowed', 'ledger_write_allowed', 'completion_claim_allowed'] as $flag) {
            if (($receipt[$flag] ?? null) !== false) {
                return false;
            }
            if (data_get($index, 'runtime_safety.'.$flag) !== false) {
                return false;
            }
        }

        return data_get($index, 'runtime_safety.runtime_safety_all_false') === true;
    }

    private function stableHash(array $payload): string
    {
        unset($payload['certified_at'], $payload['certification_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

}
