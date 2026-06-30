<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainComplexityDebtBurnDownPlanner;
use Tests\TestCase;

final class AtlasExternalBrainComplexityDebtBurnDownPlannerTest extends TestCase
{
    private AtlasExternalBrainComplexityDebtBurnDownPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = new AtlasExternalBrainComplexityDebtBurnDownPlanner;
    }

    // ── AC2: zero-evidence duplicate without consumers → delete/consolidate + proof_required

    public function test_ac2_zero_evidence_duplicate_without_consumers_is_ranked_for_delete(): void
    {
        $result = $this->planner->plan([
            'candidates' => [[
                'organ_id'            => 'AtlasOrphanDuplicateScorer',
                'similar_organs'      => ['AtlasOrganHealthScorer'],
                'usage_evidence_count' => 0,
                'compounding_value'   => 0.05,
                'has_active_consumers' => false,
                'estimated_line_delta' => 120,
            ]],
        ]);

        $candidate = $result['ranked_candidates'][0];
        $this->assertSame(AtlasExternalBrainComplexityDebtBurnDownPlanner::ACTION_DELETE, $candidate['recommended_action']);
        $this->assertNotNull($candidate['proof_required_before_deletion']);
        $this->assertStringContainsString('AtlasOrphanDuplicateScorer', $candidate['proof_required_before_deletion']);
    }

    public function test_ac2_zero_evidence_duplicate_does_not_appear_in_blocked_deletions(): void
    {
        $result = $this->planner->plan([
            'candidates' => [[
                'organ_id'            => 'AtlasOrphanDuplicateScorer',
                'similar_organs'      => ['AtlasOrganHealthScorer'],
                'usage_evidence_count' => 0,
                'compounding_value'   => 0.05,
                'has_active_consumers' => false,
            ]],
        ]);

        $this->assertNotContains('AtlasOrphanDuplicateScorer', $result['blocked_deletions']);
    }

    public function test_ac2_consolidate_candidate_also_gets_proof_required(): void
    {
        $result = $this->planner->plan([
            'candidates' => [[
                'organ_id'            => 'AtlasLegacyRouter',
                'similar_organs'      => ['AtlasDecisionRouter'],
                'usage_evidence_count' => 2,
                'compounding_value'   => 0.5,
                'has_active_consumers' => false,
            ]],
        ]);

        $candidate = $result['ranked_candidates'][0];
        $this->assertSame(AtlasExternalBrainComplexityDebtBurnDownPlanner::ACTION_CONSOLIDATE, $candidate['recommended_action']);
        $this->assertNotNull($candidate['proof_required_before_deletion']);
    }

    // ── AC3: has_active_consumers=true → blocked_deletions, excluded from total_expected_line_delta

    public function test_ac3_active_consumer_organ_appears_in_blocked_deletions(): void
    {
        $result = $this->planner->plan([
            'candidates' => [[
                'organ_id'            => 'AtlasActiveDuplicateScorer',
                'similar_organs'      => ['AtlasOrganHealthScorer'],
                'usage_evidence_count' => 0,
                'compounding_value'   => 0.05,
                'has_active_consumers' => true,
                'estimated_line_delta' => 200,
            ]],
        ]);

        $this->assertContains('AtlasActiveDuplicateScorer', $result['blocked_deletions']);
    }

    public function test_ac3_blocked_organ_does_not_contribute_to_total_expected_line_delta(): void
    {
        $result = $this->planner->plan([
            'candidates' => [
                [
                    'organ_id'            => 'AtlasActiveDuplicateScorer',
                    'similar_organs'      => ['AtlasOrganHealthScorer'],
                    'usage_evidence_count' => 0,
                    'compounding_value'   => 0.05,
                    'has_active_consumers' => true,
                    'estimated_line_delta' => 300,
                ],
                [
                    'organ_id'            => 'AtlasCleanOrphan',
                    'similar_organs'      => ['AtlasOrganHealthScorer'],
                    'usage_evidence_count' => 0,
                    'compounding_value'   => 0.05,
                    'has_active_consumers' => false,
                    'estimated_line_delta' => 80,
                ],
            ],
        ]);

        // Only the clean orphan's 80 lines count; blocked organ's 300 must be excluded.
        $this->assertSame(80, $result['total_expected_line_delta']);
    }

    public function test_ac3_blocked_organ_risk_notes_mention_active_consumers(): void
    {
        $result = $this->planner->plan([
            'candidates' => [[
                'organ_id'            => 'AtlasActiveDuplicateScorer',
                'similar_organs'      => ['AtlasOrganHealthScorer'],
                'usage_evidence_count' => 0,
                'compounding_value'   => 0.05,
                'has_active_consumers' => true,
            ]],
        ]);

        $candidate = $result['ranked_candidates'][0];
        $hasBlockedNote = array_filter(
            $candidate['risk_notes'],
            fn (string $n) => str_contains($n, 'active consumers'),
        );
        $this->assertNotEmpty($hasBlockedNote);
    }

    // ── AC4: preferred_action routing based on overlap presence

    public function test_ac4_no_overlap_produces_add_new_capability(): void
    {
        $result = $this->planner->plan([
            'candidates' => [[
                'organ_id'            => 'AtlasUniqueOrgan',
                'similar_organs'      => [],
                'usage_evidence_count' => 5,
                'compounding_value'   => 0.6,
                'maintenance_cost'    => 0.3,
            ]],
        ]);

        $this->assertSame(
            AtlasExternalBrainComplexityDebtBurnDownPlanner::PREFERRED_ADD_NEW_CAPABILITY,
            $result['preferred_action'],
        );
    }

    public function test_ac4_similar_organs_produces_consolidate_or_delete(): void
    {
        $result = $this->planner->plan([
            'candidates' => [[
                'organ_id'       => 'AtlasDuplicateOrgan',
                'similar_organs' => ['AtlasOrganHealthScorer'],
            ]],
        ]);

        $this->assertSame(
            AtlasExternalBrainComplexityDebtBurnDownPlanner::PREFERRED_CONSOLIDATE_OR_DELETE,
            $result['preferred_action'],
        );
    }

    public function test_ac4_covers_same_decision_surface_also_produces_consolidate_or_delete(): void
    {
        $result = $this->planner->plan([
            'candidates' => [[
                'organ_id'                       => 'AtlasRedundantRouter',
                'covers_same_decision_surface_as' => ['AtlasDecisionRouter'],
            ]],
        ]);

        $this->assertSame(
            AtlasExternalBrainComplexityDebtBurnDownPlanner::PREFERRED_CONSOLIDATE_OR_DELETE,
            $result['preferred_action'],
        );
    }

    public function test_ac4_empty_candidates_produces_add_new_capability(): void
    {
        $result = $this->planner->plan(['candidates' => []]);

        $this->assertSame(
            AtlasExternalBrainComplexityDebtBurnDownPlanner::PREFERRED_ADD_NEW_CAPABILITY,
            $result['preferred_action'],
        );
    }
}
