<?php

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentRuntimeEvidenceReceiptBuilder;
use PHPUnit\Framework\TestCase;

final class AgentRuntimeEvidenceReceiptBuilderTest extends TestCase
{
    private function builder(): AgentRuntimeEvidenceReceiptBuilder
    {
        return new AgentRuntimeEvidenceReceiptBuilder;
    }

    private function validEntry(array $overrides = []): array
    {
        return array_merge([
            'journal_entry_id' => 'entry-001',
            'journal_entry_hash' => str_repeat('a', 64),
            'task_packet_id' => 'task-001',
            'agent_id' => 'claude-muscle-3',
            'evidence_type' => 'tests_or_gates_result',
            'evidence_hash' => str_repeat('b', 64),
        ], $overrides);
    }

    public function test_valid_entry_yields_receipt_ready_with_empty_blocker_reasons(): void
    {
        $receipt = $this->builder()->build($this->validEntry());

        $this->assertSame('receipt_ready', $receipt['status']);
        $this->assertSame([], $receipt['blocker_reasons']);
    }

    public function test_missing_journal_entry_id_is_blocked_with_reason(): void
    {
        $receipt = $this->builder()->build($this->validEntry(['journal_entry_id' => '']));

        $this->assertSame('blocked', $receipt['status']);
        $this->assertContains('missing_journal_entry_id', $receipt['blocker_reasons']);
    }

    public function test_missing_task_packet_id_is_blocked_with_reason(): void
    {
        $receipt = $this->builder()->build($this->validEntry(['task_packet_id' => '']));

        $this->assertSame('blocked', $receipt['status']);
        $this->assertContains('missing_task_packet_id', $receipt['blocker_reasons']);
    }

    public function test_missing_agent_id_is_blocked_with_reason(): void
    {
        $receipt = $this->builder()->build($this->validEntry(['agent_id' => '']));

        $this->assertSame('blocked', $receipt['status']);
        $this->assertContains('missing_agent_id', $receipt['blocker_reasons']);
    }

    public function test_missing_evidence_type_is_blocked_with_reason(): void
    {
        $receipt = $this->builder()->build($this->validEntry(['evidence_type' => '']));

        $this->assertSame('blocked', $receipt['status']);
        $this->assertContains('missing_evidence_type', $receipt['blocker_reasons']);
    }

    public function test_missing_evidence_hash_is_blocked_with_reason(): void
    {
        $receipt = $this->builder()->build($this->validEntry(['evidence_hash' => '']));

        $this->assertSame('blocked', $receipt['status']);
        $this->assertContains('missing_evidence_hash', $receipt['blocker_reasons']);
    }

    public function test_malformed_journal_entry_hash_is_blocked_with_reason(): void
    {
        $receipt = $this->builder()->build($this->validEntry(['journal_entry_hash' => 'not-a-valid-hash']));

        $this->assertSame('blocked', $receipt['status']);
        $this->assertContains('malformed_journal_entry_hash', $receipt['blocker_reasons']);
    }

    public function test_multiple_missing_fields_all_appear_in_blocker_reasons(): void
    {
        $receipt = $this->builder()->build($this->validEntry([
            'journal_entry_id' => '',
            'agent_id' => '',
            'journal_entry_hash' => '',
        ]));

        $this->assertContains('missing_journal_entry_id', $receipt['blocker_reasons']);
        $this->assertContains('missing_agent_id', $receipt['blocker_reasons']);
        $this->assertContains('malformed_journal_entry_hash', $receipt['blocker_reasons']);
        $this->assertCount(3, $receipt['blocker_reasons']);
    }

    public function test_receipt_hash_is_deterministic_for_identical_input(): void
    {
        $entry = $this->validEntry();
        $a = $this->builder()->build($entry);
        $b = $this->builder()->build($entry);

        $this->assertSame($a['receipt_hash'], $b['receipt_hash']);
    }

    public function test_blocked_receipt_keeps_all_safety_flags_false(): void
    {
        $receipt = $this->builder()->build($this->validEntry(['journal_entry_hash' => 'bad']));

        $this->assertSame('blocked', $receipt['status']);
        foreach ([
            'is_canonical_evidence_ledger_entry',
            'is_signed_dispatch_receipt',
            'runtime_execution_allowed',
            'dispatch_allowed',
            'provider_call_allowed',
            'token_spend_allowed',
            'self_programming_allowed',
            'ledger_write_allowed',
            'completion_claim_allowed',
        ] as $flag) {
            $this->assertFalse($receipt[$flag], "expected {$flag} to be false on a blocked receipt");
        }
    }

    public function test_ready_receipt_also_keeps_all_safety_flags_false(): void
    {
        $receipt = $this->builder()->build($this->validEntry());

        $this->assertSame('receipt_ready', $receipt['status']);
        foreach ([
            'is_canonical_evidence_ledger_entry',
            'is_signed_dispatch_receipt',
            'runtime_execution_allowed',
            'dispatch_allowed',
            'provider_call_allowed',
            'token_spend_allowed',
            'self_programming_allowed',
            'ledger_write_allowed',
            'completion_claim_allowed',
        ] as $flag) {
            $this->assertFalse($receipt[$flag], "expected {$flag} to be false even on a ready receipt");
        }
    }

    public function test_empty_journal_entry_yields_all_six_blocker_reasons(): void
    {
        $receipt = $this->builder()->build([]);

        $this->assertSame('blocked', $receipt['status']);
        $this->assertCount(6, $receipt['blocker_reasons']);
        $this->assertContains('missing_journal_entry_id', $receipt['blocker_reasons']);
        $this->assertContains('missing_task_packet_id', $receipt['blocker_reasons']);
        $this->assertContains('missing_agent_id', $receipt['blocker_reasons']);
        $this->assertContains('missing_evidence_type', $receipt['blocker_reasons']);
        $this->assertContains('missing_evidence_hash', $receipt['blocker_reasons']);
        $this->assertContains('malformed_journal_entry_hash', $receipt['blocker_reasons']);
    }
}
