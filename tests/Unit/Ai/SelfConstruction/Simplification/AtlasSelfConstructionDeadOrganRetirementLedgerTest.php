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
}
