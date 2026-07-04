<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionDeadOrganRetirementLedger;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionDeadOrganRetirementLedgerTest extends TestCase
{
    private function ledger(): AtlasSelfConstructionDeadOrganRetirementLedger
    {
        return new AtlasSelfConstructionDeadOrganRetirementLedger;
    }

    public function test_proxy_only_organ_is_marked_retire_or_convert(): void
    {
        $result = $this->ledger()->classify([
            'organs' => [
                ['organ_id' => 'proxy-organ', 'proxy_only' => true, 'consumer_count' => 5],
            ],
        ]);

        $row = $result['organs'][0];
        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::VERDICT_RETIRE_OR_CONVERT, $row['verdict']);
        $this->assertContains('proxy_only_no_real_capability', $row['retire_reasons']);
        $this->assertSame(1, $result['retire_count']);
    }

    public function test_active_organ_with_consumers_and_proof_is_retained_with_reasons(): void
    {
        $result = $this->ledger()->classify([
            'organs' => [
                ['organ_id' => 'active-organ', 'consumer_count' => 3, 'has_proof_receipt' => true],
            ],
        ]);

        $row = $result['organs'][0];
        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::VERDICT_RETAIN, $row['verdict']);
        $this->assertContains('has_consumers:3', $row['retain_reasons']);
        $this->assertContains('has_proof_receipt', $row['retain_reasons']);
        $this->assertSame(1, $result['retain_count']);
    }

    public function test_unused_organ_without_proof_is_retired(): void
    {
        $result = $this->ledger()->classify([
            'organs' => [
                ['organ_id' => 'unused-organ'],
            ],
        ]);

        $row = $result['organs'][0];
        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::VERDICT_RETIRE_OR_CONVERT, $row['verdict']);
        $this->assertContains('unused_no_consumers_no_proof', $row['retire_reasons']);
    }

    public function test_organ_with_zero_consumers_but_proof_receipt_is_retained(): void
    {
        $result = $this->ledger()->classify([
            'organs' => [
                ['organ_id' => 'dormant-verified', 'consumer_count' => 0, 'has_proof_receipt' => true],
            ],
        ]);

        $row = $result['organs'][0];
        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::VERDICT_RETAIN, $row['verdict']);
    }

    public function test_superseded_organ_is_retired_with_named_successor(): void
    {
        $result = $this->ledger()->classify([
            'organs' => [
                ['organ_id' => 'old-organ', 'consumer_count' => 2, 'superseded_by' => 'NewOrgan'],
            ],
        ]);

        $row = $result['organs'][0];
        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::VERDICT_RETIRE_OR_CONVERT, $row['verdict']);
        $this->assertContains('superseded_by:NewOrgan', $row['retire_reasons']);
    }

    public function test_empty_organs_returns_zero_counts(): void
    {
        $result = $this->ledger()->classify(['organs' => []]);

        $this->assertSame(0, $result['retire_count']);
        $this->assertSame(0, $result['retain_count']);
        $this->assertSame([], $result['organs']);
    }

    // ── recordRetirement(): durable proof ledger for a completed retirement ──

    private function completeRetirementRecord(array $overrides = []): array
    {
        return array_merge([
            'organ_id' => 'proxy-organ',
            'evidence_refs' => ['docs/engineering-knowledge-base/proxy-organ.md'],
            'consumer_scan_result' => ['unsafe_consumers' => []],
            'parity_decision' => ['equivalence_proven' => true],
            'deletion_plan_hash' => 'abc123',
            'replay_gate_result' => ['ready' => true],
            'rollback_receipt' => ['present' => true],
            'knowledge_sync_status' => 'synced',
        ], $overrides);
    }

    public function test_complete_retirement_record_is_recorded_with_receipt_hash(): void
    {
        $result = $this->ledger()->recordRetirement($this->completeRetirementRecord());

        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::STATUS_RECORDED, $result['status']);
        $this->assertSame([], $result['missing_proof_fields']);
        $this->assertNotNull($result['receipt_hash']);
    }

    public function test_incomplete_retirement_record_is_rejected_with_missing_proof_fields(): void
    {
        $record = $this->completeRetirementRecord();
        unset($record['rollback_receipt'], $record['replay_gate_result']);

        $result = $this->ledger()->recordRetirement($record);

        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::STATUS_REJECTED, $result['status']);
        $this->assertContains('rollback_receipt', $result['missing_proof_fields']);
        $this->assertContains('replay_gate_result', $result['missing_proof_fields']);
        $this->assertNull($result['receipt_hash']);
    }

    public function test_receipt_hash_is_deterministic_for_identical_proof(): void
    {
        $record = $this->completeRetirementRecord();

        $first = $this->ledger()->recordRetirement($record);
        $second = $this->ledger()->recordRetirement($record);

        $this->assertSame($first['receipt_hash'], $second['receipt_hash']);
    }

    public function test_receipt_hash_changes_when_proof_changes(): void
    {
        $first = $this->ledger()->recordRetirement($this->completeRetirementRecord());
        $second = $this->ledger()->recordRetirement($this->completeRetirementRecord([
            'deletion_plan_hash' => 'different-hash',
        ]));

        $this->assertNotSame($first['receipt_hash'], $second['receipt_hash']);
    }

    // ── AC: safe_to_retire only when all four evidence gates are present ──────

    private function retireCandidate(array $overrides = []): array
    {
        return array_merge([
            'organ_id' => 'proxy-organ',
            'proxy_only' => true,
        ], $overrides);
    }

    public function test_safe_retirement_when_all_four_evidence_gates_confirmed(): void
    {
        $result = $this->ledger()->classify([
            'organs' => [$this->retireCandidate([
                'consumer_impact_assessed' => true,
                'behavior_parity_confirmed' => true,
                'rollback_plan_present' => true,
                'knowledge_sync_confirmed' => true,
            ])],
        ]);

        $row = $result['organs'][0];
        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::VERDICT_SAFE_TO_RETIRE, $row['verdict']);
        $this->assertTrue($row['safe_to_retire']);
        $this->assertSame([], $row['blocking_reasons']);
    }

    public function test_blocked_retirement_when_no_evidence_gates_confirmed(): void
    {
        $result = $this->ledger()->classify([
            'organs' => [$this->retireCandidate([
                'consumer_impact_assessed' => false,
                'behavior_parity_confirmed' => false,
                'rollback_plan_present' => false,
                'knowledge_sync_confirmed' => false,
            ])],
        ]);

        $row = $result['organs'][0];
        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::VERDICT_REVIEW_NEEDED, $row['verdict']);
        $this->assertFalse($row['safe_to_retire']);
        $this->assertCount(4, $row['blocking_reasons']);
    }

    public function test_review_needed_retirement_when_evidence_partially_confirmed(): void
    {
        $result = $this->ledger()->classify([
            'organs' => [$this->retireCandidate([
                'consumer_impact_assessed' => true,
                'behavior_parity_confirmed' => true,
                'rollback_plan_present' => false,
                'knowledge_sync_confirmed' => false,
            ])],
        ]);

        $row = $result['organs'][0];
        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::VERDICT_REVIEW_NEEDED, $row['verdict']);
        $this->assertFalse($row['safe_to_retire']);
        $this->assertContains('rollback_plan_present', $row['blocking_reasons']);
        $this->assertContains('knowledge_sync_confirmed', $row['blocking_reasons']);
        $this->assertNotContains('consumer_impact_assessed', $row['blocking_reasons']);
    }

    public function test_legacy_callers_without_any_evidence_gate_keep_bare_retire_or_convert(): void
    {
        // No evidence-gate keys supplied at all — must match pre-existing behavior exactly.
        $result = $this->ledger()->classify([
            'organs' => [$this->retireCandidate()],
        ]);

        $row = $result['organs'][0];
        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::VERDICT_RETIRE_OR_CONVERT, $row['verdict']);
        $this->assertFalse($row['safe_to_retire']);
        $this->assertSame([], $row['blocking_reasons']);
    }

    // ── AC: tamper-resistant provider-safe receipt fields ─────────────────────

    public function test_receipt_never_includes_provider_sensitive_keys(): void
    {
        $result = $this->ledger()->recordRetirement($this->completeRetirementRecord());

        foreach (['raw_prompt', 'provider_trace', 'conversation_text', 'secret', 'api_key'] as $sensitiveKey) {
            $this->assertArrayNotHasKey($sensitiveKey, $result);
        }
    }

    public function test_receipt_hash_detects_tampering_with_any_single_proof_field(): void
    {
        $baseline = $this->ledger()->recordRetirement($this->completeRetirementRecord())['receipt_hash'];

        foreach (['evidence_refs', 'consumer_scan_result', 'parity_decision', 'rollback_receipt', 'knowledge_sync_status'] as $field) {
            $originalValue = $this->completeRetirementRecord()[$field];
            $tamperedValue = is_array($originalValue) ? ['tampered' => true] : 'tampered-value';
            $tampered = $this->ledger()->recordRetirement($this->completeRetirementRecord([
                $field => $tamperedValue,
            ]))['receipt_hash'];

            $this->assertNotSame($baseline, $tampered, "tampering '{$field}' must change the receipt hash");
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC2: unbound proof fields (parity, replay, rollback, knowledge_sync)
    // ═══════════════════════════════════════════════════════════════════════

    public function test_rejects_unbound_parity_decision_short_string(): void
    {
        // parity_decision = 'yes' — too short (4 chars), unbound placeholder
        $result = $this->ledger()->recordRetirement($this->completeRetirementRecord([
            'parity_decision' => 'yes',
        ]));

        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::STATUS_REJECTED, $result['status']);
        $this->assertContains('parity_decision', $result['unbound_proof_fields']);
    }

    public function test_rejects_unbound_replay_gate_result_short_string(): void
    {
        // replay_gate_result = 'na' — too short (2 chars), passes isProofPresent but fails bound check.
        $result = $this->ledger()->recordRetirement($this->completeRetirementRecord([
            'replay_gate_result' => 'na',
        ]));

        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::STATUS_REJECTED, $result['status']);
        $this->assertContains('replay_gate_result', $result['unbound_proof_fields']);
    }

    public function test_rejects_unbound_rollback_receipt_array_with_single_short_value(): void
    {
        // Array with a single numeric-indexed short string → unbound
        $result = $this->ledger()->recordRetirement($this->completeRetirementRecord([
            'rollback_receipt' => ['ok'],
        ]));

        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::STATUS_REJECTED, $result['status']);
        $this->assertContains('rollback_receipt', $result['unbound_proof_fields']);
    }

    public function test_rejects_unbound_knowledge_sync_status_short_word(): void
    {
        // knowledge_sync_status = 'ok' — too short (2 chars)
        $result = $this->ledger()->recordRetirement($this->completeRetirementRecord([
            'knowledge_sync_status' => 'ok',
        ]));

        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::STATUS_REJECTED, $result['status']);
        $this->assertContains('knowledge_sync_status', $result['unbound_proof_fields']);
    }

    public function test_rejects_multiple_unbound_fields_simultaneously(): void
    {
        $result = $this->ledger()->recordRetirement($this->completeRetirementRecord([
            'parity_decision' => 'no',
            'replay_gate_result' => 'na',
            'knowledge_sync_status' => '?',
        ]));

        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::STATUS_REJECTED, $result['status']);
        $this->assertContains('parity_decision', $result['unbound_proof_fields']);
        $this->assertContains('replay_gate_result', $result['unbound_proof_fields']);
        $this->assertContains('knowledge_sync_status', $result['unbound_proof_fields']);
        $this->assertCount(3, $result['unbound_proof_fields']);
    }

    public function test_unbound_does_not_affect_fields_not_in_bound_check(): void
    {
        // evidence_refs is not in PROOF_FIELDS_REQUIRING_BOUND_VALUE.
        // A short evidence_refs value should NOT be rejected as unbound.
        $result = $this->ledger()->recordRetirement($this->completeRetirementRecord([
            'evidence_refs' => ['ok'],
        ]));

        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::STATUS_RECORDED, $result['status'],
            'evidence_refs with short value should NOT be flagged as unbound');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC3: provider-unsafe retirement proof (raw prompts, traces, secrets)
    // ═══════════════════════════════════════════════════════════════════════

    public function test_rejects_provider_unsafe_raw_prompt_in_string(): void
    {
        $result = $this->ledger()->recordRetirement($this->completeRetirementRecord([
            'parity_decision' => 'raw_prompt: "I need to compare these two things..."',
        ]));

        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::STATUS_REJECTED, $result['status']);
        $this->assertTrue($result['provider_unsafe_retirement_proof']);
        $this->assertNull($result['receipt_hash']);
    }

    public function test_rejects_provider_unsafe_provider_trace_in_array(): void
    {
        $result = $this->ledger()->recordRetirement($this->completeRetirementRecord([
            'replay_gate_result' => ['provider_trace' => '2026-07-04T16:00:00Z inference complete'],
        ]));

        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::STATUS_REJECTED, $result['status']);
        $this->assertTrue($result['provider_unsafe_retirement_proof']);
    }

    public function test_rejects_provider_unsafe_conversation_text(): void
    {
        $result = $this->ledger()->recordRetirement($this->completeRetirementRecord([
            'rollback_receipt' => ['conversation_text' => 'Can you help me retire this organ?'],
        ]));

        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::STATUS_REJECTED, $result['status']);
        $this->assertTrue($result['provider_unsafe_retirement_proof']);
    }

    public function test_rejects_provider_unsafe_api_key_in_knowledge_sync(): void
    {
        $result = $this->ledger()->recordRetirement($this->completeRetirementRecord([
            'knowledge_sync_status' => 'api_key=sk-1234567890abcdef',
        ]));

        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::STATUS_REJECTED, $result['status']);
        $this->assertTrue($result['provider_unsafe_retirement_proof']);
    }

    public function test_rejects_provider_unsafe_secret_in_nested_array(): void
    {
        // Unsafe pattern found in a nested array value.
        $result = $this->ledger()->recordRetirement($this->completeRetirementRecord([
            'consumer_scan_result' => ['details' => ['auth' => 'secret=super-secret-value']],
        ]));

        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::STATUS_REJECTED, $result['status']);
        $this->assertTrue($result['provider_unsafe_retirement_proof']);
    }

    public function test_provider_safe_record_is_accepted(): void
    {
        // AC4: a complete provider-safe record emits a deterministic receipt_hash.
        $result = $this->ledger()->recordRetirement($this->completeRetirementRecord());

        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::STATUS_RECORDED, $result['status']);
        $this->assertFalse($result['provider_unsafe_retirement_proof']);
        $this->assertSame([], $result['missing_proof_fields']);
        $this->assertSame([], $result['unbound_proof_fields']);
        $this->assertNotNull($result['receipt_hash']);
    }
}
