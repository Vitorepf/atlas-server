<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskGraphDependencyStalenessAuditor;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainTaskGraphDependencyStalenessAuditorTest extends TestCase
{
    private function auditor(): AtlasExternalBrainTaskGraphDependencyStalenessAuditor
    {
        return new AtlasExternalBrainTaskGraphDependencyStalenessAuditor;
    }

    // ── AC2: dependencies already satisfied by implemented capability are marked superseded ──

    public function test_capability_already_implemented_elsewhere_is_marked_superseded(): void
    {
        $result = $this->auditor()->audit([
            'edges' => [['task_id' => 'a', 'depends_on_task_id' => 'b']],
            'statuses' => ['b' => 'queued'],
            'capability_already_implemented' => ['b' => 'c-already-implements-it'],
        ]);

        $this->assertCount(1, $result['superseded_dependents']);
        $this->assertSame('c-already-implements-it', $result['superseded_dependents'][0]['superseded_by']);
        $this->assertSame('capability_already_implemented_elsewhere', $result['superseded_dependents'][0]['reason']);
        $this->assertSame([], $result['stale_edges']);
        $this->assertContains('a', $result['stale_task_ids']);
    }

    public function test_formally_superseded_target_carries_its_own_reason(): void
    {
        $result = $this->auditor()->audit([
            'edges' => [['task_id' => 'a', 'depends_on_task_id' => 'b']],
            'statuses' => ['b' => 'queued'],
            'superseded_targets' => ['b' => 'b-replacement'],
        ]);

        $this->assertSame('formally_superseded', $result['superseded_dependents'][0]['reason']);
    }

    // ── AC3: missing or stale prerequisites hold dependents with reason=stale_dependency ──

    public function test_aged_waiting_dependency_is_flagged_stale_dependency(): void
    {
        $result = $this->auditor()->audit([
            'edges' => [['task_id' => 'a', 'depends_on_task_id' => 'b']],
            'statuses' => ['b' => 'queued'],
            'dependency_age_days' => ['b' => 45],
        ]);

        $this->assertCount(1, $result['stale_edges']);
        $this->assertSame('stale_dependency', $result['stale_edges'][0]['reason']);
        $this->assertSame(45, $result['stale_edges'][0]['age_days']);
        $this->assertContains('a', $result['stale_task_ids']);
        $this->assertSame('resequence', $result['recommended_chain_action']['a']);
    }

    public function test_fresh_waiting_dependency_below_threshold_is_not_flagged(): void
    {
        $result = $this->auditor()->audit([
            'edges' => [['task_id' => 'a', 'depends_on_task_id' => 'b']],
            'statuses' => ['b' => 'queued'],
            'dependency_age_days' => ['b' => 5],
        ]);

        $this->assertSame([], $result['stale_edges']);
        $this->assertNotContains('a', $result['stale_task_ids']);
    }

    public function test_missing_dependency_status_remains_dangling_reference_reason(): void
    {
        $result = $this->auditor()->audit([
            'edges' => [['task_id' => 'a', 'depends_on_task_id' => 'ghost']],
            'statuses' => [],
        ]);

        $this->assertSame('dependency_status_unknown_dangling_reference', $result['stale_edges'][0]['reason']);
    }

    // ── AC4: output includes resequence_actions ────────────────────────────────

    public function test_output_includes_resequence_actions_for_flagged_tasks(): void
    {
        $result = $this->auditor()->audit([
            'edges' => [
                ['task_id' => 'aged', 'depends_on_task_id' => 'b'],
                ['task_id' => 'cancelled-dep', 'depends_on_task_id' => 'c'],
            ],
            'statuses' => ['b' => 'queued', 'c' => 'cancelled'],
            'dependency_age_days' => ['b' => 60],
        ]);

        $this->assertArrayHasKey('resequence_actions', $result);
        $byTaskId = array_column($result['resequence_actions'], null, 'task_id');
        $this->assertArrayHasKey('aged', $byTaskId);
        $this->assertSame('resequence', $byTaskId['aged']['action']);
        $this->assertSame(['b'], $byTaskId['aged']['blocked_by']);
        $this->assertArrayHasKey('cancelled-dep', $byTaskId);
        $this->assertSame('retire', $byTaskId['cancelled-dep']['action']);
    }

    public function test_resequence_actions_is_empty_when_nothing_is_flagged(): void
    {
        $result = $this->auditor()->audit([
            'edges' => [['task_id' => 'a', 'depends_on_task_id' => 'b']],
            'statuses' => ['b' => 'queued'],
        ]);

        $this->assertSame([], $result['resequence_actions']);
    }

    // ── baseline sanity (mirrors the existing Feature-test contract) ──────────

    public function test_output_has_required_keys(): void
    {
        $result = $this->auditor()->audit(['edges' => []]);

        foreach (['schema_version', 'edge_count', 'stale_edges', 'satisfied_dependencies', 'broken_dependencies', 'superseded_dependents', 'repair_or_retire_recommendations', 'stale_task_ids', 'dependency_blockers', 'recommended_chain_action', 'resequence_actions', 'mutates_queue'] as $key) {
            $this->assertArrayHasKey($key, $result, "missing key: {$key}");
        }
        $this->assertSame(AtlasExternalBrainTaskGraphDependencyStalenessAuditor::SCHEMA, $result['schema_version']);
        $this->assertFalse($result['mutates_queue']);
    }

    public function test_completed_delivered_evidenced_dependency_is_satisfied(): void
    {
        $result = $this->auditor()->audit([
            'edges' => [['task_id' => 'a', 'depends_on_task_id' => 'b']],
            'statuses' => ['b' => 'completed'],
            'completion_outcomes' => ['b' => ['outcome' => 'delivered', 'capability_evidence_present' => true]],
        ]);

        $this->assertCount(1, $result['satisfied_dependencies']);
        $this->assertSame([], $result['broken_dependencies']);
    }
}
