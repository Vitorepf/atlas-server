<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Knowledge;

use App\Services\Ai\SelfConstruction\Knowledge\AtlasSelfConstructionKnowledgeDominanceLoopPlanner;
use Tests\TestCase;

final class AtlasSelfConstructionKnowledgeDominanceLoopPlannerTest extends TestCase
{
    private function planner(): AtlasSelfConstructionKnowledgeDominanceLoopPlanner
    {
        return new AtlasSelfConstructionKnowledgeDominanceLoopPlanner();
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->planner()->plan([]);

        $this->assertSame(AtlasSelfConstructionKnowledgeDominanceLoopPlanner::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->planner()->plan([]);

        foreach (['schema', 'refresh_actions', 'skipped_actions', 'next_originator_context_ready', 'not_ready_reasons'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
    }

    public function test_clean_state_is_ready_with_no_refresh_actions(): void
    {
        $result = $this->planner()->plan([
            'changed_files'    => [],
            'docs_touched'     => [],
            'give_back_count'  => 0,
            'code_index_freshness_seconds' => 0,
            'context_pack_age_seconds'     => 0,
        ]);

        $this->assertTrue($result['next_originator_context_ready']);
        $this->assertSame([], $result['not_ready_reasons']);
        $actionIds = array_column($result['refresh_actions'], 'action_id');
        $this->assertNotContains(AtlasSelfConstructionKnowledgeDominanceLoopPlanner::ACTION_REFRESH_CODE_INDEX, $actionIds);
        $this->assertNotContains(AtlasSelfConstructionKnowledgeDominanceLoopPlanner::ACTION_SYNC_DOCS, $actionIds);
        $this->assertNotContains(AtlasSelfConstructionKnowledgeDominanceLoopPlanner::ACTION_CAPTURE_GIVE_BACKS, $actionIds);
    }

    // ── refresh_code_index ────────────────────────────────────────────────────

    public function test_code_index_refresh_triggered_when_files_changed_and_index_stale(): void
    {
        $result = $this->planner()->plan([
            'changed_files'                => ['Foo.php'],
            'code_index_freshness_seconds' => 301,
        ]);

        $actionIds = array_column($result['refresh_actions'], 'action_id');
        $this->assertContains(AtlasSelfConstructionKnowledgeDominanceLoopPlanner::ACTION_REFRESH_CODE_INDEX, $actionIds);
    }

    public function test_code_index_not_triggered_when_files_changed_but_index_fresh(): void
    {
        $result = $this->planner()->plan([
            'changed_files'                => ['Foo.php'],
            'code_index_freshness_seconds' => 300,   // at threshold, not over
        ]);

        $actionIds = array_column($result['refresh_actions'], 'action_id');
        $this->assertNotContains(AtlasSelfConstructionKnowledgeDominanceLoopPlanner::ACTION_REFRESH_CODE_INDEX, $actionIds);
    }

    public function test_code_index_not_triggered_when_no_files_changed(): void
    {
        $result = $this->planner()->plan([
            'changed_files'                => [],
            'code_index_freshness_seconds' => 9999,
        ]);

        $actionIds = array_column($result['refresh_actions'], 'action_id');
        $this->assertNotContains(AtlasSelfConstructionKnowledgeDominanceLoopPlanner::ACTION_REFRESH_CODE_INDEX, $actionIds);
    }

    public function test_code_index_stale_blocks_originator_readiness(): void
    {
        $result = $this->planner()->plan([
            'changed_files'                => ['Foo.php'],
            'code_index_freshness_seconds' => 600,
        ]);

        $this->assertFalse($result['next_originator_context_ready']);
        $this->assertContains('code_changed_without_index_refresh', $result['not_ready_reasons']);
    }

    // ── sync_docs ─────────────────────────────────────────────────────────────

    public function test_sync_docs_triggered_when_docs_touched_and_no_memory_writes(): void
    {
        $result = $this->planner()->plan([
            'docs_touched'  => ['docs/canon.md'],
            'memory_writes' => [],
        ]);

        $actionIds = array_column($result['refresh_actions'], 'action_id');
        $this->assertContains(AtlasSelfConstructionKnowledgeDominanceLoopPlanner::ACTION_SYNC_DOCS, $actionIds);
    }

    public function test_sync_docs_skipped_when_memory_writes_present(): void
    {
        $result = $this->planner()->plan([
            'docs_touched'  => ['docs/canon.md'],
            'memory_writes' => ['some_memory_key'],
        ]);

        $actionIds = array_column($result['refresh_actions'], 'action_id');
        $this->assertNotContains(AtlasSelfConstructionKnowledgeDominanceLoopPlanner::ACTION_SYNC_DOCS, $actionIds);
    }

    public function test_sync_docs_skipped_when_no_docs_touched(): void
    {
        $result = $this->planner()->plan(['docs_touched' => [], 'memory_writes' => []]);

        $actionIds = array_column($result['refresh_actions'], 'action_id');
        $this->assertNotContains(AtlasSelfConstructionKnowledgeDominanceLoopPlanner::ACTION_SYNC_DOCS, $actionIds);
    }

    public function test_docs_without_sync_blocks_originator_readiness(): void
    {
        $result = $this->planner()->plan(['docs_touched' => ['docs/x.md'], 'memory_writes' => []]);

        $this->assertFalse($result['next_originator_context_ready']);
        $this->assertContains('docs_changed_without_sync_evidence', $result['not_ready_reasons']);
    }

    // ── capture_give_backs ────────────────────────────────────────────────────

    public function test_capture_give_backs_triggered_when_count_meets_threshold_and_not_captured(): void
    {
        $result = $this->planner()->plan([
            'give_back_count'                => 2,
            'give_backs_captured_in_learning' => false,
        ]);

        $actionIds = array_column($result['refresh_actions'], 'action_id');
        $this->assertContains(AtlasSelfConstructionKnowledgeDominanceLoopPlanner::ACTION_CAPTURE_GIVE_BACKS, $actionIds);
    }

    public function test_capture_give_backs_skipped_when_already_captured(): void
    {
        $result = $this->planner()->plan([
            'give_back_count'                => 5,
            'give_backs_captured_in_learning' => true,
        ]);

        $actionIds = array_column($result['refresh_actions'], 'action_id');
        $this->assertNotContains(AtlasSelfConstructionKnowledgeDominanceLoopPlanner::ACTION_CAPTURE_GIVE_BACKS, $actionIds);
    }

    public function test_capture_give_backs_skipped_when_count_below_threshold(): void
    {
        $result = $this->planner()->plan([
            'give_back_count'                => 1,
            'give_backs_captured_in_learning' => false,
        ]);

        $actionIds = array_column($result['refresh_actions'], 'action_id');
        $this->assertNotContains(AtlasSelfConstructionKnowledgeDominanceLoopPlanner::ACTION_CAPTURE_GIVE_BACKS, $actionIds);
    }

    public function test_uncaptured_give_backs_block_originator_readiness(): void
    {
        $result = $this->planner()->plan([
            'give_back_count'                => 3,
            'give_backs_captured_in_learning' => false,
        ]);

        $this->assertFalse($result['next_originator_context_ready']);
        $this->assertContains('give_backs_not_captured_in_learning', $result['not_ready_reasons']);
    }

    // ── refresh_context_pack (advisory only) ──────────────────────────────────

    public function test_context_pack_refresh_triggered_when_age_exceeds_threshold(): void
    {
        $result = $this->planner()->plan(['context_pack_age_seconds' => 3601]);

        $actionIds = array_column($result['refresh_actions'], 'action_id');
        $this->assertContains(AtlasSelfConstructionKnowledgeDominanceLoopPlanner::ACTION_REFRESH_CONTEXT_PACK, $actionIds);
    }

    public function test_context_pack_age_alone_does_not_block_readiness(): void
    {
        $result = $this->planner()->plan(['context_pack_age_seconds' => 9999]);

        $this->assertTrue($result['next_originator_context_ready']);
        $this->assertNotContains('context_pack_stale', $result['not_ready_reasons']);
    }

    public function test_context_pack_skipped_when_fresh(): void
    {
        $result = $this->planner()->plan(['context_pack_age_seconds' => 3600]);

        $actionIds = array_column($result['refresh_actions'], 'action_id');
        $this->assertNotContains(AtlasSelfConstructionKnowledgeDominanceLoopPlanner::ACTION_REFRESH_CONTEXT_PACK, $actionIds);
    }

    // ── multiple triggers ─────────────────────────────────────────────────────

    public function test_all_three_blocking_triggers_simultaneously(): void
    {
        $result = $this->planner()->plan([
            'changed_files'                  => ['A.php'],
            'code_index_freshness_seconds'   => 999,
            'docs_touched'                   => ['docs/x.md'],
            'memory_writes'                  => [],
            'give_back_count'                => 4,
            'give_backs_captured_in_learning' => false,
        ]);

        $this->assertFalse($result['next_originator_context_ready']);
        $this->assertCount(3, $result['not_ready_reasons']);
    }

    // ── skipped_actions ───────────────────────────────────────────────────────

    public function test_skipped_actions_always_populated(): void
    {
        $result = $this->planner()->plan([]);

        $this->assertNotEmpty($result['skipped_actions']);
        foreach ($result['skipped_actions'] as $skip) {
            $this->assertArrayHasKey('action_id', $skip);
            $this->assertArrayHasKey('reason', $skip);
        }
    }

    // ── each action has command ───────────────────────────────────────────────

    public function test_refresh_actions_include_commands(): void
    {
        $result = $this->planner()->plan([
            'changed_files'                => ['X.php'],
            'code_index_freshness_seconds' => 400,
        ]);

        foreach ($result['refresh_actions'] as $action) {
            $this->assertArrayHasKey('command', $action);
            $this->assertNotEmpty($action['command']);
        }
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = [
            'changed_files'                  => ['A.php', 'B.php'],
            'docs_touched'                   => ['docs/x.md'],
            'memory_writes'                  => [],
            'code_index_freshness_seconds'   => 400,
            'context_pack_age_seconds'       => 4000,
            'give_back_count'                => 3,
            'give_backs_captured_in_learning' => false,
        ];

        $this->assertSame($this->planner()->plan($input), $this->planner()->plan($input));
    }
}
