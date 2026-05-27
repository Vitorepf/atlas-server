<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionReadModelService;
use Tests\TestCase;

class StewardshipEvolutionReadModelServiceTest extends TestCase
{
    private function service(): StewardshipEvolutionReadModelService
    {
        return app(StewardshipEvolutionReadModelService::class);
    }

    public function test_projects_full_ladder_above_continuous_loop(): void
    {
        $report = $this->service()->project();

        $this->assertSame(StewardshipEvolutionReadModelService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(StewardshipEvolutionReadModelService::STATUS_READY_PROPOSAL_ONLY, $report['status']);
        $this->assertFalse($report['stewardship_stack']['continuous_loop_is_ceiling']);
        $this->assertSame('24h governed motor', $report['stewardship_stack']['continuous_loop_role']);
        $this->assertSame('Self-Expanding Software Company', $report['stewardship_stack']['stack_ceiling']);
        $this->assertArrayHasKey('area_stewardship', $report);
        $this->assertArrayHasKey('portfolio_stewardship', $report);
        $this->assertArrayHasKey('autonomous_executive', $report);
        $this->assertArrayHasKey('self_expanding_software_company', $report);
    }

    public function test_area_stewardship_reuses_area_focus_and_emits_health_model(): void
    {
        $report = $this->service()->project();
        $area = $report['area_stewardship'];

        $this->assertSame(StewardshipEvolutionReadModelService::AREA_STEWARD_SCHEMA, $area['schema_version']);
        $this->assertSame('read_only', $area['mode']);
        $this->assertSame('agentic_engineering_os', $area['area_id']);
        $this->assertIsInt($area['health_model']['score']);
        $this->assertNotEmpty($area['roadmap_candidates']);
        $this->assertFalse($area['operator_inbox']['auto_approval']);
    }

    public function test_portfolio_prioritizes_lowest_health_area(): void
    {
        $report = $this->service()->project([
            'areas' => [
                ['area_id' => 'atlas_dev', 'health_score' => 82, 'dependencies' => ['evidence']],
                ['area_id' => 'atlas_forge', 'health_score' => 61, 'dependencies' => ['atlas_dev']],
                ['area_id' => 'evidence', 'health_score' => 90, 'dependencies' => []],
            ],
        ]);

        $portfolio = $report['portfolio_stewardship'];
        $executive = $report['autonomous_executive'];

        $this->assertSame(StewardshipEvolutionReadModelService::PORTFOLIO_SCHEMA, $portfolio['schema_version']);
        $this->assertSame('atlas_forge', $portfolio['portfolio_health']['lowest_health_area']);
        $this->assertSame('atlas_forge', $executive['primary_recommendation']['target_area']);
        $this->assertSame(['atlas_dev'], $portfolio['dependency_graph']['atlas_forge']);
    }

    public function test_autonomous_executive_is_recommendation_only(): void
    {
        $executive = $this->service()->project()['autonomous_executive'];

        $this->assertSame(StewardshipEvolutionReadModelService::EXECUTIVE_SCHEMA, $executive['schema_version']);
        $this->assertSame('recommendation_only', $executive['mode']);
        $this->assertFalse($executive['decision_inbox']['irreversible_action_allowed']);
        $this->assertTrue($executive['capacity_allocation']['operator_review_required']);
        $this->assertStringStartsWith('exec_', $executive['recommendation_id']);
    }

    public function test_self_expanding_company_proposes_new_areas_only_with_gates(): void
    {
        $self = $this->service()->project([
            'observed_gaps' => [
                ['gap_id' => 'gap_replay', 'summary' => 'Replay evidence lacks a steward', 'candidate_area' => 'replay_evidence'],
            ],
        ])['self_expanding_software_company'];

        $this->assertSame(StewardshipEvolutionReadModelService::SELF_EXPANDING_SCHEMA, $self['schema_version']);
        $this->assertSame(StewardshipEvolutionReadModelService::STATUS_READY_PROPOSAL_ONLY, $self['status']);
        $this->assertSame(1, $self['proposal_count']);
        $proposal = $self['new_area_proposals'][0];
        $this->assertSame('replay_evidence', $proposal['candidate_area']);
        $this->assertSame('required', $proposal['operator_approval']);
        $this->assertTrue($proposal['risk_policy']['proposal_only']);
        $this->assertFalse($self['promotion_gate']['auto_promotion']);
    }

    public function test_report_hash_is_deterministic_for_same_input(): void
    {
        $input = [
            'areas' => [
                ['area_id' => 'atlas_dev', 'health_score' => 82],
                ['area_id' => 'atlas_forge', 'health_score' => 61],
            ],
        ];

        $a = $this->service()->project($input);
        $b = $this->service()->project($input);

        $this->assertSame($a['report_hash'], $b['report_hash']);
        $this->assertStringStartsWith('sha256:', $a['report_hash']);
    }

    public function test_claim_policy_never_allows_mutation_or_parallel_os(): void
    {
        $policy = $this->service()->project()['claim_policy'];

        $this->assertTrue($policy['read_only']);
        $this->assertTrue($policy['proposal_only']);
        $this->assertFalse($policy['providers_invoked']);
        $this->assertFalse($policy['repo_mutation']);
        $this->assertFalse($policy['branch_created']);
        $this->assertFalse($policy['dev_invoked']);
        $this->assertFalse($policy['forge_invoked']);
        $this->assertFalse($policy['merge_without_operator']);
        $this->assertFalse($policy['deploy_without_operator']);
        $this->assertFalse($policy['secret_access']);
        $this->assertFalse($policy['destructive_change']);
        $this->assertFalse($policy['auto_promotion']);
        $this->assertFalse($policy['new_os_created']);
        $this->assertFalse($policy['parallel_runtime_created']);
    }

    public function test_unknown_area_blocks_through_area_focus_owner(): void
    {
        $report = $this->service()->project(['area_id' => 'not_registered']);

        $this->assertSame(StewardshipEvolutionReadModelService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('area_focus_not_ready', $report['reason']);
    }
}
