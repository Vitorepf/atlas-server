<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\LearningTransfer;

use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasSelfConstructionLearningTransferGiveBackClassifier;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionLearningTransferGiveBackClassifierStarvationTest extends TestCase
{
    private function classifier(): AtlasSelfConstructionLearningTransferGiveBackClassifier
    {
        return new AtlasSelfConstructionLearningTransferGiveBackClassifier;
    }

    public function test_no_claimable_task_classifies_as_queue_starvation_with_facts_preserved(): void
    {
        $result = $this->classifier()->classify([
            'reason' => 'no_claimable_task',
            'task_packet_id' => 'p1',
            'claimable_depth' => 3,
            'active_worker_count' => 6,
        ]);

        $this->assertSame('queue_starvation', $result['class']);
        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_QUEUE_STARVATION, $result['class']);
        $this->assertSame(3, $result['queue_floor_facts']['claimable_depth']);
        $this->assertSame(6, $result['queue_floor_facts']['active_worker_count']);
    }

    public function test_bad_scope_classification_remains_unchanged(): void
    {
        $result = $this->classifier()->classify([
            'reason' => 'scope_gap: allowed_files_insufficient',
        ]);

        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_SCOPE_GAP, $result['class']);
        $this->assertArrayNotHasKey('queue_floor_facts', $result);
    }

    public function test_provider_failure_classification_remains_unchanged(): void
    {
        $result = $this->classifier()->classify([
            'reason' => 'worker_error: execution_error',
        ]);

        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_WORKER_ERROR, $result['class']);
        $this->assertArrayNotHasKey('queue_floor_facts', $result);
    }

    // ── worker_feed_starvation vs packet_shape_defect (AC) ────────────────────

    public function test_no_claimable_task_with_low_claimable_per_active_worker_is_worker_feed_starvation(): void
    {
        $result = $this->classifier()->classify([
            'reason' => 'no_claimable_task',
            'task_packet_id' => 'p1',
            'claimable_depth' => 3,
            'active_worker_count' => 6,
            'claimable_per_active_worker' => 1.5,
        ]);

        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_WORKER_FEED_STARVATION, $result['class']);
        $this->assertSame(1.5, $result['queue_floor_facts']['claimable_per_active_worker']);
    }

    public function test_no_claimable_task_without_low_worker_floor_stays_queue_starvation(): void
    {
        $result = $this->classifier()->classify([
            'reason' => 'no_claimable_task',
            'claimable_per_active_worker' => 10.0,
        ]);

        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_QUEUE_STARVATION, $result['class']);
    }

    public function test_malformed_give_back_evidence_classifies_as_packet_shape_defect(): void
    {
        $result = $this->classifier()->classify([
            'reason' => 'malformed_packet: missing objective',
        ]);

        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_PACKET_SHAPE_DEFECT, $result['class']);
        $this->assertSame('respec_packet_shape', $result['action_hint']);
    }

    public function test_underspecified_give_back_evidence_classifies_as_packet_shape_defect(): void
    {
        $result = $this->classifier()->classify([
            'reason' => 'underspecified_evidence',
        ]);

        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_PACKET_SHAPE_DEFECT, $result['class']);
    }
}
