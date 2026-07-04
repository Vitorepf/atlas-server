<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskGraphDependencyStalenessAuditor;
use Tests\TestCase;

final class AtlasExternalBrainTaskGraphDependencyStalenessAuditorTest extends TestCase
{
    private function auditor(): AtlasExternalBrainTaskGraphDependencyStalenessAuditor
    {
        return new AtlasExternalBrainTaskGraphDependencyStalenessAuditor;
    }

    public function test_accepts_edges_statuses_outcomes_and_superseded_facts(): void
    {
        $result = $this->auditor()->audit([
            'edges' => [['task_id' => 'a', 'depends_on_task_id' => 'b']],
            'statuses' => ['b' => 'queued'],
            'completion_outcomes' => [],
            'superseded_targets' => [],
        ]);

        foreach (['stale_edges', 'satisfied_dependencies', 'broken_dependencies', 'superseded_dependents', 'repair_or_retire_recommendations'] as $field) {
            $this->assertArrayHasKey($field, $result, "missing field: {$field}");
        }
        $this->assertFalse($result['mutates_queue']);
    }

    public function test_completed_with_delivered_evidenced_outcome_is_satisfied(): void
    {
        $result = $this->auditor()->audit([
            'edges' => [['task_id' => 'a', 'depends_on_task_id' => 'b']],
            'statuses' => ['b' => 'completed'],
            'completion_outcomes' => ['b' => ['outcome' => 'delivered', 'capability_evidence_present' => true]],
        ]);

        $this->assertCount(1, $result['satisfied_dependencies']);
        $this->assertSame([], $result['broken_dependencies']);
    }

    public function test_completed_without_capability_evidence_is_broken(): void
    {
        $result = $this->auditor()->audit([
            'edges' => [['task_id' => 'a', 'depends_on_task_id' => 'b']],
            'statuses' => ['b' => 'completed'],
            'completion_outcomes' => ['b' => ['outcome' => 'delivered', 'capability_evidence_present' => false]],
        ]);

        $this->assertCount(1, $result['broken_dependencies']);
        $this->assertSame([], $result['satisfied_dependencies']);
        $this->assertContains('repair', array_column($result['repair_or_retire_recommendations'], 'action'));
    }

    public function test_cancelled_dependency_is_broken_and_recommends_retire(): void
    {
        $result = $this->auditor()->audit([
            'edges' => [['task_id' => 'a', 'depends_on_task_id' => 'b']],
            'statuses' => ['b' => 'cancelled'],
        ]);

        $this->assertCount(1, $result['broken_dependencies']);
        $actions = array_column($result['repair_or_retire_recommendations'], 'action');
        $this->assertContains('retire', $actions);
    }

    public function test_superseded_dependency_is_reported_separately(): void
    {
        $result = $this->auditor()->audit([
            'edges' => [['task_id' => 'a', 'depends_on_task_id' => 'b']],
            'statuses' => ['b' => 'queued'],
            'superseded_targets' => ['b' => 'b-replacement'],
        ]);

        $this->assertCount(1, $result['superseded_dependents']);
        $this->assertSame('b-replacement', $result['superseded_dependents'][0]['superseded_by']);
        $this->assertSame([], $result['stale_edges']);
    }

    public function test_unknown_dependency_status_is_a_stale_dangling_edge(): void
    {
        $result = $this->auditor()->audit([
            'edges' => [['task_id' => 'a', 'depends_on_task_id' => 'ghost']],
            'statuses' => [],
        ]);

        $this->assertCount(1, $result['stale_edges']);
    }

    public function test_still_valid_waiting_dependency_is_not_reported_as_stale(): void
    {
        $result = $this->auditor()->audit([
            'edges' => [
                ['task_id' => 'a', 'depends_on_task_id' => 'b'],
                ['task_id' => 'c', 'depends_on_task_id' => 'd'],
                ['task_id' => 'e', 'depends_on_task_id' => 'f'],
            ],
            'statuses' => ['b' => 'queued', 'd' => 'claimed', 'f' => 'blocked'],
        ]);

        $this->assertSame([], $result['stale_edges']);
        $this->assertSame([], $result['broken_dependencies']);
        $this->assertSame([], $result['superseded_dependents']);
        $this->assertSame([], $result['satisfied_dependencies']);
    }

    // ── quarantined ──────────────────────────────────────────────────────────

    public function test_quarantined_dependency_is_broken_and_recommends_rescope(): void
    {
        $result = $this->auditor()->audit([
            'edges' => [['task_id' => 'a', 'depends_on_task_id' => 'b']],
            'statuses' => ['b' => 'quarantined'],
        ]);

        $this->assertCount(1, $result['broken_dependencies']);
        $actions = array_column($result['repair_or_retire_recommendations'], 'action');
        $this->assertContains('rescope', $actions);
        $this->assertNotEmpty($result['broken_dependencies'][0]['rescope_plan']);
    }

    // ── new fields: stale_task_ids, dependency_blockers, recommended_chain_action ──

    public function test_terminal_quarantined_and_superseded_populate_stale_task_ids_and_chain_action(): void
    {
        $result = $this->auditor()->audit([
            'edges' => [
                ['task_id' => 'cancelled-dependent', 'depends_on_task_id' => 'x'],
                ['task_id' => 'quarantined-dependent', 'depends_on_task_id' => 'y'],
                ['task_id' => 'superseded-dependent', 'depends_on_task_id' => 'z'],
            ],
            'statuses' => ['x' => 'cancelled', 'y' => 'quarantined', 'z' => 'queued'],
            'superseded_targets' => ['z' => 'z-replacement'],
        ]);

        $this->assertContains('cancelled-dependent', $result['stale_task_ids']);
        $this->assertContains('quarantined-dependent', $result['stale_task_ids']);
        $this->assertContains('superseded-dependent', $result['stale_task_ids']);

        $this->assertSame(['x'], $result['dependency_blockers']['cancelled-dependent']);
        $this->assertSame('retire', $result['recommended_chain_action']['cancelled-dependent']);
        $this->assertSame('rescope', $result['recommended_chain_action']['quarantined-dependent']);
        $this->assertSame('rescope', $result['recommended_chain_action']['superseded-dependent']);
    }

    public function test_fresh_dependency_task_id_is_not_present_in_stale_task_ids(): void
    {
        $result = $this->auditor()->audit([
            'edges' => [['task_id' => 'still-good', 'depends_on_task_id' => 'b']],
            'statuses' => ['b' => 'queued'],
        ]);

        $this->assertNotContains('still-good', $result['stale_task_ids']);
        $this->assertArrayNotHasKey('still-good', $result['recommended_chain_action']);
    }

    public function test_never_mutates_queue(): void
    {
        $result = $this->auditor()->audit(['edges' => []]);

        $this->assertFalse($result['mutates_queue']);
        $this->assertSame(0, $result['edge_count']);
    }

    // ── AC2: rescope_patch on stale/dangling edges ─────────────────────────

    public function test_dangling_stale_edge_includes_rescope_patch_with_remove(): void
    {
        $result = $this->auditor()->audit([
            'edges' => [['task_id' => 'a', 'depends_on_task_id' => 'ghost']],
            'statuses' => [],
        ]);

        $this->assertArrayHasKey('rescope_patch', $result['stale_edges'][0]);
        $this->assertArrayHasKey('remove_depends_on', $result['stale_edges'][0]['rescope_patch']);
        $this->assertSame('ghost', $result['stale_edges'][0]['rescope_patch']['remove_depends_on']);
    }

    // ── AC3: rescope_patch on superseded edges with replace ───────────────

    public function test_superseded_edge_includes_rescope_patch_with_replace(): void
    {
        $result = $this->auditor()->audit([
            'edges' => [['task_id' => 'a', 'depends_on_task_id' => 'b']],
            'statuses' => ['b' => 'queued'],
            'superseded_targets' => ['b' => 'b-replacement'],
        ]);

        $this->assertArrayHasKey('rescope_patch', $result['superseded_dependents'][0]);
        $patch = $result['superseded_dependents'][0]['rescope_patch'];
        $this->assertArrayHasKey('replace_depends_on', $patch);
        $this->assertArrayHasKey('replacement_id', $patch);
        $this->assertSame('b', $patch['replace_depends_on']);
        $this->assertSame('b-replacement', $patch['replacement_id']);
    }

    // ── AC4: completed with delivered evidence never emits rescope_patch ──

    public function test_satisfied_dependency_never_emits_rescope_patch(): void
    {
        $result = $this->auditor()->audit([
            'edges' => [['task_id' => 'a', 'depends_on_task_id' => 'b']],
            'statuses' => ['b' => 'completed'],
            'completion_outcomes' => ['b' => ['outcome' => 'delivered', 'capability_evidence_present' => true]],
        ]);

        $this->assertSame([], $result['broken_dependencies']);
        $this->assertSame([], $result['stale_edges']);
        $this->assertSame([], $result['superseded_dependents']);
    }

    public function test_quarantined_and_cancelled_also_have_rescope_patch(): void
    {
        $result = $this->auditor()->audit([
            'edges' => [
                ['task_id' => 'a', 'depends_on_task_id' => 'x'],
                ['task_id' => 'b', 'depends_on_task_id' => 'y'],
            ],
            'statuses' => ['x' => 'cancelled', 'y' => 'quarantined'],
        ]);

        foreach ($result['broken_dependencies'] as $edge) {
            $this->assertArrayHasKey('rescope_patch', $edge);
            $this->assertArrayHasKey('remove_depends_on', $edge['rescope_patch']);
        }
    }
}
