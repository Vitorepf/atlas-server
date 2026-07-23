<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs\Scoring;

use App\Services\Ai\AgenticEngineeringOs\Scoring\ContextParetoDominanceFilter;
use PHPUnit\Framework\TestCase;

final class ContextParetoDominanceFilterTest extends TestCase
{
    private ContextParetoDominanceFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new ContextParetoDominanceFilter();
    }

    public function testSchemaVersionIsCanonical(): void
    {
        $result = $this->filter->filter([], ['quality_score' => 'maximize'], []);

        $this->assertSame('atlas.aaeos.context_pareto_dominance.v1', $result['schema_version']);
    }

    public function testWeakDominationWithStrictImprovementMarksDominatedWithLineage(): void
    {
        $result = $this->filter->filter(
            [
                ['id' => 'A', 'quality_score' => 0.94, 'input_tokens' => 12000, 'cost_units' => 1.0],
                ['id' => 'B', 'quality_score' => 0.94, 'input_tokens' => 7000, 'cost_units' => 0.72],
            ],
            [
                'quality_score' => 'maximize',
                'input_tokens' => 'minimize',
                'cost_units' => 'minimize',
            ],
        );

        $statusById = $this->statusById($result);
        $this->assertSame('dominated', $statusById['A']);
        $this->assertSame('frontier', $statusById['B']);

        $this->assertContains('B', $this->dominatedByOf($result, 'A'));
        $this->assertSame([], $this->dominatedByOf($result, 'B'));

        $this->assertNotContains('A', $result['frontier']);
        $this->assertContains('B', $result['frontier']);
    }

    public function testDirectionFlipUndoesDominationSoBothStayOnFrontier(): void
    {
        $variants = [
            ['id' => 'A', 'quality_score' => 0.94, 'input_tokens' => 12000, 'cost_units' => 1.0],
            ['id' => 'B', 'quality_score' => 0.94, 'input_tokens' => 7000, 'cost_units' => 0.72],
        ];

        $result = $this->filter->filter(
            $variants,
            [
                'quality_score' => 'maximize',
                'input_tokens' => 'maximize',
                'cost_units' => 'minimize',
            ],
        );

        $statusById = $this->statusById($result);
        $this->assertSame('frontier', $statusById['A']);
        $this->assertSame('frontier', $statusById['B']);

        $this->assertSame(['A', 'B'], $result['frontier']);
        $this->assertSame([], $this->dominatedByOf($result, 'A'));
        $this->assertSame([], $this->dominatedByOf($result, 'B'));
    }

    public function testHardConstraintBlocksVariantEvenWithLowestCost(): void
    {
        $result = $this->filter->filter(
            [
                ['id' => 'cheap', 'quality_score' => 0.90, 'input_tokens' => 6000, 'cost_units' => 0.10, 'must_keep_coverage' => 0.92],
                ['id' => 'safe', 'quality_score' => 0.90, 'input_tokens' => 6000, 'cost_units' => 0.80, 'must_keep_coverage' => 1.0],
            ],
            [
                'quality_score' => 'maximize',
                'input_tokens' => 'minimize',
                'cost_units' => 'minimize',
            ],
            [
                'must_keep_coverage' => ['min' => 1.0],
            ],
        );

        $statusById = $this->statusById($result);
        $this->assertSame('blocked', $statusById['cheap']);

        $blocked = $this->blockedById($result);
        $this->assertArrayHasKey('cheap', $blocked);
        $this->assertSame(['must_keep_coverage'], $blocked['cheap']);

        $this->assertNotContains('cheap', $result['frontier']);
        $this->assertContains('safe', $result['frontier']);

        // Blocked variants carry an empty dominated_by lineage.
        $this->assertSame([], $this->dominatedByOf($result, 'cheap'));
    }

    public function testIdenticalObjectiveVectorsAreNotDominatedAndBothStayFrontier(): void
    {
        $result = $this->filter->filter(
            [
                ['id' => 'twinA', 'quality_score' => 0.88, 'input_tokens' => 5000, 'cost_units' => 0.50],
                ['id' => 'twinB', 'quality_score' => 0.88, 'input_tokens' => 5000, 'cost_units' => 0.50],
            ],
            [
                'quality_score' => 'maximize',
                'input_tokens' => 'minimize',
                'cost_units' => 'minimize',
            ],
        );

        $statusById = $this->statusById($result);
        $this->assertSame('frontier', $statusById['twinA']);
        $this->assertSame('frontier', $statusById['twinB']);

        $this->assertSame(['twinA', 'twinB'], $result['frontier']);
        $this->assertSame([], $this->dominatedByOf($result, 'twinA'));
        $this->assertSame([], $this->dominatedByOf($result, 'twinB'));
    }

    public function testSummaryPartitionsTotalAcrossFrontierDominatedAndBlocked(): void
    {
        $result = $this->filter->filter(
            [
                ['id' => 'A', 'quality_score' => 0.94, 'input_tokens' => 12000, 'cost_units' => 1.0, 'must_keep_coverage' => 1.0],
                ['id' => 'B', 'quality_score' => 0.94, 'input_tokens' => 7000, 'cost_units' => 0.72, 'must_keep_coverage' => 1.0],
                ['id' => 'C', 'quality_score' => 0.90, 'input_tokens' => 6000, 'cost_units' => 0.10, 'must_keep_coverage' => 0.92],
            ],
            [
                'quality_score' => 'maximize',
                'input_tokens' => 'minimize',
                'cost_units' => 'minimize',
            ],
            [
                'must_keep_coverage' => ['min' => 1.0],
            ],
        );

        $summary = $result['summary'];

        $this->assertSame(3, $summary['total']);
        $this->assertSame(2, $summary['admitted']);
        $this->assertSame(1, $summary['blocked']);
        $this->assertSame(1, $summary['frontier']);
        $this->assertSame(1, $summary['dominated']);

        $this->assertSame(
            $summary['total'],
            $summary['frontier'] + $summary['dominated'] + $summary['blocked'],
        );
    }

    public function testEqualsConstraintBlocksOnPrivacyStatus(): void
    {
        $result = $this->filter->filter(
            [
                ['id' => 'leaky', 'quality_score' => 0.99, 'cost_units' => 0.05, 'privacy_status' => 'fail'],
                ['id' => 'clean', 'quality_score' => 0.80, 'cost_units' => 0.90, 'privacy_status' => 'pass'],
            ],
            [
                'quality_score' => 'maximize',
                'cost_units' => 'minimize',
            ],
            [
                'privacy_status' => ['equals' => 'pass'],
            ],
        );

        $blocked = $this->blockedById($result);
        $this->assertArrayHasKey('leaky', $blocked);
        $this->assertSame(['privacy_status'], $blocked['leaky']);
        $this->assertNotContains('leaky', $result['frontier']);
        $this->assertContains('clean', $result['frontier']);
    }

    public function testFrontierPreservesInputOrder(): void
    {
        $result = $this->filter->filter(
            [
                ['id' => 'z', 'quality_score' => 0.90, 'cost_units' => 0.50],
                ['id' => 'm', 'quality_score' => 0.95, 'cost_units' => 0.50],
                ['id' => 'a', 'quality_score' => 0.92, 'cost_units' => 0.50],
            ],
            [
                'quality_score' => 'maximize',
            ],
        );

        // Only 'm' (highest quality) survives; lower-quality same-cost peers are dominated.
        $this->assertSame(['m'], $result['frontier']);

        $statusById = $this->statusById($result);
        $this->assertSame('dominated', $statusById['z']);
        $this->assertSame('dominated', $statusById['a']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $variants = [
            ['id' => 'A', 'quality_score' => 0.94, 'input_tokens' => 12000, 'cost_units' => 1.0, 'must_keep_coverage' => 0.92],
            ['id' => 'B', 'quality_score' => 0.94, 'input_tokens' => 7000, 'cost_units' => 0.72, 'must_keep_coverage' => 1.0],
        ];
        $direction = [
            'quality_score' => 'maximize',
            'input_tokens' => 'minimize',
            'cost_units' => 'minimize',
        ];
        $constraints = ['must_keep_coverage' => ['min' => 1.0]];

        $first = $this->filter->filter($variants, $direction, $constraints);
        $second = $this->filter->filter($variants, $direction, $constraints);

        $this->assertSame($first, $second);
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,string>
     */
    private function statusById(array $result): array
    {
        $map = [];

        foreach ($result['evaluated'] as $row) {
            $map[$row['id']] = $row['status'];
        }

        return $map;
    }

    /**
     * @param  array<string,mixed>  $result
     * @return list<string>
     */
    private function dominatedByOf(array $result, string $id): array
    {
        foreach ($result['evaluated'] as $row) {
            if ($row['id'] === $id) {
                return $row['dominated_by'];
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,list<string>>
     */
    private function blockedById(array $result): array
    {
        $map = [];

        foreach ($result['blocked'] as $row) {
            $map[$row['id']] = $row['failed_constraints'];
        }

        return $map;
    }
}
