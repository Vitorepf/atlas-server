<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOrganSprawlReductionPlanner;
use Tests\TestCase;

final class AtlasExternalBrainOrganSprawlReductionPlannerTest extends TestCase
{
    private AtlasExternalBrainOrganSprawlReductionPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = new AtlasExternalBrainOrganSprawlReductionPlanner;
    }

    // ── AC2: low-evidence or retired scaffold without replacement/tests → retire_blocked

    public function test_ac2_low_evidence_without_replacement_is_retire_blocked(): void
    {
        $result = $this->planner->plan([
            'organs' => [[
                'organ_id'            => 'AtlasLegacyScorer',
                'evidence_strength'   => 0.05,
                'has_replacement_owner' => false,
                'has_test_coverage'   => false,
            ]],
        ]);

        $action = $result['ranked_actions'][0];
        $this->assertSame('AtlasLegacyScorer', $action['organ_id']);
        $this->assertSame(AtlasExternalBrainOrganSprawlReductionPlanner::ACTION_RETIRE_BLOCKED, $action['action']);
    }

    public function test_ac2_low_evidence_without_tests_but_has_replacement_is_retire_blocked(): void
    {
        $result = $this->planner->plan([
            'organs' => [[
                'organ_id'            => 'AtlasLegacyScorer',
                'evidence_strength'   => 0.10,
                'has_replacement_owner' => true,
                'has_test_coverage'   => false,
            ]],
        ]);

        $this->assertSame(
            AtlasExternalBrainOrganSprawlReductionPlanner::ACTION_RETIRE_BLOCKED,
            $result['ranked_actions'][0]['action'],
        );
    }

    public function test_ac2_retired_scaffold_without_replacement_is_retire_blocked(): void
    {
        $result = $this->planner->plan([
            'organs' => [[
                'organ_id'            => 'AtlasRetiredScaffold',
                'scaffold_status'     => 'retired',
                'evidence_strength'   => 0.80,
                'has_replacement_owner' => false,
                'has_test_coverage'   => true,
            ]],
        ]);

        $this->assertSame(
            AtlasExternalBrainOrganSprawlReductionPlanner::ACTION_RETIRE_BLOCKED,
            $result['ranked_actions'][0]['action'],
        );
    }

    public function test_ac2_retire_blocked_action_lists_missing_prerequisites(): void
    {
        $result = $this->planner->plan([
            'organs' => [[
                'organ_id'          => 'AtlasOrphan',
                'evidence_strength' => 0.05,
            ]],
        ]);

        $reasons = $result['ranked_actions'][0]['reasons'];
        $this->assertNotEmpty($reasons);
        $combined = implode(' ', $reasons);
        $this->assertStringContainsString('missing:', $combined);
    }

    // ── AC3: overlap + replacement + tests → merge; oversized + low consumers → simplify

    public function test_ac3_overlapping_organ_with_replacement_and_tests_classifies_as_merge(): void
    {
        $result = $this->planner->plan([
            'organs' => [[
                'organ_id'            => 'AtlasDuplicateRouter',
                'overlap_organs'      => ['AtlasPrimaryRouter'],
                'has_replacement_owner' => true,
                'has_test_coverage'   => true,
                'evidence_strength'   => 0.80,
                'line_count'          => 150,
            ]],
        ]);

        $this->assertSame(
            AtlasExternalBrainOrganSprawlReductionPlanner::ACTION_MERGE,
            $result['ranked_actions'][0]['action'],
        );
    }

    public function test_ac3_oversized_low_consumer_organ_classifies_as_simplify(): void
    {
        $result = $this->planner->plan([
            'organs' => [[
                'organ_id'          => 'AtlasBloatedService',
                'evidence_strength' => 0.90,
                'line_count'        => 350,
                'consumer_count'    => 1,
            ]],
        ]);

        $this->assertSame(
            AtlasExternalBrainOrganSprawlReductionPlanner::ACTION_SIMPLIFY,
            $result['ranked_actions'][0]['action'],
        );
    }

    public function test_ac3_well_evidenced_adequate_consumer_organ_is_kept(): void
    {
        $result = $this->planner->plan([
            'organs' => [[
                'organ_id'          => 'AtlasHealthyService',
                'evidence_strength' => 0.90,
                'line_count'        => 100,
                'consumer_count'    => 5,
            ]],
        ]);

        $this->assertSame(
            AtlasExternalBrainOrganSprawlReductionPlanner::ACTION_KEEP,
            $result['ranked_actions'][0]['action'],
        );
    }

    // ── AC4: first_safe_batch sorted by highest line_delta then fewest capabilities; required_tests preserved

    public function test_ac4_first_safe_batch_sorts_by_highest_line_reduction(): void
    {
        $result = $this->planner->plan([
            'organs' => [
                [
                    'organ_id'          => 'SmallOrgan',
                    'evidence_strength' => 0.05,
                    'has_replacement_owner' => true,
                    'has_test_coverage' => true,
                    'line_count'        => 100,
                ],
                [
                    'organ_id'          => 'LargeOrgan',
                    'evidence_strength' => 0.05,
                    'has_replacement_owner' => true,
                    'has_test_coverage' => true,
                    'line_count'        => 500,
                ],
            ],
        ]);

        $batch = $result['first_safe_batch'];
        $this->assertCount(2, $batch);
        $this->assertSame('LargeOrgan', $batch[0]);
        $this->assertSame('SmallOrgan', $batch[1]);
    }

    public function test_ac4_first_safe_batch_contains_only_retire_and_simplify(): void
    {
        $result = $this->planner->plan([
            'organs' => [
                ['organ_id' => 'Keeper', 'evidence_strength' => 0.90, 'line_count' => 50, 'consumer_count' => 5],
                ['organ_id' => 'Retiree', 'evidence_strength' => 0.05, 'has_replacement_owner' => true, 'has_test_coverage' => true, 'line_count' => 200],
                ['organ_id' => 'Bloated', 'evidence_strength' => 0.90, 'line_count' => 300, 'consumer_count' => 1],
            ],
        ]);

        $this->assertContains('Retiree', $result['first_safe_batch']);
        $this->assertContains('Bloated', $result['first_safe_batch']);
        $this->assertNotContains('Keeper', $result['first_safe_batch']);
    }

    public function test_ac4_required_tests_preserved_in_output(): void
    {
        $result = $this->planner->plan([
            'organs' => [[
                'organ_id'          => 'AtlasHealthyService',
                'evidence_strength' => 0.90,
                'line_count'        => 100,
                'consumer_count'    => 5,
                'capability_labels' => ['routing', 'dispatch'],
            ]],
        ]);

        $this->assertNotEmpty($result['required_tests']);
        $combined = implode(' ', $result['required_tests']);
        $this->assertStringContainsString('AtlasHealthyService', $combined);
    }

    public function test_ac4_same_input_produces_identical_output(): void
    {
        $input = [
            'organs' => [
                ['organ_id' => 'OrganA', 'evidence_strength' => 0.05, 'has_replacement_owner' => true, 'has_test_coverage' => true, 'line_count' => 200],
                ['organ_id' => 'OrganB', 'evidence_strength' => 0.90, 'line_count' => 350, 'consumer_count' => 1],
            ],
        ];

        $this->assertSame($this->planner->plan($input), $this->planner->plan($input));
    }
}
