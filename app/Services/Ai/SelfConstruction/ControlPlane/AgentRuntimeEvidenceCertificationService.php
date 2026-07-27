<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use Carbon\CarbonImmutable;

/**
 * Certifies the local Runtime Evidence Journal layer without writing the
 * canonical ledger or executing any provider/runtime action.
 *
 * Proof-class reporting (AC2): required_proof_classes/observed_proof_classes/missing_proof_classes
 * come straight from the real journal entries vs AgentRuntimeEvidenceContinuityIndexer::REQUIRED_TYPES.
 * stale_evidence_classes names any required class whose real entries are ALL freshness_status=stale
 * (a class with at least one fresh entry is not counted as stale).
 *
 * Freshness refusal (AC3): the required_evidence_not_all_stale invariant blocks certification when a
 * required class is present but entirely stale — evidence that has aged out cannot satisfy continuity.
 *
 * Actionable repair (AC4): repair_steps lists a repair_hint string per failing invariant, so a repair
 * task can act directly instead of reading a bare invariants_all_true=false boolean.
 */
final class AgentRuntimeEvidenceCertificationService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    public const SCHEMA_VERSION = 'atlas.self_construction.agent_runtime_evidence_certification.v1';

    public const MODE = 'read_only_agent_runtime_evidence_certification';

    private const REPAIR_HINTS = [
        'journal_repository_available' => 'ensure the local journal storage prefix/disk is writable, then re-run certify()',
        'receipt_builder_emits_stable_hash' => 'investigate AgentRuntimeEvidenceReceiptBuilder for non-deterministic hashing leaking into the receipt payload',
        'receipt_is_not_ledger_entry' => 'fix AgentRuntimeEvidenceReceiptBuilder so is_canonical_evidence_ledger_entry is always false for receipts',
        'continuity_index_detects_complete_required_set' => 'check AgentRuntimeEvidenceContinuityIndexer::build() for the complete-required-set case',
        'continuity_index_detects_missing_required_set' => 'check AgentRuntimeEvidenceContinuityIndexer::build() for the missing-required-set case',
        'journal_summary_emits_hash' => 'check AgentRuntimeEvidenceJournalRepository::summary() hash computation',
        'runtime_safety_all_false' => 'audit every runtime_safety flag source (receipt + continuity index) for an unexpected true value',
        'journal_integrity_ok' => 'run the journal integrity repair path; corrupted or hash-mismatched records must be quarantined before certification',
        'per_task_continuity_not_stitched_proxy' => 'record all required evidence types under the SAME task_packet_id for at least one task before certifying',
        'required_evidence_not_all_stale' => 're-record a fresh instance of each required evidence class currently marked stale (within the freshness window)',
    ];

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

        $journalIntegrity = $this->journal->integrityAudit();
        $realEntries = $this->journal->list();
        $realIndex = $this->indexer->build($realEntries);
        $globallyComplete = ($realIndex['missing_required_evidence_types'] ?? []) === [];
        $anyTaskChainComplete = false;
        foreach ((array) ($realIndex['per_task_continuity'] ?? []) as $row) {
            if (($row['complete'] ?? false) === true) {
                $anyTaskChainComplete = true;
                break;
            }
        }
        // A stitched-together proxy: required types only exist when scattered across different
        // task_packet_id values, so no single task chain is actually complete.
        $continuityIsStitchedProxy = $realEntries !== [] && $globallyComplete && ! $anyTaskChainComplete;

        $perTaskContinuitySummary = [
            'task_count' => count((array) ($realIndex['per_task_continuity'] ?? [])),
            'complete_task_count' => count(array_filter((array) ($realIndex['per_task_continuity'] ?? []), static fn (array $r): bool => ($r['complete'] ?? false) === true)),
            'globally_complete' => $globallyComplete,
            'any_task_chain_complete' => $anyTaskChainComplete,
            'stitched_proxy_detected' => $continuityIsStitchedProxy,
        ];

        // AC2: required/observed/missing/stale proof-class facts over the REAL journal entries.
        $requiredProofClasses = array_values(AgentRuntimeEvidenceContinuityIndexer::REQUIRED_TYPES);
        $observedProofClasses = array_values(array_unique(array_map(
            static fn (array $e): string => (string) ($e['evidence_type'] ?? ''),
            $realEntries,
        )));
        sort($observedProofClasses, SORT_STRING);
        $missingProofClasses = array_values(array_diff($requiredProofClasses, $observedProofClasses));
        sort($missingProofClasses, SORT_STRING);

        $entriesByType = [];
        foreach ($realEntries as $entry) {
            $entriesByType[(string) ($entry['evidence_type'] ?? '')][] = $entry;
        }
        $staleEvidenceClasses = [];
        foreach ($requiredProofClasses as $type) {
            $entriesOfType = $entriesByType[$type] ?? [];
            if ($entriesOfType === []) {
                continue;
            }
            $anyFresh = false;
            foreach ($entriesOfType as $entry) {
                if ((string) ($entry['freshness_status'] ?? '') !== 'stale') {
                    $anyFresh = true;
                    break;
                }
            }
            if (! $anyFresh) {
                $staleEvidenceClasses[] = $type;
            }
        }
        sort($staleEvidenceClasses, SORT_STRING);

        $invariants = [
            $this->inv('journal_repository_available', $this->journal->isAvailable(), 'journal storage prefix must be writable for local dry-run evidence'),
            $this->inv('receipt_builder_emits_stable_hash', $receiptA['receipt_hash'] === $receiptB['receipt_hash'], 'same journal entry must produce same receipt hash'),
            $this->inv('receipt_is_not_ledger_entry', ($receiptA['is_canonical_evidence_ledger_entry'] ?? true) === false, 'receipt must not masquerade as Evidence Ledger entry'),
            $this->inv('continuity_index_detects_complete_required_set', ($completeIndex['status'] ?? '') === 'continuity_index_complete', 'complete sample evidence set should be complete'),
            $this->inv('continuity_index_detects_missing_required_set', ($incompleteIndex['status'] ?? '') === 'continuity_index_incomplete', 'partial sample evidence set should stay incomplete'),
            $this->inv('journal_summary_emits_hash', preg_match('/^[a-f0-9]{64}$/', (string) ($summary['journal_summary_hash'] ?? '')) === 1, 'journal summary must be hashable'),
            $this->inv('runtime_safety_all_false', $this->runtimeSafetyAllFalse($receiptA, $completeIndex), 'runtime flags must stay false across the evidence layer'),
            $this->inv('journal_integrity_ok', ($journalIntegrity['status'] ?? '') === 'ok', 'local journal records must not be corrupt or hash-mismatched'),
            $this->inv('per_task_continuity_not_stitched_proxy', ! $continuityIsStitchedProxy, 'required evidence types must be complete within a single task chain, not stitched across different task_packet_id values'),
            $this->inv('required_evidence_not_all_stale', $staleEvidenceClasses === [], 'every required evidence class present must have at least one fresh (non-stale) entry'),
        ];

        $violations = array_values(array_filter($invariants, static fn (array $i): bool => $i['ok'] === false));
        $allTrue = $violations === [];
        $violationSummary = $this->violationSummary($violations);
        // AC4: actionable repair steps instead of a bare failed boolean.
        $repairSteps = array_map(fn (array $v): array => [
            'invariant' => (string) ($v['name'] ?? ''),
            'observation' => (string) ($v['observation'] ?? ''),
            'repair_hint' => self::REPAIR_HINTS[(string) ($v['name'] ?? '')] ?? 'investigate and re-run certify() to confirm the invariant is restored',
        ], $violations);
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $allTrue ? 'available' : 'blocked',
            'certified_at' => CarbonImmutable::now()->toIso8601String(),
            'invariants' => $invariants,
            'invariants_all_true' => $allTrue,
            'violation_count' => count($violations),
            'violations' => $violations,
            'violation_summary' => $violationSummary,
            'repair_steps' => $repairSteps,
            'journal_summary' => $summary,
            'journal_integrity' => $journalIntegrity,
            'per_task_continuity_summary' => $perTaskContinuitySummary,
            'required_proof_classes' => $requiredProofClasses,
            'observed_proof_classes' => $observedProofClasses,
            'missing_proof_classes' => $missingProofClasses,
            'stale_evidence_classes' => $staleEvidenceClasses,
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

        // Add certification summary fields
        $payload['certified'] = $allTrue;
        $payload['failed_checks'] = array_values(array_map(fn (array $v) => (string) $v['name'], $violations));
        $payload['freshness_status'] = $staleEvidenceClasses !== [] ? 'stale' : 'fresh';
        $payload['proof_refs'] = array_values(array_map(
            static fn (array $e) => (string) ($e['evidence_ref'] ?? $e['evidence_type'] ?? ''),
            $realEntries,
        ));

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

    /**
     * Groups this class's fixed invariant set into journal/receipt/continuity/
     * runtime_safety classes so a repair task can target the exact broken
     * layer instead of re-reading every invariant name. next_repair_focus
     * names the class with the most violations (fixed tie-break order below),
     * so self-repair always attacks the highest-signal class first.
     *
     * @param  list<array<string,mixed>>  $violations
     * @return array{by_class: array<string,int>, next_repair_focus: ?string}
     */
    private function violationSummary(array $violations): array
    {
        $classifier = [
            'journal_repository_available' => 'journal',
            'journal_summary_emits_hash' => 'journal',
            'journal_integrity_ok' => 'journal',
            'receipt_builder_emits_stable_hash' => 'receipt',
            'receipt_is_not_ledger_entry' => 'receipt',
            'continuity_index_detects_complete_required_set' => 'continuity',
            'continuity_index_detects_missing_required_set' => 'continuity',
            'per_task_continuity_not_stitched_proxy' => 'continuity',
            'runtime_safety_all_false' => 'runtime_safety',
            'required_evidence_not_all_stale' => 'freshness',
        ];

        $byClass = ['journal' => 0, 'receipt' => 0, 'continuity' => 0, 'runtime_safety' => 0, 'freshness' => 0];
        foreach ($violations as $violation) {
            $class = $classifier[(string) ($violation['name'] ?? '')] ?? 'unclassified';
            $byClass[$class] = ($byClass[$class] ?? 0) + 1;
        }

        $nextRepairFocus = null;
        $maxCount = 0;
        foreach (['journal', 'receipt', 'continuity', 'freshness', 'runtime_safety', 'unclassified'] as $class) {
            $count = $byClass[$class] ?? 0;
            if ($count > $maxCount) {
                $maxCount = $count;
                $nextRepairFocus = $class;
            }
        }

        return [
            'by_class' => $byClass,
            'next_repair_focus' => $nextRepairFocus,
        ];
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
