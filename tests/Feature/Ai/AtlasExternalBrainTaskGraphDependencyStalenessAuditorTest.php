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

    public function test_never_mutates_queue(): void
    {
        $result = $this->auditor()->audit(['edges' => []]);

        $this->assertFalse($result['mutates_queue']);
        $this->assertSame(0, $result['edge_count']);
    }
}
