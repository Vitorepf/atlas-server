<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\LearningTransfer;

use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasSelfConstructionLearningTransferGiveBackClassifier;
use Tests\TestCase;

final class AtlasSelfConstructionLearningTransferGiveBackClassifierReplenishSignalsTest extends TestCase
{
    public function test_no_claimable_task_class_produces_originator_top_up_hint(): void
    {
        $classifier = new AtlasSelfConstructionLearningTransferGiveBackClassifier();
        $result = $classifier->classify(['reason' => 'no_claimable_task: queue exhausted']);

        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_NO_CLAIMABLE_TASK, $result['class']);
        $this->assertSame('originator_top_up', $result['action_hint']);
    }

    public function test_stale_claimable_backlog_class_produces_rotate_or_refresh_hint(): void
    {
        $classifier = new AtlasSelfConstructionLearningTransferGiveBackClassifier();
        $result = $classifier->classify(['reason' => 'stale_claimable_backlog: every queued task already attempted']);

        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_STALE_CLAIMABLE_BACKLOG, $result['class']);
        $this->assertSame('rotate_or_refresh_claimable_queue', $result['action_hint']);
    }

    public function test_no_claimable_task_classification_is_deterministic(): void
    {
        $classifier = new AtlasSelfConstructionLearningTransferGiveBackClassifier();
        $fact = ['reason' => 'no_claimable_task', 'task_packet_id' => 'p1'];

        $a = $classifier->classify($fact);
        $b = $classifier->classify($fact);

        $this->assertSame($a, $b);
    }

    public function test_forbidden_target_classification_unchanged(): void
    {
        $classifier = new AtlasSelfConstructionLearningTransferGiveBackClassifier();
        $result = $classifier->classify(['reason' => 'forbidden_target: pétreo path']);

        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_FORBIDDEN_TARGET, $result['class']);
        $this->assertSame('quarantine_poison', $result['action_hint']);
    }

    public function test_scope_gap_classification_unchanged(): void
    {
        $classifier = new AtlasSelfConstructionLearningTransferGiveBackClassifier();
        $result = $classifier->classify(['reason' => 'scope_gap: allowed_files_insufficient for acceptance']);

        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_SCOPE_GAP, $result['class']);
        $this->assertSame('respec_allowed_files', $result['action_hint']);
    }
}
