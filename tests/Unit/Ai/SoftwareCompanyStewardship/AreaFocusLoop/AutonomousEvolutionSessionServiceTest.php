<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDeepFindingEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Ap786RobustForgeQualityContractService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusCandidateQuarantineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowExecutor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowRunner;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchMergeGovernor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchMergeGovernorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipPriorityRanker;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultProjector;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AutonomousEvolutionSessionServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap786_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): AutonomousEvolutionSessionService
    {
        $service = app(AutonomousEvolutionSessionService::class);
        $service->setStorageDirForTesting($this->tmp.'/sessions');
        $quarantine = app(AreaFocusCandidateQuarantineService::class);
        $quarantine->setStorageRootForTesting($this->tmp.'/quarantine');
        $service->setCandidateQuarantineForTesting($quarantine);

        return $service;
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function finding(string $id, string $title, array $overrides = []): array
    {
        return array_merge([
            'finding_id' => $id,
            'finding_hash' => 'sha256:'.$id,
            'title' => $title,
            'detail' => $title.' detail',
            'why_it_matters' => 'Throughput matters.',
            'kind' => 'bug',
            'severity' => 'high',
            'owner_candidate' => 'atlas_dev',
            'affected_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php'],
            'affected_docs' => [],
            'evidence_refs' => ['expected_test:AutonomousEvolutionSessionServiceTest.php'],
            'origin_type' => 'runtime_gap',
            'auto_execution_allowed' => true,
            'operator_review_required' => false,
        ], $overrides);
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return array<string,mixed>
     */
    private function scan(array $findings): array
    {
        return [
            'findings' => $findings,
            'status' => 'ready',
        ];
    }

    /** @return array<string,true> */
    private function factoryMaxExhaustedSessionReviewLocked(): array
    {
        return [
            'factory_max_ap789_forge_topology_dispatch_readiness' => true,
            'factory_max_ap789_awis_workspace_handoff_readiness' => true,
            'factory_max_ap790_runtime_gap_matrix_ingestion' => true,
            'factory_max_ap789_forge_authority_readiness' => true,
            'factory_max_ap792_loop_certification_runtime_realness' => true,
            'factory_max_ap786_loop_hardening' => true,
            'factory_max_ap785_priority_power' => true,
            'factory_max_ap748_deep_scan_power' => true,
            'factory_max_ap717_missing_test_precision' => true,
            'factory_max_ap756_sandbox_throughput' => true,
            'factory_max_ap769_merge_throughput' => true,
            'factory_max_cursor_driver_reliability' => true,
            'factory_max_ap786_owner_failure_specificity' => true,
            'factory_max_ap790_blocked_cycle_summary_test' => true,
            'factory_max_ap785_priority_state_test' => true,
            'factory_max_ap748_deep_scan_path_test' => true,
            'factory_max_ap786_read_model_test' => true,
            'factory_max_ap791_receipt_integrity_test' => true,
            'factory_max_ap716_area_focus_read_model_test' => true,
            'factory_max_ap790_blocked_cycle_mergeable_test' => true,
            'factory_max_ap748_interface_false_positive_test' => true,
            'factory_max_ap790_seen_finding_resume_test' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function materializedSandbox(): array
    {
        $worktree = $this->tmp.'/worktree';
        $target = $worktree.'/app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php';
        File::ensureDirectoryExists(dirname($target));
        if (! is_dir($worktree.'/.git')) {
            $this->runGit(['git', 'init'], $worktree);
            $this->runGit(['git', 'config', 'user.email', 'atlas@example.test'], $worktree);
            $this->runGit(['git', 'config', 'user.name', 'Atlas Test'], $worktree);
            file_put_contents($target, "<?php\n// baseline\n");
            $this->runGit(['git', 'add', 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php'], $worktree);
            $this->runGit(['git', 'commit', '-m', 'init'], $worktree);
            $this->runGit(['git', 'branch', '-M', 'atlas/area-focus/agentic_engineering_os/atlas_dev/test'], $worktree);
        }
        file_put_contents($target, "// owner-flow change\n", FILE_APPEND);

        return [
            'status' => AreaFocusBranchSandboxMaterializerService::STATUS_MATERIALIZED,
            'sandbox_id' => 'afsb_test',
            'materialization' => [
                'worktree_path' => $worktree,
                'branch_name' => 'atlas/area-focus/agentic_engineering_os/atlas_dev/test',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function ownerFlowReport(bool $mergeAllowed): array
    {
        return [
            'status' => Ap786OwnerFlowExecutor::STATUS_COMPLETED,
            'uses_full_owner_runtime_chain' => true,
            'provider_router_used' => false,
            'merge_allowed' => $mergeAllowed,
            'consumption_id' => 'afcons_test',
            'release_id' => 'afrel_test',
            'queue_item_id' => 'afq_test',
            'owner_execution_id' => 'afexec_test',
            'owner_sandbox_run_id' => 'afrun_test',
            'owner_result' => ['result_id' => 'afrunres_test', 'result_status' => 'completed'],
            'result_bridge' => ['status' => 'ready_for_operator_result_review', 'result_bridge_id' => 'afobr_test'],
            'result_bridge_id' => 'afobr_test',
            'execution_result' => [
                'result_status' => 'completed',
                'summary' => 'Atlas Dev senior loop completed in the AP-756 worktree.',
                'changed_files' => ['app/Services/Ai/Example.php'],
                'tests' => ['php artisan test --filter=Example'],
            ],
            'steps' => [],
            'blockers' => [],
        ];
    }

    public function test_dry_run_does_not_call_provider_or_merge_governor(): void
    {
        $finding = $this->finding('afdf_dry', 'Dry-run candidate');

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($finding): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$finding]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock) use ($finding): void {
            $mock->shouldReceive('rank')->once()->andReturn([
                'top_candidate' => ['candidate_id' => 'afdf_dry'],
            ]);
        });
        $this->mock(AtlasForgeProviderInvocationDriverRouter::class)->shouldNotReceive('driverInvoke');
        $this->mock(StewardshipBranchMergeGovernor::class)->shouldNotReceive('evaluate');

        $payload = $this->service()->run([
            'execute' => false,
            'cycles' => 1,
        ]);

        $this->assertSame(AutonomousEvolutionSessionService::STATUS_DRY_RUN, $payload['status']);
        $this->assertSame('dry_run_planned', $payload['cycles'][0]['final_status']);
        $this->assertFalse($payload['claim_policy']['provider_called']);
    }

    public function test_rejects_deep_scan_findings_that_require_operator_review(): void
    {
        $reviewOnly = $this->finding('afdf_review', 'Operator review first', [
            'auto_execution_allowed' => false,
            'operator_review_required' => true,
        ]);
        $runnable = $this->finding('afdf_runnable', 'Autonomous-ready candidate');

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($reviewOnly, $runnable): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$reviewOnly, $runnable]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock) use ($runnable): void {
            $mock->shouldReceive('rank')->once()->andReturn([
                'top_candidate' => ['candidate_id' => 'afdf_runnable'],
            ]);
        });
        $payload = $this->service()->run([
            'execute' => false,
            'cycles' => 1,
        ]);

        $this->assertSame('dry_run_planned', $payload['cycles'][0]['final_status']);
        $this->assertSame('afdf_runnable', $payload['cycles'][0]['selected_finding']['finding_id']);
        $reasons = array_column($payload['cycles'][0]['selection_rejections'] ?? [], 'reason');
        $this->assertContains('auto_execution_not_allowed', $reasons);
    }

    public function test_factory_max_rejects_docs_only_candidate(): void
    {
        $docsOnly = $this->finding('afdf_docs', 'Docs only', [
            'affected_files' => [],
            'affected_docs' => ['docs/ap/AP-786-autonomous-evolution-session-contract.md'],
            'evidence_refs' => [],
            'origin_type' => 'docs_stale',
        ]);

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($docsOnly): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$docsOnly]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->andReturn([
                'top_candidate' => ['candidate_id' => 'factory_max_ap786_loop_hardening'],
            ]);
        });

        $payload = $this->service()->run([
            'execute' => false,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'cycles' => 1,
        ]);

        $this->assertSame('dry_run_planned', $payload['cycles'][0]['final_status']);
        $this->assertSame('factory_max_ap786_loop_hardening', $payload['cycles'][0]['selected_finding']['finding_id']);
        $reasons = array_column($payload['cycles'][0]['selection_rejections'] ?? [], 'reason');
        $this->assertContains('factory_max_rejects_low_leverage_doc_or_evidence_work', $reasons);
    }

    public function test_factory_max_rejects_candidate_when_runtime_source_does_not_exist(): void
    {
        $missingSource = $this->finding('afdf_missing_source', 'Missing runtime source', [
            'affected_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/DefinitelyMissingRuntime.php'],
            'evidence_refs' => ['expected_test:DefinitelyMissingRuntimeTest.php'],
        ]);
        $valid = $this->finding('afdf_valid_source', 'Valid runtime source', [
            'severity' => 'medium',
            'affected_files' => [
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionServiceTest.php',
            ],
        ]);

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($missingSource, $valid): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$missingSource, $valid]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock) use ($valid): void {
            $mock->shouldReceive('rank')->once()->andReturn([
                'top_candidate' => ['candidate_id' => 'afdf_valid_source'],
            ]);
        });

        $payload = $this->service()->run([
            'execute' => false,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'cycles' => 1,
        ]);

        $this->assertSame('afdf_valid_source', $payload['cycles'][0]['selected_finding']['finding_id']);
        $reasonsById = [];
        foreach ($payload['cycles'][0]['selection_rejections'] ?? [] as $rejection) {
            $reasonsById[(string) ($rejection['finding_id'] ?? '')] = (string) ($rejection['reason'] ?? '');
        }
        $this->assertSame('factory_max_rejects_missing_runtime_source', $reasonsById['afdf_missing_source'] ?? null);
    }

    public function test_factory_max_rejects_routine_missing_test_findings(): void
    {
        $missingTest = $this->finding('afdf_missing_test', 'Missing test for AreaFocusBranchSandboxMaterializer', [
            'kind' => 'test',
            'severity' => 'medium',
            'origin' => 'structural_ap717',
            'origin_type' => 'missing_test',
            'in_focus' => true,
            'auto_execution_allowed' => false,
            'operator_review_required' => true,
            'affected_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializer.php'],
            'affected_docs' => [],
            'evidence_refs' => ['expected_test:AreaFocusBranchSandboxMaterializerTest.php'],
            'spec_seed' => [
                'proposal_only' => true,
                'operator_review_required' => true,
            ],
        ]);

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($missingTest): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$missingTest]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->withArgs(function (array $input): bool {
                $ids = array_map(static fn (array $candidate): string => (string) ($candidate['finding_id'] ?? ''), $input['candidates'] ?? []);

                return ! in_array('afdf_missing_test', $ids, true)
                    && in_array('factory_max_ap790_runtime_gap_matrix_ingestion', $ids, true);
            })->andReturn([
                'top_candidate' => ['candidate_id' => 'factory_max_ap790_runtime_gap_matrix_ingestion'],
            ]);
        });

        $payload = $this->service()->run([
            'execute' => false,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'cycles' => 1,
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertSame('dry_run_planned', $cycle['final_status'], json_encode($cycle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertSame('factory_max_ap790_runtime_gap_matrix_ingestion', $cycle['selected_finding']['finding_id']);

        $reasonsById = [];
        foreach ($cycle['selection_rejections'] ?? [] as $rejection) {
            $reasonsById[(string) ($rejection['finding_id'] ?? '')] = (string) ($rejection['reason'] ?? '');
        }
        $this->assertSame('factory_max_rejects_routine_missing_test_work', $reasonsById['afdf_missing_test'] ?? null);
    }

    public function test_factory_max_strategic_seed_competes_with_deep_scan_maintenance_findings(): void
    {
        $maintenance = $this->finding('afdf_missing_test', 'Missing test for AreaFocusBranchSandboxMaterializer', [
            'kind' => 'test',
            'severity' => 'medium',
            'origin' => 'structural_ap717',
            'origin_type' => 'missing_test',
            'in_focus' => true,
            'auto_execution_allowed' => false,
            'operator_review_required' => true,
            'affected_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializer.php'],
            'affected_docs' => [],
            'evidence_refs' => ['expected_test:AreaFocusBranchSandboxMaterializerTest.php'],
        ]);

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($maintenance): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$maintenance]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->withArgs(function (array $input): bool {
                $ids = array_map(static fn (array $candidate): string => (string) ($candidate['finding_id'] ?? ''), $input['candidates'] ?? []);

                return ! in_array('afdf_missing_test', $ids, true)
                    && in_array('factory_max_ap786_loop_hardening', $ids, true);
            })->andReturn([
                'top_candidate' => ['candidate_id' => 'factory_max_ap786_loop_hardening'],
            ]);
        });

        $payload = $this->service()->run([
            'execute' => false,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'cycles' => 1,
        ]);

        $this->assertSame('factory_max_ap786_loop_hardening', $payload['cycles'][0]['selected_finding']['finding_id']);
    }

    public function test_factory_max_stops_promoting_missing_test_maintenance_after_recent_budget(): void
    {
        File::ensureDirectoryExists($this->tmp.'/sessions');
        $records = [];
        for ($i = 0; $i < 4; $i++) {
            $records[] = json_encode([
                'schema_version' => AutonomousEvolutionSessionService::RECORD_SCHEMA,
                'cycles' => [[
                    'final_status' => 'cycle_completed',
                    'selected_finding' => [
                        'finding_id' => 'maintenance_'.$i,
                        'title' => 'Missing test for MaintenanceService'.$i,
                    ],
                    'blockers' => [],
                ]],
            ], JSON_UNESCAPED_SLASHES);
        }
        for ($i = 0; $i < 8; $i++) {
            $records[] = json_encode([
                'schema_version' => AutonomousEvolutionSessionService::RECORD_SCHEMA,
                'cycles' => [[
                    'final_status' => 'dry_run_planned',
                    'selected_finding' => [
                        'finding_id' => 'dry_run_projection_'.$i,
                        'title' => 'Missing test for DryRunProjection'.$i,
                    ],
                    'blockers' => [],
                ]],
            ], JSON_UNESCAPED_SLASHES);
        }
        File::put($this->tmp.'/sessions/agentic_engineering_os.jsonl', implode(PHP_EOL, $records).PHP_EOL);

        $maintenance = $this->finding('afdf_missing_test', 'Missing test for AreaFocusBranchSandboxMaterializer', [
            'kind' => 'test',
            'severity' => 'medium',
            'origin' => 'structural_ap717',
            'origin_type' => 'missing_test',
            'in_focus' => true,
            'auto_execution_allowed' => false,
            'operator_review_required' => true,
            'affected_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializer.php'],
            'affected_docs' => [],
            'evidence_refs' => ['expected_test:AreaFocusBranchSandboxMaterializerTest.php'],
        ]);

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($maintenance): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$maintenance]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->withArgs(function (array $input): bool {
                $ids = array_map(static fn (array $candidate): string => (string) ($candidate['finding_id'] ?? ''), $input['candidates'] ?? []);

                return ! in_array('afdf_missing_test', $ids, true)
                    && in_array('factory_max_ap790_runtime_gap_matrix_ingestion', $ids, true);
            })->andReturn([
                'top_candidate' => ['candidate_id' => 'factory_max_ap790_runtime_gap_matrix_ingestion'],
            ]);
        });

        $payload = $this->service()->run([
            'execute' => false,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'cycles' => 1,
        ]);

        $this->assertSame('factory_max_ap790_runtime_gap_matrix_ingestion', $payload['cycles'][0]['selected_finding']['finding_id']);
        $reasonsById = [];
        foreach ($payload['cycles'][0]['selection_rejections'] ?? [] as $rejection) {
            $reasonsById[(string) ($rejection['finding_id'] ?? '')] = (string) ($rejection['reason'] ?? '');
        }
        $this->assertSame('factory_max_rejects_maintenance_after_budget', $reasonsById['afdf_missing_test'] ?? null);
    }

    public function test_factory_max_rejects_forge_seed_without_live_forge_authority(): void
    {
        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->andReturn([
                'top_candidate' => ['candidate_id' => 'factory_max_ap785_priority_power'],
            ]);
        });

        $payload = $this->service()->run([
            'execute' => false,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'cycles' => 1,
        ]);

        $this->assertSame('factory_max_ap789_forge_topology_dispatch_readiness', $payload['cycles'][0]['selected_finding']['finding_id']);
        $reasonsById = [];
        foreach ($payload['cycles'][0]['selection_rejections'] ?? [] as $rejection) {
            $reasonsById[(string) ($rejection['finding_id'] ?? '')] = (string) ($rejection['reason'] ?? '');
        }
        $this->assertSame('factory_max_rejects_forge_without_live_authority', $reasonsById['factory_max_ap785_priority_power'] ?? null);
    }

    public function test_factory_max_materializes_priority_backlog_when_static_candidates_are_exhausted(): void
    {
        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->andReturn($this->priorityBacklogReport([
                'owner_runtime_real_execution_bridge',
                'continuous_24h_scheduler',
                'product_mode_controls_receipts',
            ]));
        });

        $payload = $this->service()->run([
            'execute' => false,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'cycles' => 1,
            'session_review_locked' => $this->factoryMaxExhaustedSessionReviewLocked(),
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertSame('dry_run_planned', $cycle['final_status'], json_encode($cycle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertSame(
            'factory_max_ap790_priority_owner_runtime_real_execution_bridge',
            $cycle['selected_finding']['finding_id'],
        );
        $this->assertSame(
            'owner_runtime_real_execution_bridge',
            $cycle['priority_report']['top_candidate']['candidate_id'],
        );
    }

    public function test_factory_max_materializes_next_priority_backlog_when_first_backlog_item_is_locked(): void
    {
        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->andReturn($this->priorityBacklogReport([
                'owner_runtime_real_execution_bridge',
                'continuous_24h_scheduler',
                'product_mode_controls_receipts',
            ]));
        });

        $payload = $this->service()->run([
            'execute' => false,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'cycles' => 1,
            'session_review_locked' => $this->factoryMaxExhaustedSessionReviewLocked() + [
                'factory_max_ap790_priority_owner_runtime_real_execution_bridge' => true,
            ],
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertSame('dry_run_planned', $cycle['final_status'], json_encode($cycle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertSame(
            'factory_max_ap790_priority_continuous_24h_scheduler',
            $cycle['selected_finding']['finding_id'],
        );
        $reasonsById = [];
        foreach ($cycle['selection_rejections'] ?? [] as $rejection) {
            $reasonsById[(string) ($rejection['finding_id'] ?? '')] = (string) ($rejection['reason'] ?? '');
        }
        $this->assertSame(
            'review_locked_existing_branch',
            $reasonsById['factory_max_ap790_priority_owner_runtime_real_execution_bridge'] ?? null,
        );
    }

    public function test_factory_max_materializes_product_mode_backlog_after_runtime_and_scheduler_are_locked(): void
    {
        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->andReturn($this->priorityBacklogReport([
                'owner_runtime_real_execution_bridge',
                'continuous_24h_scheduler',
                'product_mode_controls_receipts',
            ]));
        });

        $payload = $this->service()->run([
            'execute' => false,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'cycles' => 1,
            'session_review_locked' => $this->factoryMaxExhaustedSessionReviewLocked() + [
                'factory_max_ap790_priority_owner_runtime_real_execution_bridge' => true,
                'factory_max_ap790_priority_continuous_24h_scheduler' => true,
            ],
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertSame('dry_run_planned', $cycle['final_status'], json_encode($cycle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertSame(
            'factory_max_ap790_priority_product_mode_controls_receipts',
            $cycle['selected_finding']['finding_id'],
        );
    }

    public function test_factory_max_materializes_provider_routing_authority_backlog_after_foundation_backlog_completed(): void
    {
        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->andReturn($this->priorityBacklogReport([
                'provider_routing_after_owner_boundaries',
            ]));
        });

        $payload = $this->service()->run([
            'execute' => false,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'cycles' => 1,
            'session_review_locked' => $this->factoryMaxExhaustedSessionReviewLocked(),
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertSame('dry_run_planned', $cycle['final_status'], json_encode($cycle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertSame(
            'factory_max_ap789_provider_routing_authority_bridge',
            $cycle['selected_finding']['finding_id'],
        );
        $this->assertSame(
            'provider_routing_after_owner_boundaries',
            $cycle['priority_report']['top_candidate']['candidate_id'],
        );
    }

    public function test_factory_max_materializes_owner_senior_loop_repair_after_authority_candidate_is_locked(): void
    {
        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->andReturn($this->priorityBacklogReport([
                'provider_routing_after_owner_boundaries',
                'owner_senior_loop_repair_after_authority_blocker',
            ]));
        });

        $payload = $this->service()->run([
            'execute' => false,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'cycles' => 1,
            'session_review_locked' => $this->factoryMaxExhaustedSessionReviewLocked() + [
                'factory_max_ap789_provider_routing_authority_bridge' => true,
            ],
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertSame('dry_run_planned', $cycle['final_status'], json_encode($cycle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertSame(
            'factory_max_ap786_owner_senior_loop_repair_after_authority_blocker',
            $cycle['selected_finding']['finding_id'],
        );
        $reasonsById = [];
        foreach ($cycle['selection_rejections'] ?? [] as $rejection) {
            $reasonsById[(string) ($rejection['finding_id'] ?? '')] = (string) ($rejection['reason'] ?? '');
        }
        $this->assertSame(
            'review_locked_existing_branch',
            $reasonsById['factory_max_ap789_provider_routing_authority_bridge'] ?? null,
        );
    }

    public function test_factory_max_rejects_atlas_dev_candidate_with_forge_leak_without_live_authority(): void
    {
        $crossRuntime = $this->finding('afdf_cross_runtime_evidence', 'Unify Dev, Forge and Stewardship evidence refs for replay', [
            'kind' => 'bug',
            'severity' => 'high',
            'origin_type' => 'runtime_gap',
            'owner_candidate' => 'atlas_dev',
            'affected_files' => [
                'app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerRuntimeResultBridgeService.php',
                'tests/Unit/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerRuntimeResultBridgeServiceTest.php',
            ],
            'detail' => 'Bridge local Dev receipts, Forge evidence and Stewardship cycle receipts into replayable evidence refs.',
            'why_it_matters' => 'Atlas Dev would reject this prompt before provider invocation because Forge topology language leaks into the prompt.',
            'evidence_refs' => ['expected_test:StewardshipOwnerRuntimeResultBridgeServiceTest.php'],
            'auto_execution_allowed' => true,
        ]);

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($crossRuntime): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$crossRuntime]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->withArgs(function (array $input): bool {
                $ids = array_map(static fn (array $candidate): string => (string) ($candidate['finding_id'] ?? ''), $input['candidates'] ?? []);

                return ! in_array('afdf_cross_runtime_evidence', $ids, true)
                    && in_array('factory_max_ap790_runtime_gap_matrix_ingestion', $ids, true);
            })->andReturn([
                'top_candidate' => ['candidate_id' => 'factory_max_ap790_runtime_gap_matrix_ingestion'],
            ]);
        });

        $payload = $this->service()->run([
            'execute' => false,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'cycles' => 1,
        ]);

        $this->assertSame('factory_max_ap790_runtime_gap_matrix_ingestion', $payload['cycles'][0]['selected_finding']['finding_id']);
        $reasonsById = [];
        foreach ($payload['cycles'][0]['selection_rejections'] ?? [] as $rejection) {
            $reasonsById[(string) ($rejection['finding_id'] ?? '')] = (string) ($rejection['reason'] ?? '');
        }
        $this->assertSame('factory_max_rejects_atlas_dev_topology_leak_without_authority', $reasonsById['afdf_cross_runtime_evidence'] ?? null);
    }

    public function test_factory_max_allows_forge_authority_readiness_seed_without_live_authority(): void
    {
        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->withArgs(function (array $input): bool {
                $ids = array_map(static fn (array $candidate): string => (string) ($candidate['finding_id'] ?? ''), $input['candidates'] ?? []);

                return in_array('factory_max_ap789_forge_authority_readiness', $ids, true)
                    && ($input['has_live_forge_authority'] ?? true) === false;
            })->andReturn([
                'top_candidate' => ['candidate_id' => 'factory_max_ap789_forge_authority_readiness'],
            ]);
        });

        $payload = $this->service()->run([
            'execute' => false,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'cycles' => 1,
        ]);

        $this->assertSame('factory_max_ap789_forge_authority_readiness', $payload['cycles'][0]['selected_finding']['finding_id']);
        $this->assertStringNotContainsString('Obra', json_encode($payload['cycles'][0]['selected_finding'], JSON_UNESCAPED_SLASHES));
        $reasonsById = [];
        foreach ($payload['cycles'][0]['selection_rejections'] ?? [] as $rejection) {
            $reasonsById[(string) ($rejection['finding_id'] ?? '')] = (string) ($rejection['reason'] ?? '');
        }
        $this->assertArrayNotHasKey('factory_max_ap789_forge_authority_readiness', $reasonsById);
    }

    public function test_factory_max_rejects_high_risk_deep_finding_without_forge_authority(): void
    {
        $highRisk = $this->finding('afdf_apcr_every_mutation', 'Require APCR plus Software Twin before every code mutation', [
            'kind' => 'runtime',
            'severity' => 'high',
            'origin' => 'structural_ap717',
            'origin_type' => 'runtime_gap',
            'owner_candidate' => 'atlas_dev',
            'affected_files' => [
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionServiceTest.php',
            ],
            'detail' => 'This deep scan item is broad enough that Atlas Dev may promote it to a heavier owner intake.',
            'auto_execution_allowed' => true,
        ]);

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($highRisk): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$highRisk]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->withArgs(function (array $input): bool {
                $ids = array_map(static fn (array $candidate): string => (string) ($candidate['finding_id'] ?? ''), $input['candidates'] ?? []);

                return ! in_array('afdf_apcr_every_mutation', $ids, true)
                    && in_array('factory_max_ap790_runtime_gap_matrix_ingestion', $ids, true);
            })->andReturn([
                'top_candidate' => ['candidate_id' => 'factory_max_ap790_runtime_gap_matrix_ingestion'],
            ]);
        });

        $payload = $this->service()->run([
            'execute' => false,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'cycles' => 1,
        ]);

        $this->assertSame('factory_max_ap790_runtime_gap_matrix_ingestion', $payload['cycles'][0]['selected_finding']['finding_id']);
        $reasonsById = [];
        foreach ($payload['cycles'][0]['selection_rejections'] ?? [] as $rejection) {
            $reasonsById[(string) ($rejection['finding_id'] ?? '')] = (string) ($rejection['reason'] ?? '');
        }
        $this->assertSame('factory_max_rejects_high_risk_deep_finding_without_forge_authority', $reasonsById['afdf_apcr_every_mutation'] ?? null);
    }

    public function test_factory_max_rejects_non_factory_scope_atlas_dev_candidate_without_automerge_authority(): void
    {
        $nonFactoryScope = $this->finding('afdf_runbook_redesign', 'Let Atlas propose structural redesigns of its own engineering runtime', [
            'kind' => 'runtime',
            'severity' => 'medium',
            'origin' => 'structural_ap717',
            'origin_type' => 'runtime_gap',
            'owner_candidate' => 'atlas_dev',
            'affected_files' => [
                'app/Services/Ai/AgenticEngineeringOs/RunbookOrchestrator.php',
                'tests/Unit/Ai/AgenticEngineeringOs/RunbookOrchestratorTest.php',
            ],
            'detail' => 'Valid work, but current autonomous auto-merge authority is limited to AreaFocusLoop runtime patches.',
            'auto_execution_allowed' => true,
        ]);

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($nonFactoryScope): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$nonFactoryScope]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->withArgs(function (array $input): bool {
                $ids = array_map(static fn (array $candidate): string => (string) ($candidate['finding_id'] ?? ''), $input['candidates'] ?? []);

                return ! in_array('afdf_runbook_redesign', $ids, true)
                    && in_array('factory_max_ap790_runtime_gap_matrix_ingestion', $ids, true);
            })->andReturn([
                'top_candidate' => ['candidate_id' => 'factory_max_ap790_runtime_gap_matrix_ingestion'],
            ]);
        });

        $payload = $this->service()->run([
            'execute' => false,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'cycles' => 1,
        ]);

        $this->assertSame('factory_max_ap790_runtime_gap_matrix_ingestion', $payload['cycles'][0]['selected_finding']['finding_id']);
        $reasonsById = [];
        foreach ($payload['cycles'][0]['selection_rejections'] ?? [] as $rejection) {
            $reasonsById[(string) ($rejection['finding_id'] ?? '')] = (string) ($rejection['reason'] ?? '');
        }
        $this->assertSame('factory_max_rejects_non_factory_scope_without_automerge_authority', $reasonsById['afdf_runbook_redesign'] ?? null);
    }

    public function test_factory_max_rejects_benchmark_or_rivals_candidates_from_autonomous_loop(): void
    {
        $rivals = $this->finding('afdf_rivals_readiness', 'Missing test for ProgrammingRivalsReadinessService', [
            'kind' => 'test',
            'severity' => 'medium',
            'origin_type' => 'missing_test',
            'affected_files' => [
                'app/Services/Ai/Programming/ProgrammingRivalsReadinessService.php',
                'tests/Unit/Ai/Programming/ProgrammingRivalsReadinessServiceTest.php',
            ],
            'evidence_refs' => ['expected_test:ProgrammingRivalsReadinessServiceTest.php'],
            'owner_candidate' => 'atlas_dev',
            'auto_execution_allowed' => true,
        ]);
        $next = $this->finding('afdf_runtime_safe', 'Missing test for ProgrammingRetrievalEvaluator', [
            'kind' => 'test',
            'severity' => 'medium',
            'origin_type' => 'missing_test',
            'affected_files' => [
                'app/Services/Ai/Programming/ProgrammingRetrievalEvaluator.php',
                'tests/Unit/Ai/Programming/ProgrammingRetrievalEvaluatorTest.php',
            ],
            'evidence_refs' => ['expected_test:ProgrammingRetrievalEvaluatorTest.php'],
            'owner_candidate' => 'atlas_dev',
            'auto_execution_allowed' => true,
        ]);

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($rivals, $next): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$rivals, $next]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->withArgs(function (array $input): bool {
                $ids = array_map(static fn (array $candidate): string => (string) ($candidate['finding_id'] ?? ''), $input['candidates'] ?? []);

                return ! in_array('afdf_rivals_readiness', $ids, true)
                    && ! in_array('afdf_runtime_safe', $ids, true)
                    && in_array('factory_max_ap790_runtime_gap_matrix_ingestion', $ids, true);
            })->andReturn([
                'top_candidate' => ['candidate_id' => 'factory_max_ap790_runtime_gap_matrix_ingestion'],
            ]);
        });

        $payload = $this->service()->run([
            'execute' => false,
            'repo_root' => $this->tmp,
            'cycles' => 1,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
        ]);

        $this->assertSame('factory_max_ap790_runtime_gap_matrix_ingestion', $payload['cycles'][0]['selected_finding']['finding_id']);
        $reasonsById = [];
        foreach ($payload['cycles'][0]['selection_rejections'] ?? [] as $rejection) {
            $reasonsById[(string) ($rejection['finding_id'] ?? '')] = (string) ($rejection['reason'] ?? '');
        }
        $this->assertSame('factory_max_rejects_benchmark_or_rivals_work', $reasonsById['afdf_rivals_readiness'] ?? null);
        $this->assertSame('factory_max_rejects_routine_missing_test_work', $reasonsById['afdf_runtime_safe'] ?? null);
    }

    public function test_review_locks_wasted_provider_attempt_from_session_record(): void
    {
        $finding = $this->finding('factory_max_ap786_loop_hardening', 'Harden AP-786 loop');
        File::ensureDirectoryExists($this->tmp.'/sessions');
        $record = [
            'schema_version' => AutonomousEvolutionSessionService::RECORD_SCHEMA,
            'cycles' => [[
                'final_status' => 'blocked',
                'blockers' => ['provider_produced_no_changes'],
                'selected_finding' => [
                    'finding_id' => 'factory_max_ap786_loop_hardening',
                    'finding_hash' => 'sha256:factory_max_ap786_loop_hardening',
                    'title' => 'Harden AP-786 loop',
                ],
            ]],
        ];
        File::put(
            $this->tmp.'/sessions/agentic_engineering_os.jsonl',
            json_encode($record, JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        $alt = $this->finding('afdf_alt', 'Alternate runtime fix');

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($finding, $alt): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$finding, $alt]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock) use ($alt): void {
            $mock->shouldReceive('rank')->once()->andReturn([
                'top_candidate' => ['candidate_id' => 'afdf_alt'],
            ]);
        });

        $payload = $this->service()->run([
            'execute' => false,
            'repo_root' => $this->tmp,
            'cycles' => 1,
        ]);

        $this->assertSame('afdf_alt', $payload['cycles'][0]['selected_finding']['finding_id']);
        $reasons = array_column($payload['cycles'][0]['selection_rejections'] ?? [], 'reason');
        $this->assertContains('review_locked_existing_branch', $reasons);
    }

    public function test_review_locks_owner_runtime_no_patch_attempt_from_session_record(): void
    {
        $finding = $this->finding('factory_max_ap786_loop_hardening', 'Harden AP-786 loop');
        File::ensureDirectoryExists($this->tmp.'/sessions');
        File::put(
            $this->tmp.'/sessions/agentic_engineering_os.jsonl',
            json_encode([
                'schema_version' => AutonomousEvolutionSessionService::RECORD_SCHEMA,
                'cycles' => [[
                    'final_status' => 'cycle_completed_waiting_review_or_merge',
                    'blockers' => ['owner_runtime_no_patch_needed'],
                    'selected_finding' => [
                        'finding_id' => 'factory_max_ap786_loop_hardening',
                        'finding_hash' => 'sha256:factory_max_ap786_loop_hardening',
                        'title' => 'Harden AP-786 loop',
                    ],
                ]],
            ], JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        $alt = $this->finding('afdf_alt_after_owner_no_patch', 'Alternate after owner no patch', [
            'severity' => 'medium',
            'affected_files' => [
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionServiceTest.php',
            ],
        ]);
        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($finding, $alt): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$finding, $alt]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock) use ($alt): void {
            $mock->shouldReceive('rank')->once()->andReturn([
                'top_candidate' => ['candidate_id' => 'afdf_alt_after_owner_no_patch'],
            ]);
        });

        $payload = $this->service()->run([
            'execute' => false,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'repo_root' => $this->tmp,
            'cycles' => 1,
        ]);

        $this->assertSame('afdf_alt_after_owner_no_patch', $payload['cycles'][0]['selected_finding']['finding_id']);
        $reasons = array_column($payload['cycles'][0]['selection_rejections'] ?? [], 'reason');
        $this->assertContains('review_locked_existing_branch', $reasons);
    }

    public function test_expired_routing_quarantine_is_not_permanent_review_lock(): void
    {
        $finding = $this->finding('factory_max_ap789_forge_authority_readiness', 'Improve AP-789 live authority readiness diagnostics');
        File::ensureDirectoryExists($this->tmp.'/sessions');
        File::put(
            $this->tmp.'/sessions/agentic_engineering_os.jsonl',
            json_encode([
                'schema_version' => AutonomousEvolutionSessionService::RECORD_SCHEMA,
                'cycles' => [[
                    'final_status' => 'cycle_completed_waiting_review_or_merge',
                    'blockers' => ['owner_runtime_routing_not_executable'],
                    'selected_finding' => [
                        'finding_id' => 'factory_max_ap789_forge_authority_readiness',
                        'finding_hash' => 'sha256:factory_max_ap789_forge_authority_readiness',
                        'title' => 'Improve AP-789 live authority readiness diagnostics',
                    ],
                ]],
            ], JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($finding): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$finding]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->andReturn([
                'top_candidate' => ['candidate_id' => 'factory_max_ap789_forge_authority_readiness'],
            ]);
        });

        $payload = $this->service()->run([
            'execute' => false,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'repo_root' => $this->tmp,
            'cycles' => 1,
        ]);

        $this->assertSame('factory_max_ap789_forge_authority_readiness', $payload['cycles'][0]['selected_finding']['finding_id']);
        $reasons = array_column($payload['cycles'][0]['selection_rejections'] ?? [], 'reason');
        $this->assertNotContains('review_locked_existing_branch', $reasons);
    }

    public function test_post_provider_no_changes_skips_validation_and_merge(): void
    {
        $git = new Process(['git', '--version']);
        $git->run();
        if (! $git->isSuccessful()) {
            $this->markTestSkipped('git binary is required for AP-786 execute-path tests.');
        }

        $repo = $this->tmp.'/repo';
        File::ensureDirectoryExists($repo);
        $this->runGit(['git', 'init'], $repo);
        $this->runGit(['git', 'config', 'user.email', 'atlas@example.test'], $repo);
        $this->runGit(['git', 'config', 'user.name', 'Atlas Test'], $repo);
        file_put_contents($repo.'/README.md', "fixture\n");
        $this->runGit(['git', 'add', 'README.md'], $repo);
        $this->runGit(['git', 'commit', '-m', 'init'], $repo);
        $this->runGit(['git', 'branch', '-M', 'main'], $repo);

        $finding = $this->finding('afdf_exec', 'Execute-path candidate');

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($finding): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$finding]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock) use ($finding): void {
            $mock->shouldReceive('rank')->once()->andReturn([
                'top_candidate' => ['candidate_id' => 'afdf_exec'],
            ]);
        });
        $this->mock(AreaFocusBranchSandboxMaterializer::class, function ($mock) use ($repo): void {
            $mock->shouldReceive('materialize')->once()->andReturn([
                'status' => AreaFocusBranchSandboxMaterializerService::STATUS_MATERIALIZED,
                'sandbox_id' => 'afbs_test',
                'materialization' => [
                    'worktree_path' => $repo,
                    'branch_name' => 'atlas/area-focus/test-branch',
                ],
            ]);
        });
        $this->mock(AtlasForgeProviderInvocationDriverRouter::class, function ($mock): void {
            $mock->shouldReceive('driverInvoke')->once()->andReturn([
                'provider' => 'cursor_cli',
                'model' => 'composer-2.5-fast',
                'provider_called' => true,
                'blockers' => [],
            ]);
        });
        $this->mock(StewardshipRuntimeResultProjector::class)->shouldNotReceive('project');
        $this->mock(StewardshipBranchMergeGovernor::class)->shouldNotReceive('evaluate');

        $payload = $this->service()->run([
            'execute' => true,
            'allow_direct_provider_driver' => true,
            'repo_root' => $repo,
            'cycles' => 1,
            'validation_commands' => ['false'],
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertSame('blocked', $cycle['final_status']);
        $this->assertContains('provider_produced_no_changes', $cycle['blockers']);
        $this->assertTrue($cycle['validation_skipped']);
        $this->assertTrue($cycle['merge_skipped']);
    }

    public function test_validation_failure_skips_merge_and_result_bridge(): void
    {
        $git = new Process(['git', '--version']);
        $git->run();
        if (! $git->isSuccessful()) {
            $this->markTestSkipped('git binary is required for AP-786 execute-path tests.');
        }

        $repo = $this->tmp.'/repo_validation';
        File::ensureDirectoryExists($repo);
        $this->runGit(['git', 'init'], $repo);
        $this->runGit(['git', 'config', 'user.email', 'atlas@example.test'], $repo);
        $this->runGit(['git', 'config', 'user.name', 'Atlas Test'], $repo);
        $source = 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php';
        File::ensureDirectoryExists($repo.'/'.dirname($source));
        file_put_contents($repo.'/'.$source, "<?php\n// fixture\n");
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'init'], $repo);
        $this->runGit(['git', 'branch', '-M', 'main'], $repo);
        file_put_contents($repo.'/'.$source, "<?php\n// provider change\n");

        $finding = $this->finding('afdf_validation', 'Validation failure candidate');

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($finding): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$finding]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock) use ($finding): void {
            $mock->shouldReceive('rank')->once()->andReturn([
                'top_candidate' => ['candidate_id' => 'afdf_validation'],
            ]);
        });
        $this->mock(AreaFocusBranchSandboxMaterializer::class, function ($mock) use ($repo): void {
            $mock->shouldReceive('materialize')->once()->andReturn([
                'status' => AreaFocusBranchSandboxMaterializerService::STATUS_MATERIALIZED,
                'sandbox_id' => 'afbs_validation',
                'materialization' => [
                    'worktree_path' => $repo,
                    'branch_name' => 'atlas/area-focus/validation-branch',
                ],
            ]);
        });
        $this->mock(AtlasForgeProviderInvocationDriverRouter::class, function ($mock): void {
            $mock->shouldReceive('driverInvoke')->once()->andReturn([
                'provider' => 'cursor_cli',
                'model' => 'composer-2.5-fast',
                'provider_called' => true,
                'blockers' => [],
            ]);
        });
        $this->mock(StewardshipRuntimeResultProjector::class)->shouldNotReceive('project');
        $this->mock(StewardshipBranchMergeGovernor::class)->shouldNotReceive('evaluate');

        $payload = $this->service()->run([
            'execute' => true,
            'allow_direct_provider_driver' => true,
            'repo_root' => $repo,
            'cycles' => 1,
            'validation_commands' => ['false'],
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertSame('blocked', $cycle['final_status']);
        $this->assertContains('validation_failed', $cycle['blockers']);
        $this->assertSame('validation_failed', $cycle['post_execution_skip']);
        $this->assertTrue($cycle['merge_skipped']);
        $this->assertTrue($cycle['result_bridge_skipped']);
        $this->assertTrue($cycle['commit_skipped']);
    }

    public function test_execute_runs_full_owner_flow_by_default_and_never_calls_provider_router(): void
    {
        $finding = $this->finding('afdf_full_flow', 'Full owner flow by default');

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($finding): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$finding]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->andReturn([
                'top_candidate' => ['candidate_id' => 'afdf_full_flow'],
            ]);
        });
        $this->mock(AreaFocusBranchSandboxMaterializer::class, function ($mock): void {
            $mock->shouldReceive('materialize')->once()->andReturn($this->materializedSandbox());
        });
        // The real owner-runtime chain is exercised, NOT the provider driver router.
        $this->mock(AtlasForgeProviderInvocationDriverRouter::class)->shouldNotReceive('driverInvoke');
        $this->mock(Ap786OwnerFlowRunner::class, function ($mock): void {
            $mock->shouldReceive('execute')->once()->andReturn($this->ownerFlowReport(true));
        });
        $this->mock(StewardshipRuntimeResultProjector::class, function ($mock): void {
            $mock->shouldReceive('project')->once()->andReturn([
                'result_bridge_id' => 'srrb_full_flow',
                'inbox_item_id' => null,
            ]);
        });
        $this->mock(StewardshipBranchMergeGovernor::class, function ($mock): void {
            $mock->shouldReceive('evaluate')->once()->andReturn([
                'status' => 'blocked_pending_review',
                'blockers' => ['operator_review_required'],
            ]);
        });

        $payload = $this->service()->run([
            'execute' => true,
            'repo_root' => $this->tmp,
            'cycles' => 1,
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertSame('cycle_completed_waiting_review_or_merge', $cycle['final_status']);
        $this->assertTrue($cycle['flow_integrity_gate']['ok']);
        $this->assertTrue($cycle['flow_integrity_gate']['uses_full_owner_runtime_chain']);
        $this->assertFalse($cycle['flow_integrity_gate']['direct_provider_driver_path']);
        $this->assertSame('owner_flow_completed', $cycle['owner_flow']['status']);
        $this->assertTrue($cycle['owner_flow']['uses_full_owner_runtime_chain']);
        $this->assertFalse($cycle['owner_flow']['provider_router_used']);
        $this->assertFalse($cycle['provider_called']);
        $this->assertTrue($cycle['inbox_emitted_before_merge_attempt']);
        $this->assertFalse($payload['claim_policy']['direct_provider_driver_allowed']);
        $this->assertFalse($payload['claim_policy']['provider_called']);
    }

    public function test_owner_flow_receives_finding_focused_test_as_validation_command(): void
    {
        $focusedTest = 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionServiceTest.php';
        $finding = $this->finding('afdf_focused_test', 'Focused test contract', [
            'spec_seed' => [
                'candidate_id' => 'afdf_focused_test',
                'tests_required' => [$focusedTest],
                'acceptance' => ['The owner runtime receives the focused test as a real validation command.'],
            ],
        ]);
        $captured = [];

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($finding): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$finding]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->andReturn([
                'top_candidate' => ['candidate_id' => 'afdf_focused_test'],
            ]);
        });
        $this->mock(AreaFocusBranchSandboxMaterializer::class, function ($mock): void {
            $mock->shouldReceive('materialize')->once()->andReturn($this->materializedSandbox());
        });
        $this->mock(AtlasForgeProviderInvocationDriverRouter::class)->shouldNotReceive('driverInvoke');
        $this->mock(Ap786OwnerFlowRunner::class, function ($mock) use (&$captured): void {
            $mock->shouldReceive('execute')->once()->with(\Mockery::on(function (array $input) use (&$captured): bool {
                $captured = $input;

                return true;
            }))->andReturn($this->ownerFlowReport(true));
        });
        $this->mock(StewardshipRuntimeResultProjector::class, function ($mock): void {
            $mock->shouldReceive('project')->once()->andReturn([
                'result_bridge_id' => 'srrb_focused_test',
                'inbox_item_id' => null,
            ]);
        });
        $this->mock(StewardshipBranchMergeGovernor::class, function ($mock): void {
            $mock->shouldReceive('evaluate')->once()->andReturn([
                'status' => 'blocked_pending_review',
                'blockers' => ['operator_review_required'],
            ]);
        });

        $this->service()->run([
            'execute' => true,
            'repo_root' => $this->tmp,
            'cycles' => 1,
            'validation_commands' => ['git diff --check'],
        ]);

        $this->assertContains('git diff --check', $captured['validation_commands']);
        $this->assertContains('php artisan test '.$focusedTest, $captured['validation_commands']);
    }

    public function test_robust_flow_contract_blocks_before_sandbox_or_owner_execution(): void
    {
        $finding = $this->finding('afdf_robust_block', 'Missing robust contract', [
            'evidence_refs' => [],
            'spec_seed' => [
                'candidate_id' => 'spec_without_tests',
                'acceptance' => ['The robust contract must block this fixture before execution.'],
            ],
        ]);

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($finding): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$finding]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->andReturn([
                'top_candidate' => ['candidate_id' => 'afdf_robust_block'],
            ]);
        });
        $this->mock(AreaFocusBranchSandboxMaterializer::class)->shouldNotReceive('materialize');
        $this->mock(AtlasForgeProviderInvocationDriverRouter::class)->shouldNotReceive('driverInvoke');
        $this->mock(Ap786OwnerFlowRunner::class)->shouldNotReceive('execute');
        $this->mock(StewardshipBranchMergeGovernor::class)->shouldNotReceive('evaluate');

        $payload = $this->service()->run([
            'execute' => true,
            'repo_root' => $this->tmp,
            'cycles' => 1,
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertSame('blocked', $cycle['final_status']);
        $this->assertContains('missing_capability:tdd_test_contract', $cycle['blockers']);
        $this->assertSame(Ap786RobustForgeQualityContractService::STATUS_BLOCKED, $cycle['robust_flow_contract']['status']);
        $this->assertTrue($cycle['provider_skipped']);
        $this->assertTrue($cycle['sandbox_skipped']);
    }

    public function test_owner_flow_block_stops_before_merge(): void
    {
        $finding = $this->finding('afdf_owner_block', 'Owner flow blocks before merge');

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($finding): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$finding]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->andReturn([
                'top_candidate' => ['candidate_id' => 'afdf_owner_block'],
            ]);
        });
        $this->mock(AreaFocusBranchSandboxMaterializer::class, function ($mock): void {
            $mock->shouldReceive('materialize')->once()->andReturn($this->materializedSandbox());
        });
        $this->mock(AtlasForgeProviderInvocationDriverRouter::class)->shouldNotReceive('driverInvoke');
        $this->mock(Ap786OwnerFlowRunner::class, function ($mock): void {
            $mock->shouldReceive('execute')->once()->andReturn([
                'status' => Ap786OwnerFlowExecutor::STATUS_BLOCKED,
                'reason' => 'ap759_owner_command_failed',
                'blockers' => ['ap759_owner_command_failed'],
                'uses_full_owner_runtime_chain' => true,
                'provider_router_used' => false,
                'merge_allowed' => false,
            ]);
        });
        // A blocked owner flow must never reach AP-769/AP-774 merge governance.
        $this->mock(StewardshipBranchMergeGovernor::class)->shouldNotReceive('evaluate');

        $payload = $this->service()->run([
            'execute' => true,
            'repo_root' => $this->tmp,
            'cycles' => 1,
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertSame('blocked', $cycle['final_status']);
        $this->assertContains('ap759_owner_command_failed', $cycle['blockers']);
        $this->assertTrue($cycle['merge_skipped']);
        $this->assertFalse($cycle['provider_called']);
    }

    public function test_routes_owner_forge_through_owner_flow_and_holds_merge_when_planned(): void
    {
        $finding = $this->finding('afdf_forge', 'Forge owner work', ['owner_candidate' => 'forge']);

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($finding): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$finding]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->andReturn([
                'top_candidate' => ['candidate_id' => 'afdf_forge'],
            ]);
        });
        $this->mock(AreaFocusBranchSandboxMaterializer::class, function ($mock): void {
            $mock->shouldReceive('materialize')->once()->andReturn($this->materializedSandbox());
        });
        $this->mock(AtlasForgeProviderInvocationDriverRouter::class)->shouldNotReceive('driverInvoke');
        $this->mock(Ap786OwnerFlowRunner::class, function ($mock): void {
            $mock->shouldReceive('execute')->once()->andReturn([
                'status' => Ap786OwnerFlowExecutor::STATUS_FORGE_PLANNED,
                'owner' => 'forge',
                'uses_full_owner_runtime_chain' => true,
                'provider_router_used' => false,
                'merge_allowed' => false,
                'forge_planned' => true,
                'dispatch_kind' => 'forge_runtime_dispatch',
                'blockers' => ['forge_runtime_dispatch_planned_only'],
                'execution_result' => [
                    'result_status' => 'partial',
                    'summary' => 'Forge produced a governed dispatch plan; not an execution.',
                    'changed_files' => [],
                    'tests' => ['atlas:forge:runtime-dispatch (AP-759 owner command)'],
                ],
            ]);
        });
        $this->mock(StewardshipRuntimeResultProjector::class, function ($mock): void {
            $mock->shouldReceive('project')->once()->andReturn([
                'result_bridge_id' => 'srrb_forge',
                'inbox_item_id' => null,
            ]);
        });
        // A planned Forge dispatch must never reach AP-769/AP-774 merge governance.
        $this->mock(StewardshipBranchMergeGovernor::class)->shouldNotReceive('evaluate');

        $payload = $this->service()->run([
            'execute' => true,
            'repo_root' => $this->tmp,
            'cycles' => 1,
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertSame('forge', $cycle['owner']);
        $this->assertSame('cycle_completed_waiting_review_or_merge', $cycle['final_status']);
        $this->assertContains('forge_runtime_dispatch_planned_only', $cycle['blockers']);
        $this->assertFalse($cycle['merge_performed']);
        $this->assertFalse($cycle['provider_called']);
        $this->assertTrue($cycle['inbox_emitted_before_merge_attempt']);
    }

    public function test_review_locks_validation_failed_attempt_from_session_record(): void
    {
        $finding = $this->finding('factory_max_ap786_loop_hardening', 'Harden AP-786 loop');
        File::ensureDirectoryExists($this->tmp.'/sessions');
        $record = [
            'schema_version' => AutonomousEvolutionSessionService::RECORD_SCHEMA,
            'cycles' => [[
                'final_status' => 'blocked',
                'blockers' => ['validation_failed'],
                'selected_finding' => [
                    'finding_id' => 'factory_max_ap786_loop_hardening',
                    'finding_hash' => 'sha256:factory_max_ap786_loop_hardening',
                    'title' => 'Harden AP-786 loop',
                ],
            ]],
        ];
        File::put(
            $this->tmp.'/sessions/agentic_engineering_os.jsonl',
            json_encode($record, JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        $alt = $this->finding('afdf_alt_validation', 'Alternate after validation failure');

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($finding, $alt): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$finding, $alt]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock) use ($alt): void {
            $mock->shouldReceive('rank')->once()->andReturn([
                'top_candidate' => ['candidate_id' => 'afdf_alt_validation'],
            ]);
        });

        $payload = $this->service()->run([
            'execute' => false,
            'repo_root' => $this->tmp,
            'cycles' => 1,
        ]);

        $this->assertSame('afdf_alt_validation', $payload['cycles'][0]['selected_finding']['finding_id']);
        $reasons = array_column($payload['cycles'][0]['selection_rejections'] ?? [], 'reason');
        $this->assertContains('review_locked_existing_branch', $reasons);
    }

    public function test_completed_finding_from_record_is_not_selected_again_by_daemon(): void
    {
        $completed = $this->finding('factory_max_ap786_loop_hardening', 'Harden AP-786 loop');
        File::ensureDirectoryExists($this->tmp.'/sessions');
        File::put(
            $this->tmp.'/sessions/agentic_engineering_os.jsonl',
            json_encode([
                'schema_version' => AutonomousEvolutionSessionService::RECORD_SCHEMA,
                'cycles' => [[
                    'final_status' => 'cycle_completed',
                    'blockers' => [],
                    'selected_finding' => [
                        'finding_id' => 'factory_max_ap786_loop_hardening',
                        'finding_hash' => 'sha256:factory_max_ap786_loop_hardening',
                        'title' => 'Harden AP-786 loop',
                    ],
                ]],
            ], JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        $next = $this->finding('factory_max_ap785_priority_power', 'Improve AP-785 priority engine');

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($completed, $next): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$completed, $next]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock) use ($next): void {
            $mock->shouldReceive('rank')->once()->andReturn([
                'top_candidate' => ['candidate_id' => 'factory_max_ap785_priority_power'],
            ]);
        });

        $payload = $this->service()->run([
            'execute' => false,
            'repo_root' => $this->tmp,
            'cycles' => 1,
        ]);

        $this->assertSame('factory_max_ap785_priority_power', $payload['cycles'][0]['selected_finding']['finding_id']);
        $reasons = array_column($payload['cycles'][0]['selection_rejections'] ?? [], 'reason');
        $this->assertContains('review_locked_existing_branch', $reasons);
    }

    public function test_review_locked_history_is_streamed_for_large_session_ledgers(): void
    {
        File::ensureDirectoryExists($this->tmp.'/sessions');
        $path = $this->tmp.'/sessions/agentic_engineering_os.jsonl';
        $handle = fopen($path, 'wb');
        $this->assertIsResource($handle);
        for ($i = 0; $i < 3000; $i++) {
            fwrite($handle, json_encode([
                'schema_version' => AutonomousEvolutionSessionService::RECORD_SCHEMA,
                'cycles' => [[
                    'final_status' => 'cycle_completed',
                    'blockers' => [],
                    'selected_finding' => [
                        'finding_id' => 'completed_'.$i,
                        'finding_hash' => 'sha256:completed_'.$i,
                        'title' => 'Completed '.$i,
                    ],
                ]],
            ], JSON_UNESCAPED_SLASHES).PHP_EOL);
        }
        fwrite($handle, json_encode([
            'schema_version' => AutonomousEvolutionSessionService::RECORD_SCHEMA,
            'cycles' => [[
                'final_status' => 'cycle_completed',
                'blockers' => [],
                'selected_finding' => [
                    'finding_id' => 'factory_max_ap786_loop_hardening',
                    'finding_hash' => 'sha256:factory_max_ap786_loop_hardening',
                    'title' => 'Harden AP-786 loop',
                ],
            ]],
        ], JSON_UNESCAPED_SLASHES).PHP_EOL);
        fclose($handle);

        $locked = $this->finding('factory_max_ap786_loop_hardening', 'Harden AP-786 loop');
        $next = $this->finding('factory_max_ap785_priority_power', 'Improve AP-785 priority engine');

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($locked, $next): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$locked, $next]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock) use ($next): void {
            $mock->shouldReceive('rank')->once()->andReturn([
                'top_candidate' => ['candidate_id' => 'factory_max_ap785_priority_power'],
            ]);
        });

        $payload = $this->service()->run([
            'execute' => false,
            'repo_root' => $this->tmp,
            'cycles' => 1,
        ]);

        $this->assertSame('factory_max_ap785_priority_power', $payload['cycles'][0]['selected_finding']['finding_id']);
        $this->assertContains('review_locked_existing_branch', array_column($payload['cycles'][0]['selection_rejections'] ?? [], 'reason'));
    }

    public function test_session_review_locked_input_from_reliable_runner_skips_candidate(): void
    {
        $locked = $this->finding('factory_max_ap785_priority_power', 'Improve AP-785 priority engine');
        $next = $this->finding('factory_max_ap786_loop_hardening', 'Harden AP-786 loop');

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($locked, $next): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$locked, $next]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock) use ($next): void {
            $mock->shouldReceive('rank')->once()->andReturn([
                'top_candidate' => ['candidate_id' => 'factory_max_ap786_loop_hardening'],
            ]);
        });

        $payload = $this->service()->run([
            'execute' => false,
            'repo_root' => $this->tmp,
            'cycles' => 1,
            'session_review_locked' => [
                'factory_max_ap785_priority_power' => true,
                'sha256:factory_max_ap785_priority_power',
                'Improve AP-785 priority engine',
            ],
        ]);

        $this->assertSame('factory_max_ap786_loop_hardening', $payload['cycles'][0]['selected_finding']['finding_id']);
        $reasons = array_column($payload['cycles'][0]['selection_rejections'] ?? [], 'reason');
        $this->assertContains('review_locked_existing_branch', $reasons);
    }

    public function test_continue_on_blocked_stops_when_no_candidate_remains(): void
    {
        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->andReturn([
                'top_candidate' => null,
            ]);
        });

        $payload = $this->service()->run([
            'execute' => false,
            'cycles' => 3,
            'continue_on_blocked' => true,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_BALANCED,
        ]);

        $this->assertCount(1, $payload['cycles']);
        $this->assertContains('no_candidate_with_allowed_files', $payload['blockers']);
    }

    public function test_factory_max_empty_selection_creates_starvation_recovery_candidate(): void
    {
        $blockedHighValue = $this->finding('afdf_high_value_blocked', 'High value but authority-gated work', [
            'severity' => 'high',
            'origin' => 'runtime_gap_matrix',
            'origin_type' => 'runtime_gap',
        ]);

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($blockedHighValue): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$blockedHighValue]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->twice()->andReturn(
                ['top_candidate' => null],
                ['top_candidate' => ['candidate_id' => 'factory_max_ap790_candidate_starvation_recovery']],
            );
        });

        $payload = $this->service()->run([
            'execute' => false,
            'cycles' => 1,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'repo_root' => $this->tmp,
            'session_review_locked' => [
                'factory_max_ap789_forge_topology_dispatch_readiness' => true,
                'factory_max_ap789_awis_workspace_handoff_readiness' => true,
                'factory_max_ap790_runtime_gap_matrix_ingestion' => true,
                'factory_max_ap789_forge_authority_readiness' => true,
                'factory_max_ap792_loop_certification_runtime_realness' => true,
                'factory_max_ap786_loop_hardening' => true,
                'factory_max_ap785_priority_power' => true,
                'factory_max_ap748_deep_scan_power' => true,
                'factory_max_ap717_missing_test_precision' => true,
                'factory_max_ap756_sandbox_throughput' => true,
                'factory_max_ap769_merge_throughput' => true,
                'factory_max_cursor_driver_reliability' => true,
                'factory_max_ap786_owner_failure_specificity' => true,
                'factory_max_ap790_blocked_cycle_summary_test' => true,
                'factory_max_ap785_priority_state_test' => true,
                'factory_max_ap748_deep_scan_path_test' => true,
                'factory_max_ap786_read_model_test' => true,
                'factory_max_ap791_receipt_integrity_test' => true,
                'factory_max_ap716_area_focus_read_model_test' => true,
                'factory_max_ap790_blocked_cycle_mergeable_test' => true,
                'factory_max_ap748_interface_false_positive_test' => true,
                'factory_max_ap790_seen_finding_resume_test' => true,
            ],
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertSame('dry_run_planned', $cycle['final_status'], json_encode($cycle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertSame(
            true,
            str_starts_with(
                (string) $cycle['selected_finding']['finding_id'],
                AutonomousEvolutionSessionService::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID.'_',
            ),
        );
        $this->assertNotSame(
            AutonomousEvolutionSessionService::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID,
            $cycle['selected_finding']['finding_id'],
        );
        $this->assertNotSame('', (string) ($cycle['selected_finding']['starvation_state_hash'] ?? ''));
        $this->assertContains(
            'factory_max_rejects_high_risk_deep_finding_without_forge_authority',
            array_column($cycle['selection_rejections'] ?? [], 'reason'),
        );
        $refill = $cycle['selection_refill'] ?? null;
        $this->assertIsArray($refill);
        $this->assertSame('ap790_candidate_starvation_recovery', $refill['strategy'] ?? null);
        $this->assertSame(
            $cycle['selected_finding']['starvation_state_hash'] ?? null,
            $refill['starvation_state_hash'] ?? null,
        );
        $this->assertGreaterThanOrEqual(2, (int) ($refill['rejection_reason_count'] ?? 0));
        $this->assertStringContainsString(
            'bounded owner-runtime cycle',
            (string) ($refill['bounded_next_action'] ?? ''),
        );
        $this->assertStringContainsString(
            'Rejection reason count:',
            (string) ($payload['cycles'][0]['selected_finding']['why_it_matters'] ?? ''),
        );
    }

    public function test_factory_max_starvation_recovery_refills_after_wasted_cycle_review_lock(): void
    {
        File::ensureDirectoryExists($this->tmp.'/sessions');
        File::put(
            $this->tmp.'/sessions/agentic_engineering_os.jsonl',
            json_encode([
                'schema_version' => AutonomousEvolutionSessionService::RECORD_SCHEMA,
                'cycles' => [[
                    'final_status' => 'blocked',
                    'blockers' => ['provider_produced_no_changes'],
                    'selected_finding' => [
                        'finding_id' => AutonomousEvolutionSessionService::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID,
                        'finding_hash' => 'sha256:factory_max_ap790_candidate_starvation_recovery',
                        'title' => 'Recover AP-790 from empty executable candidate selection',
                    ],
                ]],
            ], JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->twice()->andReturn(
                ['top_candidate' => null],
                ['top_candidate' => ['candidate_id' => AutonomousEvolutionSessionService::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID]],
            );
        });

        $payload = $this->service()->run([
            'execute' => false,
            'cycles' => 1,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'repo_root' => $this->tmp,
            'session_review_locked' => [
                'factory_max_ap789_forge_topology_dispatch_readiness' => true,
                'factory_max_ap789_awis_workspace_handoff_readiness' => true,
                'factory_max_ap790_runtime_gap_matrix_ingestion' => true,
                'factory_max_ap789_forge_authority_readiness' => true,
                'factory_max_ap792_loop_certification_runtime_realness' => true,
                'factory_max_ap786_loop_hardening' => true,
                'factory_max_ap785_priority_power' => true,
                'factory_max_ap748_deep_scan_power' => true,
                'factory_max_ap717_missing_test_precision' => true,
                'factory_max_ap756_sandbox_throughput' => true,
                'factory_max_ap769_merge_throughput' => true,
                'factory_max_cursor_driver_reliability' => true,
                'factory_max_ap786_owner_failure_specificity' => true,
                'factory_max_ap790_blocked_cycle_summary_test' => true,
                'factory_max_ap785_priority_state_test' => true,
                'factory_max_ap748_deep_scan_path_test' => true,
                'factory_max_ap786_read_model_test' => true,
                'factory_max_ap791_receipt_integrity_test' => true,
                'factory_max_ap716_area_focus_read_model_test' => true,
                'factory_max_ap790_blocked_cycle_mergeable_test' => true,
                'factory_max_ap748_interface_false_positive_test' => true,
                'factory_max_ap790_seen_finding_resume_test' => true,
            ],
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertSame('dry_run_planned', $cycle['final_status']);
        $this->assertTrue(
            str_starts_with(
                (string) $cycle['selected_finding']['finding_id'],
                AutonomousEvolutionSessionService::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID.'_',
            ),
        );
        $this->assertSame('ap790_candidate_starvation_recovery', $cycle['selection_refill']['strategy'] ?? null);
        $this->assertContains(
            'review_locked_existing_branch',
            array_column($cycle['selection_rejections'] ?? [], 'reason'),
        );
    }

    public function test_factory_max_starvation_recovery_does_not_repeat_after_completed_merge(): void
    {
        File::ensureDirectoryExists($this->tmp.'/sessions');
        File::put(
            $this->tmp.'/sessions/agentic_engineering_os.jsonl',
            json_encode([
                'schema_version' => AutonomousEvolutionSessionService::RECORD_SCHEMA,
                'cycles' => [[
                    'final_status' => 'cycle_completed',
                    'blockers' => [],
                    'selected_finding' => [
                        'finding_id' => AutonomousEvolutionSessionService::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID,
                        'finding_hash' => 'sha256:factory_max_ap790_candidate_starvation_recovery',
                        'title' => 'Recover AP-790 from empty executable candidate selection',
                    ],
                ]],
            ], JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->twice()->andReturn(
                ['top_candidate' => null],
                ['top_candidate' => ['candidate_id' => AutonomousEvolutionSessionService::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID.'_newstate1234']],
            );
        });

        $payload = $this->service()->run([
            'execute' => false,
            'cycles' => 1,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'repo_root' => $this->tmp,
            'session_review_locked' => $this->factoryMaxExhaustedSessionReviewLocked(),
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertTrue(
            str_starts_with(
                (string) ($cycle['selected_finding']['finding_id'] ?? ''),
                AutonomousEvolutionSessionService::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID.'_',
            ),
        );
        $this->assertNotContains('no_candidate_with_allowed_files', $cycle['blockers'] ?? []);
    }

    public function test_factory_max_starvation_recovery_refills_when_quarantined_after_no_patch_needed(): void
    {
        $recoveryId = AutonomousEvolutionSessionService::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID;
        $quarantine = app(AreaFocusCandidateQuarantineService::class);
        $quarantine->setStorageRootForTesting($this->tmp.'/quarantine_starvation_refill');
        $quarantine->appendFromCycle(
            'agentic_engineering_os',
            'dev_forge',
            [
                'finding_id' => $recoveryId,
                'finding_hash' => 'sha256:'.$recoveryId,
                'title' => 'Recover AP-790 from empty executable candidate selection',
                'origin_type' => 'ap790_candidate_starvation_recovery',
            ],
            ['owner_runtime_no_patch_needed'],
            ['owner' => 'atlas_dev', 'reason' => 'no_patch_needed'],
        );

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock) use ($recoveryId): void {
            $mock->shouldReceive('rank')->twice()->andReturn(
                ['top_candidate' => null],
                ['top_candidate' => ['candidate_id' => $recoveryId]],
            );
        });

        $payload = $this->service()->run([
            'execute' => false,
            'cycles' => 1,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'repo_root' => $this->tmp,
            'session_review_locked' => $this->factoryMaxExhaustedSessionReviewLocked(),
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertSame('dry_run_planned', $cycle['final_status'], json_encode($cycle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertTrue(str_starts_with((string) $cycle['selected_finding']['finding_id'], $recoveryId.'_'));
        $this->assertSame('ap790_candidate_starvation_recovery', $cycle['selection_refill']['strategy'] ?? null);
        $this->assertNotContains(
            'candidate_quarantined',
            array_column($cycle['selection_rejections'] ?? [], 'reason'),
        );
        $this->assertNotContains('no_candidate_with_allowed_files', $cycle['blockers'] ?? []);
    }

    public function test_factory_max_starvation_recovery_refills_when_session_review_locked_same_state_hash(): void
    {
        $locked = $this->factoryMaxExhaustedSessionReviewLocked();

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock): void {
            $mock->shouldReceive('scan')->twice()->andReturn($this->scan([]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->times(4)->andReturn(
                ['top_candidate' => null],
                ['top_candidate' => ['candidate_id' => AutonomousEvolutionSessionService::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID]],
                ['top_candidate' => null],
                ['top_candidate' => ['candidate_id' => AutonomousEvolutionSessionService::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID]],
            );
        });

        $baseline = $this->service()->run([
            'execute' => false,
            'cycles' => 1,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'repo_root' => $this->tmp,
            'session_review_locked' => $locked,
        ]);

        $recoveryId = (string) ($baseline['cycles'][0]['selected_finding']['finding_id'] ?? '');
        $stateHash = (string) ($baseline['cycles'][0]['selected_finding']['starvation_state_hash'] ?? '');
        $this->assertTrue(str_starts_with($recoveryId, AutonomousEvolutionSessionService::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID.'_'));
        $this->assertNotSame('', $stateHash);

        $payload = $this->service()->run([
            'execute' => false,
            'cycles' => 1,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'repo_root' => $this->tmp,
            'session_review_locked' => $locked + [
                $recoveryId => true,
                'sha256:'.$recoveryId => true,
            ],
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertSame('dry_run_planned', $cycle['final_status'], json_encode($cycle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertSame($recoveryId, $cycle['selected_finding']['finding_id'] ?? null);
        $this->assertSame($stateHash, $cycle['selected_finding']['starvation_state_hash'] ?? null);
        $this->assertSame('ap790_candidate_starvation_recovery', $cycle['selection_refill']['strategy'] ?? null);
        $this->assertSame($stateHash, $cycle['selection_refill']['starvation_state_hash'] ?? null);
        $this->assertGreaterThanOrEqual(1, (int) ($cycle['selection_refill']['rejection_reason_count'] ?? 0));
        $this->assertNotContains('no_candidate_with_allowed_files', $cycle['blockers'] ?? []);
        $rejectedFindingIds = array_column($cycle['selection_rejections'] ?? [], 'finding_id');
        $this->assertNotContains($recoveryId, $rejectedFindingIds);
    }

    public function test_factory_max_starvation_recovery_is_blocked_by_terminal_session_lock(): void
    {
        $locked = $this->factoryMaxExhaustedSessionReviewLocked();

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock): void {
            $mock->shouldReceive('scan')->twice()->andReturn($this->scan([]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->times(3)->andReturn(
                ['top_candidate' => null],
                ['top_candidate' => ['candidate_id' => AutonomousEvolutionSessionService::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID]],
                ['top_candidate' => null],
            );
        });

        $baseline = $this->service()->run([
            'execute' => false,
            'cycles' => 1,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'repo_root' => $this->tmp,
            'session_review_locked' => $locked,
        ]);

        $recoveryId = (string) ($baseline['cycles'][0]['selected_finding']['finding_id'] ?? '');
        $this->assertTrue(str_starts_with($recoveryId, AutonomousEvolutionSessionService::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID.'_'));

        $payload = $this->service()->run([
            'execute' => false,
            'cycles' => 1,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'repo_root' => $this->tmp,
            'session_review_locked' => $locked,
            'session_terminal_locked' => [
                $recoveryId => true,
                'sha256:'.$recoveryId => true,
            ],
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertSame('blocked', $cycle['final_status'], json_encode($cycle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertContains('no_candidate_with_allowed_files', $cycle['blockers'] ?? []);
        $this->assertContains('terminal_locked_existing_failure', array_column($cycle['selection_rejections'] ?? [], 'reason'));
    }

    public function test_factory_max_continue_on_blocked_keeps_refilling_starvation_recovery(): void
    {
        $recoveryId = AutonomousEvolutionSessionService::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID;

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock): void {
            $mock->shouldReceive('scan')->times(2)->andReturn($this->scan([]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock) use ($recoveryId): void {
            $mock->shouldReceive('rank')->times(4)->andReturn(
                ['top_candidate' => null],
                ['top_candidate' => ['candidate_id' => $recoveryId]],
                ['top_candidate' => null],
                ['top_candidate' => ['candidate_id' => $recoveryId]],
            );
        });

        $payload = $this->service()->run([
            'execute' => false,
            'cycles' => 2,
            'continue_on_blocked' => true,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'repo_root' => $this->tmp,
            'session_review_locked' => $this->factoryMaxExhaustedSessionReviewLocked(),
        ]);

        $this->assertCount(2, $payload['cycles']);
        foreach ($payload['cycles'] as $cycle) {
            $this->assertSame('dry_run_planned', $cycle['final_status']);
            $this->assertTrue(str_starts_with((string) $cycle['selected_finding']['finding_id'], $recoveryId.'_'));
            $this->assertSame('ap790_candidate_starvation_recovery', $cycle['selection_refill']['strategy'] ?? null);
        }
        $this->assertNotContains('no_candidate_with_allowed_files', $payload['blockers']);
    }

    public function test_factory_max_starvation_recovery_when_all_priority_backlog_items_are_locked(): void
    {
        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->twice()->andReturn(
                $this->priorityBacklogReport([
                    'owner_runtime_real_execution_bridge',
                    'continuous_24h_scheduler',
                    'product_mode_controls_receipts',
                    'provider_routing_after_owner_boundaries',
                    'owner_senior_loop_repair_after_authority_blocker',
                ]),
                ['top_candidate' => ['candidate_id' => AutonomousEvolutionSessionService::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID]],
            );
        });

        $payload = $this->service()->run([
            'execute' => false,
            'cycles' => 1,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'repo_root' => $this->tmp,
            'session_review_locked' => $this->factoryMaxExhaustedSessionReviewLocked() + [
                'factory_max_ap790_priority_owner_runtime_real_execution_bridge' => true,
                'factory_max_ap790_priority_continuous_24h_scheduler' => true,
                'factory_max_ap790_priority_product_mode_controls_receipts' => true,
                'factory_max_ap789_provider_routing_authority_bridge' => true,
                'factory_max_ap786_owner_senior_loop_repair_after_authority_blocker' => true,
            ],
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertSame('dry_run_planned', $cycle['final_status'], json_encode($cycle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertTrue(str_starts_with(
            (string) ($cycle['selected_finding']['finding_id'] ?? ''),
            AutonomousEvolutionSessionService::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID.'_',
        ));
        $this->assertSame('ap790_candidate_starvation_recovery', $cycle['selection_refill']['strategy'] ?? null);
        $this->assertNotContains('no_candidate_with_allowed_files', $cycle['blockers'] ?? []);
        $this->assertNotContains(
            'factory_max_rejects_atlas_dev_topology_leak_without_authority',
            array_column($cycle['selection_rejections'] ?? [], 'reason'),
        );
        $this->assertSame(
            $cycle['selected_finding']['starvation_state_hash'] ?? null,
            $cycle['selection_refill']['starvation_state_hash'] ?? null,
        );
        $this->assertGreaterThanOrEqual(1, (int) ($cycle['selection_refill']['rejection_reason_count'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($cycle['selection_refill']['rejected_finding_count'] ?? 0));
    }

    public function test_factory_max_starvation_recovery_is_not_blocked_by_topology_leak_guard(): void
    {
        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->twice()->andReturn(
                ['top_candidate' => null],
                ['top_candidate' => ['candidate_id' => AutonomousEvolutionSessionService::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID]],
            );
        });

        $payload = $this->service()->run([
            'execute' => false,
            'cycles' => 1,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'repo_root' => $this->tmp,
            'session_review_locked' => $this->factoryMaxExhaustedSessionReviewLocked(),
        ]);

        $cycle = $payload['cycles'][0];
        $recoveryId = (string) ($cycle['selected_finding']['finding_id'] ?? '');
        $this->assertTrue(str_starts_with(
            $recoveryId,
            AutonomousEvolutionSessionService::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID.'_',
        ));
        $reasonsById = [];
        foreach ($cycle['selection_rejections'] ?? [] as $rejection) {
            $reasonsById[(string) ($rejection['finding_id'] ?? '')] = (string) ($rejection['reason'] ?? '');
        }
        $this->assertArrayNotHasKey($recoveryId, $reasonsById);
        $this->assertNotContains(
            'factory_max_rejects_atlas_dev_topology_leak_without_authority',
            array_column($cycle['selection_rejections'] ?? [], 'reason'),
        );
    }

    public function test_factory_max_starvation_recovery_converts_eight_reason_exhaustion_into_bounded_next_action(): void
    {
        $scanFindings = [
            $this->finding('afdf_high_risk', 'High risk gap', [
                'origin' => 'runtime_gap_matrix',
                'origin_type' => 'runtime_gap',
            ]),
            $this->finding('afdf_forge', 'Forge dispatch item', [
                'owner_candidate' => 'forge',
                'origin_type' => 'ap789_forge',
            ]),
            $this->finding('afdf_topology', 'Forge council routing gap', [
                'title' => 'Forge topology council leak guard',
                'detail' => 'Needs forge council fix',
            ]),
            $this->finding('afdf_non_factory', 'Outside factory scope', [
                'affected_files' => ['app/Models/User.php'],
                'evidence_refs' => ['expected_test:UserTest.php'],
            ]),
            $this->finding('afdf_docs', 'Docs only work', [
                'affected_files' => ['docs/engineering-knowledge-base/ap790.md'],
                'affected_docs' => ['docs/engineering-knowledge-base/ap790.md'],
                'evidence_refs' => [],
            ]),
            $this->finding('afdf_missing_test', 'Missing test coverage', [
                'origin_type' => 'missing_test',
            ]),
            $this->finding('afdf_benchmark', 'Benchmark rivals harness', [
                'title' => 'Add rivals benchmark harness',
            ]),
            $this->finding('afdf_no_files', 'No executable files', [
                'affected_files' => [],
                'affected_docs' => [],
                'evidence_refs' => [],
            ]),
        ];

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($scanFindings): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan($scanFindings));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->twice()->andReturn(
                $this->priorityBacklogReport([
                    'owner_runtime_real_execution_bridge',
                    'continuous_24h_scheduler',
                    'product_mode_controls_receipts',
                ]),
                ['top_candidate' => ['candidate_id' => AutonomousEvolutionSessionService::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID]],
            );
        });

        $payload = $this->service()->run([
            'execute' => false,
            'cycles' => 1,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'repo_root' => $this->tmp,
            'session_review_locked' => $this->factoryMaxExhaustedSessionReviewLocked() + [
                'factory_max_ap790_priority_owner_runtime_real_execution_bridge' => true,
                'factory_max_ap790_priority_continuous_24h_scheduler' => true,
                'factory_max_ap790_priority_product_mode_controls_receipts' => true,
            ],
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertSame('dry_run_planned', $cycle['final_status'], json_encode($cycle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertTrue(str_starts_with(
            (string) ($cycle['selected_finding']['finding_id'] ?? ''),
            AutonomousEvolutionSessionService::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID.'_',
        ));
        $refill = $cycle['selection_refill'] ?? null;
        $this->assertIsArray($refill);
        $this->assertSame('ap790_candidate_starvation_recovery', $refill['strategy'] ?? null);
        $this->assertGreaterThanOrEqual(8, (int) ($refill['rejection_reason_count'] ?? 0));
        $this->assertSame(
            (int) ($refill['rejection_reason_count'] ?? 0),
            count($refill['rejection_reasons'] ?? []),
        );
        $stateHash = (string) ($cycle['selected_finding']['starvation_state_hash'] ?? '');
        $recoveryFindingId = (string) ($cycle['selected_finding']['finding_id'] ?? '');
        $this->assertSame(
            AutonomousEvolutionSessionService::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID.'_'.$stateHash,
            $recoveryFindingId,
        );
        $this->assertSame(
            $stateHash,
            $refill['starvation_state_hash'] ?? null,
        );
        $this->assertNotContains(
            $recoveryFindingId,
            array_column($cycle['selection_rejections'] ?? [], 'finding_id'),
        );
        $this->assertStringContainsString(
            'Rejection state hash: '.$stateHash,
            (string) ($cycle['selected_finding']['why_it_matters'] ?? ''),
        );
        $this->assertStringContainsString(
            'bounded owner-runtime cycle',
            (string) ($refill['bounded_next_action'] ?? ''),
        );
        $this->assertNotContains('no_candidate_with_allowed_files', $cycle['blockers'] ?? []);
        $this->assertStringContainsString(
            'Rejection reason count:',
            (string) ($cycle['selected_finding']['why_it_matters'] ?? ''),
        );
    }

    public function test_session_locks_blocked_finding_so_next_cycle_selects_alternate(): void
    {
        $first = $this->finding('afdf_first', 'First candidate');
        $second = $this->finding('afdf_second', 'Second candidate');

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($first, $second): void {
            $mock->shouldReceive('scan')->times(2)->andReturn($this->scan([$first, $second]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock) use ($first, $second): void {
            $mock->shouldReceive('rank')->twice()->andReturn(
                ['top_candidate' => ['candidate_id' => 'afdf_first']],
                ['top_candidate' => ['candidate_id' => 'afdf_second']],
            );
        });
        $this->mock(AreaFocusBranchSandboxMaterializer::class, function ($mock): void {
            $mock->shouldReceive('materialize')->twice()->andReturn([
                'status' => AreaFocusBranchSandboxMaterializerService::STATUS_BLOCKED,
                'blockers' => ['sandbox_materialization_failed'],
            ]);
        });

        $payload = $this->service()->run([
            'execute' => true,
            'allow_direct_provider_driver' => true,
            'cycles' => 2,
            'continue_on_blocked' => true,
            'repo_root' => $this->tmp,
        ]);

        $this->assertCount(2, $payload['cycles']);
        $this->assertSame('afdf_first', $payload['cycles'][0]['selected_finding']['finding_id']);
        $this->assertSame('afdf_second', $payload['cycles'][1]['selected_finding']['finding_id']);
    }

    public function test_session_locks_merged_finding_so_next_cycle_does_not_repeat_provider_work(): void
    {
        $git = new Process(['git', '--version']);
        $git->run();
        if (! $git->isSuccessful()) {
            $this->markTestSkipped('git binary is required for AP-786 execute-path tests.');
        }

        $repo = $this->tmp.'/repo_merged';
        File::ensureDirectoryExists($repo);
        $this->runGit(['git', 'init'], $repo);
        $this->runGit(['git', 'config', 'user.email', 'atlas@example.test'], $repo);
        $this->runGit(['git', 'config', 'user.name', 'Atlas Test'], $repo);
        $source = 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php';
        $test = 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionServiceTest.php';
        File::ensureDirectoryExists($repo.'/'.dirname($source));
        File::ensureDirectoryExists($repo.'/'.dirname($test));
        file_put_contents($repo.'/'.$source, "<?php\n// fixture\n");
        file_put_contents($repo.'/'.$test, "<?php\n// fixture test\n");
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'init'], $repo);
        $this->runGit(['git', 'branch', '-M', 'main'], $repo);
        file_put_contents($repo.'/'.$source, "<?php\n// provider change\n");

        $first = $this->finding('afdf_merged', 'Merged candidate');
        $second = $this->finding('afdf_next', 'Next candidate');

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($first, $second): void {
            $mock->shouldReceive('scan')->times(2)->andReturn($this->scan([$first, $second]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock) use ($first, $second): void {
            $mock->shouldReceive('rank')->twice()->andReturn(
                ['top_candidate' => ['candidate_id' => 'afdf_merged']],
                ['top_candidate' => ['candidate_id' => 'afdf_next']],
            );
        });
        $this->mock(AreaFocusBranchSandboxMaterializer::class, function ($mock) use ($repo): void {
            $mock->shouldReceive('materialize')->twice()->andReturn(
                [
                    'status' => AreaFocusBranchSandboxMaterializerService::STATUS_MATERIALIZED,
                    'sandbox_id' => 'afbs_merged',
                    'materialization' => [
                        'worktree_path' => $repo,
                        'branch_name' => 'atlas/area-focus/merged-branch',
                    ],
                ],
                [
                    'status' => AreaFocusBranchSandboxMaterializerService::STATUS_BLOCKED,
                    'blockers' => ['sandbox_materialization_failed'],
                ],
            );
        });
        $this->mock(AtlasForgeProviderInvocationDriverRouter::class, function ($mock): void {
            $mock->shouldReceive('driverInvoke')->once()->andReturn([
                'provider' => 'cursor_cli',
                'model' => 'composer-2.5-fast',
                'provider_called' => true,
                'blockers' => [],
            ]);
        });
        $this->mock(StewardshipRuntimeResultProjector::class, function ($mock): void {
            $mock->shouldReceive('project')->once()->andReturn([
                'result_bridge_id' => 'srrb_test',
                'inbox_item_id' => 'inbox_test',
            ]);
        });
        $this->mock(StewardshipBranchMergeGovernor::class, function ($mock): void {
            $mock->shouldReceive('evaluate')->once()->andReturn([
                'status' => StewardshipBranchMergeGovernorService::STATUS_MERGED,
                'blockers' => [],
            ]);
        });

        $payload = $this->service()->run([
            'execute' => true,
            'allow_direct_provider_driver' => true,
            'cycles' => 2,
            'auto_merge' => true,
            'repo_root' => $repo,
            'continue_on_blocked' => true,
            'validation_commands' => [],
        ]);

        $this->assertCount(2, $payload['cycles']);
        $this->assertSame('cycle_completed', $payload['cycles'][0]['final_status']);
        $this->assertTrue($payload['cycles'][0]['continue_loop']);
        $this->assertSame('afdf_next', $payload['cycles'][1]['selected_finding']['finding_id']);
        $reasons = array_column($payload['cycles'][1]['selection_rejections'] ?? [], 'reason');
        $this->assertContains('review_locked_existing_branch', $reasons);
    }

    public function test_execute_path_skips_sandbox_and_provider_when_finding_is_review_locked(): void
    {
        $locked = $this->finding('afdf_locked_exec', 'Already attempted finding');
        File::ensureDirectoryExists($this->tmp.'/sessions');
        File::put(
            $this->tmp.'/sessions/agentic_engineering_os.jsonl',
            json_encode([
                'schema_version' => AutonomousEvolutionSessionService::RECORD_SCHEMA,
                'cycles' => [[
                    'final_status' => 'blocked',
                    'blockers' => ['provider_produced_no_changes'],
                    'selected_finding' => [
                        'finding_id' => 'afdf_locked_exec',
                        'finding_hash' => 'sha256:afdf_locked_exec',
                        'title' => 'Already attempted finding',
                    ],
                ]],
            ], JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($locked): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$locked]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->andReturn([
                'top_candidate' => null,
            ]);
        });
        $this->mock(AreaFocusBranchSandboxMaterializer::class)->shouldNotReceive('materialize');
        $this->mock(AtlasForgeProviderInvocationDriverRouter::class)->shouldNotReceive('driverInvoke');

        $payload = $this->service()->run([
            'execute' => true,
            'repo_root' => $this->tmp,
            'cycles' => 1,
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertSame('blocked', $cycle['final_status']);
        $this->assertContains('no_candidate_with_allowed_files', $cycle['blockers']);
    }

    public function test_plain_blocked_history_does_not_permanently_starve_candidate(): void
    {
        $candidate = $this->finding('afdf_plain_blocked_retry', 'Retry candidate after transient blocker');
        File::ensureDirectoryExists($this->tmp.'/sessions');
        File::put(
            $this->tmp.'/sessions/agentic_engineering_os.jsonl',
            json_encode([
                'schema_version' => AutonomousEvolutionSessionService::RECORD_SCHEMA,
                'cycles' => [[
                    'final_status' => 'blocked',
                    'blockers' => ['no_candidate_with_allowed_files'],
                    'selected_finding' => [
                        'finding_id' => 'afdf_plain_blocked_retry',
                        'finding_hash' => 'sha256:afdf_plain_blocked_retry',
                        'title' => 'Retry candidate after transient blocker',
                    ],
                ]],
            ], JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($candidate): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$candidate]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->andReturn([
                'top_candidate' => ['candidate_id' => 'afdf_plain_blocked_retry'],
            ]);
        });
        $this->mock(AreaFocusBranchSandboxMaterializer::class, function ($mock): void {
            $mock->shouldReceive('materialize')->once()->andReturn([
                'status' => AreaFocusBranchSandboxMaterializerService::STATUS_BLOCKED,
                'blockers' => ['sandbox_materialization_failed'],
            ]);
        });

        $payload = $this->service()->run([
            'execute' => true,
            'allow_direct_provider_driver' => true,
            'repo_root' => $this->tmp,
            'cycles' => 1,
        ]);

        $this->assertSame('afdf_plain_blocked_retry', $payload['cycles'][0]['selected_finding']['finding_id']);
        $this->assertNotContains('review_locked_existing_branch', array_column($payload['cycles'][0]['selection_rejections'] ?? [], 'reason'));
    }

    public function test_wasted_provider_cycle_locks_finding_for_next_cycle_in_same_session(): void
    {
        $git = new Process(['git', '--version']);
        $git->run();
        if (! $git->isSuccessful()) {
            $this->markTestSkipped('git binary is required for AP-786 execute-path tests.');
        }

        $repo = $this->tmp.'/repo_wasted_session';
        File::ensureDirectoryExists($repo);
        $this->runGit(['git', 'init'], $repo);
        $this->runGit(['git', 'config', 'user.email', 'atlas@example.test'], $repo);
        $this->runGit(['git', 'config', 'user.name', 'Atlas Test'], $repo);
        file_put_contents($repo.'/README.md', "fixture\n");
        $this->runGit(['git', 'add', 'README.md'], $repo);
        $this->runGit(['git', 'commit', '-m', 'init'], $repo);
        $this->runGit(['git', 'branch', '-M', 'main'], $repo);

        $first = $this->finding('afdf_wasted', 'Wasted provider candidate');
        $second = $this->finding('afdf_after_wasted', 'Alternate after wasted cycle');

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($first, $second): void {
            $mock->shouldReceive('scan')->times(2)->andReturn($this->scan([$first, $second]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock) use ($first, $second): void {
            $mock->shouldReceive('rank')->twice()->andReturn(
                ['top_candidate' => ['candidate_id' => 'afdf_wasted']],
                ['top_candidate' => ['candidate_id' => 'afdf_after_wasted']],
            );
        });
        $this->mock(AreaFocusBranchSandboxMaterializer::class, function ($mock) use ($repo): void {
            $mock->shouldReceive('materialize')->twice()->andReturn(
                [
                    'status' => AreaFocusBranchSandboxMaterializerService::STATUS_MATERIALIZED,
                    'sandbox_id' => 'afbs_wasted',
                    'materialization' => [
                        'worktree_path' => $repo,
                        'branch_name' => 'atlas/area-focus/wasted-branch',
                    ],
                ],
                [
                    'status' => AreaFocusBranchSandboxMaterializerService::STATUS_BLOCKED,
                    'blockers' => ['sandbox_materialization_failed'],
                ],
            );
        });
        $this->mock(AtlasForgeProviderInvocationDriverRouter::class, function ($mock): void {
            $mock->shouldReceive('driverInvoke')->once()->andReturn([
                'provider' => 'cursor_cli',
                'model' => 'composer-2.5-fast',
                'provider_called' => true,
                'blockers' => [],
            ]);
        });
        $this->mock(StewardshipRuntimeResultProjector::class)->shouldNotReceive('project');
        $this->mock(StewardshipBranchMergeGovernor::class)->shouldNotReceive('evaluate');

        $payload = $this->service()->run([
            'execute' => true,
            'allow_direct_provider_driver' => true,
            'repo_root' => $repo,
            'cycles' => 2,
            'continue_on_blocked' => true,
            'validation_commands' => [],
        ]);

        $this->assertCount(2, $payload['cycles']);
        $this->assertSame('afdf_wasted', $payload['cycles'][0]['selected_finding']['finding_id']);
        $this->assertContains('provider_produced_no_changes', $payload['cycles'][0]['blockers']);
        $this->assertSame('afdf_after_wasted', $payload['cycles'][1]['selected_finding']['finding_id']);
        $reasons = array_column($payload['cycles'][1]['selection_rejections'] ?? [], 'reason');
        $this->assertContains('review_locked_existing_branch', $reasons);
    }

    public function test_quarantine_ledger_prevents_repeat_selection(): void
    {
        $quarantined = $this->finding('afdf_quarantined', 'Quarantined finding');
        $quarantine = app(AreaFocusCandidateQuarantineService::class);
        $quarantine->setStorageRootForTesting($this->tmp.'/quarantine');
        $quarantine->appendFromCycle(
            'agentic_engineering_os',
            'dev_forge',
            $quarantined,
            ['owner_runtime_routing_not_executable'],
            ['owner' => 'atlas_dev', 'reason' => 'routing_not_executable'],
        );

        $alt = $this->finding('afdf_after_quarantine', 'Alternate after quarantine');
        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($quarantined, $alt): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$quarantined, $alt]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock) use ($alt): void {
            $mock->shouldReceive('rank')->once()->andReturn([
                'top_candidate' => ['candidate_id' => 'afdf_after_quarantine'],
            ]);
        });

        $payload = $this->service()->run([
            'execute' => false,
            'cycles' => 1,
        ]);

        $this->assertSame('afdf_after_quarantine', $payload['cycles'][0]['selected_finding']['finding_id']);
        $reasons = array_column($payload['cycles'][0]['selection_rejections'] ?? [], 'reason');
        $this->assertContains('candidate_quarantined', $reasons);
    }

    public function test_validation_failed_retries_once_when_diff_stays_in_allowed_files(): void
    {
        $git = new Process(['git', '--version']);
        $git->run();
        if (! $git->isSuccessful()) {
            $this->markTestSkipped('git binary is required for AP-786 execute-path tests.');
        }

        $repo = $this->tmp.'/repo_validation_retry';
        File::ensureDirectoryExists($repo);
        $this->runGit(['git', 'init'], $repo);
        $this->runGit(['git', 'config', 'user.email', 'atlas@example.test'], $repo);
        $this->runGit(['git', 'config', 'user.name', 'Atlas Test'], $repo);
        $source = 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php';
        File::ensureDirectoryExists($repo.'/'.dirname($source));
        file_put_contents($repo.'/'.$source, "<?php\n// fixture\n");
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'init'], $repo);
        $this->runGit(['git', 'branch', '-M', 'main'], $repo);
        file_put_contents($repo.'/'.$source, "<?php\n// provider change\n");

        $marker = 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/.ap786_retry_marker';
        $retryCommand = "bash -c 'test -f {$marker} || (touch {$marker} && exit 1)'";
        $finding = $this->finding('afdf_validation_retry', 'Validation retry candidate', [
            'affected_files' => [
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
                $marker,
            ],
        ]);

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($finding): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$finding]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->andReturn([
                'top_candidate' => ['candidate_id' => 'afdf_validation_retry'],
            ]);
        });
        $this->mock(AreaFocusBranchSandboxMaterializer::class, function ($mock) use ($repo): void {
            $mock->shouldReceive('materialize')->once()->andReturn([
                'status' => AreaFocusBranchSandboxMaterializerService::STATUS_MATERIALIZED,
                'sandbox_id' => 'afbs_validation_retry',
                'materialization' => [
                    'worktree_path' => $repo,
                    'branch_name' => 'atlas/area-focus/validation-retry-branch',
                ],
            ]);
        });
        $this->mock(AtlasForgeProviderInvocationDriverRouter::class, function ($mock): void {
            $mock->shouldReceive('driverInvoke')->once()->andReturn([
                'provider' => 'cursor_cli',
                'model' => 'composer-2.5-fast',
                'provider_called' => true,
                'blockers' => [],
            ]);
        });
        $this->mock(StewardshipRuntimeResultProjector::class, function ($mock): void {
            $mock->shouldReceive('project')->once()->andReturn([
                'result_bridge_id' => 'srrb_validation_retry',
                'inbox_item_id' => 'inbox_validation_retry',
            ]);
        });
        $this->mock(StewardshipBranchMergeGovernor::class, function ($mock): void {
            $mock->shouldReceive('evaluate')->once()->andReturn([
                'status' => 'blocked_pending_review',
                'blockers' => ['operator_review_required'],
            ]);
        });

        $payload = $this->service()->run([
            'execute' => true,
            'allow_direct_provider_driver' => true,
            'repo_root' => $repo,
            'cycles' => 1,
            'validation_commands' => [$retryCommand],
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertTrue($cycle['validation']['repair']['retried'] ?? false);
        $this->assertNotContains('validation_failed', $cycle['blockers'] ?? []);
    }

    public function test_no_patch_needed_quarantines_and_emits_failure_capsule(): void
    {
        $quarantine = app(AreaFocusCandidateQuarantineService::class);
        $quarantine->setStorageRootForTesting($this->tmp.'/quarantine_no_patch');
        $finding = $this->finding('afdf_no_patch', 'No patch finding');

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($finding): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$finding]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->andReturn([
                'top_candidate' => ['candidate_id' => 'afdf_no_patch'],
            ]);
        });
        $this->mock(AreaFocusBranchSandboxMaterializer::class, function ($mock): void {
            $mock->shouldReceive('materialize')->once()->andReturn($this->materializedSandbox());
        });
        $this->mock(AtlasForgeProviderInvocationDriverRouter::class)->shouldNotReceive('driverInvoke');
        $this->mock(Ap786OwnerFlowRunner::class, function ($mock): void {
            $mock->shouldReceive('execute')->once()->andReturn([
                'status' => Ap786OwnerFlowExecutor::STATUS_COMPLETED,
                'uses_full_owner_runtime_chain' => true,
                'provider_router_used' => false,
                'merge_allowed' => false,
                'blockers' => ['owner_runtime_no_patch_needed'],
                'execution_result' => ['result_status' => 'partial', 'summary' => 'no patch'],
            ]);
        });
        $this->mock(StewardshipRuntimeResultProjector::class, function ($mock): void {
            $mock->shouldReceive('project')->once()->andReturn([
                'result_bridge_id' => 'srrb_no_patch',
                'inbox_item_id' => 'inbox_no_patch',
            ]);
        });
        $this->mock(StewardshipBranchMergeGovernor::class)->shouldNotReceive('evaluate');

        $service = $this->service();
        $service->setCandidateQuarantineForTesting($quarantine);

        $payload = $service->run([
            'execute' => true,
            'repo_root' => $this->tmp,
            'cycles' => 1,
            'continue_on_blocked' => true,
            'focus' => 'dev_forge',
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertTrue($cycle['quarantined'] ?? false);
        $this->assertContains('owner_runtime_no_patch_needed', $cycle['blockers']);
        $this->assertSame(
            AreaFocusCandidateQuarantineService::FAILED_GATE_CAPSULE_SCHEMA,
            $cycle['failure_capsule']['schema_version'] ?? '',
        );
        $entries = $quarantine->readEntries('agentic_engineering_os', 'dev_forge');
        $this->assertCount(1, $entries);
        $this->assertSame('no_patch_needed', $entries[0]['reason']);
    }

    public function test_routing_not_executable_quarantines_without_session_stop(): void
    {
        app(AreaFocusCandidateQuarantineService::class)->setStorageRootForTesting($this->tmp.'/quarantine_routing');
        $finding = $this->finding('afdf_routing', 'Routing blocked finding');

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($finding): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$finding]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->andReturn([
                'top_candidate' => ['candidate_id' => 'afdf_routing'],
            ]);
        });
        $this->mock(AreaFocusBranchSandboxMaterializer::class, function ($mock): void {
            $mock->shouldReceive('materialize')->once()->andReturn($this->materializedSandbox());
        });
        $this->mock(AtlasForgeProviderInvocationDriverRouter::class)->shouldNotReceive('driverInvoke');
        $this->mock(Ap786OwnerFlowRunner::class, function ($mock): void {
            $mock->shouldReceive('execute')->once()->andReturn([
                'status' => Ap786OwnerFlowExecutor::STATUS_COMPLETED,
                'uses_full_owner_runtime_chain' => true,
                'provider_router_used' => false,
                'merge_allowed' => false,
                'blockers' => ['owner_runtime_routing_not_executable'],
                'execution_result' => ['result_status' => 'partial', 'summary' => 'routing blocked'],
            ]);
        });
        $this->mock(StewardshipRuntimeResultProjector::class, function ($mock): void {
            $mock->shouldReceive('project')->once()->andReturn([
                'result_bridge_id' => 'srrb_routing',
                'inbox_item_id' => 'inbox_routing',
            ]);
        });
        $this->mock(StewardshipBranchMergeGovernor::class)->shouldNotReceive('evaluate');

        $payload = $this->service()->run([
            'execute' => true,
            'repo_root' => $this->tmp,
            'cycles' => 1,
            'continue_on_blocked' => true,
            'focus' => 'dev_forge',
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertTrue($cycle['quarantined'] ?? false);
        $this->assertContains('owner_runtime_routing_not_executable', $cycle['blockers']);
        $this->assertFalse($cycle['stop_session_after_blocker'] ?? false);
    }

    /**
     * @param  list<string>  $itemIds
     * @return array<string,mixed>
     */
    private function priorityBacklogReport(array $itemIds): array
    {
        $ranked = [];
        foreach ($itemIds as $index => $itemId) {
            $ranked[] = [
                'rank' => $index + 1,
                'item_id' => $itemId,
                'candidate_id' => $itemId,
                'item_type' => $itemId,
                'lane' => 'now',
                'completion_status' => 'pending',
                'final_priority_score' => 100 - $index,
            ];
        }

        return [
            'top_candidate' => ['candidate_id' => $itemIds[0] ?? ''],
            'ranked_items' => $ranked,
        ];
    }

    /**
     * @param  list<string>  $command
     */
    private function runGit(array $command, string $cwd): void
    {
        $process = new Process($command, $cwd);
        $process->setTimeout(30);
        $process->run();
        $this->assertTrue($process->isSuccessful(), implode(' ', $command)."\n".$process->getErrorOutput());
    }
}
