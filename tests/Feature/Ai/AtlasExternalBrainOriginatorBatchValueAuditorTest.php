<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOriginatorBatchValueAuditor;
use Tests\TestCase;

final class AtlasExternalBrainOriginatorBatchValueAuditorTest extends TestCase
{
    private function auditor(): AtlasExternalBrainOriginatorBatchValueAuditor
    {
        return new AtlasExternalBrainOriginatorBatchValueAuditor;
    }

    private function goodTask(array $overrides = []): array
    {
        return array_merge([
            'task_id' => 'task-1',
            'theme' => 'theme-a',
            'is_test_only' => false,
            'is_wrapper_only' => false,
            'has_runnable_proof' => true,
            'structural_gates_passed' => true,
            'impact_score' => 0.8,
            'evidence_strength' => 0.8,
            'duplicate_of' => null,
            'collision_risk' => 0.0,
        ], $overrides);
    }

    public function test_returns_per_task_and_batch_level_scores(): void
    {
        $result = $this->auditor()->audit(['tasks' => [
            $this->goodTask(['task_id' => 'a', 'theme' => 'theme-a']),
            $this->goodTask(['task_id' => 'b', 'theme' => 'theme-b']),
        ]]);

        $this->assertCount(2, $result['task_scores']);
        foreach ($result['task_scores'] as $score) {
            foreach (['leverage', 'implementability', 'evidence_strength', 'collision_risk', 'duplication_risk', 'compounding_value'] as $field) {
                $this->assertArrayHasKey($field, $score, "missing field: {$field}");
            }
        }
        $this->assertArrayHasKey('low_value', $result);
        $this->assertArrayHasKey('recommendation', $result);
    }

    public function test_high_quality_diverse_batch_proceeds(): void
    {
        $result = $this->auditor()->audit(['tasks' => [
            $this->goodTask(['task_id' => 'a', 'theme' => 'theme-a']),
            $this->goodTask(['task_id' => 'b', 'theme' => 'theme-b']),
            $this->goodTask(['task_id' => 'c', 'theme' => 'theme-c']),
        ]]);

        $this->assertFalse($result['low_value']);
        $this->assertSame('proceed', $result['recommendation']);
        $this->assertFalse($result['mutates_queue']);
    }

    public function test_same_theme_variants_dominant_pivots_theme(): void
    {
        $result = $this->auditor()->audit(['tasks' => [
            $this->goodTask(['task_id' => 'a', 'theme' => 'cleanup']),
            $this->goodTask(['task_id' => 'b', 'theme' => 'cleanup']),
            $this->goodTask(['task_id' => 'c', 'theme' => 'cleanup']),
            $this->goodTask(['task_id' => 'd', 'theme' => 'other']),
        ]]);

        $this->assertTrue($result['low_value']);
        $this->assertContains('same_theme_variants_dominant', $result['low_value_reasons']);
        $this->assertSame('pivot_theme', $result['recommendation']);
    }

    public function test_test_only_dominant_batch_trims(): void
    {
        $result = $this->auditor()->audit(['tasks' => [
            $this->goodTask(['task_id' => 'a', 'theme' => 'a', 'is_test_only' => true]),
            $this->goodTask(['task_id' => 'b', 'theme' => 'b', 'is_test_only' => true]),
            $this->goodTask(['task_id' => 'c', 'theme' => 'c', 'is_test_only' => false]),
        ]]);

        $this->assertTrue($result['low_value']);
        $this->assertContains('test_only_tasks_dominant', $result['low_value_reasons']);
        $this->assertSame('trim_batch', $result['recommendation']);
    }

    public function test_wrapper_only_dominant_batch_trims(): void
    {
        $result = $this->auditor()->audit(['tasks' => [
            $this->goodTask(['task_id' => 'a', 'theme' => 'a', 'is_wrapper_only' => true]),
            $this->goodTask(['task_id' => 'b', 'theme' => 'b', 'is_wrapper_only' => true]),
            $this->goodTask(['task_id' => 'c', 'theme' => 'c', 'is_wrapper_only' => false]),
        ]]);

        $this->assertTrue($result['low_value']);
        $this->assertContains('wrapper_only_tasks_dominant', $result['low_value_reasons']);
        $this->assertSame('trim_batch', $result['recommendation']);
    }

    public function test_some_tasks_missing_runnable_proof_trims_batch(): void
    {
        $result = $this->auditor()->audit(['tasks' => [
            $this->goodTask(['task_id' => 'a', 'theme' => 'a', 'has_runnable_proof' => false]),
            $this->goodTask(['task_id' => 'b', 'theme' => 'b']),
            $this->goodTask(['task_id' => 'c', 'theme' => 'c']),
        ]]);

        $this->assertTrue($result['low_value']);
        $this->assertContains('tasks_missing_runnable_proof', $result['low_value_reasons']);
        $this->assertSame('trim_batch', $result['recommendation']);
        $this->assertContains('missing_runnable_proof_tasks_present', $result['recommendation_reasons']);
    }

    public function test_all_tasks_missing_runnable_proof_stops_and_researches(): void
    {
        $result = $this->auditor()->audit(['tasks' => [
            $this->goodTask(['task_id' => 'a', 'theme' => 'a', 'has_runnable_proof' => false]),
            $this->goodTask(['task_id' => 'b', 'theme' => 'b', 'has_runnable_proof' => false]),
        ]]);

        $this->assertSame('stop_and_research', $result['recommendation']);
        $this->assertContains('no_task_has_runnable_proof', $result['recommendation_reasons']);
    }

    public function test_low_impact_despite_passing_gates_stops_and_researches(): void
    {
        $result = $this->auditor()->audit(['tasks' => [
            $this->goodTask(['task_id' => 'a', 'theme' => 'a', 'impact_score' => 0.1, 'structural_gates_passed' => true]),
            $this->goodTask(['task_id' => 'b', 'theme' => 'b', 'impact_score' => 0.1, 'structural_gates_passed' => true]),
            $this->goodTask(['task_id' => 'c', 'theme' => 'c', 'impact_score' => 0.1, 'structural_gates_passed' => true]),
        ]]);

        $this->assertTrue($result['low_value']);
        $this->assertContains('low_impact_despite_passing_structural_gates', $result['low_value_reasons']);
        $this->assertSame('stop_and_research', $result['recommendation']);
    }

    public function test_duplicate_task_scores_zero_compounding_value(): void
    {
        $result = $this->auditor()->audit(['tasks' => [
            $this->goodTask(['task_id' => 'a', 'theme' => 'a', 'duplicate_of' => 'task-old']),
        ]]);

        $this->assertSame(1.0, $result['task_scores'][0]['duplication_risk']);
        $this->assertSame(0.0, $result['task_scores'][0]['compounding_value']);
    }

    public function test_empty_batch_stops_and_researches(): void
    {
        $result = $this->auditor()->audit(['tasks' => []]);

        $this->assertSame('stop_and_research', $result['recommendation']);
        $this->assertSame(0, $result['task_count']);
    }

    // ── Trim: keep/drop reasons per task ──────────────────────────────────

    public function test_proceed_recommendation_keeps_all_task_ids(): void
    {
        $result = $this->auditor()->audit(['tasks' => [
            $this->goodTask(['task_id' => 'a', 'theme' => 'theme-a']),
            $this->goodTask(['task_id' => 'b', 'theme' => 'theme-b']),
            $this->goodTask(['task_id' => 'c', 'theme' => 'theme-c']),
        ]]);

        $this->assertSame('proceed', $result['recommendation']);
        $this->assertSame([], $result['trimmed_task_ids']);
        $this->assertSame([], $result['dropped_task_reasons']);
    }

    public function test_trim_batch_drops_tasks_missing_runnable_proof(): void
    {
        $result = $this->auditor()->audit(['tasks' => [
            $this->goodTask(['task_id' => 'a', 'theme' => 'a', 'has_runnable_proof' => false]),
            $this->goodTask(['task_id' => 'b', 'theme' => 'b']),
            $this->goodTask(['task_id' => 'c', 'theme' => 'c']),
        ]]);

        $this->assertSame('trim_batch', $result['recommendation']);
        $this->assertContains('a', $result['trimmed_task_ids']);
        $this->assertNotContains('b', $result['trimmed_task_ids']);
        $this->assertSame('missing_runnable_proof', $result['dropped_task_reasons']['a']);
    }

    public function test_trim_batch_drops_duplicate_and_high_collision_risk_tasks(): void
    {
        $result = $this->auditor()->audit(['tasks' => [
            $this->goodTask(['task_id' => 'a', 'theme' => 'a', 'is_test_only' => true]),
            $this->goodTask(['task_id' => 'b', 'theme' => 'b', 'is_test_only' => true]),
            $this->goodTask(['task_id' => 'c', 'theme' => 'c', 'duplicate_of' => 'old-task']),
            $this->goodTask(['task_id' => 'd', 'theme' => 'd', 'collision_risk' => 0.9]),
        ]]);

        $this->assertSame('trim_batch', $result['recommendation']);
        $this->assertSame('duplicate_of_set', $result['dropped_task_reasons']['c']);
        $this->assertSame('high_collision_risk', $result['dropped_task_reasons']['d']);
        $this->assertContains('c', $result['trimmed_task_ids']);
        $this->assertContains('d', $result['trimmed_task_ids']);
    }

    public function test_pivot_theme_names_dominant_theme_and_trims(): void
    {
        $result = $this->auditor()->audit(['tasks' => [
            $this->goodTask(['task_id' => 'a', 'theme' => 'cleanup']),
            $this->goodTask(['task_id' => 'b', 'theme' => 'cleanup']),
            $this->goodTask(['task_id' => 'c', 'theme' => 'cleanup', 'duplicate_of' => 'old-task']),
            $this->goodTask(['task_id' => 'd', 'theme' => 'other']),
        ]]);

        $this->assertSame('pivot_theme', $result['recommendation']);
        $this->assertSame('cleanup', $result['dominant_theme']);
        $this->assertContains('c', $result['trimmed_task_ids']);
        $this->assertSame('duplicate_of_set', $result['dropped_task_reasons']['c']);
    }
}
