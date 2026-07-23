<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\MemoryGovernance;

use App\Services\Ai\MemoryGovernance\MemoryHealthCompositeScorer;
use App\Services\Ai\MemoryGovernance\MemoryHealthCompositePolicy;
use ReflectionClass;
use Tests\TestCase;

final class MemoryHealthCompositeScorerTest extends TestCase
{
    private MemoryHealthCompositeScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scorer = new MemoryHealthCompositeScorer;
    }

    /**
     * @return array<string,int>
     */
    private function allAt(int $value): array
    {
        return [
            // D5 weights: structural_honesty/rationale/provider_safety=0.14,
            // relation_density/feedback=0.12, governance=0.10, readiness/freshness=0.08,
            // retrieval_eval=0.06, completeness=0.02.
            'structural_honesty' => $value,
            'rationale' => $value,
            'provider_safety' => $value,
            'relation_density' => $value,
            'feedback' => $value,
            'governance' => $value,
            'readiness' => $value,
            'freshness' => $value,
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

        // Weighted argmax with all axes at 0: the first of the top-weight (0.14) trio
        // in iteration order wins the tie — structural_honesty. Headroom 100*0.14=14.0.
        $this->assertSame(0, $result['composite_score']);
        $this->assertSame('structural_honesty', $result['limiting_dimension']);
        $this->assertSame(14.0, $result['limiting_headroom']);
    }

    public function test_single_zero_axis_selects_weakest_weighted_dimension(): void
    {
        $dimensions = $this->allAt(100);
        $dimensions['governance'] = 0;

        $result = $this->scorer->compose($dimensions);

        // Only governance has positive headroom: 100 * 0.10 = 10.0.
        // Composite = 100 * (1 - 0.10) = 90.
        $this->assertSame(90, $result['composite_score']);
        $this->assertSame('governance', $result['limiting_dimension']);
        $this->assertSame(10.0, $result['limiting_headroom']);
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
        // -> headroom 100 * 0.08 = 8.0. Composite = 0.08*0 + 0.92*100 = 92.
        $this->assertSame(92, $result['composite_score']);
        $this->assertSame('readiness', $result['limiting_dimension']);
        $this->assertSame(8.0, $result['limiting_headroom']);
    }

    public function test_equal_weighted_headroom_tie_resolves_to_highest_weight_first(): void
    {
        // structural_honesty and rationale share the top weight (0.14). At the same
        // value both have headroom (100-78)*0.14 = 3.08 — an exact tie. The doc
        // mandates ties break to highest-weight-FIRST iteration order, so the axis
        // listed first in WEIGHTS (structural_honesty) MUST win over rationale, never
        // the last-seen one (compose uses a strict `>` so the first survivor holds).
        $dimensions = $this->allAt(100);
        $dimensions['structural_honesty'] = 78;
        $dimensions['rationale'] = 78;

        $result = $this->scorer->compose($dimensions);

        $this->assertSame('structural_honesty', $result['limiting_dimension']);
        $this->assertSame(3.08, $result['limiting_headroom']);
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

        // readiness -> 0: headroom (100-0)*0.08 = 8.0; composite = 92.
        // NaN is present (numeric), so it is NOT recorded as missing.
        $this->assertSame(92, $result['composite_score']);
        $this->assertSame('readiness', $result['limiting_dimension']);
        $this->assertSame(8.0, $result['limiting_headroom']);
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
        // ordered highest-weight-first (feedback 0.12 before freshness 0.08).
        $this->assertSame(['feedback', 'freshness'], $result['missing_dimensions']);
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

    public function test_d5_score_is_driven_by_the_crude_quality_numbers_not_mere_presence(): void
    {
        // The wiper state: safe/present/conflict-free/fresh (all 100), but structurally
        // hollow — 50% title=summary, ~5% with rationale, 0 relations, 0 feedback fill.
        $wiper = $this->allAt(100);
        $wiper['structural_honesty'] = 50;
        $wiper['rationale'] = 5;
        $wiper['relation_density'] = 0;
        $wiper['feedback'] = 0;
        $wiperScore = $this->scorer->compose($wiper)['composite_score'];

        // Now D1-D4 move the RAW numbers (re-hydration, relations, feedback). The SAME
        // policy must reward that — the score rises ONLY because the crude dims rose.
        $healed = $this->allAt(100);
        $healed['structural_honesty'] = 95;
        $healed['rationale'] = 90;
        $healed['relation_density'] = 80;
        $healed['feedback'] = 85;
        $healedScore = $this->scorer->compose($healed)['composite_score'];

        // The hollow store is dragged far below the old naive ~93 …
        $this->assertLessThan(60, $wiperScore);
        // … and improving the crude numbers (D1-D4) is what — and the only thing that — raises it.
        $this->assertGreaterThan($wiperScore + 30, $healedScore);
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
