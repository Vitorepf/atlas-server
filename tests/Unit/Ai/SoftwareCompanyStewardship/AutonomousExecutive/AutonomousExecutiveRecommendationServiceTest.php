<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AutonomousExecutive;

use App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive\AutonomousExecutiveRecommendationService;
use App\Services\Ai\SoftwareCompanyStewardship\PortfolioStewardship\PortfolioStewardshipInboxService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionDecisionLedgerService;
use Tests\TestCase;

class AutonomousExecutiveRecommendationServiceTest extends TestCase
{
    private string $tmp;

    private string $portfolioTmp;

    private string $decisionTmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_aer_'.uniqid('', true);
        $this->portfolioTmp = sys_get_temp_dir().'/atlas_aer_portfolio_'.uniqid('', true);
        $this->decisionTmp = sys_get_temp_dir().'/atlas_aer_decision_'.uniqid('', true);
        @mkdir($this->tmp, 0775, true);
        @mkdir($this->portfolioTmp, 0775, true);
        @mkdir($this->decisionTmp, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->tmp, $this->portfolioTmp, $this->decisionTmp] as $dir) {
            foreach ((array) glob($dir.'/*') as $file) {
                @unlink((string) $file);
            }
            @rmdir($dir);
        }
        parent::tearDown();
    }

    private function service(): AutonomousExecutiveRecommendationService
    {
        $portfolioInbox = app(PortfolioStewardshipInboxService::class);
        $portfolioInbox->setStorageRootForTesting($this->portfolioTmp);
        $ledger = app(StewardshipEvolutionDecisionLedgerService::class);
        $ledger->setStorageRootForTesting($this->decisionTmp);

        $service = new AutonomousExecutiveRecommendationService($portfolioInbox, $ledger);
        $service->setStorageRootForTesting($this->tmp);

        return $service;
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
            'inbox_id' => 'psi_test_inbox',
            'inbox_hash' => 'sha256:'.hash('sha256', 'portfolio-inbox'),
            'source_health_hash' => 'sha256:'.hash('sha256', 'portfolio-health'),
            'item_count' => 1,
            'items' => [[
                'schema_version' => PortfolioStewardshipInboxService::ITEM_SCHEMA,
                'item_id' => 'psib_forge',
                'portfolio_id' => 'atlas_software_company',
                'area_id' => 'agentic_engineering_os',
                'target_type' => 'portfolio_stewardship',
                'target_id' => 'portfolio_rebalance_forge',
                'target_hash' => 'sha256:'.hash('sha256', 'portfolio-item'),
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

    public function test_projects_executive_recommendation_pack_from_portfolio_inbox(): void
    {
        $pack = $this->service()->project(['portfolio_inbox' => $this->portfolioInbox()]);

        $this->assertSame(AutonomousExecutiveRecommendationService::PACK_SCHEMA, $pack['schema_version']);
        $this->assertSame(AutonomousExecutiveRecommendationService::STATUS_READY_FOR_OPERATOR_REVIEW, $pack['status']);
        $this->assertSame('AP-735', $pack['ap_contract']);
        $this->assertSame(1, $pack['recommendation_count']);
        $this->assertSame('autonomous_executive', $pack['operator_inbox']['target_type']);
        $this->assertFalse($pack['operator_inbox']['autoapproval_allowed']);
        $this->assertFalse($pack['operator_inbox']['autoimplementation_allowed']);

        $recommendation = $pack['recommendations'][0];
        $this->assertSame(AutonomousExecutiveRecommendationService::RECOMMENDATION_SCHEMA, $recommendation['schema_version']);
        $this->assertSame('autonomous_executive', $recommendation['target_type']);
        $this->assertSame('atlas_forge', $recommendation['target_area']);
        $this->assertSame('high', $recommendation['risk_analysis']['risk_level']);
        $this->assertStringStartsWith('exec_', $recommendation['recommendation_id']);
        $this->assertStringStartsWith('sha256:', $recommendation['target_hash']);
        $this->assertTrue($recommendation['capacity_allocation']['operator_review_required']);
        $this->assertTrue($recommendation['budget_policy']['spend_requires_operator_acceptance']);
        $this->assertArrayHasKey('regret_if_defer_score', $recommendation['regret_analysis']);
        $this->assertFalse($recommendation['decision_inbox']['irreversible_action_allowed']);
    }

    public function test_ap751_owner_runtime_result_signal_flows_into_executive_recommendations(): void
    {
        $pack = $this->service()->project([
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
                        'result_ids' => ['orr_ap751_exec_test'],
                    ]],
                ],
            ],
        ]);

        $this->assertSame(AutonomousExecutiveRecommendationService::STATUS_READY_FOR_OPERATOR_REVIEW, $pack['status']);
        $this->assertContains('AP-751', $pack['source_ap_contracts']);
        $this->assertSame('agentic_engineering_os', $pack['recommendations'][0]['target_area']);
        $this->assertSame('review_owner_runtime_result', $pack['recommendations'][0]['recommended_action']);
        $this->assertSame('owner_runtime_result_waiting_for_review', $pack['recommendations'][0]['risk_analysis']['primary_risk']);
        $this->assertSame(1, $pack['recommendations'][0]['target_payload']['source_portfolio_inbox_item']['target_payload']['candidate']['owner_runtime_result_count']);
    }

    public function test_pack_hash_is_deterministic_for_same_portfolio_inbox(): void
    {
        $input = ['portfolio_inbox' => $this->portfolioInbox()];

        $a = $this->service()->project($input);
        $b = $this->service()->project($input);

        $this->assertSame($a['pack_hash'], $b['pack_hash']);
        $this->assertStringStartsWith('sha256:', $a['pack_hash']);
    }

    public function test_records_lists_and_replays_pack_idempotently(): void
    {
        $service = $this->service();
        $a = $service->record(['portfolio_inbox' => $this->portfolioInbox()]);
        $b = $service->record(['portfolio_inbox' => $this->portfolioInbox()]);

        $this->assertSame($a['pack_id'], $b['pack_id']);
        $this->assertSame(AutonomousExecutiveRecommendationService::LEDGER_SCHEMA, $a['ledger_schema_version']);
        $this->assertFileExists($service->ledgerFilePath('atlas_software_company'));

        $lines = file($service->ledgerFilePath('atlas_software_company'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(1, $lines);

        $list = $service->listPacks('atlas_software_company');
        $this->assertSame(1, $list['pack_count']);
        $this->assertSame($a['pack_id'], $list['packs'][0]['pack_id']);
        $this->assertSame($a, $service->replay($a['pack_id']));
    }

    public function test_corrupted_lines_are_counted_without_breaking_replay(): void
    {
        $service = $this->service();
        $record = $service->record(['portfolio_inbox' => $this->portfolioInbox()]);
        file_put_contents(
            $service->ledgerFilePath('atlas_software_company'),
            "not-json\n".json_encode(['missing' => 'pack_id']).PHP_EOL,
            FILE_APPEND,
        );

        $list = $service->listPacks('atlas_software_company');

        $this->assertSame(1, $list['pack_count']);
        $this->assertSame(2, $list['corrupted_line_count']);
        $this->assertNotNull($service->replay($record['pack_id']));
    }

    public function test_decision_uses_ap731_ledger_without_execution(): void
    {
        $service = $this->service();
        $pack = $service->record(['portfolio_inbox' => $this->portfolioInbox()]);
        $recommendation = $pack['recommendations'][0];

        $receipt = $service->decide([
            'pack_id' => $pack['pack_id'],
            'recommendation_id' => $recommendation['recommendation_id'],
            'operator_actor' => 'vitor',
            'decision' => 'accept',
            'rationale' => 'approved for next governed executive planning slice',
        ]);

        $this->assertSame('atlas.software_company_stewardship.evolution_operator_decision_receipt.v1', $receipt['schema_version']);
        $this->assertSame('AP-731', $receipt['ap_contract']);
        $this->assertSame('AP-735', $receipt['source_ap_contract']);
        $this->assertSame($pack['pack_id'], $receipt['source_pack_id']);
        $this->assertSame($recommendation['recommendation_id'], $receipt['source_recommendation_id']);
        $this->assertSame('autonomous_executive', $receipt['target_type']);
        $this->assertSame($recommendation['target_id'], $receipt['target_id']);
        $this->assertSame($recommendation['target_hash'], $receipt['target_hash']);
        $this->assertFalse($receipt['executed']);
        $this->assertFalse($receipt['dev_invoked']);
        $this->assertFalse($receipt['forge_invoked']);
        $this->assertFalse($receipt['mutates_target_repo']);
    }

    public function test_blocks_when_portfolio_inbox_is_blocked(): void
    {
        $pack = $this->service()->project([
            'portfolio_inbox' => [
                'schema_version' => PortfolioStewardshipInboxService::INBOX_SCHEMA,
                'status' => PortfolioStewardshipInboxService::STATUS_BLOCKED,
                'portfolio_id' => 'atlas_software_company',
                'area_id' => 'agentic_engineering_os',
            ],
        ]);

        $this->assertSame(AutonomousExecutiveRecommendationService::STATUS_BLOCKED, $pack['status']);
        $this->assertSame('portfolio_inbox_not_ready', $pack['reason']);
        $this->assertSame(0, $pack['recommendation_count']);
    }

    public function test_secret_like_input_is_not_persisted(): void
    {
        $service = $this->service();
        $record = $service->record([
            'portfolio_inbox' => $this->portfolioInbox(),
            'api_secret' => 'super-secret',
            'auth_token' => 'never-store-me',
        ]);
        $raw = (string) file_get_contents($service->ledgerFilePath('atlas_software_company'));

        $this->assertStringNotContainsString('super-secret', $raw);
        $this->assertStringNotContainsString('never-store-me', $raw);
        $this->assertArrayNotHasKey('api_secret', $record);
        $this->assertArrayNotHasKey('auth_token', $record);
        $this->assertFalse($record['claim_policy']['touches_secrets']);
        $this->assertFalse($record['claim_policy']['secrets_in_payload']);
    }

    public function test_claim_policy_never_allows_execution_or_parallel_runtime(): void
    {
        $policy = $this->service()->project(['portfolio_inbox' => $this->portfolioInbox()])['claim_policy'];

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
