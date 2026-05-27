<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDeepFindingEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
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

    public function test_execute_requires_full_owner_flow_before_direct_provider_driver(): void
    {
        $finding = $this->finding('afdf_full_flow', 'Full Forge flow required');

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($finding): void {
            $mock->shouldReceive('scan')->once()->andReturn($this->scan([$finding]));
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->andReturn([
                'top_candidate' => ['candidate_id' => 'afdf_full_flow'],
            ]);
        });
        $this->mock(AreaFocusBranchSandboxMaterializer::class)->shouldNotReceive('materialize');
        $this->mock(AtlasForgeProviderInvocationDriverRouter::class)->shouldNotReceive('driverInvoke');
        $this->mock(StewardshipRuntimeResultProjector::class)->shouldNotReceive('project');
        $this->mock(StewardshipBranchMergeGovernor::class)->shouldNotReceive('evaluate');

        $payload = $this->service()->run([
            'execute' => true,
            'repo_root' => $this->tmp,
            'cycles' => 1,
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertSame('blocked', $cycle['final_status']);
        $this->assertContains('full_atlas_forge_flow_required', $cycle['blockers']);
        $this->assertFalse($cycle['flow_integrity_gate']['ok']);
        $this->assertTrue($cycle['flow_integrity_gate']['direct_provider_driver_path']);
        $this->assertSame([
            'AP-747',
            'AP-756',
            'AP-757',
            'AP-749',
            'AP-758',
            'AP-759',
            'AP-750',
        ], $cycle['flow_integrity_gate']['required_chain']);
        $this->assertContains('native_obra_or_work_packet', $cycle['flow_integrity_gate']['required_robust_flow_capabilities']);
        $this->assertContains('self_directed_spec_or_sdd_packet', $cycle['flow_integrity_gate']['required_robust_flow_capabilities']);
        $this->assertContains('tdd_test_contract', $cycle['flow_integrity_gate']['required_robust_flow_capabilities']);
        $this->assertContains('bdd_acceptance_contract', $cycle['flow_integrity_gate']['required_robust_flow_capabilities']);
        $this->assertContains('atlas_decide_provider_topology', $cycle['flow_integrity_gate']['required_robust_flow_capabilities']);
        $this->assertContains('aawr_or_multi_agent_workcell', $cycle['flow_integrity_gate']['required_robust_flow_capabilities']);
        $this->assertContains('repair_loop_with_failed_gate_capsule', $cycle['flow_integrity_gate']['required_robust_flow_capabilities']);
        $this->assertContains('evidence_ledger_and_decision_receipts', $cycle['flow_integrity_gate']['required_robust_flow_capabilities']);
        $this->assertFalse($payload['claim_policy']['direct_provider_driver_allowed']);
        $this->assertTrue($payload['claim_policy']['requires_full_atlas_forge_owner_flow']);
        $this->assertTrue($payload['claim_policy']['requires_robust_obra_forge_quality_flow']);
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
