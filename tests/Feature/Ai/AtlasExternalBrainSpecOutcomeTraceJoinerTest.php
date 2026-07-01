<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSpecOutcomeTraceJoiner;
use Tests\TestCase;

final class AtlasExternalBrainSpecOutcomeTraceJoinerTest extends TestCase
{
    private function spec(): array
    {
        return [
            'task_packet_id' => 'task-1',
            'task_family' => 'wiring',
            'allowed_files' => ['app/Services/Foo.php', 'tests/Unit/FooTest.php'],
            'acceptance_criteria' => ['exit 0'],
        ];
    }

    public function test_pending_outcome_returns_task_shape_without_fake_success(): void
    {
        $result = (new AtlasExternalBrainSpecOutcomeTraceJoiner)->join($this->spec(), []);

        $this->assertSame(AtlasExternalBrainSpecOutcomeTraceJoiner::STATUS_PENDING, $result['status']);
        $this->assertArrayHasKey('task_shape', $result);
        $this->assertArrayNotHasKey('success', $result);
    }

    public function test_success_record_computes_evidence_strength_and_emits_learning_signal(): void
    {
        $result = (new AtlasExternalBrainSpecOutcomeTraceJoiner)->join($this->spec(), [
            'status' => 'success',
            'commit_sha' => 'abc123',
            'evidence' => [
                'tests_or_gates_result' => 'green',
                'implementation_notes' => 'wired the class',
            ],
        ]);

        $this->assertSame(AtlasExternalBrainSpecOutcomeTraceJoiner::STATUS_SUCCESS, $result['status']);
        $this->assertTrue($result['success']);
        $this->assertSame(1.0, $result['evidence_strength']);
        $this->assertSame('reinforce_task_shape_and_worker_pairing', $result['learning_signal']);
    }

    public function test_give_back_with_spec_shape_defect_becomes_repair_candidate(): void
    {
        $result = (new AtlasExternalBrainSpecOutcomeTraceJoiner)->join($this->spec(), [
            'status' => 'give_back',
            'give_back_reason' => 'objective is ambiguous',
        ]);

        $this->assertSame(AtlasExternalBrainSpecOutcomeTraceJoiner::STATUS_GIVE_BACK, $result['status']);
        $this->assertTrue($result['repair_candidate']);
        $this->assertSame('repair_spec_shape_before_resubmitting', $result['learning_signal']);
    }

    public function test_weak_green_emits_correct_learning_signal(): void
    {
        $result = (new AtlasExternalBrainSpecOutcomeTraceJoiner)->join($this->spec(), ['status' => 'weak_green']);

        $this->assertSame(AtlasExternalBrainSpecOutcomeTraceJoiner::STATUS_WEAK_GREEN, $result['status']);
        $this->assertSame('evidence_too_weak_trust_only_partially', $result['learning_signal']);
    }

    public function test_poison_emits_correct_learning_signal(): void
    {
        $result = (new AtlasExternalBrainSpecOutcomeTraceJoiner)->join($this->spec(), ['status' => 'poison']);

        $this->assertSame(AtlasExternalBrainSpecOutcomeTraceJoiner::STATUS_POISON, $result['status']);
        $this->assertSame('quarantine_pattern_before_reuse', $result['learning_signal']);
    }

    public function test_failed_gate_emits_correct_learning_signal(): void
    {
        $result = (new AtlasExternalBrainSpecOutcomeTraceJoiner)->join($this->spec(), ['status' => 'failed_gate']);

        $this->assertSame(AtlasExternalBrainSpecOutcomeTraceJoiner::STATUS_FAILED_GATE, $result['status']);
        $this->assertSame('strengthen_acceptance_or_gate_alignment', $result['learning_signal']);
    }
}
