<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCognitiveAntifragilityEquationService;
use InvalidArgumentException;
use Tests\TestCase;

final class AtlasCognitiveAntifragilityEquationTest extends TestCase
{
    private AtlasCognitiveAntifragilityEquationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasCognitiveAntifragilityEquationService();
    }

    public function testTheEightMComponentWeightsTotalExactlyOne(): void
    {
        // Doc table: 0.20 + 0.15 + 0.15 + 0.10 + 0.10 + 0.10 + 0.10 + 0.10 = 1.00.
        $this->assertCount(8, AtlasCognitiveAntifragilityEquationService::M_COMPONENTS);
        $this->assertSame(1.0, $this->service->totalWeight());
    }

    public function testComputeMReproducesTheDocumentedSnapshotValueOfOnePointFour(): void
    {
        // M = sum(weight_i x metric_i) with the snapshot inputs -> 1.4.
        $m = $this->service->computeM([
            'memory_governed' => 1.0,
            'evidence_receipts' => 1.0,
            'compounding_learning' => 1.0,
            'self_construction' => 1.0,
            'multi_agent_topology' => 5.0,
            'multi_provider' => 1.0,
            'sovereignty' => 1.0,
            'governance_gates' => 1.0,
        ]);

        $this->assertSame(1.4, $m['value']);
        $this->assertSame('atlas.antifragility.measurement.v1', $m['schema_version']);
    }

    public function testCompoundingLearningIsTheOnlySignedMetricAndCanSubtractFromM(): void
    {
        // obras_quality_trend ranges -1..+1; a negative trend drags M down.
        // Only compounding (weight 0.15) supplied at -1.0; all others default to range min (0,
        // except multi_agent whose min is 1.0 -> contributes 0.10*1.0=0.10).
        $m = $this->service->computeM(['compounding_learning' => -1.0]);

        // expected = 0.15*(-1.0) + 0.10*1.0 (multi_agent floor) = -0.15 + 0.10 = -0.05
        $this->assertSame(-0.05, $m['value']);

        $compounding = $this->componentById($m['components'], 'compounding_learning');
        $this->assertSame(-1.0, $compounding['clamped']);
        $this->assertSame(-0.15, $compounding['contribution']);
    }

    public function testOutOfRangeMetricsAreClampedToTheirDocumentedRangeBeforeWeighting(): void
    {
        // parallel_efficiency_factor max is 8.0; a supplied 99 clamps to 8.0.
        // retention_quality_score max is 1.0; a supplied 5 clamps to 1.0.
        $m = $this->service->computeM([
            'multi_agent_topology' => 99.0,
            'memory_governed' => 5.0,
        ]);

        $multiAgent = $this->componentById($m['components'], 'multi_agent_topology');
        $this->assertSame(8.0, $multiAgent['clamped']);
        $this->assertTrue($multiAgent['clamped_flag']);
        $this->assertSame(0.8, $multiAgent['contribution']); // 0.10 * 8.0

        $memory = $this->componentById($m['components'], 'memory_governed');
        $this->assertSame(1.0, $memory['clamped']);
        $this->assertSame(0.2, $memory['contribution']); // 0.20 * 1.0
    }

    public function testMeasureComposesTotalAsNTimesMUnderTheDocumentedSchema(): void
    {
        // Snapshot 2026-Q2: N=8.5, M=1.4, Total=11.9.
        $measurement = $this->service->measure(
            [['id' => 'claude_code', 'score' => 8.5]],
            [
                'memory_governed' => 1.0,
                'evidence_receipts' => 1.0,
                'compounding_learning' => 1.0,
                'self_construction' => 1.0,
                'multi_agent_topology' => 5.0,
                'multi_provider' => 1.0,
                'sovereignty' => 1.0,
                'governance_gates' => 1.0,
            ],
            '2026-04-01T00:00:00+00:00',
            0.05,
        );

        $this->assertSame('atlas.antifragility.measurement.v1', $measurement['schema']);
        $this->assertSame(8.5, $measurement['n']['value']);
        $this->assertSame(1.4, $measurement['m']['value']);
        $this->assertSame(11.9, $measurement['total']);
        $this->assertSame(0.05, $measurement['trend_30d']);
    }

    public function testProviderLeapMultipliesTotalThroughNWhileMIsCapturedWithoutCodeChange(): void
    {
        // Doc row: "Atlas + Claude 5x salto provider" -> N 8.5 -> 42.5, M=2.0 -> Total 85.0.
        $leap = $this->service->applyProviderLeap(8.5, 2.0, 5.0);

        $this->assertSame(42.5, $leap['n_after']);
        $this->assertSame(17.0, $leap['total_before']); // 8.5 * 2.0
        $this->assertSame(85.0, $leap['total_after']);   // 42.5 * 2.0
        $this->assertTrue($leap['captured_without_code_change']);
    }

    public function testProposalFilterMultipliesOnlyWhenItRaisesMorNCapture(): void
    {
        // Regras para IA: raises M? raises capacity to capture N? If neither, it does not multiply.
        $this->assertTrue($this->service->evaluateProposal(true, false)['multiplies']);
        $this->assertTrue($this->service->evaluateProposal(false, true)['multiplies']);
        $this->assertFalse($this->service->evaluateProposal(false, false)['multiplies']);
    }

    public function testComputeNRejectsAnEmptyProviderSet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->computeN([]);
    }

    /**
     * @param  list<array<string,mixed>>  $components
     * @return array<string,mixed>
     */
    private function componentById(array $components, string $id): array
    {
        foreach ($components as $component) {
            if ($component['id'] === $id) {
                return $component;
            }
        }

        $this->fail("Component {$id} not found in M result.");
    }
}
