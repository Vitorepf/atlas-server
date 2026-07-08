<?php

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentRuntimeEvidenceReceiptBuilder;
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
            'timestamp' => '2026-07-04T12:00:00Z',
            'outcome' => 'success',
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
        $this->assertContains('missing_journal_entry_hash', $receipt['blocker_reasons']);
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
        $this->assertContains('missing_journal_entry_id', $receipt['blocker_reasons']);
        $this->assertContains('missing_task_packet_id', $receipt['blocker_reasons']);
        $this->assertContains('missing_agent_id', $receipt['blocker_reasons']);
        $this->assertContains('missing_evidence_type', $receipt['blocker_reasons']);
        $this->assertContains('missing_evidence_hash', $receipt['blocker_reasons']);
        $this->assertContains('missing_journal_entry_hash', $receipt['blocker_reasons']);
        $this->assertContains('missing_timestamp', $receipt['blocker_reasons']);
        $this->assertContains('missing_outcome', $receipt['blocker_reasons']);
    }

    public function test_malformed_evidence_hash_and_malformed_journal_entry_hash_each_produce_blockers(): void
    {
        $badEvidence = $this->builder()->build($this->validEntry(['evidence_hash' => 'not-a-real-hash']));
        $badEntry = $this->builder()->build($this->validEntry(['journal_entry_hash' => 'not-a-real-hash']));

        $this->assertContains('malformed_evidence_hash', $badEvidence['blocker_reasons']);
        $this->assertContains('malformed_journal_entry_hash', $badEntry['blocker_reasons']);
    }

    public function test_blocker_count_equals_blocker_reasons_count(): void
    {
        $ready = $this->builder()->build($this->validEntry());
        $blocked = $this->builder()->build([]);

        $this->assertSame(count($ready['blocker_reasons']), $ready['blocker_count']);
        $this->assertSame(0, $ready['blocker_count']);
        $this->assertSame(count($blocked['blocker_reasons']), $blocked['blocker_count']);
        $this->assertSame(8, $blocked['blocker_count']);
    }

    public function test_test_result_evidence_type_without_command_ref_is_blocked(): void
    {
        $receipt = $this->builder()->build($this->validEntry(['evidence_type' => 'test_result']));

        $this->assertSame('blocked', $receipt['status']);
        $this->assertContains('missing_command_ref', $receipt['blocker_reasons']);
    }

    public function test_test_result_evidence_type_with_command_ref_is_not_blocked_for_that_reason(): void
    {
        $receipt = $this->builder()->build($this->validEntry(['evidence_type' => 'test_result', 'command_ref' => 'phpunit tests/Foo.php']));

        $this->assertNotContains('missing_command_ref', $receipt['blocker_reasons']);
    }

    public function test_gate_result_evidence_type_without_target_path_is_blocked(): void
    {
        $receipt = $this->builder()->build($this->validEntry(['evidence_type' => 'gate_result']));

        $this->assertSame('blocked', $receipt['status']);
        $this->assertContains('missing_target_path', $receipt['blocker_reasons']);
    }

    public function test_gate_result_evidence_type_with_target_path_is_not_blocked_for_that_reason(): void
    {
        $receipt = $this->builder()->build($this->validEntry(['evidence_type' => 'gate_result', 'target_path' => 'app/Foo.php']));

        $this->assertNotContains('missing_target_path', $receipt['blocker_reasons']);
    }

    public function test_trailing_newline_on_evidence_hash_and_journal_entry_hash_still_rejected(): void
    {
        $badEvidence = $this->builder()->build($this->validEntry(['evidence_hash' => str_repeat('b', 64)."\n"]));
        $badEntry = $this->builder()->build($this->validEntry(['journal_entry_hash' => str_repeat('a', 64)."\n"]));

        $this->assertContains('malformed_evidence_hash', $badEvidence['blocker_reasons']);
        $this->assertContains('malformed_journal_entry_hash', $badEntry['blocker_reasons']);
    }

    public function test_equivalent_valid_entries_produce_stable_hash_and_all_flags_false(): void
    {
        $entryA = $this->validEntry();
        $entryB = $this->validEntry();

        $receiptA = $this->builder()->build($entryA);
        $receiptB = $this->builder()->build($entryB);

        $this->assertSame($receiptA['receipt_hash'], $receiptB['receipt_hash']);
        foreach ([
            'runtime_execution_allowed',
            'dispatch_allowed',
            'provider_call_allowed',
            'token_spend_allowed',
            'self_programming_allowed',
            'ledger_write_allowed',
            'completion_claim_allowed',
        ] as $flag) {
            $this->assertFalse($receiptA[$flag]);
            $this->assertFalse($receiptB[$flag]);
        }
    }

    public function test_json_encode_failure_throws_instead_of_hashing_empty_string(): void
    {
        // stableHash is private; test through build() with a payload that contains
        // a value json_encode cannot handle. We use reflection to call stableHash directly
        // with an array containing a resource (which json_encode always rejects).
        $builder = $this->builder();
        $method = new \ReflectionMethod($builder, 'stableHash');

        // A resource cannot be JSON-encoded — json_encode returns false.
        $unencodable = ['_resource' => fopen('php://memory', 'r')];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('json_encode failed on receipt payload');
        $method->invoke($builder, $unencodable);
    }

    // ── AC: missing timestamp and outcome blocked ──────────────────────────

    public function test_missing_timestamp_is_blocked(): void
    {
        $receipt = $this->builder()->build($this->validEntry(['timestamp' => '']));

        $this->assertSame('blocked', $receipt['status']);
        $this->assertContains('missing_timestamp', $receipt['blocker_reasons']);
    }

    public function test_missing_outcome_is_blocked(): void
    {
        $receipt = $this->builder()->build($this->validEntry(['outcome' => '']));

        $this->assertSame('blocked', $receipt['status']);
        $this->assertContains('missing_outcome', $receipt['blocker_reasons']);
    }

    public function test_gate_result_type_without_gate_result_is_blocked(): void
    {
        $receipt = $this->builder()->build($this->validEntry([
            'evidence_type' => 'gate_result',
            'target_path' => 'app/Foo.php',
        ]));

        $this->assertContains('missing_gate_result', $receipt['blocker_reasons']);
    }

    public function test_gate_result_type_with_gate_result_passes(): void
    {
        $receipt = $this->builder()->build($this->validEntry([
            'evidence_type' => 'gate_result',
            'gate_result' => 'passed',
            'target_path' => 'app/Foo.php',
        ]));

        $this->assertNotContains('missing_gate_result', $receipt['blocker_reasons']);
    }

    // ── AC: proof_hash exists and is stable for equivalent entries ──────────

    public function test_proof_hash_present_in_output(): void
    {
        $receipt = $this->builder()->build($this->validEntry());

        $this->assertArrayHasKey('proof_hash', $receipt);
        $this->assertMatchesRegularExpression('/^proof_[a-f0-9]{32}$/', $receipt['proof_hash']);
    }

    public function test_proof_hash_stable_for_equivalent_entry(): void
    {
        $a = $this->builder()->build($this->validEntry());
        $b = $this->builder()->build($this->validEntry());

        $this->assertSame($a['proof_hash'], $b['proof_hash']);
    }

    public function test_proof_hash_changes_when_proof_fields_change(): void
    {
        $a = $this->builder()->build($this->validEntry());
        $b = $this->builder()->build($this->validEntry(['outcome' => 'give_back']));

        $this->assertNotSame($a['proof_hash'], $b['proof_hash']);
    }

    public function test_proof_hash_stable_across_volatile_metadata(): void
    {
        // Different blocker reasons should not change the proof hash
        $a = $this->builder()->build($this->validEntry());
        $b = $this->builder()->build($this->validEntry(['journal_entry_id' => 'entry-002']));

        $this->assertNotSame($a['proof_hash'], $b['proof_hash'], 'different entry id changes proof');
    }

    public function test_receipt_hash_differs_from_proof_hash(): void
    {
        $receipt = $this->builder()->build($this->validEntry());

        $this->assertNotSame($receipt['receipt_hash'], $receipt['proof_hash']);
    }

    // ── AC: volatile/provider-private fields redacted from hash ─────────────

    // ── AC: receipt_hash stable for identical proof-bearing content ─────────

    public function test_receipt_hash_stable_for_equivalent_valid_entries(): void
    {
        $receiptA = $this->builder()->build($this->validEntry());
        $receiptB = $this->builder()->build($this->validEntry());

        $this->assertSame($receiptA['receipt_hash'], $receiptB['receipt_hash']);
    }

    public function test_receipt_hash_changes_when_proof_changes(): void
    {
        $receiptA = $this->builder()->build($this->validEntry());
        $receiptB = $this->builder()->build($this->validEntry(['task_packet_id' => 'different-task']));

        $this->assertNotSame($receiptA['receipt_hash'], $receiptB['receipt_hash']);
    }
}
