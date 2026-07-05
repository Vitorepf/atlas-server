<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentDispatchExecutorReceiptUseWriter;
use Tests\TestCase;

final class AgentDispatchExecutorReceiptUseWriterTest extends TestCase
{
    private function writer(): AgentDispatchExecutorReceiptUseWriter
    {
        return app(AgentDispatchExecutorReceiptUseWriter::class);
    }

    public function test_generic_success_text_without_proof_becomes_weak_green_learning_payload(): void
    {
        $result = $this->writer()->learningPayloadFromReceipt([
            'outcome_text' => 'success',
            'outcome_class' => '',
            'proof_command' => '',
            'proof_output' => '',
            'worker_id' => 'worker-1',
            'packet_id' => 'packet-1',
        ]);

        $this->assertSame('learning_payload_built', $result['status']);
        $payload = $result['learning_payload'];
        $this->assertSame('weak_green', $payload['task_outcome']);
        $this->assertSame('unproven_success_claim', $payload['proof_status']);
        $this->assertSame('untrusted', $payload['worker_fit_signal']);
    }

    public function test_verified_success_payload_includes_task_family_evidence_hash_and_worker_id(): void
    {
        $result = $this->writer()->learningPayloadFromReceipt([
            'outcome_text' => 'tests passed',
            'outcome_class' => 'success',
            'proof_command' => 'php artisan test',
            'proof_output' => 'OK 10 tests',
            'worker_id' => 'worker-1',
            'packet_id' => 'packet-1',
            'task_family' => 'goal-value',
            'evidence_hash' => 'a'.str_repeat('b', 63),
            'started_at' => '2026-07-05T10:00:00Z',
            'completed_at' => '2026-07-05T10:00:05Z',
        ]);

        $this->assertSame('learning_payload_built', $result['status']);
        $payload = $result['learning_payload'];
        $this->assertSame('packet-1', $payload['packet_id']);
        $this->assertSame('worker-1', $payload['worker_id']);
        $this->assertSame('success', $payload['task_outcome']);
        $this->assertSame('proven', $payload['proof_status']);
        $this->assertSame('positive', $payload['worker_fit_signal']);
        $this->assertSame(5, $payload['elapsed_seconds']);
        $this->assertSame('goal-value', $payload['task_family']);
        $this->assertSame('a'.str_repeat('b', 63), $payload['evidence_hash']);
        $this->assertContains('runtime_registry', $payload['destination']);
        $this->assertContains('task_fabric', $payload['destination']);
    }

    public function test_verified_success_without_task_family_or_evidence_hash_omits_them(): void
    {
        $result = $this->writer()->learningPayloadFromReceipt([
            'outcome_text' => 'tests passed',
            'outcome_class' => 'success',
            'proof_command' => 'php artisan test',
            'proof_output' => 'OK',
            'worker_id' => 'worker-1',
            'packet_id' => 'packet-1',
        ]);

        $payload = $result['learning_payload'];
        $this->assertArrayNotHasKey('task_family', $payload);
        $this->assertArrayNotHasKey('evidence_hash', $payload);
    }

    public function test_give_back_receipt_includes_root_cause_and_respec_hint(): void
    {
        $result = $this->writer()->learningPayloadFromReceipt([
            'outcome_text' => 'give back',
            'outcome_class' => 'give_back',
            'proof_command' => '',
            'proof_output' => '',
            'worker_id' => 'worker-1',
            'packet_id' => 'packet-1',
            'root_cause' => 'missing test coverage',
            'respec_hint' => 'add unit tests',
        ]);

        $this->assertSame('learning_payload_built', $result['status']);
        $payload = $result['learning_payload'];
        $this->assertSame('give_back', $payload['task_outcome']);
        $this->assertSame('missing test coverage', $payload['root_cause']);
        $this->assertSame('add unit tests', $payload['respec_hint']);
    }

    public function test_poison_receipt_includes_root_cause_and_respec_hint(): void
    {
        $result = $this->writer()->learningPayloadFromReceipt([
            'outcome_text' => 'poison',
            'outcome_class' => 'poison',
            'proof_command' => '',
            'proof_output' => '',
            'worker_id' => 'worker-1',
            'packet_id' => 'packet-1',
            'root_cause' => 'proxy leak',
            'respec_hint' => 'tighten gate',
        ]);

        $this->assertSame('learning_payload_built', $result['status']);
        $payload = $result['learning_payload'];
        $this->assertSame('poison', $payload['task_outcome']);
        $this->assertSame('proxy leak', $payload['root_cause']);
        $this->assertSame('tighten gate', $payload['respec_hint']);
    }

    public function test_give_back_without_root_cause_does_not_include_it(): void
    {
        $result = $this->writer()->learningPayloadFromReceipt([
            'outcome_text' => 'give back',
            'outcome_class' => 'give_back',
            'worker_id' => 'worker-1',
            'packet_id' => 'packet-1',
        ]);

        $payload = $result['learning_payload'];
        $this->assertArrayNotHasKey('root_cause', $payload);
        $this->assertArrayNotHasKey('respec_hint', $payload);
    }

    public function test_learning_payload_does_not_include_raw_prompt_or_trace(): void
    {
        $result = $this->writer()->learningPayloadFromReceipt([
            'outcome_text' => 'tests passed',
            'outcome_class' => 'success',
            'proof_command' => 'php artisan test',
            'proof_output' => 'OK',
            'worker_id' => 'worker-1',
            'packet_id' => 'packet-1',
            'raw_prompt' => 'secret prompt',
            'provider_trace' => 'secret trace',
        ]);

        $this->assertSame('learning_payload_built', $result['status']);
        $payload = $result['learning_payload'];
        $this->assertArrayNotHasKey('raw_prompt', $payload);
        $this->assertArrayNotHasKey('provider_trace', $payload);
    }
}
