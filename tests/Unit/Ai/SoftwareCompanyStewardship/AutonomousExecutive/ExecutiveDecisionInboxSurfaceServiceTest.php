<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AutonomousExecutive;

use App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive\AutonomousExecutiveRecommendationService;
use App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive\ExecutiveDecisionInboxSurfaceService;
use Tests\TestCase;

class ExecutiveDecisionInboxSurfaceServiceTest extends TestCase
{
    private function service(): ExecutiveDecisionInboxSurfaceService
    {
        return app(ExecutiveDecisionInboxSurfaceService::class);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function pack(array $overrides = []): array
    {
        return array_merge([
            'schema_version' => AutonomousExecutiveRecommendationService::PACK_SCHEMA,
            'status' => AutonomousExecutiveRecommendationService::STATUS_READY_FOR_OPERATOR_REVIEW,
            'ap_contract' => 'AP-735',
            'portfolio_id' => 'atlas_software_company',
            'area_id' => 'agentic_engineering_os',
            'pack_id' => 'aer_test_pack',
            'pack_hash' => 'sha256:'.hash('sha256', 'pack'),
            'recommendation_count' => 1,
            'recommendations' => [[
                'schema_version' => AutonomousExecutiveRecommendationService::RECOMMENDATION_SCHEMA,
                'recommendation_id' => 'exec_test',
                'target_type' => 'autonomous_executive',
                'target_id' => 'executive_allocation_atlas_forge',
                'target_hash' => 'sha256:'.hash('sha256', 'recommendation'),
                'portfolio_id' => 'atlas_software_company',
                'area_id' => 'agentic_engineering_os',
                'target_area' => 'atlas_forge',
                'recommended_action' => 'allocate_next_governed_cycle',
                'executive_summary' => 'Allocate the next governed stewardship cycle to atlas_forge.',
                'risk_analysis' => ['risk_level' => 'high', 'priority_score' => 72],
                'regret_analysis' => ['regret_if_defer_score' => 71],
            ]],
        ], $overrides);
    }

    public function test_projects_read_only_decision_inbox_surface(): void
    {
        $surface = $this->service()->project('atlas_software_company', ['executive_pack' => $this->pack()]);

        $this->assertSame(ExecutiveDecisionInboxSurfaceService::SURFACE_SCHEMA, $surface['schema_version']);
        $this->assertSame(ExecutiveDecisionInboxSurfaceService::STATUS_READY, $surface['status']);
        $this->assertSame('AP-736', $surface['ap_contract']);
        $this->assertTrue($surface['read_only']);
        $this->assertSame(1, $surface['item_count']);
        $this->assertSame(1, $surface['decision_summary']['pending_operator_review']);

        $item = $surface['items'][0];
        $this->assertSame(ExecutiveDecisionInboxSurfaceService::ITEM_SCHEMA, $item['schema_version']);
        $this->assertSame('pending_operator_review', $item['status']);
        $this->assertSame('exec_test', $item['source_recommendation_id']);
        $this->assertSame('aer_test_pack', $item['stable_decision_anchor']['pack_id']);
        $this->assertFalse($item['policy']['irreversible_action_allowed']);
    }

    public function test_existing_ap731_decision_changes_item_state_without_execution(): void
    {
        $pack = $this->pack();
        $recommendation = $pack['recommendations'][0];

        $surface = $this->service()->project('atlas_software_company', [
            'executive_pack' => $pack,
            'decision_ledger' => [
                'decisions' => [[
                    'decision_id' => 'seod_test',
                    'target_type' => 'autonomous_executive',
                    'target_id' => $recommendation['target_id'],
                    'target_hash' => $recommendation['target_hash'],
                    'operator_actor' => 'vitor',
                    'decision' => 'defer',
                    'recorded_at' => '2026-05-27T00:00:00+00:00',
                    'executed' => false,
                ]],
            ],
        ]);

        $this->assertSame(0, $surface['decision_summary']['pending_operator_review']);
        $this->assertSame(1, $surface['decision_summary']['deferred']);
        $item = $surface['items'][0];
        $this->assertSame('deferred', $item['status']);
        $this->assertTrue($item['decision_state']['has_decision']);
        $this->assertSame('seod_test', $item['decision_state']['latest_decision_id']);
        $this->assertFalse($item['decision_state']['executed']);
    }

    public function test_surface_hash_is_deterministic(): void
    {
        $input = ['executive_pack' => $this->pack()];

        $a = $this->service()->project('atlas_software_company', $input);
        $b = $this->service()->project('atlas_software_company', $input);

        $this->assertSame($a['surface_hash'], $b['surface_hash']);
        $this->assertStringStartsWith('sha256:', $a['surface_hash']);
    }

    public function test_blocks_when_pack_is_blocked(): void
    {
        $surface = $this->service()->project('atlas_software_company', [
            'executive_pack' => [
                'schema_version' => AutonomousExecutiveRecommendationService::PACK_SCHEMA,
                'status' => AutonomousExecutiveRecommendationService::STATUS_BLOCKED,
                'portfolio_id' => 'atlas_software_company',
                'area_id' => 'agentic_engineering_os',
            ],
        ]);

        $this->assertSame(ExecutiveDecisionInboxSurfaceService::STATUS_BLOCKED, $surface['status']);
        $this->assertSame('executive_recommendation_pack_not_ready', $surface['reason']);
        $this->assertSame(0, $surface['item_count']);
    }

    public function test_claim_policy_never_allows_mutation_or_parallel_runtime(): void
    {
        $policy = $this->service()->project('atlas_software_company', ['executive_pack' => $this->pack()])['claim_policy'];

        $this->assertTrue($policy['read_only_over_repo']);
        $this->assertTrue($policy['surface_only']);
        $this->assertFalse($policy['writes_local_state']);
        $this->assertFalse($policy['mutates_target_repo']);
        $this->assertFalse($policy['provider_invoked']);
        $this->assertFalse($policy['dev_invoked']);
        $this->assertFalse($policy['forge_invoked']);
        $this->assertFalse($policy['opens_branch']);
        $this->assertFalse($policy['merges']);
        $this->assertFalse($policy['deploys']);
        $this->assertFalse($policy['touches_secrets']);
        $this->assertFalse($policy['autoapproval_allowed']);
        $this->assertFalse($policy['autoimplementation_allowed']);
        $this->assertFalse($policy['auto_promotion']);
        $this->assertFalse($policy['is_new_os']);
        $this->assertFalse($policy['parallel_runtime_created']);
    }
}
