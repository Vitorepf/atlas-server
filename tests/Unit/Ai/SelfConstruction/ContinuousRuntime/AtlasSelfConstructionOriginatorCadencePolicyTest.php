<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ContinuousRuntime;

use App\Services\Ai\SelfConstruction\ContinuousRuntime\AtlasSelfConstructionOriginatorCadencePolicy;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionOriginatorCadencePolicyTest extends TestCase
{
    private AtlasSelfConstructionOriginatorCadencePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new AtlasSelfConstructionOriginatorCadencePolicy;
    }

    private function healthy(array $overrides = []): array
    {
        return array_merge([
            'claimable_depth'       => 2,   // starving → seed_now by default
            'blocked_count'         => 0,
            'worker_throughput_rate' => 0.8,
            'malformed_risk'        => 0.05,
            'recent_quality_score'  => 8.5,
            'max_batch_size'        => 5,
        ], $overrides);
    }

    // ── Schema / envelope ────────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->policy->decide($this->healthy());

        foreach (['schema', 'action', 'recommended_batch_size', 'reasons'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
        $this->assertSame(AtlasSelfConstructionOriginatorCadencePolicy::SCHEMA, $result['schema']);
    }

    // ── AC1: actions and batch_size ──────────────────────────────────────────

    public function test_seed_now_when_queue_starving_and_conditions_safe(): void
    {
        $result = $this->policy->decide($this->healthy(['claimable_depth' => 1]));

        $this->assertSame(AtlasSelfConstructionOriginatorCadencePolicy::ACTION_SEED_NOW, $result['action']);
        $this->assertGreaterThanOrEqual(1, $result['recommended_batch_size']);
        $this->assertLessThanOrEqual(5, $result['recommended_batch_size']);
    }

    public function test_seed_now_batch_size_bounded_by_max_batch_size(): void
    {
        $result = $this->policy->decide($this->healthy(['max_batch_size' => 3]));

        $this->assertLessThanOrEqual(3, $result['recommended_batch_size']);
    }

    public function test_pause_when_throughput_low_and_queue_not_starving(): void
    {
        $result = $this->policy->decide($this->healthy([
            'claimable_depth'        => 10,
            'worker_throughput_rate' => 0.10,  // < 0.30 threshold
        ]));

        $this->assertSame(AtlasSelfConstructionOriginatorCadencePolicy::ACTION_PAUSE, $result['action']);
    }

    // ── AC2: refuse to seed when claimable healthy but risk rising ───────────

    public function test_consolidate_when_claimable_healthy_but_quality_risk_rising(): void
    {
        $result = $this->policy->decide($this->healthy([
            'claimable_depth'      => 8,
            'recent_quality_score' => 4.0,  // < 5.5 threshold
        ]));

        $this->assertSame(AtlasSelfConstructionOriginatorCadencePolicy::ACTION_CONSOLIDATE, $result['action']);
        $this->assertSame(0, $result['recommended_batch_size']);
    }

    public function test_consolidate_when_claimable_healthy_but_malformed_risk_rising(): void
    {
        $result = $this->policy->decide($this->healthy([
            'claimable_depth' => 8,
            'malformed_risk'  => 0.40,  // > 0.25 threshold
        ]));

        $this->assertSame(AtlasSelfConstructionOriginatorCadencePolicy::ACTION_CONSOLIDATE, $result['action']);
    }

    public function test_consolidate_reason_mentions_claimable_depth_healthy(): void
    {
        $result = $this->policy->decide($this->healthy([
            'claimable_depth'      => 6,
            'recent_quality_score' => 3.0,
        ]));

        $reasonsStr = implode(' ', $result['reasons']);
        $this->assertStringContainsString('claimable_depth_healthy', $reasonsStr);
    }

    // ── unblock_first takes priority over everything ──────────────────────────

    public function test_unblock_first_when_blocked_pressure_high(): void
    {
        $result = $this->policy->decide($this->healthy([
            'claimable_depth' => 10,
            'blocked_count'   => 6,   // 6/(10+6) = 37.5% > 30% threshold
        ]));

        $this->assertSame(AtlasSelfConstructionOriginatorCadencePolicy::ACTION_UNBLOCK_FIRST, $result['action']);
        $this->assertSame(0, $result['recommended_batch_size']);
    }

    public function test_unblock_first_beats_consolidate_when_both_conditions_present(): void
    {
        $result = $this->policy->decide($this->healthy([
            'claimable_depth'      => 8,
            'blocked_count'        => 5,    // high blocked ratio
            'recent_quality_score' => 3.0,  // also quality risk
        ]));

        $this->assertSame(AtlasSelfConstructionOriginatorCadencePolicy::ACTION_UNBLOCK_FIRST, $result['action']);
    }

    // ── batch_size adjustments ────────────────────────────────────────────────

    public function test_seed_now_batch_size_reduced_when_quality_risk(): void
    {
        $full = $this->policy->decide($this->healthy(['claimable_depth' => 0]))['recommended_batch_size'];
        $risky = $this->policy->decide($this->healthy([
            'claimable_depth'      => 0,
            'recent_quality_score' => 4.0,  // quality risk but queue starving → still seed
        ]))['recommended_batch_size'];

        $this->assertLessThan($full, $risky, 'Quality risk must reduce batch size');
    }

    // ── edge cases ───────────────────────────────────────────────────────────

    public function test_empty_snapshot_returns_valid_result(): void
    {
        $result = $this->policy->decide([]);

        $this->assertContains($result['action'], [
            AtlasSelfConstructionOriginatorCadencePolicy::ACTION_SEED_NOW,
            AtlasSelfConstructionOriginatorCadencePolicy::ACTION_PAUSE,
            AtlasSelfConstructionOriginatorCadencePolicy::ACTION_CONSOLIDATE,
            AtlasSelfConstructionOriginatorCadencePolicy::ACTION_UNBLOCK_FIRST,
        ]);
    }

    public function test_reasons_list_is_non_empty(): void
    {
        $result = $this->policy->decide($this->healthy());
        $this->assertNotEmpty($result['reasons']);
    }
}
