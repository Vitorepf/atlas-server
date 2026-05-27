<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\PortfolioStewardship;

use App\Services\Ai\SoftwareCompanyStewardship\PortfolioStewardship\PortfolioStewardshipHealthModelService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionReadModelService;
use Tests\TestCase;

class PortfolioStewardshipHealthModelServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_pshm_'.uniqid('', true);
        @mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->tmp.'/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function service(): PortfolioStewardshipHealthModelService
    {
        $service = app(PortfolioStewardshipHealthModelService::class);
        $service->setStorageRootForTesting($this->tmp);

        return $service;
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'portfolio_id' => 'atlas_software_company',
            'area_id' => 'agentic_engineering_os',
            'areas' => [
                ['area_id' => 'agentic_engineering_os', 'area_name' => 'Agentic Engineering OS', 'health_score' => 88, 'dependencies' => ['atlas_dev', 'atlas_forge']],
                ['area_id' => 'atlas_dev', 'area_name' => 'Atlas Dev', 'health_score' => 74, 'dependencies' => ['evidence']],
                ['area_id' => 'atlas_forge', 'area_name' => 'Atlas Forge', 'health_score' => 61, 'dependencies' => ['atlas_dev', 'evidence']],
                ['area_id' => 'evidence', 'area_name' => 'Evidence', 'health_score' => 91, 'dependencies' => []],
            ],
            'decision_ledger' => ['decisions' => []],
        ], $overrides);
    }

    public function test_projects_portfolio_health_model_from_existing_ladder(): void
    {
        $report = $this->service()->project($this->input());

        $this->assertSame(PortfolioStewardshipHealthModelService::HEALTH_SCHEMA, $report['schema_version']);
        $this->assertSame(PortfolioStewardshipHealthModelService::STATUS_READY_FOR_OPERATOR_REVIEW, $report['status']);
        $this->assertSame('AP-733', $report['ap_contract']);
        $this->assertSame('atlas_software_company', $report['portfolio_id']);
        $this->assertSame('atlas_forge', $report['portfolio_health']['lowest_health_area']);
        $this->assertSame('morning_inbox', $report['operator_inbox']['destination']);
        $this->assertFalse($report['operator_inbox']['auto_approval']);
        $this->assertFalse($report['promotion_boundary']['auto_promotion']);
        $this->assertStringStartsWith('sha256:', $report['health_hash']);
    }

    public function test_rebalance_candidates_prioritize_lowest_and_dependency_weighted_areas(): void
    {
        $report = $this->service()->project($this->input([
            'areas' => [
                ['area_id' => 'area_a', 'health_score' => 80, 'dependencies' => ['shared']],
                ['area_id' => 'area_b', 'health_score' => 63, 'dependencies' => ['shared']],
                ['area_id' => 'shared', 'health_score' => 92, 'dependencies' => []],
            ],
        ]));

        $this->assertSame('area_b', $report['portfolio_health']['lowest_health_area']);
        $this->assertSame('area_b', $report['rebalance_candidates'][0]['target_area']);
        $this->assertSame('lowest_health_area', $report['rebalance_candidates'][0]['reason']);
        $this->assertSame(1, $report['portfolio_health']['dependency_bottleneck_count']);
        $this->assertSame('shared', $report['risk_summary']['dependency_bottlenecks'][0]['area_id']);
    }

    public function test_health_hash_is_deterministic_for_same_input(): void
    {
        $input = $this->input();

        $a = $this->service()->project($input);
        $b = $this->service()->project($input);

        $this->assertSame($a['health_hash'], $b['health_hash']);
    }

    public function test_records_lists_and_replays_health_snapshots_idempotently(): void
    {
        $service = $this->service();
        $a = $service->record($this->input());
        $b = $service->record($this->input());

        $this->assertSame($a['snapshot_id'], $b['snapshot_id']);
        $this->assertSame(PortfolioStewardshipHealthModelService::SNAPSHOT_SCHEMA, $a['snapshot_schema_version']);
        $this->assertSame(PortfolioStewardshipHealthModelService::LEDGER_SCHEMA, $a['ledger_schema_version']);
        $this->assertFileExists($service->ledgerFilePath('atlas_software_company'));

        $lines = file($service->ledgerFilePath('atlas_software_company'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(1, $lines);

        $list = $service->listSnapshots('atlas_software_company');
        $this->assertSame(1, $list['snapshot_count']);
        $this->assertSame($a['snapshot_id'], $list['snapshots'][0]['snapshot_id']);

        $this->assertSame($a, $service->replay($a['snapshot_id']));
    }

    public function test_ap747_release_outcome_feed_prioritizes_owner_queue_review(): void
    {
        $report = $this->service()->project($this->input([
            'areas' => [
                ['area_id' => 'agentic_engineering_os', 'health_score' => 88, 'dependencies' => []],
                ['area_id' => 'atlas_dev', 'health_score' => 86, 'dependencies' => []],
            ],
            'release_outcome_bridge' => [
                'portfolio_feed' => [
                    'areas' => [[
                        'area_id' => 'agentic_engineering_os',
                        'owner_queue_pending_count' => 4,
                        'blocked_release_count' => 0,
                        'target_owners' => ['atlas_dev'],
                        'queue_item_ids' => ['afq_ap748_test'],
                    ]],
                ],
            ],
        ]));

        $this->assertSame(1, $report['release_outcome_summary']['area_count_with_release_signal']);
        $this->assertSame(4, $report['portfolio_health']['owner_queue_pending_count']);
        $this->assertSame(4, $report['risk_summary']['owner_queue_pending_count']);
        $this->assertSame('agentic_engineering_os', $report['rebalance_candidates'][0]['target_area']);
        $this->assertSame('review_owner_queue_release', $report['rebalance_candidates'][0]['action']);
        $this->assertSame('owner_queue_waiting_for_review', $report['rebalance_candidates'][0]['reason']);
        $this->assertSame(4, $report['rebalance_candidates'][0]['owner_queue_pending_count']);
        $this->assertSame('afq_ap748_test', $report['areas'][0]['release_outcome_signal']['queue_item_ids'][0]);
    }

    public function test_ap750_owner_runtime_result_feed_prioritizes_result_review(): void
    {
        $report = $this->service()->project($this->input([
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
                        'result_ids' => ['orr_ap750_test'],
                        'result_health_signal' => 'positive_execution_outcome',
                        'recommended_portfolio_action' => 'review_completed_owner_runtime_result_for_merge_or_next_cycle',
                    ]],
                ],
            ],
        ]));

        $this->assertSame(1, $report['owner_runtime_result_summary']['area_count_with_result_signal']);
        $this->assertSame(1, $report['owner_runtime_result_summary']['owner_runtime_result_count']);
        $this->assertSame(1, $report['portfolio_health']['owner_runtime_result_count']);
        $this->assertSame(1, $report['portfolio_health']['completed_owner_runtime_result_count']);
        $this->assertSame(1, $report['risk_summary']['owner_runtime_result_count']);
        $this->assertSame('owner_runtime_result_review_latency', $report['risk_summary']['primary_risk']);
        $this->assertSame('agentic_engineering_os', $report['rebalance_candidates'][0]['target_area']);
        $this->assertSame('review_owner_runtime_result', $report['rebalance_candidates'][0]['action']);
        $this->assertSame('owner_runtime_result_waiting_for_review', $report['rebalance_candidates'][0]['reason']);
        $this->assertSame(1, $report['rebalance_candidates'][0]['owner_runtime_result_count']);
        $this->assertSame('orr_ap750_test', $report['areas'][0]['owner_runtime_result_signal']['result_ids'][0]);
        $this->assertContains('AP-751', $report['source_ap_contracts']);
        $this->assertContains('docs/ap/AP-751-portfolio-owner-runtime-result-signal-contract.md', $report['evidence_refs']);
    }

    public function test_ap750_failed_owner_runtime_result_feed_prioritizes_followup(): void
    {
        $report = $this->service()->project($this->input([
            'areas' => [
                ['area_id' => 'agentic_engineering_os', 'health_score' => 88, 'dependencies' => []],
                ['area_id' => 'atlas_dev', 'health_score' => 86, 'dependencies' => []],
            ],
            'owner_runtime_result_bridge' => [
                'portfolio_feed' => [
                    'areas' => [[
                        'area_id' => 'agentic_engineering_os',
                        'owner_runtime_result_count' => 1,
                        'completed_result_count' => 0,
                        'failed_result_count' => 1,
                        'partial_result_count' => 0,
                        'result_health_signal' => 'followup_required',
                        'recommended_portfolio_action' => 'prioritize_owner_runtime_followup_before_new_allocation',
                    ]],
                ],
            ],
        ]));

        $this->assertSame(1, $report['owner_runtime_result_summary']['failed_result_count']);
        $this->assertSame(1, $report['portfolio_health']['failed_owner_runtime_result_count']);
        $this->assertSame('owner_runtime_result_followup_required', $report['risk_summary']['primary_risk']);
        $this->assertSame('agentic_engineering_os', $report['rebalance_candidates'][0]['target_area']);
        $this->assertSame('route_owner_runtime_followup', $report['rebalance_candidates'][0]['action']);
        $this->assertSame('owner_runtime_result_followup_required', $report['rebalance_candidates'][0]['reason']);
        $this->assertSame(1, $report['rebalance_candidates'][0]['owner_runtime_result_followup_count']);
    }

    public function test_corrupted_snapshot_lines_are_counted_without_breaking_replay(): void
    {
        $service = $this->service();
        $record = $service->record($this->input());
        file_put_contents(
            $service->ledgerFilePath('atlas_software_company'),
            "not-json\n".json_encode(['missing' => 'snapshot_id']).PHP_EOL,
            FILE_APPEND,
        );

        $list = $service->listSnapshots('atlas_software_company');

        $this->assertSame(1, $list['snapshot_count']);
        $this->assertSame(2, $list['corrupted_line_count']);
        $this->assertNotNull($service->replay($record['snapshot_id']));
    }

    public function test_secret_like_input_is_not_persisted(): void
    {
        $service = $this->service();
        $record = $service->record($this->input([
            'api_secret' => 'super-secret',
            'auth_token' => 'never-store-me',
        ]));
        $raw = (string) file_get_contents($service->ledgerFilePath('atlas_software_company'));

        $this->assertStringNotContainsString('super-secret', $raw);
        $this->assertStringNotContainsString('never-store-me', $raw);
        $this->assertArrayNotHasKey('api_secret', $record);
        $this->assertArrayNotHasKey('auth_token', $record);
        $this->assertFalse($record['claim_policy']['touches_secrets']);
        $this->assertFalse($record['claim_policy']['secrets_in_payload']);
    }

    public function test_blocks_when_ap730_evolution_report_is_blocked(): void
    {
        $report = $this->service()->project([
            'evolution_report' => [
                'schema_version' => StewardshipEvolutionReadModelService::REPORT_SCHEMA,
                'status' => StewardshipEvolutionReadModelService::STATUS_BLOCKED,
            ],
        ]);

        $this->assertSame(PortfolioStewardshipHealthModelService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('stewardship_evolution_not_ready', $report['reason']);
        $this->assertContains('AP-730 evolution report is blocked for this portfolio seed.', $report['blockers']);
    }

    public function test_claim_policy_never_allows_execution_or_parallel_runtime(): void
    {
        $policy = $this->service()->project($this->input())['claim_policy'];

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
