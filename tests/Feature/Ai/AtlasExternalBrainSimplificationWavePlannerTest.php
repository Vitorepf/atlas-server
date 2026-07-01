<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSimplificationWavePlanner;
use Tests\TestCase;

final class AtlasExternalBrainSimplificationWavePlannerTest extends TestCase
{
    private function planner(): AtlasExternalBrainSimplificationWavePlanner
    {
        return new AtlasExternalBrainSimplificationWavePlanner;
    }

    private function candidate(string $id, array $overrides = []): array
    {
        return array_merge([
            'candidate_id' => $id,
            'has_behavior_coverage' => true,
            'dependency_risk' => 'low',
            'line_reduction' => 50,
            'ownership_clear' => true,
            'rollback_ease' => 'easy',
        ], $overrides);
    }

    public function test_candidate_without_behavior_coverage_or_ownership_is_deferred_with_required_prework(): void
    {
        $r = $this->planner()->plan([
            $this->candidate('no-tests', ['has_behavior_coverage' => false]),
            $this->candidate('no-owner', ['ownership_clear' => false]),
        ]);

        self::assertSame([], $r['waves']);
        self::assertCount(2, $r['deferred']);
        self::assertContains('add_test_coverage', $r['deferred'][1]['required_prework']);
        self::assertContains('clarify_ownership', $r['deferred'][0]['required_prework']);
    }

    public function test_eligible_candidates_are_ranked_and_chunked_by_effective_wave_capacity(): void
    {
        $candidates = [
            $this->candidate('z-high-risk-small', ['dependency_risk' => 'high', 'line_reduction' => 10, 'rollback_ease' => 'hard']),
            $this->candidate('a-low-risk-big', ['dependency_risk' => 'low', 'line_reduction' => 200, 'rollback_ease' => 'easy']),
            $this->candidate('b-low-risk-small', ['dependency_risk' => 'low', 'line_reduction' => 5, 'rollback_ease' => 'easy']),
        ];

        $r = $this->planner()->plan($candidates, ['wave_capacity' => 2]);

        // dependency_risk ASC, then line_reduction DESC, then rollback_ease ASC, then candidate_id ASC.
        self::assertSame(['a-low-risk-big', 'b-low-risk-small'], $r['waves'][0]);
        self::assertSame(['z-high-risk-small'], $r['waves'][1]);
    }

    public function test_urgent_build_or_repair_halves_capacity_and_stop_go_defers_to_repair(): void
    {
        $r = $this->planner()->plan(
            [$this->candidate('a', ['line_reduction' => 100])],
            ['wave_capacity' => 4, 'build_or_repair_urgent' => true, 'complexity_debt_high' => true],
        );

        self::assertSame(2, $r['capacity_allocation']['effective_wave_capacity']);
        self::assertSame('defer_to_build_repair', $r['stop_go_decision']['decision']);
    }

    public function test_high_complexity_debt_with_covered_eligible_candidates_prioritizes_simplification(): void
    {
        $r = $this->planner()->plan(
            [$this->candidate('a', ['line_reduction' => 100])],
            ['complexity_debt_high' => true],
        );

        self::assertSame('prioritize_simplification_over_new_feature', $r['stop_go_decision']['decision']);
    }
}
