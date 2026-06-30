<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainComplexityDebtBurnDownPlanner;
use Tests\TestCase;

final class AtlasExternalBrainComplexityDebtBurnDownPlannerTest extends TestCase
{
    private function planner(): AtlasExternalBrainComplexityDebtBurnDownPlanner
    {
        return new AtlasExternalBrainComplexityDebtBurnDownPlanner();
    }

    private function keepCandidate(string $id = 'K1'): array
    {
        return [
            'organ_id'             => $id,
            'purpose'              => 'unique purpose',
            'similar_organs'       => [],
            'usage_evidence_count' => 10,
            'compounding_value'    => 0.80,
            'maintenance_cost'     => 0.20,
            'covers_same_decision_surface_as' => [],
        ];
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->planner()->plan([]);

        $this->assertSame(AtlasExternalBrainComplexityDebtBurnDownPlanner::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->planner()->plan([]);

        foreach (['schema', 'ranked_candidates', 'preferred_action'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
    }

    public function test_each_candidate_has_required_fields(): void
    {
        $result = $this->planner()->plan(['candidates' => [$this->keepCandidate()]]);

        $entry = $result['ranked_candidates'][0];
        foreach (['rank', 'organ_id', 'recommended_action', 'preserved_capability', 'risk_notes', 'proof_required_before_deletion'] as $f) {
            $this->assertArrayHasKey($f, $entry);
        }
    }

    public function test_empty_candidates_yields_empty_ranked_list(): void
    {
        $result = $this->planner()->plan(['candidates' => []]);

        $this->assertSame([], $result['ranked_candidates']);
    }

    // ── recommended_action: delete ────────────────────────────────────────────

    public function test_delete_when_zero_evidence_similar_organs_and_low_compounding(): void
    {
        $result = $this->planner()->plan([
            'candidates' => [[
                'organ_id'             => 'D1',
                'similar_organs'       => ['similar-a'],
                'usage_evidence_count' => 0,
                'compounding_value'    => 0.05,
                'maintenance_cost'     => 0.50,
            ]],
        ]);

        $this->assertSame(AtlasExternalBrainComplexityDebtBurnDownPlanner::ACTION_DELETE, $result['ranked_candidates'][0]['recommended_action']);
    }

    public function test_delete_requires_proof_before_deletion(): void
    {
        $result = $this->planner()->plan([
            'candidates' => [[
                'organ_id'             => 'D2',
                'similar_organs'       => ['other'],
                'usage_evidence_count' => 0,
                'compounding_value'    => 0.05,
                'maintenance_cost'     => 0.50,
            ]],
        ]);

        $this->assertNotNull($result['ranked_candidates'][0]['proof_required_before_deletion']);
        $this->assertStringContainsString('D2', $result['ranked_candidates'][0]['proof_required_before_deletion']);
    }

    // ── recommended_action: consolidate ───────────────────────────────────────

    public function test_consolidate_when_similar_organs_exist(): void
    {
        $result = $this->planner()->plan([
            'candidates' => [[
                'organ_id'             => 'C1',
                'similar_organs'       => ['organ-b'],
                'usage_evidence_count' => 5,
                'compounding_value'    => 0.50,
                'maintenance_cost'     => 0.30,
            ]],
        ]);

        $this->assertSame(AtlasExternalBrainComplexityDebtBurnDownPlanner::ACTION_CONSOLIDATE, $result['ranked_candidates'][0]['recommended_action']);
    }

    public function test_consolidate_when_covers_same_decision_surface(): void
    {
        $result = $this->planner()->plan([
            'candidates' => [[
                'organ_id'                       => 'C2',
                'similar_organs'                 => [],
                'usage_evidence_count'           => 5,
                'compounding_value'              => 0.50,
                'maintenance_cost'               => 0.30,
                'covers_same_decision_surface_as' => ['overlapping-organ'],
            ]],
        ]);

        $this->assertSame(AtlasExternalBrainComplexityDebtBurnDownPlanner::ACTION_CONSOLIDATE, $result['ranked_candidates'][0]['recommended_action']);
    }

    public function test_consolidate_requires_proof_before_deletion(): void
    {
        $result = $this->planner()->plan([
            'candidates' => [[
                'organ_id'             => 'C3',
                'similar_organs'       => ['x'],
                'usage_evidence_count' => 3,
                'compounding_value'    => 0.50,
                'maintenance_cost'     => 0.30,
            ]],
        ]);

        $this->assertNotNull($result['ranked_candidates'][0]['proof_required_before_deletion']);
    }

    // ── recommended_action: simplify ──────────────────────────────────────────

    public function test_simplify_when_maintenance_cost_above_ceiling(): void
    {
        $result = $this->planner()->plan([
            'candidates' => [[
                'organ_id'             => 'S1',
                'similar_organs'       => [],
                'usage_evidence_count' => 5,
                'compounding_value'    => 0.80,
                'maintenance_cost'     => 0.80,
            ]],
        ]);

        $this->assertSame(AtlasExternalBrainComplexityDebtBurnDownPlanner::ACTION_SIMPLIFY, $result['ranked_candidates'][0]['recommended_action']);
    }

    public function test_simplify_proof_is_null(): void
    {
        $result = $this->planner()->plan([
            'candidates' => [[
                'organ_id'             => 'S2',
                'similar_organs'       => [],
                'usage_evidence_count' => 5,
                'compounding_value'    => 0.80,
                'maintenance_cost'     => 0.80,
            ]],
        ]);

        $this->assertNull($result['ranked_candidates'][0]['proof_required_before_deletion']);
    }

    // ── recommended_action: keep ──────────────────────────────────────────────

    public function test_keep_when_healthy(): void
    {
        $result = $this->planner()->plan(['candidates' => [$this->keepCandidate()]]);

        $this->assertSame(AtlasExternalBrainComplexityDebtBurnDownPlanner::ACTION_KEEP, $result['ranked_candidates'][0]['recommended_action']);
        $this->assertNull($result['ranked_candidates'][0]['proof_required_before_deletion']);
    }

    // ── preferred_action ──────────────────────────────────────────────────────

    public function test_preferred_consolidate_or_delete_when_overlap_exists(): void
    {
        $result = $this->planner()->plan([
            'candidates' => [[
                'organ_id'       => 'O1',
                'similar_organs' => ['twin'],
                'usage_evidence_count' => 5,
                'compounding_value' => 0.50,
                'maintenance_cost' => 0.30,
            ]],
        ]);

        $this->assertSame(AtlasExternalBrainComplexityDebtBurnDownPlanner::PREFERRED_CONSOLIDATE_OR_DELETE, $result['preferred_action']);
    }

    public function test_preferred_add_new_capability_when_no_overlap(): void
    {
        $result = $this->planner()->plan(['candidates' => [$this->keepCandidate('N1')]]);

        $this->assertSame(AtlasExternalBrainComplexityDebtBurnDownPlanner::PREFERRED_ADD_NEW_CAPABILITY, $result['preferred_action']);
    }

    // ── ranking ───────────────────────────────────────────────────────────────

    public function test_rank_starts_at_one(): void
    {
        $result = $this->planner()->plan(['candidates' => [$this->keepCandidate()]]);

        $this->assertSame(1, $result['ranked_candidates'][0]['rank']);
    }

    public function test_high_maintenance_cost_ranks_higher(): void
    {
        $result = $this->planner()->plan([
            'candidates' => [
                array_merge($this->keepCandidate('low'), ['maintenance_cost' => 0.10]),
                array_merge($this->keepCandidate('high'), ['maintenance_cost' => 0.60]),
            ],
        ]);

        $this->assertSame('high', $result['ranked_candidates'][0]['organ_id']);
    }

    public function test_more_similar_organs_ranks_higher(): void
    {
        $result = $this->planner()->plan([
            'candidates' => [
                array_merge($this->keepCandidate('no-sim'), ['similar_organs' => [], 'maintenance_cost' => 0.0]),
                array_merge($this->keepCandidate('sim'), ['similar_organs' => ['a', 'b'], 'maintenance_cost' => 0.0, 'usage_evidence_count' => 10]),
            ],
        ]);

        $this->assertSame('sim', $result['ranked_candidates'][0]['organ_id']);
    }

    // ── risk_notes ────────────────────────────────────────────────────────────

    public function test_risk_notes_populated_for_consolidate(): void
    {
        $result = $this->planner()->plan([
            'candidates' => [[
                'organ_id'             => 'R1',
                'similar_organs'       => ['twin-a'],
                'usage_evidence_count' => 5,
                'compounding_value'    => 0.50,
                'maintenance_cost'     => 0.30,
            ]],
        ]);

        $this->assertNotEmpty($result['ranked_candidates'][0]['risk_notes']);
    }

    public function test_risk_notes_empty_for_keep(): void
    {
        $result = $this->planner()->plan(['candidates' => [$this->keepCandidate('K2')]]);

        $this->assertSame([], $result['ranked_candidates'][0]['risk_notes']);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = [
            'candidates' => [
                $this->keepCandidate('A'),
                array_merge($this->keepCandidate('B'), ['similar_organs' => ['x']]),
            ],
        ];

        $this->assertSame($this->planner()->plan($input), $this->planner()->plan($input));
    }
}
