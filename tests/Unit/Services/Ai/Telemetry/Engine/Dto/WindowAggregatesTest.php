<?php

namespace Tests\Unit\Services\Ai\Telemetry\Engine\Dto;

use App\Services\Ai\Telemetry\Engine\Dto\WindowAggregates;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Engine F0 — pin the contract of WindowAggregates.
 *
 * The aggregator_version cross-version protection signal is the most important
 * property: if the trend layer mixes v1 + v2 traces in a window, results lie.
 * Tests pin isV2() so that protection is unambiguous downstream.
 */
class WindowAggregatesTest extends TestCase
{
    public function test_v2_aggregator_with_no_mixed_versions_is_v2(): void
    {
        $agg = $this->makeAggregates(
            aggregatorVersion: 'ai_trace_metric_aggregator_v2',
            hasMixedAggregatorVersions: false,
        );

        $this->assertTrue($agg->isV2(),
            'Pure v2 window — statistical layer free to compute trends with full new fields.');
    }

    public function test_v1_aggregator_is_not_v2(): void
    {
        $agg = $this->makeAggregates(
            aggregatorVersion: 'ai_trace_metric_aggregator_v1',
            hasMixedAggregatorVersions: false,
        );

        $this->assertFalse($agg->isV2(),
            'v1 window predates score_components.tools/router/diagnostics; statistical layer must skip those metrics.');
    }

    public function test_mixed_versions_is_not_v2_even_when_v2_dominant(): void
    {
        $agg = $this->makeAggregates(
            aggregatorVersion: 'ai_trace_metric_aggregator_v2',
            hasMixedAggregatorVersions: true,
        );

        $this->assertFalse($agg->isV2(),
            'Mixed window contaminates trend computation — must be flagged as not-v2 even when v2 traces dominate.');
    }

    public function test_trace_count_reads_from_summaries_collection(): void
    {
        $agg = $this->makeAggregates(summaries: collect([1, 2, 3, 4, 5]));

        $this->assertSame(5, $agg->traceCount());
    }

    public function test_empty_window_returns_zero_trace_count(): void
    {
        $agg = $this->makeAggregates(summaries: collect([]));

        $this->assertSame(0, $agg->traceCount(),
            'Empty window must report zero, not throw — required for cold start handling.');
    }

    private function makeAggregates(
        array $scorecard = [],
        array $health = [],
        array $summary = [],
        ?Collection $summaries = null,
        string $aggregatorVersion = 'ai_trace_metric_aggregator_v2',
        bool $hasMixedAggregatorVersions = false,
    ): WindowAggregates {
        return new WindowAggregates(
            scorecard: $scorecard,
            health: $health,
            summary: $summary,
            summaries: $summaries ?? collect(),
            aggregatorVersion: $aggregatorVersion,
            hasMixedAggregatorVersions: $hasMixedAggregatorVersions,
        );
    }
}
