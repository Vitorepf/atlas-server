<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Context;

use App\Services\Ai\Context\AtlasRetrievalCostLatencyGovernorService;
use Tests\TestCase;

final class AtlasRetrievalCostLatencyGovernorServiceTest extends TestCase
{
    private AtlasRetrievalCostLatencyGovernorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AtlasRetrievalCostLatencyGovernorService::class);
    }

    // ── AC1: required source trimming → blocked + receipt ────────────────────

    public function test_max_refs_removing_required_sources_blocks_with_receipt(): void
    {
        $result = $this->service->govern([
            'risk_level' => 'low',
            'required_sources' => ['src_a', 'src_b', 'src_c'],
            'max_refs' => 1, // only 1 of 3 required sources fits → 2 removed
        ]);

        $this->assertSame('blocked', $result['degraded_mode']['status']);
        $this->assertFalse($result['claims']['required_source_removed_silently']);
        $this->assertNotEmpty($result['receipt']['removed_required_sources']);
    }

    public function test_blocked_when_required_sources_would_be_trimmed(): void
    {
        $result = $this->service->govern([
            'risk_level' => 'low',
            'required_sources' => ['a', 'b', 'c', 'd'],
            'max_refs' => 2,
        ]);

        $this->assertSame('blocked', $result['degraded_mode']['status']);
        $this->assertTrue($result['degraded_mode']['blocks_execution']);
        $removed = $result['receipt']['removed_required_sources'];
        $this->assertCount(2, $removed);
    }

    // ── AC2: low-risk overruns without removed required sources → degraded ───

    public function test_latency_overrun_without_required_removal_is_degraded_not_blocked(): void
    {
        $result = $this->service->govern([
            'risk_level' => 'low',
            'required_sources' => ['src_a'],
            'max_refs' => 10, // all sources fit
            'observed_latency_ms' => 999999, // way over budget
        ]);

        $this->assertNotSame('blocked', $result['degraded_mode']['status']);
        $this->assertNotSame('not_needed', $result['degraded_mode']['status']);
    }

    public function test_cost_overrun_without_required_removal_is_degraded(): void
    {
        $result = $this->service->govern([
            'risk_level' => 'low',
            'required_sources' => ['src_a'],
            'max_refs' => 10,
            'budget_cost_units' => 1, // tiny budget → overrun
        ]);

        $this->assertNotSame('blocked', $result['degraded_mode']['status']);
        $this->assertSame('degraded_with_receipt', $result['degraded_mode']['status']);
    }

    // ── AC3: pure output — no providers, tokens, writes, benchmarks ──────────

    public function test_output_keeps_purity_claims_false(): void
    {
        $result = $this->service->govern(['risk_level' => 'low']);

        $this->assertFalse($result['claims']['providers_invoked']);
        $this->assertFalse($result['claims']['writes']);
        $this->assertFalse($result['claims']['benchmark_run']);
    }

    public function test_output_has_schema_and_status(): void
    {
        $result = $this->service->govern(['risk_level' => 'low']);

        $this->assertSame(
            AtlasRetrievalCostLatencyGovernorService::SCHEMA_VERSION,
            $result['schema_version'],
        );
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('degraded_mode', $result);
        $this->assertArrayHasKey('receipt', $result);
    }

    // ── required_source_removed_silently always false ────────────────────────

    public function test_required_source_removed_silently_always_false(): void
    {
        // Even when sources are removed (blocked), it's never silent.
        $result = $this->service->govern([
            'risk_level' => 'low',
            'required_sources' => ['a', 'b'],
            'max_refs' => 1,
        ]);

        $this->assertFalse($result['claims']['required_source_removed_silently']);
        $this->assertSame('blocked', $result['degraded_mode']['status']);
    }

    // ── AC2: quality_floor=1.5 is bounded to 1.0 ──

    public function test_quality_floor_above_one_bounded_to_one(): void
    {
        $result = $this->service->govern([
            'risk' => 'low',
            'quality_floor' => 1.5,
        ]);

        $this->assertSame(1.0, $result['budget_policy']['quality_floor']);
        $this->assertSame(1.0, $result['receipt']['quality_floor']);
    }

    // ── AC3: quality_floor=-0.2 is bounded to 0.0 ──

    public function test_quality_floor_below_zero_bounded_to_zero(): void
    {
        $result = $this->service->govern([
            'risk' => 'low',
            'quality_floor' => -0.2,
        ]);

        $this->assertSame(0.0, $result['budget_policy']['quality_floor']);
        $this->assertSame(0.0, $result['receipt']['quality_floor']);
    }

    // ── AC4: default floors stay unchanged when quality_floor is omitted ──

    public function test_default_low_floor_unchanged_when_omitted(): void
    {
        $result = $this->service->govern(['risk' => 'low']);
        $this->assertSame(0.76, $result['budget_policy']['quality_floor']);
    }

    public function test_default_medium_floor_unchanged_when_omitted(): void
    {
        $result = $this->service->govern(['risk' => 'medium']);
        $this->assertSame(0.84, $result['budget_policy']['quality_floor']);
    }

    public function test_default_high_floor_unchanged_when_omitted(): void
    {
        $result = $this->service->govern(['risk' => 'high']);
        $this->assertSame(0.92, $result['budget_policy']['quality_floor']);
    }
}
