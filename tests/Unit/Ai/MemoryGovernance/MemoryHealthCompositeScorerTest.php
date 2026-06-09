<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\MemoryGovernance;

use App\Services\Ai\MemoryGovernance\MemoryHealthCompositeScorer;
use App\Services\Ai\MemoryHealthCompositePolicy;
use ReflectionClass;
use Tests\TestCase;

final class MemoryHealthCompositeScorerTest extends TestCase
{
    private MemoryHealthCompositeScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scorer = new MemoryHealthCompositeScorer();
    }

    /**
     * @return array<string,int>
     */
    private function allAt(int $value): array
    {
        return [
            'provider_safety' => $value,
            'readiness' => $value,
            'governance' => $value,
            'freshness' => $value,
            'feedback' => $value,
            'retrieval_eval' => $value,
            'completeness' => $value,
        ];
    }

    public function test_all_dimensions_at_ceiling_reports_none_limiting(): void
    {
        $result = $this->scorer->compose($this->allAt(100));

        $this->assertSame(100, $result['composite_score']);
        $this->assertSame('none', $result['limiting_dimension']);
        $this->assertSame(0.0, $result['limiting_headroom']);
        $this->assertSame([], $result['missing_dimensions']);
        $this->assertSame('all_dimensions_at_ceiling', $result['reason']);
    }

    public function test_all_dimensions_at_floor_picks_highest_weighted_axis(): void
    {
        $result = $this->scorer->compose($this->allAt(0));

        // Weighted argmax: provider_safety (0.24) beats readiness (0.22),
        // not first-key insertion or alphabetical ordering.
        $this->assertSame(0, $result['composite_score']);
        $this->assertSame('provider_safety', $result['limiting_dimension']);
        $this->assertSame(24.0, $result['limiting_headroom']);
    }

    public function test_single_zero_axis_selects_weakest_weighted_dimension(): void
    {
        $dimensions = $this->allAt(100);
        $dimensions['governance'] = 0;

        $result = $this->scorer->compose($dimensions);

        // Only governance has positive headroom: 100 * 0.20 = 20.0.
        // Composite = 100 * (1 - 0.20) = 80.
        $this->assertSame(80, $result['composite_score']);
        $this->assertSame('governance', $result['limiting_dimension']);
        $this->assertSame(20.0, $result['limiting_headroom']);
    }

    public function test_missing_dimension_is_fail_closed_and_becomes_limiter(): void
    {
        $dimensions = $this->allAt(100);
        unset($dimensions['completeness']);

        $result = $this->scorer->compose($dimensions);

        // completeness absent -> treated as 0, recorded missing, headroom 2.0.
        // Composite = 100 * (1 - 0.02) = 98.
        $this->assertSame(['completeness'], $result['missing_dimensions']);
        $this->assertSame(98, $result['composite_score']);
        $this->assertSame('completeness', $result['limiting_dimension']);
    }

    public function test_out_of_range_inputs_are_clamped_to_zero_hundred(): void
    {
        $dimensions = $this->allAt(100);
        $dimensions['provider_safety'] = 150;
        $dimensions['readiness'] = -10;

        $result = $this->scorer->compose($dimensions);

        // provider_safety clamps to 100 (no headroom); readiness clamps to 0
        // -> headroom 100 * 0.22 = 22.0. Composite = 0.22*0 + 0.78*100 = 78.
        $this->assertSame(78, $result['composite_score']);
        $this->assertSame('readiness', $result['limiting_dimension']);
        $this->assertSame(22.0, $result['limiting_headroom']);
    }

    public function test_equal_weighted_headroom_tie_resolves_to_highest_weight_first(): void
    {
        // provider_safety=78 -> headroom (100-78)*0.24 = 5.28
        // readiness=76      -> headroom (100-76)*0.22 = 5.28  (exact tie at 2dp)
        // The doc mandates ties break to highest-weight-first iteration order,
        // so provider_safety (0.24) MUST win over readiness (0.22). IEEE-754
        // drift makes the raw floats 5.2799…93 vs 5.2800…02, which would flip the
        // winner to readiness under a naive raw-float compare — this guards it.
        $dimensions = $this->allAt(100);
        $dimensions['provider_safety'] = 78;
        $dimensions['readiness'] = 76;

        $result = $this->scorer->compose($dimensions);

        $this->assertSame('provider_safety', $result['limiting_dimension']);
        $this->assertSame(5.28, $result['limiting_headroom']);
    }

    public function test_huge_and_infinite_values_saturate_to_ceiling_not_floor(): void
    {
        // is_numeric(INF) and is_numeric(PHP_INT_MAX) are both true, so these
        // pass the fail-closed gate as PRESENT values. The spec clamps present
        // values to [0,100], i.e. a maximal signal MUST saturate to the ceiling
        // (100), never wrap to the floor (0). A naive (int) round((float)$v)
        // before clamping would wrap PHP_INT_MAX to PHP_INT_MIN and INF to 0,
        // pinning them to 0 and turning a maxed dimension into the limiter.
        $dimensions = $this->allAt(100);
        $dimensions['provider_safety'] = PHP_INT_MAX;
        $dimensions['readiness'] = INF;
        $dimensions['governance'] = '1e400'; // numeric string overflowing to INF

        $result = $this->scorer->compose($dimensions);

        // All seven dims saturate to 100 -> composite 100, nothing limits,
        // and none of these maxed dims are recorded as missing.
        $this->assertSame(100, $result['composite_score']);
        $this->assertSame('none', $result['limiting_dimension']);
        $this->assertSame(0.0, $result['limiting_headroom']);
        $this->assertSame([], $result['missing_dimensions']);
        $this->assertSame('all_dimensions_at_ceiling', $result['reason']);
    }

    public function test_nan_is_treated_as_floor_not_a_passing_value(): void
    {
        // is_numeric(NAN) is true, so NaN is a PRESENT (non-missing) value, but
        // it is not a valid score: it must fail-closed to the floor (0), which
        // gives it the full weighted headroom for its dimension.
        $dimensions = $this->allAt(100);
        $dimensions['readiness'] = NAN;

        $result = $this->scorer->compose($dimensions);

        // readiness -> 0: headroom (100-0)*0.22 = 22.0; composite = 78.
        // NaN is present (numeric), so it is NOT recorded as missing.
        $this->assertSame(78, $result['composite_score']);
        $this->assertSame('readiness', $result['limiting_dimension']);
        $this->assertSame(22.0, $result['limiting_headroom']);
        $this->assertSame([], $result['missing_dimensions']);
    }

    public function test_schema_version_is_the_canonical_literal(): void
    {
        $result = $this->scorer->compose($this->allAt(100));

        $this->assertSame(
            'atlas.memory_governance.health_composite.v1',
            $result['schema_version'],
        );
    }

    public function test_missing_dimensions_is_a_sequential_string_list(): void
    {
        $dimensions = $this->allAt(100);
        unset($dimensions['freshness'], $dimensions['feedback']);

        $result = $this->scorer->compose($dimensions);

        // list<string> contract: sequential int keys 0..n, only string values,
        // ordered highest-weight-first (freshness 0.14 before feedback 0.10).
        $this->assertSame(['freshness', 'feedback'], $result['missing_dimensions']);
        $this->assertSame(
            array_keys($result['missing_dimensions']),
            range(0, count($result['missing_dimensions']) - 1),
        );
        $onlyStrings = array_filter(
            $result['missing_dimensions'],
            static fn (mixed $value): bool => is_string($value),
        );
        $this->assertSame($result['missing_dimensions'], $onlyStrings);
    }

    public function test_class_is_pure_with_zero_constructor_dependencies(): void
    {
        $reflection = new ReflectionClass(MemoryHealthCompositeScorer::class);

        $this->assertTrue($reflection->isFinal());

        $constructor = $reflection->getConstructor();
        $this->assertTrue(
            $constructor === null || $constructor->getNumberOfParameters() === 0,
        );
    }

    public function test_scorer_wraps_shared_policy(): void
    {
        $dimensions = $this->allAt(100);
        $dimensions['provider_safety'] = 78;
        $dimensions['readiness'] = 76;

        $this->assertSame(
            [
                'schema_version' => 'atlas.memory_governance.health_composite.v1',
                ...MemoryHealthCompositePolicy::compose($dimensions),
            ],
            $this->scorer->compose($dimensions),
        );
    }
}
