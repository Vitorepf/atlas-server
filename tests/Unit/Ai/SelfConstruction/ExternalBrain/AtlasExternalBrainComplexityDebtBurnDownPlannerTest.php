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

    // ── AC4: new output keys always present ───────────────────────────────────

    public function test_new_output_keys_present_on_empty_input(): void
    {
        $r = $this->planner()->plan([]);

        $this->assertArrayHasKey('total_expected_line_delta', $r);
        $this->assertArrayHasKey('blocked_deletions', $r);
        $this->assertArrayHasKey('proof_required', $r);
        $this->assertSame(0, $r['total_expected_line_delta']);
        $this->assertSame([], $r['blocked_deletions']);
        $this->assertSame([], $r['proof_required']);
    }

    // ── AC4: total_expected_line_delta ────────────────────────────────────────

    public function test_total_line_delta_sums_non_blocked_candidates(): void
    {
        $r = $this->planner()->plan(['candidates' => [
            array_merge($this->keepCandidate('A'), ['estimated_line_delta' => 100]),
            array_merge($this->keepCandidate('B'), ['estimated_line_delta' => 50]),
        ]]);

        $this->assertSame(150, $r['total_expected_line_delta']);
    }

    public function test_blocked_candidate_excluded_from_line_delta(): void
    {
        $blocked = [
            'organ_id'            => 'B',
            'similar_organs'      => ['X'],
            'has_active_consumers' => true,
            'estimated_line_delta' => 200,
        ];
        $r = $this->planner()->plan(['candidates' => [$blocked]]);

        // blocked → excluded from total
        $this->assertSame(0, $r['total_expected_line_delta']);
    }

    // ── AC3: blocked_deletions ────────────────────────────────────────────────

    public function test_active_consumers_block_delete_action(): void
    {
        $candidate = [
            'organ_id'             => 'D1',
            'similar_organs'       => ['S1'],
            'usage_evidence_count' => 0,
            'compounding_value'    => 0.0,
            'has_active_consumers' => true,
        ];
        $r = $this->planner()->plan(['candidates' => [$candidate]]);

        $this->assertContains('D1', $r['blocked_deletions']);
    }

    public function test_active_consumers_block_consolidate_action(): void
    {
        $candidate = [
            'organ_id'             => 'C1',
            'similar_organs'       => ['S1'],
            'usage_evidence_count' => 5,   // not zero → consolidate (not delete)
            'compounding_value'    => 0.9,
            'has_active_consumers' => true,
        ];
        $r = $this->planner()->plan(['candidates' => [$candidate]]);

        $this->assertContains('C1', $r['blocked_deletions']);
    }

    public function test_no_active_consumers_not_blocked(): void
    {
        $candidate = [
            'organ_id'             => 'D2',
            'similar_organs'       => ['S2'],
            'usage_evidence_count' => 0,
            'compounding_value'    => 0.0,
            'has_active_consumers' => false,
        ];
        $r = $this->planner()->plan(['candidates' => [$candidate]]);

        $this->assertNotContains('D2', $r['blocked_deletions']);
    }

    public function test_keep_action_not_blocked_even_with_active_consumers(): void
    {
        $r = $this->planner()->plan(['candidates' => [
            array_merge($this->keepCandidate('K3'), ['has_active_consumers' => true]),
        ]]);

        $this->assertSame([], $r['blocked_deletions']);
    }

    // ── AC4: proof_required ───────────────────────────────────────────────────

    public function test_proof_required_populated_for_delete_candidate(): void
    {
        $candidate = [
            'organ_id'             => 'DEL',
            'similar_organs'       => ['X'],
            'usage_evidence_count' => 0,
            'compounding_value'    => 0.0,
        ];
        $r = $this->planner()->plan(['candidates' => [$candidate]]);

        $this->assertCount(1, $r['proof_required']);
        $this->assertStringContainsString('DEL', $r['proof_required'][0]);
    }

    public function test_proof_required_empty_for_keep_only(): void
    {
        $r = $this->planner()->plan(['candidates' => [$this->keepCandidate('K4')]]);

        $this->assertSame([], $r['proof_required']);
    }

    public function test_blocked_candidate_still_appears_in_proof_required(): void
    {
        $candidate = [
            'organ_id'             => 'BL',
            'similar_organs'       => ['Y'],
            'usage_evidence_count' => 0,
            'compounding_value'    => 0.0,
            'has_active_consumers' => true,
        ];
        $r = $this->planner()->plan(['candidates' => [$candidate]]);

        // Blocked but proof is still required before it can be actioned.
        $this->assertNotEmpty($r['proof_required']);
        $this->assertStringContainsString('BL', $r['proof_required'][0]);
    }
}
