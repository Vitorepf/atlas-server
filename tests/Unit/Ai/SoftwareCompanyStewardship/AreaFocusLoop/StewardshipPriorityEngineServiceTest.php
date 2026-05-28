<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipPriorityEngineService;
use Tests\TestCase;

final class StewardshipPriorityEngineServiceTest extends TestCase
{
    private function service(): StewardshipPriorityEngineService
    {
        return app(StewardshipPriorityEngineService::class);
    }

    public function test_orders_ap783_above_cosmetic_ui(): void
    {
        $report = $this->service()->rank([
            'candidates' => [
                [
                    'id' => 'cosmetic_ui',
                    'title' => 'Polish cockpit button spacing',
                    'type' => 'ui_cosmetic',
                    'changed_files' => ['resources/js/Components/Button.vue'],
                    'operator_touchpoints_reduced' => 0,
                    'dependency_unlocks' => [],
                ],
                [
                    'id' => 'AP-783',
                    'title' => 'AP-783 integration lane promotion',
                    'ap_contract' => 'AP-783',
                    'type' => 'integration_lane_promotion',
                    'evidence_refs' => ['ap782_receipt', 'ap780_packet'],
                    'dependency_unlocks' => ['merge_review', 'owner_runtime', '24h_loop_truth'],
                    'operator_touchpoints_reduced' => 4,
                    'requires_main_dirty' => false,
                    'requires_provider_without_sandbox' => false,
                ],
            ],
        ]);

        $this->assertSame(StewardshipPriorityEngineService::STATUS_READY, $report['status']);
        $this->assertSame('AP-785', $report['ap_contract']);
        $this->assertSame('AP-783', $report['top_candidate']['item_id']);
        $this->assertSame('now', $report['top_candidate']['lane']);
        $this->assertGreaterThan(
            $report['ranked_items'][1]['final_priority_score'],
            $report['ranked_items'][0]['final_priority_score'],
        );
    }

    public function test_penalizes_main_dirty_or_provider_without_sandbox(): void
    {
        $report = $this->service()->rank([
            'candidates' => [
                [
                    'id' => 'provider_no_sandbox',
                    'title' => 'Provider patch directly on main',
                    'type' => 'provider_execution',
                    'requires_main_dirty' => true,
                    'requires_provider_without_sandbox' => true,
                    'sensitive_data' => false,
                ],
                [
                    'id' => 'safe_docs_test',
                    'title' => 'Document and test existing owner boundary',
                    'type' => 'safety_robustness_unlock',
                    'changed_files' => ['docs/ap/AP-785-stewardship-priority-engine-contract.md', 'tests/Unit/Ai/FooTest.php'],
                    'evidence_refs' => ['test_plan'],
                    'dependency_unlocks' => ['operator_review'],
                ],
            ],
        ]);

        $risky = $this->byId($report, 'provider_no_sandbox');

        $this->assertSame('blocked', $risky['lane']);
        $this->assertGreaterThanOrEqual(60, $risky['risk_penalty']);
        $this->assertContains('main_dirty_required', $risky['reason_machine']);
        $this->assertContains('provider_without_sandbox', $risky['reason_machine']);
        $this->assertSame('safe_docs_test', $report['top_candidate']['item_id']);
    }

    public function test_promotes_safety_and_robustness_unlock(): void
    {
        $report = $this->service()->rank([
            'candidates' => [
                [
                    'id' => 'robustness_unlock',
                    'title' => 'Add branch collision gate and regression tests',
                    'type' => 'safety_robustness_unlock',
                    'evidence_refs' => ['unit_test', 'branch_cert'],
                    'dependency_unlocks' => ['24h_scheduler', 'merge_queue', 'operator_confidence'],
                    'operator_touchpoints_reduced' => 3,
                    'changed_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Gate.php', 'tests/Unit/Ai/GateTest.php'],
                ],
                [
                    'id' => 'later_provider_routing',
                    'title' => 'Provider routing Opus Sonnet Gemini Codex',
                    'type' => 'provider_routing',
                    'requires_owner_runtime_boundary' => true,
                    'requires_provider_without_sandbox' => true,
                ],
            ],
        ]);

        $top = $report['top_candidate'];

        $this->assertSame('robustness_unlock', $top['item_id']);
        $this->assertSame('now', $top['lane']);
        $this->assertGreaterThanOrEqual(80, $top['robustness_score']);
        $this->assertGreaterThanOrEqual(60, $top['dependency_unlock_score']);
    }

    public function test_blocks_sensitive_item_without_gates(): void
    {
        $report = $this->service()->rank([
            'candidates' => [[
                'id' => 'sensitive_without_gates',
                'title' => 'Run data migration touching sensitive customer content',
                'type' => 'data_migration',
                'sensitive_data' => true,
                'required_gates' => [],
            ]],
        ]);

        $item = $report['top_candidate'];

        $this->assertSame('blocked', $item['lane']);
        $this->assertContains('sensitive_without_required_gates', $item['reason_machine']);
        $this->assertFalse($report['claim_policy']['provider_invoked']);
        $this->assertFalse($report['claim_policy']['mutates_target_repo']);
    }

    public function test_output_is_deterministic(): void
    {
        $input = [
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'candidates' => [
                ['id' => 'b', 'type' => 'ui_cosmetic'],
                ['id' => 'a', 'type' => 'safety_robustness_unlock', 'dependency_unlocks' => ['x']],
            ],
        ];

        $first = $this->service()->rank($input);
        $second = $this->service()->rank($input);

        $this->assertSame($first['priority_hash'], $second['priority_hash']);
        $this->assertSame($first['ranked_items'], $second['ranked_items']);
    }

    public function test_command_smoke_json_uses_canonical_seed_priorities(): void
    {
        $this->artisan('atlas:software-company-stewardship:priority-engine', [
            '--area' => 'agentic_engineering_os',
            '--focus' => 'dev_forge',
            '--json' => true,
        ])->assertExitCode(0);
    }

    public function test_canonical_seed_skips_already_completed_aps(): void
    {
        $report = $this->service()->rank([
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
        ]);

        $this->assertSame('provider_routing_after_owner_boundaries', $report['top_candidate']['item_id']);
        $this->assertSame('now', $report['top_candidate']['lane']);
        $this->assertSame('completed', $this->byId($report, 'AP-783')['lane']);
        $this->assertSame('completed', $this->byId($report, 'live_cycle_audit_truth_surface')['completion_status']);
        $this->assertSame('completed', $this->byId($report, 'owner_runtime_real_execution_bridge')['completion_status']);
        $this->assertSame('completed', $this->byId($report, 'continuous_24h_scheduler')['completion_status']);
        $this->assertSame('completed', $this->byId($report, 'product_mode_controls_receipts')['completion_status']);
        $this->assertSame('pending', $report['top_candidate']['completion_status']);
        $this->assertNotContains('owner_runtime_boundary_required_first', $report['top_candidate']['reason_machine']);
        $this->assertSame('pending', $this->byId($report, 'owner_senior_loop_repair_after_authority_blocker')['completion_status']);
        $this->assertSame('now', $this->byId($report, 'owner_senior_loop_repair_after_authority_blocker')['lane']);
    }

    public function test_factory_max_ranks_executable_candidate_above_docs_only(): void
    {
        $report = $this->service()->rank([
            'scope_profile' => StewardshipPriorityEngineService::SCOPE_FACTORY_MAX,
            'candidates' => [
                [
                    'finding_id' => 'afdf_docs',
                    'kind' => 'doc',
                    'title' => 'Docs only',
                    'affected_docs' => ['docs/ap/AP-786.md'],
                    'evidence_refs' => [],
                ],
                [
                    'finding_id' => 'afdf_good',
                    'kind' => 'test',
                    'title' => 'Harden AP-786 autonomous evolution loop',
                    'owner_candidate' => 'atlas_dev',
                    'factory_execution_ready' => true,
                    'roi_score' => 88,
                    'execution_readiness_score' => 90,
                    'factory_leverage_score' => 92,
                    'risk_penalty' => 8,
                    'allowed_files' => [
                        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
                        'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionServiceTest.php',
                    ],
                    'tests_required' => ['tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionServiceTest.php'],
                    'acceptance' => ['Focused test passes'],
                    'affected_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php'],
                ],
            ],
        ]);

        $this->assertSame('afdf_good', $report['top_candidate']['item_id']);
        $this->assertGreaterThan(
            $this->byId($report, 'afdf_docs')['final_priority_score'],
            $report['top_candidate']['final_priority_score'],
        );
        $this->assertSame('factory_max_rejects_low_leverage_doc_or_evidence_work', $this->byId($report, 'afdf_docs')['rejection_reason']);
    }

    public function test_factory_max_rejects_forge_without_live_authority(): void
    {
        $report = $this->service()->rank([
            'scope_profile' => StewardshipPriorityEngineService::SCOPE_FACTORY_MAX,
            'has_live_forge_authority' => false,
            'candidates' => [[
                'finding_id' => 'factory_max_forge_seed',
                'kind' => 'bug',
                'title' => 'Forge-only improvement',
                'owner_candidate' => 'forge',
                'factory_execution_ready' => true,
                'allowed_files' => ['app/Services/Ai/Programming/Forge/ForgeIntakeService.php'],
                'tests_required' => ['tests/Unit/Ai/Programming/Forge/ForgeIntakeServiceTest.php'],
                'affected_files' => ['app/Services/Ai/Programming/Forge/ForgeIntakeService.php'],
            ]],
        ]);

        $item = $report['top_candidate'];
        $this->assertSame('blocked', $item['lane']);
        $this->assertSame('factory_max_rejects_forge_without_live_authority', $item['rejection_reason']);
    }

    public function test_factory_max_exposes_audit_scores_on_ranked_item(): void
    {
        $report = $this->service()->rank([
            'scope_profile' => StewardshipPriorityEngineService::SCOPE_FACTORY_MAX,
            'candidates' => [[
                'finding_id' => 'afdf_scored',
                'kind' => 'test',
                'title' => 'Improve sandbox materializer',
                'owner_candidate' => 'atlas_dev',
                'factory_execution_ready' => true,
                'roi_score' => 81,
                'execution_readiness_score' => 79,
                'factory_leverage_score' => 85,
                'risk_penalty' => 10,
                'allowed_files' => [
                    'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializerService.php',
                    'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializerTest.php',
                ],
                'tests_required' => ['tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializerTest.php'],
                'affected_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializerService.php'],
            ]],
        ]);

        $item = $report['top_candidate'];
        foreach (['roi_score', 'execution_readiness_score', 'factory_leverage_score', 'risk_penalty', 'rejection_reason'] as $key) {
            $this->assertArrayHasKey($key, $item);
        }
        $this->assertSame(81, $item['roi_score']);
        $this->assertSame('', $item['rejection_reason']);
    }

    public function test_factory_max_prioritizes_forge_authority_readiness_unlock_above_read_model_test(): void
    {
        $report = $this->service()->rank([
            'scope_profile' => StewardshipPriorityEngineService::SCOPE_FACTORY_MAX,
            'has_live_forge_authority' => false,
            'candidates' => [
                [
                    'finding_id' => 'factory_max_ap786_read_model_test',
                    'kind' => 'test',
                    'title' => 'Add focused unit coverage for AP-786 session read model',
                    'owner_candidate' => 'atlas_dev',
                    'origin' => 'factory_max_seed',
                    'origin_type' => 'ap786_read_model_test',
                    'affected_files' => [
                        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionReadModelService.php',
                    ],
                    'spec_seed' => [
                        'tests_required' => [
                            'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionReadModelServiceTest.php',
                        ],
                    ],
                ],
                [
                    'finding_id' => 'factory_max_ap789_forge_authority_readiness',
                    'kind' => 'bug',
                    'title' => 'Improve AP-789 live authority readiness diagnostics',
                    'detail' => 'Make AP-789 live authority blockers more actionable so the 24h loop can graduate into real owner-runtime dispatch without fabricating authority.',
                    'owner_candidate' => 'atlas_dev',
                    'origin' => 'factory_max_seed',
                    'origin_type' => 'ap789_forge_authority_readiness',
                    'affected_files' => [
                        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeLiveAuthorityBootstrapService.php',
                    ],
                    'spec_seed' => [
                        'tests_required' => [
                            'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeLiveAuthorityBootstrapServiceTest.php',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('factory_max_ap789_forge_authority_readiness', $report['top_candidate']['item_id']);
        $this->assertGreaterThan(
            $this->byId($report, 'factory_max_ap786_read_model_test')['final_priority_score'],
            $report['top_candidate']['final_priority_score'],
        );
        $this->assertSame('', $report['top_candidate']['rejection_reason']);
    }

    public function test_factory_max_terminal_backlog_rebalance_keeps_executable_unlock_backlog_materializable(): void
    {
        $report = $this->service()->rank([
            'scope_profile' => StewardshipPriorityEngineService::SCOPE_FACTORY_MAX,
            'terminal_backlog_state_hash' => '931e47566754',
            'terminal_backlog_rejection_reasons' => [
                'review_locked_existing_branch',
                'terminal_locked_existing_failure',
                'terminal_unlock_candidate_locked',
                'duplicate_candidate_key_in_pass',
                'factory_max_rejects_forge_without_live_authority',
                'no_executable_candidates_after_selection_pass',
                'review_locked_existing_branch',
                'terminal_locked_existing_failure',
            ],
        ]);

        $materialization = $report['priority_backlog_materialization'];
        $this->assertTrue($materialization['terminal_backlog_rebalance']);
        $this->assertSame('931e47566754', $materialization['terminal_backlog_state_hash']);
        $this->assertGreaterThanOrEqual(2, $materialization['executable_unlock_count']);
        $this->assertContains('forge_authority', $materialization['executable_unlock_categories']);
        $this->assertContains('owner_runtime', $materialization['executable_unlock_categories']);
        $this->assertContains('provider_routing_after_owner_boundaries', $materialization['executable_unlock_ids']);
        $this->assertContains('owner_senior_loop_repair_after_authority_blocker', $materialization['executable_unlock_ids']);

        $forgeAuthority = $this->byId($report, 'provider_routing_after_owner_boundaries');
        $this->assertTrue($forgeAuthority['priority_backlog_materializable']);
        $this->assertSame('forge_authority', $forgeAuthority['priority_backlog_unlock_category']);
        $this->assertSame('now', $forgeAuthority['lane']);
        $this->assertTrue($forgeAuthority['factory_execution_ready']);
        $this->assertContains(
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeLiveAuthorityBootstrapService.php',
            $forgeAuthority['affected_files'],
        );
        $this->assertContains(
            'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeLiveAuthorityBootstrapServiceTest.php',
            $forgeAuthority['tests_required'],
        );
        $this->assertContains('terminal_backlog_rebalance', $forgeAuthority['reason_machine']);

        $ownerRepair = $this->byId($report, 'owner_senior_loop_repair_after_authority_blocker');
        $this->assertTrue($ownerRepair['priority_backlog_materializable']);
        $this->assertSame('now', $ownerRepair['lane']);
        $this->assertContains(
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutor.php',
            $ownerRepair['affected_files'],
        );
    }

    public function test_terminal_backlog_rebalance_ec7740946157_keeps_scheduler_merge_and_runtime_unlocks_executable(): void
    {
        $rejectionReasons = [
            'review_locked_existing_branch',
            'terminal_locked_existing_failure',
            'terminal_unlock_candidate_locked',
            'duplicate_candidate_key_in_pass',
            'factory_max_rejects_forge_without_live_authority',
            'no_executable_candidates_after_selection_pass',
            'review_locked_existing_branch',
            'terminal_locked_existing_failure',
        ];

        $report = $this->service()->rank([
            'scope_profile' => StewardshipPriorityEngineService::SCOPE_FACTORY_MAX,
            'terminal_backlog_state_hash' => 'ec7740946157',
            'terminal_backlog_rejection_reasons' => $rejectionReasons,
        ]);

        $materialization = $report['priority_backlog_materialization'];
        $this->assertTrue($materialization['terminal_backlog_rebalance']);
        $this->assertSame('ec7740946157', $materialization['terminal_backlog_state_hash']);
        $this->assertSame(8, $materialization['terminal_backlog_rejection_reason_count']);
        $this->assertGreaterThanOrEqual(4, $materialization['executable_unlock_count']);
        foreach (['owner_runtime', 'forge_authority', 'scheduler', 'merge'] as $category) {
            $this->assertContains($category, $materialization['executable_unlock_categories']);
        }

        $scheduler = $this->byId($report, 'continuous_24h_scheduler');
        $this->assertTrue($scheduler['priority_backlog_materializable']);
        $this->assertSame('scheduler', $scheduler['priority_backlog_unlock_category']);
        $this->assertSame('pending', $scheduler['completion_status']);
        $this->assertTrue($scheduler['priority_backlog_replenishment_anchor'] ?? false);
        $this->assertSame('now', $scheduler['lane']);
        $this->assertContains(
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php',
            $scheduler['affected_files'],
        );

        $merge = $this->byId($report, 'terminal_backlog_replenish_merge_queue');
        $this->assertTrue($merge['priority_backlog_materializable']);
        $this->assertSame('merge', $merge['priority_backlog_unlock_category']);
        $this->assertSame('now', $merge['lane']);
        $this->assertContains(
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeQueueService.php',
            $merge['affected_files'],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function byId(array $report, string $id): array
    {
        foreach ($report['ranked_items'] as $item) {
            if (($item['item_id'] ?? '') === $id) {
                return $item;
            }
        }

        $this->fail("Priority item {$id} not found.");
    }
}
