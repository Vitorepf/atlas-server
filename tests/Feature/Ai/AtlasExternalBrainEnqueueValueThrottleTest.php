<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainEnqueueValueThrottle;
use Tests\TestCase;

final class AtlasExternalBrainEnqueueValueThrottleTest extends TestCase
{
    private AtlasExternalBrainEnqueueValueThrottle $throttle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->throttle = new AtlasExternalBrainEnqueueValueThrottle;
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'batch_value_score' => 0.8,
            'recommended_batch_size' => 6,
            'novelty_score' => 0.8,
            'impact_diversity_score' => 0.8,
            'worker_drain_confidence' => 0.8,
            'queue_depth' => 5,
            'roadmap_coverage_score' => 0.8,
            'candidate_task_ids' => ['t1', 't2', 't3', 't4', 't5', 't6'],
        ], $overrides);
    }

    public function test_healthy_batch_allows_enqueue(): void
    {
        $result = $this->throttle->throttle($this->input());

        $this->assertSame(AtlasExternalBrainEnqueueValueThrottle::SCHEMA, $result['schema']);
        $this->assertSame('allow', $result['enqueue_decision']);
        $this->assertTrue($result['allow_enqueue']);
        $this->assertSame([], $result['blockers']);
        $this->assertNull($result['pivot_recommendation']);
    }

    public function test_low_value_blocks_enqueue(): void
    {
        $result = $this->throttle->throttle($this->input(['batch_value_score' => 0.1]));

        $this->assertFalse($result['allow_enqueue']);
        $this->assertContains('low_value', $result['blockers']);
        $this->assertSame('wait_for_higher_value_candidates', $result['pivot_recommendation']);
    }

    public function test_low_novelty_blocks_as_theme_saturated(): void
    {
        $result = $this->throttle->throttle($this->input(['novelty_score' => 0.1]));

        $this->assertFalse($result['allow_enqueue']);
        $this->assertContains('theme_saturated', $result['blockers']);
        $this->assertSame('pivot_to_different_theme', $result['pivot_recommendation']);
    }

    public function test_too_large_batch_blocks(): void
    {
        $result = $this->throttle->throttle($this->input(['recommended_batch_size' => 11]));

        $this->assertFalse($result['allow_enqueue']);
        $this->assertContains('batch_too_large', $result['blockers']);
    }

    public function test_low_impact_diversity_blocks_as_duplicate_prone(): void
    {
        $result = $this->throttle->throttle($this->input(['impact_diversity_score' => 0.1]));

        $this->assertFalse($result['allow_enqueue']);
        $this->assertContains('duplicate_prone', $result['blockers']);
    }

    public function test_low_roadmap_coverage_blocks_as_unrelated_to_gaps(): void
    {
        $result = $this->throttle->throttle($this->input(['roadmap_coverage_score' => 0.1]));

        $this->assertFalse($result['allow_enqueue']);
        $this->assertContains('unrelated_to_high_priority_gaps', $result['blockers']);
        $this->assertSame('realign_to_roadmap_coverage_gaps', $result['pivot_recommendation']);
    }

    public function test_low_worker_drain_confidence_blocks(): void
    {
        $result = $this->throttle->throttle($this->input(['worker_drain_confidence' => 0.1]));

        $this->assertFalse($result['allow_enqueue']);
        $this->assertContains('worker_throughput_insufficient', $result['blockers']);
        $this->assertSame('wait_for_worker_capacity_to_recover', $result['pivot_recommendation']);
    }

    public function test_salvageable_batch_returns_trimmed_task_ids(): void
    {
        $result = $this->throttle->throttle($this->input([
            'recommended_batch_size' => 11,
            'candidate_task_ids' => ['t1', 't2', 't3', 't4'],
        ]));

        $this->assertFalse($result['allow_enqueue']);
        $this->assertSame(['batch_too_large'], $result['blockers']);
        $this->assertSame(['t1', 't2'], $result['trimmed_task_ids']);
        $this->assertSame('trim_batch_to_salvageable_subset', $result['pivot_recommendation']);
    }

    public function test_non_salvageable_batch_does_not_trim(): void
    {
        $result = $this->throttle->throttle($this->input([
            'batch_value_score' => 0.1,
            'recommended_batch_size' => 11,
        ]));

        $this->assertSame([], $result['trimmed_task_ids']);
    }

    public function test_never_mutates_input_candidate_ids(): void
    {
        $candidates = ['t1', 't2', 't3', 't4'];
        $this->throttle->throttle($this->input(['recommended_batch_size' => 11, 'candidate_task_ids' => $candidates]));

        $this->assertSame(['t1', 't2', 't3', 't4'], $candidates);
    }

    public function test_empty_input_is_blocked(): void
    {
        $result = $this->throttle->throttle([]);

        $this->assertFalse($result['allow_enqueue']);
        $this->assertNotEmpty($result['blockers']);
    }
}
