<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AutonomousExecutive;

use App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive\AutonomousExecutiveAllocationHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive\AutonomousExecutiveRecommendationService;
use App\Services\Ai\SoftwareCompanyStewardship\PortfolioStewardship\PortfolioStewardshipInboxService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionDecisionLedgerService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AutonomousExecutiveAllocationHandoffServiceTest extends TestCase
{
    private string $tmp;

    private string $recommendationTmp;

    private string $portfolioTmp;

    private string $decisionTmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap752_'.uniqid('', true);
        $this->recommendationTmp = sys_get_temp_dir().'/atlas_ap752_recommendations_'.uniqid('', true);
        $this->portfolioTmp = sys_get_temp_dir().'/atlas_ap752_portfolio_'.uniqid('', true);
        $this->decisionTmp = sys_get_temp_dir().'/atlas_ap752_decisions_'.uniqid('', true);
        foreach ([$this->tmp, $this->recommendationTmp, $this->portfolioTmp, $this->decisionTmp] as $dir) {
            @mkdir($dir, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        foreach ([$this->tmp, $this->recommendationTmp, $this->portfolioTmp, $this->decisionTmp] as $dir) {
            File::deleteDirectory($dir);
        }
        parent::tearDown();
    }

    /**
     * @return array{handoff:AutonomousExecutiveAllocationHandoffService,recommendations:AutonomousExecutiveRecommendationService,ledger:StewardshipEvolutionDecisionLedgerService}
     */
    private function rig(): array
    {
        $portfolioInbox = app(PortfolioStewardshipInboxService::class);
        $portfolioInbox->setStorageRootForTesting($this->portfolioTmp);

        $ledger = app(StewardshipEvolutionDecisionLedgerService::class);
        $ledger->setStorageRootForTesting($this->decisionTmp);

        $recommendations = new AutonomousExecutiveRecommendationService($portfolioInbox, $ledger);
        $recommendations->setStorageRootForTesting($this->recommendationTmp);

        $handoff = new AutonomousExecutiveAllocationHandoffService($recommendations, $ledger);
        $handoff->setStorageRootForTesting($this->tmp);

        return [
            'handoff' => $handoff,
            'recommendations' => $recommendations,
            'ledger' => $ledger,
        ];
    }

    public function test_blocks_when_executive_pack_is_missing_or_blocked(): void
    {
        ['handoff' => $handoff] = $this->rig();

        $report = $handoff->project([
            'executive_pack' => [
                'schema_version' => AutonomousExecutiveRecommendationService::PACK_SCHEMA,
                'status' => AutonomousExecutiveRecommendationService::STATUS_BLOCKED,
                'portfolio_id' => 'atlas_software_company',
                'area_id' => 'agentic_engineering_os',
            ],
        ]);

        $this->assertSame(AutonomousExecutiveAllocationHandoffService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(AutonomousExecutiveAllocationHandoffService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('executive_recommendation_pack_not_ready', $report['reason']);
        $this->assertSame(0, $report['allocation_handoff_count']);
        $this->assertFalse($report['claim_policy']['provider_invoked']);
        $this->assertFalse($report['claim_policy']['dev_invoked']);
        $this->assertFalse($report['claim_policy']['forge_invoked']);
    }

    public function test_awaits_operator_acceptance_when_recommendation_has_no_ap731_accept(): void
    {
        ['handoff' => $handoff, 'recommendations' => $recommendations] = $this->rig();
        $pack = $recommendations->record(['portfolio_inbox' => $this->portfolioInbox()]);

        $report = $handoff->project([
            'pack_id' => $pack['pack_id'],
            'recommendation_id' => $pack['recommendations'][0]['recommendation_id'],
        ]);

        $this->assertSame(AutonomousExecutiveAllocationHandoffService::STATUS_AWAITING_OPERATOR_ACCEPTANCE, $report['status']);
        $this->assertSame('executive_allocation_requires_operator_accept', $report['reason']);
        $this->assertSame(0, $report['allocation_handoff_count']);
        $this->assertSame(['executive_allocation_requires_operator_accept'], $report['blockers']);
    }

    public function test_builds_ready_allocation_handoff_after_ap731_acceptance(): void
    {
        ['handoff' => $handoff, 'recommendations' => $recommendations] = $this->rig();
        $pack = $recommendations->record(['portfolio_inbox' => $this->portfolioInbox()]);
        $recommendation = $pack['recommendations'][0];
        $decision = $recommendations->decide([
            'pack_id' => $pack['pack_id'],
            'recommendation_id' => $recommendation['recommendation_id'],
            'operator_actor' => 'vitor',
            'decision' => 'accept',
            'rationale' => 'approved for AP-752 allocation handoff test',
        ]);

        $report = $handoff->project([
            'pack_id' => $pack['pack_id'],
            'recommendation_id' => $recommendation['recommendation_id'],
        ]);

        $this->assertSame(AutonomousExecutiveAllocationHandoffService::STATUS_READY, $report['status']);
        $this->assertSame('AP-752', $report['ap_contract']);
        $this->assertSame($decision['decision_id'], $report['source_decision_id']);
        $this->assertSame(1, $report['allocation_handoff_count']);

        $packet = $report['allocation_handoff_packets'][0];
        $this->assertSame(AutonomousExecutiveAllocationHandoffService::PACKET_SCHEMA, $packet['schema_version']);
        $this->assertSame('ready_for_owner_allocation_review', $packet['handoff_status']);
        $this->assertSame('Area Stewardship / Area Focus Loop', $packet['target_owner']);
        $this->assertSame('AP-743/AP-744/AP-745/AP-746/AP-747', $packet['target_owner_contract']);
        $this->assertSame('allocate_next_governed_cycle', $packet['recommended_action']);
        $this->assertSame($recommendation['target_hash'], $packet['target_hash']);
        $this->assertSame($decision['decision_id'], $packet['operator_acceptance']['decision_id']);
        $this->assertContains('ap752_allocation_handoff_packet_review', $packet['required_gate_sequence']);
        $this->assertContains('invoke_forge', $packet['forbidden_actions']);
        $this->assertFalse($packet['handoff_boundary']['starts_execution']);
        $this->assertFalse($packet['claim_policy']['dev_invoked']);
        $this->assertFalse($packet['claim_policy']['forge_invoked']);
        $this->assertFalse($packet['claim_policy']['branch_created']);
        $this->assertStringStartsWith('sha256:', $packet['packet_hash']);
    }

    public function test_routes_ap751_owner_runtime_result_review_to_owner_result_review(): void
    {
        ['handoff' => $handoff, 'recommendations' => $recommendations] = $this->rig();
        $pack = $recommendations->record($this->ownerRuntimeResultInput());
        $recommendation = $pack['recommendations'][0];
        $recommendations->decide([
            'pack_id' => $pack['pack_id'],
            'recommendation_id' => $recommendation['recommendation_id'],
            'operator_actor' => 'vitor',
            'decision' => 'accept',
            'rationale' => 'approved for AP-751 owner result review handoff',
        ]);

        $report = $handoff->project([
            'pack_id' => $pack['pack_id'],
            'recommendation_id' => $recommendation['recommendation_id'],
        ]);

        $this->assertSame(AutonomousExecutiveAllocationHandoffService::STATUS_READY, $report['status']);
        $this->assertContains('AP-751', $report['source_ap_contracts']);

        $packet = $report['allocation_handoff_packets'][0];
        $this->assertSame('review_owner_runtime_result', $packet['recommended_action']);
        $this->assertSame('Portfolio/Area Owner Runtime Result Review', $packet['target_owner']);
        $this->assertSame('AP-750/AP-751', $packet['target_owner_contract']);
        $this->assertContains('ap751_portfolio_signal_review', $packet['required_gate_sequence']);
        $this->assertContains('request_owner_followup', $packet['allowed_next_actions']);
    }

    public function test_record_handoff_is_append_only_and_idempotent(): void
    {
        ['handoff' => $handoff, 'recommendations' => $recommendations] = $this->rig();
        $pack = $recommendations->record(['portfolio_inbox' => $this->portfolioInbox()]);
        $recommendation = $pack['recommendations'][0];
        $recommendations->decide([
            'pack_id' => $pack['pack_id'],
            'recommendation_id' => $recommendation['recommendation_id'],
            'operator_actor' => 'vitor',
            'decision' => 'accept',
            'rationale' => 'approved for AP-752 record idempotency',
        ]);
        $input = [
            'pack_id' => $pack['pack_id'],
            'recommendation_id' => $recommendation['recommendation_id'],
            'record_allocation_handoff' => true,
        ];

        $first = $handoff->project($input);
        $second = $handoff->project($input);

        $this->assertSame('recorded', $first['allocation_handoff_packets'][0]['handoff_storage_status']);
        $this->assertSame('existing', $second['allocation_handoff_packets'][0]['handoff_storage_status']);
        $this->assertFileExists($handoff->ledgerFilePath('atlas_software_company'));
        $this->assertCount(1, file($handoff->ledgerFilePath('atlas_software_company'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

        $list = $handoff->listHandoffs('atlas_software_company');
        $this->assertSame(1, $list['handoff_count']);
        $packetId = $first['allocation_handoff_packets'][0]['handoff_packet_id'];
        $this->assertSame($packetId, $list['handoffs'][0]['handoff_packet_id']);
        $this->assertSame($first['allocation_handoff_packets'][0], $handoff->replay($packetId));
    }

    public function test_claim_policy_never_allows_execution_or_parallel_runtime(): void
    {
        ['handoff' => $handoff, 'recommendations' => $recommendations] = $this->rig();
        $pack = $recommendations->record(['portfolio_inbox' => $this->portfolioInbox()]);
        $recommendation = $pack['recommendations'][0];
        $recommendations->decide([
            'pack_id' => $pack['pack_id'],
            'recommendation_id' => $recommendation['recommendation_id'],
            'operator_actor' => 'vitor',
            'decision' => 'accept',
            'rationale' => 'approved for AP-752 claim policy test',
        ]);

        $policy = $handoff->project([
            'pack_id' => $pack['pack_id'],
            'recommendation_id' => $recommendation['recommendation_id'],
        ])['claim_policy'];

        $this->assertFalse($policy['mutates_target_repo']);
        $this->assertFalse($policy['provider_invoked']);
        $this->assertFalse($policy['dev_invoked']);
        $this->assertFalse($policy['forge_invoked']);
        $this->assertFalse($policy['opens_branch']);
        $this->assertFalse($policy['opens_worktree']);
        $this->assertFalse($policy['merges']);
        $this->assertFalse($policy['deploys']);
        $this->assertFalse($policy['touches_secrets']);
        $this->assertFalse($policy['spends_budget']);
        $this->assertFalse($policy['autoapproval_allowed']);
        $this->assertFalse($policy['autoimplementation_allowed']);
        $this->assertFalse($policy['auto_promotion']);
        $this->assertFalse($policy['parallel_runtime_created']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function portfolioInbox(array $overrides = []): array
    {
        return array_merge([
            'schema_version' => PortfolioStewardshipInboxService::INBOX_SCHEMA,
            'status' => PortfolioStewardshipInboxService::STATUS_READY,
            'ap_contract' => 'AP-734',
            'portfolio_id' => 'atlas_software_company',
            'area_id' => 'agentic_engineering_os',
            'inbox_id' => 'psi_ap752_inbox',
            'inbox_hash' => 'sha256:'.hash('sha256', 'ap752-portfolio-inbox'),
            'source_health_hash' => 'sha256:'.hash('sha256', 'ap752-portfolio-health'),
            'source_ap_contracts' => ['AP-730', 'AP-731', 'AP-733', 'AP-734'],
            'item_count' => 1,
            'items' => [[
                'schema_version' => PortfolioStewardshipInboxService::ITEM_SCHEMA,
                'item_id' => 'psib_ap752_forge',
                'portfolio_id' => 'atlas_software_company',
                'area_id' => 'agentic_engineering_os',
                'target_type' => 'portfolio_stewardship',
                'target_id' => 'portfolio_rebalance_forge',
                'target_hash' => 'sha256:'.hash('sha256', 'ap752-portfolio-item'),
                'target_payload' => ['target_area' => 'atlas_forge'],
                'target_area' => 'atlas_forge',
                'priority_score' => 72,
                'risk_level' => 'high',
                'recommended_action' => 'allocate_next_governed_cycle',
                'rationale' => 'lowest_health_area',
                'evidence_refs' => ['docs/ap/AP-734-portfolio-steward-inbox-contract.md'],
            ]],
        ], $overrides);
    }

    /**
     * @return array<string,mixed>
     */
    private function ownerRuntimeResultInput(): array
    {
        return [
            'areas' => [
                ['area_id' => 'agentic_engineering_os', 'health_score' => 88, 'dependencies' => []],
                ['area_id' => 'atlas_dev', 'health_score' => 86, 'dependencies' => []],
            ],
            'owner_runtime_result_bridge' => [
                'portfolio_feed' => [
                    'areas' => [[
                        'area_id' => 'agentic_engineering_os',
                        'owner_runtime_result_count' => 1,
                        'completed_result_count' => 1,
                        'failed_result_count' => 0,
                        'partial_result_count' => 0,
                        'result_ids' => ['orr_ap752_review_test'],
                    ]],
                ],
            ],
        ];
    }
}
