<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Trinity;

use App\Services\Ai\AutonomousEvolution\Trinity\ThreeWay\AtlasLoopTrinityHealthService;
use App\Services\Ai\AutonomousEvolution\Trinity\ThreeWay\TrinityChainMissingException;
use PHPUnit\Framework\TestCase;

/**
 * Proves the Trinity health service (trinity audits trinity): deterministic per-primitive latency p50/p95 and
 * mutual coverage from a fixture, drift counting, the self-inspection re-emitted as a source=cortex fact into
 * the next cycle's merger, and fail-closed behaviour when the chain is shorter than the window.
 */
final class AtlasLoopTrinityHealthServiceTest extends TestCase
{
    /**
     * @param  array{loop:float,cortex:float,maestro:float}  $completed
     * @param  list<array<string,mixed>>  $facts
     * @return array<string,mixed>
     */
    private function cycle(string $id, float $start, array $completed, array $facts, bool $integrity = true): array
    {
        return [
            'cycleId' => $id,
            'integrityOk' => $integrity,
            'startedAt' => $start,
            'completedAt' => $completed,
            'facts' => $facts,
        ];
    }

    /**
     * @param  list<string>  $parents
     * @return array<string,mixed>
     */
    private function fact(string $factId, string $source, array $parents = []): array
    {
        return ['factId' => $factId, 'source' => $source, 'parentFactIds' => $parents];
    }

    /**
     * A fully-covered cycle: loop L, cortex C(parent L), maestro M(parent C).
     *
     * @param  array{loop:float,cortex:float,maestro:float}  $completed
     * @return array<string,mixed>
     */
    private function coveredCycle(string $id, float $start, array $completed): array
    {
        return $this->cycle($id, $start, $completed, [
            $this->fact('L-'.$id, 'loop'),
            $this->fact('C-'.$id, 'cortex', ['L-'.$id]),
            $this->fact('M-'.$id, 'maestro', ['C-'.$id]),
        ]);
    }

    public function test_latency_percentiles_and_full_mutual_coverage_from_fixture(): void
    {
        $service = new AtlasLoopTrinityHealthService([
            $this->coveredCycle('c1', 0.0, ['loop' => 1.0, 'cortex' => 3.0, 'maestro' => 6.0]),
            $this->coveredCycle('c2', 0.0, ['loop' => 2.0, 'cortex' => 4.0, 'maestro' => 8.0]),
            $this->coveredCycle('c3', 0.0, ['loop' => 3.0, 'cortex' => 9.0, 'maestro' => 12.0]),
        ]);

        $snapshot = $service->health(3);

        // loop latencies [1,2,3]: nearest-rank p50=rank2=2, p95=rank3=3
        $this->assertSame(2.0, $snapshot->latencyP50['loop']);
        $this->assertSame(3.0, $snapshot->latencyP95['loop']);
        // cortex [3,4,9]: p50=4, p95=9 ; maestro [6,8,12]: p50=8, p95=12
        $this->assertSame(4.0, $snapshot->latencyP50['cortex']);
        $this->assertSame(9.0, $snapshot->latencyP95['cortex']);
        $this->assertSame(8.0, $snapshot->latencyP50['maestro']);
        $this->assertSame(12.0, $snapshot->latencyP95['maestro']);

        $this->assertSame(1.0, $snapshot->mutualCoverage, 'every Loop fact has a Cortex+Maestro descendant');
        $this->assertGreaterThanOrEqual(0.0, $snapshot->mutualCoverage);
        $this->assertLessThanOrEqual(1.0, $snapshot->mutualCoverage);
        $this->assertTrue($snapshot->chainIntegrityOk);
    }

    public function test_partial_mutual_coverage_when_a_loop_fact_lacks_a_maestro_descendant(): void
    {
        $service = new AtlasLoopTrinityHealthService([
            $this->coveredCycle('c1', 0.0, ['loop' => 1.0, 'cortex' => 2.0, 'maestro' => 3.0]),
            // c2: L2 has a Cortex (C2) but NO Maestro descending from C2 ⇒ L2 uncovered.
            $this->cycle('c2', 0.0, ['loop' => 1.0, 'cortex' => 2.0, 'maestro' => 3.0], [
                $this->fact('L-c2', 'loop'),
                $this->fact('C-c2', 'cortex', ['L-c2']),
            ]),
        ]);

        $snapshot = $service->health(2);

        $this->assertSame(0.5, $snapshot->mutualCoverage, '1 of 2 distinct Loop facts is mutually covered');
    }

    public function test_drift_counts_cycles_with_zero_new_facts_for_a_primitive(): void
    {
        $service = new AtlasLoopTrinityHealthService([
            $this->cycle('c1', 0.0, ['loop' => 1.0, 'cortex' => 2.0, 'maestro' => 3.0], [
                $this->fact('L1', 'loop'),
                $this->fact('C1', 'cortex', ['L1']),
                $this->fact('M1', 'maestro', ['C1']),
            ]),
            // c2 REUSES L1 (zero new loop facts ⇒ loop drift) but produces a new Cortex + Maestro.
            $this->cycle('c2', 0.0, ['loop' => 1.0, 'cortex' => 2.0, 'maestro' => 3.0], [
                $this->fact('L1', 'loop'),
                $this->fact('C2', 'cortex', ['L1']),
                $this->fact('M2', 'maestro', ['C2']),
            ]),
        ]);

        $snapshot = $service->health(2);

        // c1 has no prior ⇒ all new (no drift). c2 loop reuses L1 ⇒ 1 drift event over 2 cycles.
        $this->assertSame(0.5, $snapshot->drift['loop']);
        $this->assertSame(0.0, $snapshot->drift['cortex']);
        $this->assertSame(0.0, $snapshot->drift['maestro']);
    }

    public function test_snapshot_is_emitted_as_cortex_fact_into_merger_for_next_cycle(): void
    {
        $merger = new class
        {
            /** @var list<array<string,mixed>> */
            public array $appended = [];

            /** @param array<string,mixed> $fact */
            public function append(array $fact): void
            {
                $this->appended[] = $fact;
            }

            /** @return list<array<string,mixed>> */
            public function stream(): array
            {
                return $this->appended;
            }
        };

        $service = new AtlasLoopTrinityHealthService([
            $this->coveredCycle('c1', 0.0, ['loop' => 1.0, 'cortex' => 2.0, 'maestro' => 3.0]),
            $this->coveredCycle('c2', 0.0, ['loop' => 1.0, 'cortex' => 2.0, 'maestro' => 3.0]),
            $this->coveredCycle('c3', 0.0, ['loop' => 1.0, 'cortex' => 2.0, 'maestro' => 3.0]),
        ], $merger);

        $service->health(3);

        $this->assertCount(1, $merger->stream(), 'the self-inspection re-enters the stream');
        $fact = $merger->stream()[0];
        $this->assertSame('cortex', $fact['source'], 'health snapshot is enrichment ABOUT the trinity');
        $this->assertSame('c3', $fact['cycleId']);
        $this->assertContains('L-c3', $fact['parentFactIds'], 'parents reference the last cycle Loop fact');
        $this->assertContains('M-c3', $fact['parentFactIds'], 'parents reference the last cycle Maestro fact');
    }

    public function test_health_fails_closed_when_chain_shorter_than_window(): void
    {
        $service = new AtlasLoopTrinityHealthService([
            $this->coveredCycle('c1', 0.0, ['loop' => 1.0, 'cortex' => 2.0, 'maestro' => 3.0]),
        ]);

        $this->expectException(TrinityChainMissingException::class);
        $service->health(20);
    }
}
