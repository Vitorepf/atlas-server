<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionPostRefactorKnowledgeSyncPlan;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionPostRefactorKnowledgeSyncPlanTest extends TestCase
{
    public function test_safe_wave_emits_docs_memory_code_index_and_capability_map_updates(): void
    {
        $result = (new AtlasSelfConstructionPostRefactorKnowledgeSyncPlan)->plan([
            'wave_id' => 'wave-1',
            'safe' => true,
            'merged_organs' => [
                ['name' => 'OrganA', 'old_paths' => ['app/Old/OrganA.php'], 'new_path' => 'app/New/Merged.php'],
            ],
        ]);

        self::assertSame(AtlasSelfConstructionPostRefactorKnowledgeSyncPlan::STATUS_SYNCED, $result['status']);
        $targets = array_column($result['sync_actions'], 'target');
        self::assertContains('docs', $targets);
        self::assertContains('memory', $targets);
        self::assertContains('code_index', $targets);
        self::assertContains('capability_map', $targets);
    }

    public function test_no_merged_organs_is_no_op_with_no_sync_churn(): void
    {
        $result = (new AtlasSelfConstructionPostRefactorKnowledgeSyncPlan)->plan([
            'wave_id' => 'wave-1',
            'safe' => true,
            'merged_organs' => [],
        ]);

        self::assertSame(AtlasSelfConstructionPostRefactorKnowledgeSyncPlan::STATUS_NO_OP, $result['status']);
        self::assertSame([], $result['sync_actions']);
    }

    public function test_unsafe_wave_is_blocked_from_sync(): void
    {
        $result = (new AtlasSelfConstructionPostRefactorKnowledgeSyncPlan)->plan([
            'wave_id' => 'wave-1',
            'safe' => false,
            'merged_organs' => [
                ['name' => 'OrganA', 'old_paths' => ['app/Old/OrganA.php'], 'new_path' => 'app/New/Merged.php'],
            ],
        ]);

        self::assertSame(AtlasSelfConstructionPostRefactorKnowledgeSyncPlan::STATUS_BLOCKED, $result['status']);
        self::assertSame([], $result['sync_actions']);
        self::assertContains('wave_not_marked_safe', $result['blockers']);
    }

    public function test_multiple_merged_organs_each_get_full_sync_action_set(): void
    {
        $result = (new AtlasSelfConstructionPostRefactorKnowledgeSyncPlan)->plan([
            'wave_id' => 'wave-1',
            'safe' => true,
            'merged_organs' => [
                ['name' => 'OrganA', 'old_paths' => ['a.php'], 'new_path' => 'merged.php'],
                ['name' => 'OrganB', 'old_paths' => ['b.php'], 'new_path' => 'merged.php'],
            ],
        ]);

        self::assertCount(8, $result['sync_actions']);
    }
}
