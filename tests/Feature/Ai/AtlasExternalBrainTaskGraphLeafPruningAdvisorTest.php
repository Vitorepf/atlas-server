<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskGraphLeafPruningAdvisor;
use Tests\TestCase;

final class AtlasExternalBrainTaskGraphLeafPruningAdvisorTest extends TestCase
{
    private AtlasExternalBrainTaskGraphLeafPruningAdvisor $advisor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->advisor = new AtlasExternalBrainTaskGraphLeafPruningAdvisor;
    }

    private function task(string $id, array $overrides = []): array
    {
        return array_merge([
            'task_id' => $id,
            'has_downstream_unlocks' => false,
            'evidence_value' => 0.5,
            'maturity_gap_coverage' => 0.5,
            'implementation_effort' => 0.2,
            'duplication_risk' => 0.1,
            'is_safety_or_certification' => false,
        ], $overrides);
    }

    private function recommendationFor(array $result, string $id): array
    {
        foreach ($result['recommendations'] as $row) {
            if ($row['task_id'] === $id) {
                return $row;
            }
        }
        $this->fail("no recommendation for {$id}");
    }

    public function test_non_leaf_task_is_always_kept(): void
    {
        $result = $this->advisor->advise(['tasks' => [
            $this->task('t1', ['has_downstream_unlocks' => true, 'evidence_value' => 0.0, 'maturity_gap_coverage' => 0.0]),
        ]]);

        $this->assertSame(AtlasExternalBrainTaskGraphLeafPruningAdvisor::SCHEMA, $result['schema']);
        $row = $this->recommendationFor($result, 't1');
        $this->assertSame('keep_leaf', $row['recommendation']);
        $this->assertSame('has_downstream_unlocks_not_a_pruning_candidate', $row['reason']);
    }

    public function test_high_evidence_safety_leaf_is_preserved_despite_no_unlocks(): void
    {
        $result = $this->advisor->advise(['tasks' => [
            $this->task('safety-leaf', [
                'is_safety_or_certification' => true,
                'evidence_value' => 0.9,
                'maturity_gap_coverage' => 0.0,
                'duplication_risk' => 0.9,
            ]),
        ]]);

        $row = $this->recommendationFor($result, 'safety-leaf');
        $this->assertSame('keep_leaf', $row['recommendation']);
        $this->assertSame('preserved_high_evidence_safety_or_certification_leaf', $row['reason']);
    }

    public function test_low_evidence_safety_leaf_is_not_automatically_preserved(): void
    {
        $result = $this->advisor->advise(['tasks' => [
            $this->task('weak-safety-leaf', [
                'is_safety_or_certification' => true,
                'evidence_value' => 0.1,
                'maturity_gap_coverage' => 0.0,
                'duplication_risk' => 0.0,
            ]),
        ]]);

        $row = $this->recommendationFor($result, 'weak-safety-leaf');
        $this->assertNotSame('preserved_high_evidence_safety_or_certification_leaf', $row['reason']);
    }

    public function test_high_duplication_risk_merges(): void
    {
        $result = $this->advisor->advise(['tasks' => [
            $this->task('dup-leaf', ['duplication_risk' => 0.9, 'evidence_value' => 0.5]),
        ]]);

        $row = $this->recommendationFor($result, 'dup-leaf');
        $this->assertSame('merge_leaf', $row['recommendation']);
        $this->assertSame('high_duplication_risk_merge_with_similar_task', $row['reason']);
    }

    public function test_very_low_leverage_retires(): void
    {
        $result = $this->advisor->advise(['tasks' => [
            $this->task('weak-leaf', [
                'evidence_value' => 0.0,
                'maturity_gap_coverage' => 0.0,
                'implementation_effort' => 0.9,
                'duplication_risk' => 0.0,
            ]),
        ]]);

        $row = $this->recommendationFor($result, 'weak-leaf');
        $this->assertSame('retire_leaf', $row['recommendation']);
        $this->assertSame('low_leverage_retire', $row['reason']);
    }

    public function test_moderate_leverage_delays(): void
    {
        $result = $this->advisor->advise(['tasks' => [
            $this->task('moderate-leaf', [
                'evidence_value' => 0.4,
                'maturity_gap_coverage' => 0.4,
                'implementation_effort' => 0.3,
                'duplication_risk' => 0.1,
            ]),
        ]]);

        $row = $this->recommendationFor($result, 'moderate-leaf');
        $this->assertSame('delay_leaf', $row['recommendation']);
        $this->assertSame('moderate_leverage_delay_until_queue_clears', $row['reason']);
    }

    public function test_high_leverage_keeps(): void
    {
        $result = $this->advisor->advise(['tasks' => [
            $this->task('strong-leaf', [
                'evidence_value' => 0.9,
                'maturity_gap_coverage' => 0.9,
                'implementation_effort' => 0.0,
                'duplication_risk' => 0.0,
            ]),
        ]]);

        $row = $this->recommendationFor($result, 'strong-leaf');
        $this->assertSame('keep_leaf', $row['recommendation']);
        $this->assertSame('sufficient_leverage_keep', $row['reason']);
    }

    public function test_leverage_score_is_clamped_between_zero_and_one(): void
    {
        $result = $this->advisor->advise(['tasks' => [
            $this->task('extreme-leaf', [
                'evidence_value' => 1.0,
                'maturity_gap_coverage' => 1.0,
                'implementation_effort' => 0.0,
                'duplication_risk' => 0.0,
            ]),
        ]]);

        $row = $this->recommendationFor($result, 'extreme-leaf');
        $this->assertGreaterThanOrEqual(0.0, $row['leverage_score']);
        $this->assertLessThanOrEqual(1.0, $row['leverage_score']);
    }

    public function test_empty_tasks_returns_empty_recommendations(): void
    {
        $result = $this->advisor->advise(['tasks' => []]);

        $this->assertSame([], $result['recommendations']);
    }
}
