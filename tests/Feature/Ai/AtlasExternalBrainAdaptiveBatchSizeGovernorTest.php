<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAdaptiveBatchSizeGovernor;
use Tests\TestCase;

final class AtlasExternalBrainAdaptiveBatchSizeGovernorTest extends TestCase
{
    private AtlasExternalBrainAdaptiveBatchSizeGovernor $governor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->governor = new AtlasExternalBrainAdaptiveBatchSizeGovernor;
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'queue_depth' => 10,
            'servable_now' => 2,
            'active_workers' => 2,
            'drain_rate_per_hour' => 4.0,
            'theme_saturation' => 0.1,
            'candidate_value_score' => 0.1,
            'high_priority_gap_count' => 0,
        ], $overrides);
    }

    public function test_normal_conditions_recommend_baseline_batch(): void
    {
        $result = $this->governor->govern($this->input());

        $this->assertSame(AtlasExternalBrainAdaptiveBatchSizeGovernor::SCHEMA, $result['schema']);
        $this->assertSame(6, $result['recommended_batch_size']);
        $this->assertContains('baseline_batch', $result['reason_codes']);
        $this->assertTrue($result['should_enqueue']);
    }

    public function test_sufficient_queue_depth_and_low_value_yields_no_enqueue(): void
    {
        $result = $this->governor->govern($this->input([
            'servable_now' => 10,
            'active_workers' => 2,
            'candidate_value_score' => 0.1,
            'high_priority_gap_count' => 0,
        ]));

        $this->assertSame(0, $result['recommended_batch_size']);
        $this->assertFalse($result['should_enqueue']);
        $this->assertContains('queue_depth_sufficient', $result['reason_codes']);
    }

    public function test_sufficient_queue_depth_but_high_value_candidate_still_enqueues_small_batch(): void
    {
        $result = $this->governor->govern($this->input([
            'servable_now' => 10,
            'active_workers' => 2,
            'candidate_value_score' => 0.9,
            'high_priority_gap_count' => 0,
        ]));

        $this->assertGreaterThan(0, $result['recommended_batch_size']);
        $this->assertTrue($result['should_enqueue']);
        $this->assertContains('high_value_candidate_override', $result['reason_codes']);
    }

    public function test_deep_backlog_reduces_batch(): void
    {
        $result = $this->governor->govern($this->input(['queue_depth' => 60]));

        $this->assertLessThan(6, $result['recommended_batch_size']);
        $this->assertContains('deep_backlog_reduces_batch', $result['reason_codes']);
    }

    public function test_theme_saturation_reduces_batch(): void
    {
        $result = $this->governor->govern($this->input(['theme_saturation' => 0.8]));

        $this->assertLessThan(6, $result['recommended_batch_size']);
        $this->assertContains('theme_saturation_reduces_batch', $result['reason_codes']);
    }

    public function test_slow_drain_rate_reduces_batch(): void
    {
        $result = $this->governor->govern($this->input(['active_workers' => 5, 'drain_rate_per_hour' => 1.0]));

        $this->assertLessThan(6, $result['recommended_batch_size']);
        $this->assertContains('slow_drain_rate_reduces_batch', $result['reason_codes']);
    }

    public function test_deep_backlog_and_slow_drain_prefer_small_or_zero_batch(): void
    {
        $result = $this->governor->govern($this->input([
            'queue_depth' => 80,
            'active_workers' => 5,
            'drain_rate_per_hour' => 0.5,
            'theme_saturation' => 0.7,
        ]));

        $this->assertLessThanOrEqual(1, $result['recommended_batch_size']);
    }

    public function test_high_priority_gap_forces_batch_to_fill_gap(): void
    {
        $result = $this->governor->govern($this->input([
            'servable_now' => 10,
            'active_workers' => 2,
            'high_priority_gap_count' => 4,
        ]));

        $this->assertGreaterThanOrEqual(4, $result['recommended_batch_size']);
        $this->assertContains('high_priority_gap_needs_fill', $result['reason_codes']);
        $this->assertTrue($result['should_enqueue']);
    }

    public function test_batch_size_never_exceeds_twelve(): void
    {
        $result = $this->governor->govern($this->input(['high_priority_gap_count' => 999]));

        $this->assertLessThanOrEqual(12, $result['recommended_batch_size']);
    }

    public function test_batch_size_never_negative(): void
    {
        $result = $this->governor->govern($this->input([
            'servable_now' => 50,
            'active_workers' => 1,
            'queue_depth' => 100,
            'theme_saturation' => 1.0,
            'drain_rate_per_hour' => 0.0,
        ]));

        $this->assertGreaterThanOrEqual(0, $result['recommended_batch_size']);
    }
}
