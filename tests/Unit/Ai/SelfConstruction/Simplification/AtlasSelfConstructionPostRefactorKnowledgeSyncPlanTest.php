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

    // ── AC2: minimal sync when behavior-neutral and no ownership change ────────

    public function test_behavior_neutral_no_ownership_change_syncs_only_code_index(): void
    {
        $result = (new AtlasSelfConstructionPostRefactorKnowledgeSyncPlan)->plan([
            'wave_id' => 'wave-1',
            'safe' => true,
            'merged_organs' => [
                [
                    'name' => 'OrganA',
                    'old_paths' => ['app/Old/OrganA.php'],
                    'new_path' => 'app/New/Merged.php',
                    'behavior_changed' => false,
                    'ownership_changed' => false,
                ],
            ],
        ]);

        $targets = array_column($result['sync_actions'], 'target');
        self::assertSame(['code_index'], $targets);
    }

    public function test_behavior_changed_only_syncs_docs_and_code_index(): void
    {
        $result = (new AtlasSelfConstructionPostRefactorKnowledgeSyncPlan)->plan([
            'wave_id' => 'wave-1',
            'safe' => true,
            'merged_organs' => [
                [
                    'name' => 'OrganA',
                    'old_paths' => ['a.php'],
                    'new_path' => 'merged.php',
                    'behavior_changed' => true,
                    'ownership_changed' => false,
                ],
            ],
        ]);

        $targets = array_column($result['sync_actions'], 'target');
        sort($targets);
        self::assertSame(['code_index', 'docs'], $targets);
    }

    public function test_ownership_changed_only_syncs_memory_capability_map_and_code_index(): void
    {
        $result = (new AtlasSelfConstructionPostRefactorKnowledgeSyncPlan)->plan([
            'wave_id' => 'wave-1',
            'safe' => true,
            'merged_organs' => [
                [
                    'name' => 'OrganA',
                    'old_paths' => ['a.php'],
                    'new_path' => 'merged.php',
                    'behavior_changed' => false,
                    'ownership_changed' => true,
                ],
            ],
        ]);

        $targets = array_column($result['sync_actions'], 'target');
        sort($targets);
        self::assertSame(['capability_map', 'code_index', 'memory'], $targets);
    }

    public function test_behavior_and_ownership_flags_absent_default_to_full_sync(): void
    {
        // Backward compatibility: organs that never mention behavior_changed/ownership_changed
        // must keep getting the full four-target sync existing callers rely on.
        $result = (new AtlasSelfConstructionPostRefactorKnowledgeSyncPlan)->plan([
            'wave_id' => 'wave-1',
            'safe' => true,
            'merged_organs' => [
                ['name' => 'OrganA', 'old_paths' => ['a.php'], 'new_path' => 'merged.php'],
            ],
        ]);

        self::assertCount(4, $result['sync_actions']);
    }

    // ── AC3: stale-knowledge risk when a merged organ still appears in docs/memory ──

    public function test_stale_knowledge_risk_flagged_when_organ_still_mentioned_in_docs(): void
    {
        $result = (new AtlasSelfConstructionPostRefactorKnowledgeSyncPlan)->plan([
            'wave_id' => 'wave-1',
            'safe' => true,
            'merged_organs' => [
                ['name' => 'OrganA', 'old_paths' => ['a.php'], 'new_path' => 'merged.php'],
            ],
            'post_sync_knowledge_snapshot' => [
                'docs_mentions' => ['OrganA'],
            ],
        ]);

        self::assertNotEmpty($result['stale_knowledge_risks']);
        self::assertSame(['organ' => 'OrganA', 'source' => 'docs'], $result['stale_knowledge_risks'][0]);
    }

    public function test_stale_knowledge_risk_flagged_when_organ_still_mentioned_in_memory(): void
    {
        $result = (new AtlasSelfConstructionPostRefactorKnowledgeSyncPlan)->plan([
            'wave_id' => 'wave-1',
            'safe' => true,
            'merged_organs' => [
                ['name' => 'OrganA', 'old_paths' => ['a.php'], 'new_path' => 'merged.php'],
            ],
            'post_sync_knowledge_snapshot' => [
                'memory_mentions' => ['OrganA'],
            ],
        ]);

        $sources = array_column($result['stale_knowledge_risks'], 'source');
        self::assertContains('memory', $sources);
    }

    public function test_no_stale_knowledge_risk_when_organ_absent_from_snapshot(): void
    {
        $result = (new AtlasSelfConstructionPostRefactorKnowledgeSyncPlan)->plan([
            'wave_id' => 'wave-1',
            'safe' => true,
            'merged_organs' => [
                ['name' => 'OrganA', 'old_paths' => ['a.php'], 'new_path' => 'merged.php'],
            ],
            'post_sync_knowledge_snapshot' => [
                'docs_mentions' => ['SomeOtherOrgan'],
            ],
        ]);

        self::assertSame([], $result['stale_knowledge_risks']);
    }

    public function test_stale_knowledge_risks_empty_when_no_snapshot_supplied(): void
    {
        $result = (new AtlasSelfConstructionPostRefactorKnowledgeSyncPlan)->plan([
            'wave_id' => 'wave-1',
            'safe' => true,
            'merged_organs' => [
                ['name' => 'OrganA', 'old_paths' => ['a.php'], 'new_path' => 'merged.php'],
            ],
        ]);

        self::assertSame([], $result['stale_knowledge_risks']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC2/AC3: malformed merged organ block
    // ═══════════════════════════════════════════════════════════════════════

    public function test_empty_name_merged_organ_returns_blocked_with_explicit_blocker(): void
    {
        $result = (new AtlasSelfConstructionPostRefactorKnowledgeSyncPlan)->plan([
            'wave_id' => 'wave-1',
            'safe' => true,
            'merged_organs' => [
                ['name' => '', 'old_paths' => ['app/Old/OrganA.php'], 'new_path' => 'app/New/Merged.php'],
            ],
        ]);

        self::assertSame(AtlasSelfConstructionPostRefactorKnowledgeSyncPlan::STATUS_BLOCKED, $result['status']);
        self::assertContains('malformed_merged_organ:index_0:empty_name', $result['blockers']);
    }

    public function test_empty_old_paths_merged_organ_returns_blocked_with_explicit_blocker(): void
    {
        $result = (new AtlasSelfConstructionPostRefactorKnowledgeSyncPlan)->plan([
            'wave_id' => 'wave-1',
            'safe' => true,
            'merged_organs' => [
                ['name' => 'OrganA', 'old_paths' => [], 'new_path' => 'app/New/Merged.php'],
            ],
        ]);

        self::assertSame(AtlasSelfConstructionPostRefactorKnowledgeSyncPlan::STATUS_BLOCKED, $result['status']);
        self::assertContains('malformed_merged_organ:OrganA:empty_old_paths', $result['blockers']);
    }

    public function test_empty_new_path_merged_organ_returns_blocked_with_explicit_blocker(): void
    {
        $result = (new AtlasSelfConstructionPostRefactorKnowledgeSyncPlan)->plan([
            'wave_id' => 'wave-1',
            'safe' => true,
            'merged_organs' => [
                ['name' => 'OrganA', 'old_paths' => ['app/Old/OrganA.php'], 'new_path' => ''],
            ],
        ]);

        self::assertSame(AtlasSelfConstructionPostRefactorKnowledgeSyncPlan::STATUS_BLOCKED, $result['status']);
        self::assertContains('malformed_merged_organ:OrganA:empty_new_path', $result['blockers']);
    }

    public function test_multiple_organs_with_multiple_malformations_produce_all_blockers(): void
    {
        $result = (new AtlasSelfConstructionPostRefactorKnowledgeSyncPlan)->plan([
            'wave_id' => 'wave-1',
            'safe' => true,
            'merged_organs' => [
                ['name' => '', 'old_paths' => [], 'new_path' => ''],
                ['name' => 'OrganB', 'old_paths' => ['b.php'], 'new_path' => ''],
            ],
        ]);

        self::assertSame(AtlasSelfConstructionPostRefactorKnowledgeSyncPlan::STATUS_BLOCKED, $result['status']);
        self::assertContains('malformed_merged_organ:index_0:empty_name', $result['blockers']);
        self::assertContains('malformed_merged_organ:index_0:empty_old_paths', $result['blockers']);
        self::assertContains('malformed_merged_organ:index_0:empty_new_path', $result['blockers']);
        self::assertContains('malformed_merged_organ:OrganB:empty_new_path', $result['blockers']);
        self::assertCount(4, $result['blockers']);
    }

    public function test_malformed_organ_produces_no_sync_actions_even_when_safe(): void
    {
        // AC3: even with safe=true, a malformed merged organ must produce zero sync_actions.
        $result = (new AtlasSelfConstructionPostRefactorKnowledgeSyncPlan)->plan([
            'wave_id' => 'wave-1',
            'safe' => true,
            'merged_organs' => [
                ['name' => 'OrganA', 'old_paths' => [], 'new_path' => 'app/New/Merged.php'],
            ],
        ]);

        self::assertSame(AtlasSelfConstructionPostRefactorKnowledgeSyncPlan::STATUS_BLOCKED, $result['status']);
        self::assertSame([], $result['sync_actions'],
            'malformed organ must produce no sync_actions even when safe=true');
    }

    public function test_malformed_does_not_affect_well_formed_organs_in_separate_wave(): void
    {
        // Ensure the existing well-formed path is still intact.
        $result = (new AtlasSelfConstructionPostRefactorKnowledgeSyncPlan)->plan([
            'wave_id' => 'wave-1',
            'safe' => true,
            'merged_organs' => [
                ['name' => 'OrganA', 'old_paths' => ['app/Old/OrganA.php'], 'new_path' => 'app/New/Merged.php'],
            ],
        ]);

        self::assertSame(AtlasSelfConstructionPostRefactorKnowledgeSyncPlan::STATUS_SYNCED, $result['status']);
        self::assertNotEmpty($result['sync_actions']);
        self::assertSame([], $result['blockers']);
    }
}
