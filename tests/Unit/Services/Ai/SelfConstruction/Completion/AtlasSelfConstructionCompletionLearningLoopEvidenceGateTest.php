<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Completion;

use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionCompletionLearningLoopEvidenceGate;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionCompletionLearningLoopEvidenceGateTest extends TestCase
{
    private AtlasSelfConstructionCompletionLearningLoopEvidenceGate $gate;

    protected function setUp(): void
    {
        $this->gate = new AtlasSelfConstructionCompletionLearningLoopEvidenceGate;
    }

    public function test_missing_learning_evidence_blocks_readiness(): void
    {
        $result = $this->gate->evaluate([]);

        $this->assertFalse($result['ready']);
        $this->assertNotEmpty($result['blockers']);
    }

    public function test_outcome_to_next_task_feedback_passes(): void
    {
        $result = $this->gate->evaluate([
            'outcome_to_next_task_feedback' => true,
            'learning_transferred' => false,
            'originator_adjusted' => false,
        ]);

        $this->assertFalse($result['ready']);
        $this->assertNotContains('missing_outcome_to_next_task_feedback', $result['blockers']);
    }

    public function test_learning_transferred_passes(): void
    {
        $result = $this->gate->evaluate([
            'outcome_to_next_task_feedback' => false,
            'learning_transferred' => true,
            'originator_adjusted' => false,
        ]);

        $this->assertFalse($result['ready']);
        $this->assertNotContains('missing_learning_transfer', $result['blockers']);
    }

    public function test_originator_adjusted_passes(): void
    {
        $result = $this->gate->evaluate([
            'outcome_to_next_task_feedback' => false,
            'learning_transferred' => false,
            'originator_adjusted' => true,
        ]);

        $this->assertFalse($result['ready']);
        $this->assertNotContains('missing_originator_adjustment', $result['blockers']);
    }

    public function test_all_evidence_present_readiness_passes(): void
    {
        $result = $this->gate->evaluate([
            'outcome_to_next_task_feedback' => true,
            'learning_transferred' => true,
            'originator_adjusted' => true,
        ]);

        $this->assertTrue($result['ready']);
        $this->assertEmpty($result['blockers']);
    }

    public function test_schema_present(): void
    {
        $result = $this->gate->evaluate([]);
        $this->assertSame(AtlasSelfConstructionCompletionLearningLoopEvidenceGate::SCHEMA, $result['schema']);
    }
}
