<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\PortfolioStewardship;

use App\Services\Ai\SoftwareCompanyStewardship\PortfolioStewardship\PortfolioStewardshipHealthModelService;
use App\Services\Ai\SoftwareCompanyStewardship\PortfolioStewardship\PortfolioStewardshipInboxService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionDecisionLedgerService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionReadModelService;
use Tests\TestCase;

class PortfolioStewardshipInboxServiceTest extends TestCase
{
    private string $tmp;

    private string $decisionTmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_psib_'.uniqid('', true);
        $this->decisionTmp = sys_get_temp_dir().'/atlas_psib_decision_'.uniqid('', true);
        @mkdir($this->tmp, 0775, true);
        @mkdir($this->decisionTmp, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->tmp.'/*') as $file) {
            @unlink((string) $file);
        }
        foreach ((array) glob($this->decisionTmp.'/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->tmp);
        @rmdir($this->decisionTmp);
        parent::tearDown();
    }

    private function service(): PortfolioStewardshipInboxService
    {
        $health = app(PortfolioStewardshipHealthModelService::class);
        $ledger = app(StewardshipEvolutionDecisionLedgerService::class);
        $ledger->setStorageRootForTesting($this->decisionTmp);

        $service = new PortfolioStewardshipInboxService($health, $ledger);
        $service->setStorageRootForTesting($this->tmp);

        return $service;
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function healthReport(array $overrides = []): array
    {
        return array_merge([
            'schema_version' => PortfolioStewardshipHealthModelService::HEALTH_SCHEMA,
            'status' => PortfolioStewardshipHealthModelService::STATUS_READY_FOR_OPERATOR_REVIEW,
            'ap_contract' => 'AP-733',
            'portfolio_id' => 'atlas_software_company',
            'area_id' => 'agentic_engineering_os',
            'portfolio_health' => [
                'score' => 76,
                'band' => 'watch',
                'lowest_health_area' => 'atlas_forge',
                'lowest_health_score' => 61,
            ],
            'risk_summary' => [
                'primary_risk' => 'dependency_bottleneck',
            ],
            'rebalance_candidates' => [[
                'candidate_id' => 'portfolio_rebalance_forge',
                'action' => 'allocate_next_governed_cycle',
                'target_area' => 'atlas_forge',
                'priority_score' => 63,
                'reason' => 'lowest_health_area',
                'requires_operator_review' => true,
                'routes_to' => ['area_stewardship', 'area_focus_loop', 'atlas_dev_or_forge_after_acceptance'],
            ]],
            'evidence_refs' => [
                'docs/ap/AP-733-portfolio-stewardship-health-model-contract.md',
            ],
            'health_hash' => 'sha256:'.hash('sha256', 'portfolio-health'),
            'snapshot_id' => 'phs_test_snapshot',
            'generated_at' => '2026-05-27T00:00:00+00:00',
        ], $overrides);
    }

    public function test_projects_portfolio_inbox_from_health_report(): void
    {
        $inbox = $this->service()->project(['health_report' => $this->healthReport()]);

        $this->assertSame(PortfolioStewardshipInboxService::INBOX_SCHEMA, $inbox['schema_version']);
        $this->assertSame(PortfolioStewardshipInboxService::STATUS_READY, $inbox['status']);
        $this->assertSame('AP-734', $inbox['ap_contract']);
        $this->assertSame(1, $inbox['item_count']);
        $this->assertSame('atlas_software_company', $inbox['portfolio_id']);
        $this->assertSame('phs_test_snapshot', $inbox['source_snapshot_id']);

        $item = $inbox['items'][0];
        $this->assertSame(PortfolioStewardshipInboxService::ITEM_SCHEMA, $item['schema_version']);
        $this->assertSame('portfolio_stewardship', $item['target_type']);
        $this->assertSame('portfolio_rebalance_forge', $item['target_id']);
        $this->assertStringStartsWith('sha256:', $item['target_hash']);
        $this->assertSame('atlas_forge', $item['target_area']);
        $this->assertSame('high', $item['risk_level']);
        $this->assertTrue($item['operator_decision_required']);
        $this->assertFalse($item['autoapproval_allowed']);
        $this->assertFalse($item['autoimplementation_allowed']);
    }

    public function test_ap751_owner_runtime_result_signal_flows_into_portfolio_inbox(): void
    {
        $inbox = $this->service()->project([
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
                        'result_ids' => ['orr_ap751_inbox_test'],
                    ]],
                ],
            ],
        ]);

        $this->assertSame(PortfolioStewardshipInboxService::STATUS_READY, $inbox['status']);
        $this->assertContains('AP-751', $inbox['source_ap_contracts']);
        $this->assertSame('agentic_engineering_os', $inbox['items'][0]['target_area']);
        $this->assertSame('review_owner_runtime_result', $inbox['items'][0]['recommended_action']);
        $this->assertSame('owner_runtime_result_waiting_for_review', $inbox['items'][0]['rationale']);
        $this->assertSame(1, $inbox['items'][0]['target_payload']['candidate']['owner_runtime_result_count']);
        $this->assertContains('docs/ap/AP-751-portfolio-owner-runtime-result-signal-contract.md', $inbox['items'][0]['evidence_refs']);
    }

    public function test_inbox_hash_is_deterministic_for_same_health_report(): void
    {
        $input = ['health_report' => $this->healthReport()];

        $a = $this->service()->project($input);
        $b = $this->service()->project($input);

        $this->assertSame($a['inbox_hash'], $b['inbox_hash']);
        $this->assertStringStartsWith('sha256:', $a['inbox_hash']);
    }

    public function test_records_lists_and_replays_inbox_idempotently(): void
    {
        $service = $this->service();
        $a = $service->record(['health_report' => $this->healthReport()]);
        $b = $service->record(['health_report' => $this->healthReport()]);

        $this->assertSame($a['inbox_id'], $b['inbox_id']);
        $this->assertSame(PortfolioStewardshipInboxService::LEDGER_SCHEMA, $a['ledger_schema_version']);
        $this->assertFileExists($service->ledgerFilePath('atlas_software_company'));

        $lines = file($service->ledgerFilePath('atlas_software_company'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(1, $lines);

        $list = $service->listInboxes('atlas_software_company');
        $this->assertSame(1, $list['inbox_count']);
        $this->assertSame($a['inbox_id'], $list['inboxes'][0]['inbox_id']);
        $this->assertSame($a, $service->replay($a['inbox_id']));
    }

    public function test_corrupted_lines_are_counted_without_breaking_replay(): void
    {
        $service = $this->service();
        $record = $service->record(['health_report' => $this->healthReport()]);
        file_put_contents(
            $service->ledgerFilePath('atlas_software_company'),
            "not-json\n".json_encode(['missing' => 'inbox_id']).PHP_EOL,
            FILE_APPEND,
        );

        $list = $service->listInboxes('atlas_software_company');

        $this->assertSame(1, $list['inbox_count']);
        $this->assertSame(2, $list['corrupted_line_count']);
        $this->assertNotNull($service->replay($record['inbox_id']));
    }

    public function test_decision_uses_ap731_ledger_without_execution(): void
    {
        $service = $this->service();
        $inbox = $service->record(['health_report' => $this->healthReport()]);
        $item = $inbox['items'][0];

        $receipt = $service->decide([
            'inbox_id' => $inbox['inbox_id'],
            'item_id' => $item['item_id'],
            'operator_actor' => 'vitor',
            'decision' => 'accept',
            'rationale' => 'approved for next governed portfolio stewardship slice',
        ]);

        $this->assertSame('atlas.software_company_stewardship.evolution_operator_decision_receipt.v1', $receipt['schema_version']);
        $this->assertSame('AP-731', $receipt['ap_contract']);
        $this->assertSame('AP-734', $receipt['source_ap_contract']);
        $this->assertSame($item['item_id'], $receipt['source_inbox_item_id']);
        $this->assertSame('portfolio_stewardship', $receipt['target_type']);
        $this->assertSame($item['target_id'], $receipt['target_id']);
        $this->assertSame($item['target_hash'], $receipt['target_hash']);
        $this->assertFalse($receipt['executed']);
        $this->assertFalse($receipt['dev_invoked']);
        $this->assertFalse($receipt['forge_invoked']);
        $this->assertFalse($receipt['mutates_target_repo']);
    }

    public function test_blocks_when_health_report_is_blocked(): void
    {
        $inbox = $this->service()->project([
            'health_report' => [
                'schema_version' => PortfolioStewardshipHealthModelService::HEALTH_SCHEMA,
                'status' => PortfolioStewardshipHealthModelService::STATUS_BLOCKED,
                'portfolio_id' => 'atlas_software_company',
                'area_id' => StewardshipEvolutionReadModelService::DEFAULT_AREA_ID,
            ],
        ]);

        $this->assertSame(PortfolioStewardshipInboxService::STATUS_BLOCKED, $inbox['status']);
        $this->assertSame('portfolio_health_not_ready', $inbox['reason']);
        $this->assertSame(0, $inbox['item_count']);
    }

    public function test_secret_like_input_is_not_persisted(): void
    {
        $service = $this->service();
        $record = $service->record([
            'health_report' => $this->healthReport(),
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
        $policy = $this->service()->project(['health_report' => $this->healthReport()])['claim_policy'];

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
