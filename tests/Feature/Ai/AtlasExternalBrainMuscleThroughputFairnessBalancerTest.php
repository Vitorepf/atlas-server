<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMuscleThroughputFairnessBalancer;
use Tests\TestCase;

final class AtlasExternalBrainMuscleThroughputFairnessBalancerTest extends TestCase
{
    private AtlasExternalBrainMuscleThroughputFairnessBalancer $balancer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->balancer = new AtlasExternalBrainMuscleThroughputFairnessBalancer;
    }

    private function muscle(string $id, array $overrides = []): array
    {
        return array_merge([
            'muscle_id' => $id,
            'throughput_per_hour' => 4.0,
            'reliability_score' => 0.9,
            'active_lease_count' => 1,
            'give_back_rate' => 0.05,
        ], $overrides);
    }

    public function test_equal_muscles_get_equal_distribution_and_high_fairness(): void
    {
        $result = $this->balancer->balance(['muscles' => [
            $this->muscle('worker-a'),
            $this->muscle('worker-b'),
        ]]);

        $this->assertSame(AtlasExternalBrainMuscleThroughputFairnessBalancer::SCHEMA, $result['schema']);
        $this->assertEqualsWithDelta(0.5, $result['recommended_distribution']['worker-a'], 0.001);
        $this->assertEqualsWithDelta(0.5, $result['recommended_distribution']['worker-b'], 0.001);
        $this->assertEqualsWithDelta(1.0, $result['fairness_score'], 0.001);
    }

    public function test_high_give_back_rate_penalizes_fast_muscle(): void
    {
        $result = $this->balancer->balance(['muscles' => [
            $this->muscle('fast-risky', ['throughput_per_hour' => 20.0, 'give_back_rate' => 0.6]),
            $this->muscle('slow-reliable', ['throughput_per_hour' => 4.0, 'give_back_rate' => 0.0]),
        ]]);

        $this->assertGreaterThan(
            $result['recommended_distribution']['fast-risky'],
            $result['recommended_distribution']['slow-reliable'],
        );
    }

    public function test_high_lease_count_reduces_share(): void
    {
        $result = $this->balancer->balance(['muscles' => [
            $this->muscle('busy', ['active_lease_count' => 5]),
            $this->muscle('idle', ['active_lease_count' => 0]),
        ]]);

        $this->assertGreaterThan(
            $result['recommended_distribution']['busy'],
            $result['recommended_distribution']['idle'],
        );
    }

    public function test_overload_warnings_fire_for_high_lease_count_and_give_back_rate(): void
    {
        $result = $this->balancer->balance(['muscles' => [
            $this->muscle('overloaded', ['active_lease_count' => 4, 'give_back_rate' => 0.5]),
        ]]);

        $this->assertContains('overloaded_lease_count:overloaded', $result['overload_warnings']);
        $this->assertContains('high_give_back_rate:overloaded', $result['overload_warnings']);
    }

    public function test_dominant_share_triggers_overloaded_share_warning(): void
    {
        $result = $this->balancer->balance(['muscles' => [
            $this->muscle('dominant', ['throughput_per_hour' => 100.0]),
            $this->muscle('tiny', ['throughput_per_hour' => 0.1]),
        ]]);

        $this->assertContains('overloaded_share:dominant', $result['overload_warnings']);
    }

    public function test_proven_muscle_is_high_risk_eligible(): void
    {
        $result = $this->balancer->balance(['muscles' => [
            $this->muscle('proven', ['reliability_score' => 0.9, 'give_back_rate' => 0.05]),
        ]]);

        $this->assertSame('high_risk_eligible', $result['next_task_family_preferences']['proven']);
    }

    public function test_unproven_muscle_is_low_risk_only(): void
    {
        $result = $this->balancer->balance(['muscles' => [
            $this->muscle('unproven', ['reliability_score' => 0.4, 'give_back_rate' => 0.3]),
        ]]);

        $this->assertSame('low_risk_only', $result['next_task_family_preferences']['unproven']);
    }

    public function test_fast_but_unreliable_muscle_is_not_high_risk_eligible(): void
    {
        $result = $this->balancer->balance(['muscles' => [
            $this->muscle('fast-but-unreliable', ['throughput_per_hour' => 50.0, 'reliability_score' => 0.3, 'give_back_rate' => 0.5]),
        ]]);

        $this->assertSame('low_risk_only', $result['next_task_family_preferences']['fast-but-unreliable']);
    }

    public function test_empty_muscles_returns_safe_defaults(): void
    {
        $result = $this->balancer->balance(['muscles' => []]);

        $this->assertSame([], $result['recommended_distribution']);
        $this->assertSame(1.0, $result['fairness_score']);
        $this->assertSame([], $result['overload_warnings']);
        $this->assertSame([], $result['next_task_family_preferences']);
    }

    public function test_zero_capacity_muscles_get_zero_share_not_division_error(): void
    {
        $result = $this->balancer->balance(['muscles' => [
            $this->muscle('idle-zero', ['throughput_per_hour' => 0.0]),
        ]]);

        $this->assertSame(0.0, $result['recommended_distribution']['idle-zero']);
    }
}
