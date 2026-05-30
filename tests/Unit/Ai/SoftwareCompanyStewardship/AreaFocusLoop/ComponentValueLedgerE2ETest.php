<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Foundry\Rsi\ImmutableInvariantRegistryService;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusCandidateQuarantineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDeepFindingEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowExecutor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowRunner;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\MetricLedgerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Rsi\ComponentValueLedgerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchMergeGovernor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchMergeGovernorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipPriorityRanker;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultProjector;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * RSI Part B · ComponentValueLedger END-TO-END through the REAL loop path.
 *
 * Drives the real AutonomousEvolutionSessionService::run() -> runOwnerFlowCycle()
 * -> applyOutcomeMeasurement() path with a real git repo, a real merge SHA, and a
 * FAKE metric measurer (no real command, no provider spend). This proves the
 * ComponentValueLedger recording hook is WIRED into the live measured-or-reverted
 * gate (not an orphan) and that after a proven cycle the per-component value-per-
 * token attribution is recorded with the real provider-call telemetry, and the
 * weakest token-spending component is identified.
 */
final class ComponentValueLedgerE2ETest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_cvl_e2e_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_proven_cycle_records_component_value_attribution_via_real_path(): void
    {
        [$repo, $mergeHash, $worktree] = $this->prepareMergedRepo();

        // baseline=75, returns 80 -> delta +5 >= target +3 -> proven MET.
        $componentLedger = $this->driveProvenCycle($repo, $worktree, $mergeHash, providerCalls: 1200);

        $service = app(AutonomousEvolutionSessionService::class);
        $this->bindServiceSeams($service, $repo, $componentLedger, fn () => ['ok' => true, 'exit_code' => 0, 'out' => '{"metric": 80.0}', 'err' => '']);

        $payload = $service->run([
            'execute' => true,
            'cycles' => 1,
            'auto_merge' => true,
            'repo_root' => $repo,
            'continue_on_blocked' => true,
            'validation_commands' => [],
            'outcome_contract' => [
                'metric_id' => 'service_maturity',
                'baseline' => 75.0,
                'target_delta' => 3.0,
                'measure_command' => 'echo metric',
                'metric_json_path' => 'metric',
            ],
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertSame('cycle_completed', $cycle['final_status']);
        $this->assertTrue($cycle['outcome_measured']);

        // The recording hook fired on the REAL path: one append-only event with
        // the proven delta and the live provider-call telemetry attributed to the
        // owner-flow component.
        $events = $componentLedger->replay('agentic_engineering_os', 'dev_forge');
        $this->assertCount(1, $events);
        $event = $events[0];

        $this->assertSame(ComponentValueLedgerService::OUTCOME_PROVEN, $event['outcome_status']);
        $this->assertEqualsWithDelta(5.0, $event['proven_value_delta'], 0.0001);
        $this->assertSame($mergeHash, $event['merge_hash']);
        $this->assertContains('session_ap786', $event['live_components']);
        $this->assertContains('metric_ledger', $event['deterministic_components']);

        $byId = [];
        foreach ($event['components'] as $component) {
            $byId[$component['component_id']] = $component;
        }
        // Real telemetry flowed: provider-call count attributed to the session.
        $this->assertSame(1200, $byId['session_ap786']['tokens_consumed']);
        $this->assertSame(0, $byId['metric_ledger']['tokens_consumed']);
        $this->assertEqualsWithDelta(5.0, $byId['session_ap786']['measured_value_contribution'], 0.0001);
    }

    public function test_after_multiple_proven_cycles_weakest_component_is_identified_via_real_path(): void
    {
        $componentLedger = new ComponentValueLedgerService(new ImmutableInvariantRegistryService());
        $componentLedger->setStorageRootForTesting($this->tmp.'/component_value');

        // Run 3 real cycles, each a proven merge with heavy provider spend for
        // modest proven value (low value-per-token on the live owner-flow seam).
        for ($i = 1; $i <= 3; $i++) {
            [$repo, $mergeHash, $worktree] = $this->prepareMergedRepo();
            $this->seamMocksForCycle($worktree, $mergeHash, providerCalls: 3000);

            $service = app(AutonomousEvolutionSessionService::class);
            $this->bindServiceSeams($service, $repo, $componentLedger, fn () => ['ok' => true, 'exit_code' => 0, 'out' => '{"metric": 78.0}', 'err' => '']);

            $payload = $service->run([
                'execute' => true,
                'cycles' => 1,
                'auto_merge' => true,
                'repo_root' => $repo,
                'continue_on_blocked' => true,
                'validation_commands' => [],
                'outcome_contract' => [
                    'metric_id' => 'service_maturity',
                    'baseline' => 75.0,
                    'target_delta' => 3.0,
                    'measure_command' => 'echo metric',
                    'metric_json_path' => 'metric',
                ],
            ]);
            $this->assertTrue($payload['cycles'][0]['outcome_measured'], "cycle {$i} must be proven");
        }

        $events = $componentLedger->replay('agentic_engineering_os', 'dev_forge');
        $this->assertCount(3, $events);

        $stats = $componentLedger->valuePerTokenByComponent('agentic_engineering_os', 'dev_forge');
        // metric_ledger is deterministic -> no finite value-per-token.
        $this->assertNull($stats['metric_ledger']['value_per_token']);
        // session_ap786: total value 3*3.0=9.0 over 3*3000=9000 tokens = 0.001.
        $this->assertEqualsWithDelta(0.001, $stats['session_ap786']['value_per_token'], 0.0000001);
        $this->assertSame(3, $stats['session_ap786']['proven_cycles']);

        // weakest: with the registry bound (session_ap786 maps to the sacred
        // Ap786OwnerFlowExecutor), the only token-spender is sacred -> honest null.
        $this->assertNull($componentLedger->weakestComponent('agentic_engineering_os', 'dev_forge'));

        // Without a registry bound, the lone token-spender IS the weakest target.
        $componentLedger->setRegistryForTesting(null);
        $weakest = $componentLedger->weakestComponent('agentic_engineering_os', 'dev_forge');
        $this->assertNotNull($weakest);
        $this->assertSame('session_ap786', $weakest['component_id']);
    }

    // ---- harness (mirrors MetricOutcomeMeasuredOrRevertedTest) -------------

    private function driveProvenCycle(string $repo, string $worktree, string $mergeHash, int $providerCalls): ComponentValueLedgerService
    {
        $this->seamMocksForCycle($worktree, $mergeHash, $providerCalls);
        $componentLedger = new ComponentValueLedgerService();
        $componentLedger->setStorageRootForTesting($this->tmp.'/component_value');

        return $componentLedger;
    }

    private function bindServiceSeams(AutonomousEvolutionSessionService $service, string $repo, ComponentValueLedgerService $componentLedger, callable $measurer): void
    {
        $service->setStorageDirForTesting($this->tmp.'/sessions_'.uniqid('', true));
        $quarantine = app(AreaFocusCandidateQuarantineService::class);
        $quarantine->setStorageRootForTesting($this->tmp.'/quarantine_'.uniqid('', true));
        $service->setCandidateQuarantineForTesting($quarantine);

        $metric = new MetricLedgerService();
        $metric->setStorageRootForTesting($this->tmp.'/metric_'.uniqid('', true));
        $metric->setMeasurerForTesting($measurer);
        $service->setMetricLedgerForTesting($metric);

        $service->setComponentValueLedgerForTesting($componentLedger);
    }

    private function seamMocksForCycle(string $worktree, string $mergeHash, int $providerCalls): void
    {
        $finding = $this->finding('afdf_outcome', 'Outcome-measured candidate');

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($finding): void {
            $mock->shouldReceive('scan')->once()->andReturn(['findings' => [$finding], 'status' => 'ready']);
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->andReturn(['top_candidate' => ['candidate_id' => 'afdf_outcome']]);
        });
        $this->mock(AreaFocusBranchSandboxMaterializer::class, function ($mock) use ($worktree): void {
            $mock->shouldReceive('materialize')->once()->andReturn([
                'status' => AreaFocusBranchSandboxMaterializerService::STATUS_MATERIALIZED,
                'sandbox_id' => 'afbs_outcome',
                'materialization' => [
                    'worktree_path' => $worktree,
                    'branch_name' => 'atlas/area-focus/agentic_engineering_os/atlas_dev/outcome',
                ],
            ]);
        });
        $this->mock(AtlasForgeProviderInvocationDriverRouter::class)->shouldNotReceive('driverInvoke');
        $this->mock(Ap786OwnerFlowRunner::class, function ($mock) use ($providerCalls): void {
            $mock->shouldReceive('execute')->once()->andReturn($this->ownerFlowReport($providerCalls));
        });
        $this->mock(StewardshipRuntimeResultProjector::class, function ($mock): void {
            $mock->shouldReceive('project')->once()->andReturn([
                'result_bridge_id' => 'srrb_outcome',
                'inbox_item_id' => 'inbox_outcome',
            ]);
        });
        $this->mock(StewardshipBranchMergeGovernor::class, function ($mock) use ($mergeHash): void {
            $mock->shouldReceive('evaluate')->once()->andReturn([
                'status' => StewardshipBranchMergeGovernorService::STATUS_MERGED,
                'blockers' => [],
                'merge_result' => ['new_head' => $mergeHash, 'target' => 'main'],
            ]);
        });
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function prepareMergedRepo(): array
    {
        $git = new Process(['git', '--version']);
        $git->run();
        if (! $git->isSuccessful()) {
            $this->markTestSkipped('git binary is required for the ComponentValueLedger E2E test.');
        }

        $repo = $this->tmp.'/repo_'.uniqid('', true);
        File::ensureDirectoryExists($repo.'/app/Services/Ai');
        $this->runGit(['git', 'init'], $repo);
        $this->runGit(['git', 'config', 'user.email', 'atlas@example.test'], $repo);
        $this->runGit(['git', 'config', 'user.name', 'Atlas Test'], $repo);
        file_put_contents($repo.'/app/Services/Ai/Example.php', "<?php\n// baseline\n");
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'init'], $repo);
        $this->runGit(['git', 'branch', '-M', 'main'], $repo);

        file_put_contents($repo.'/app/Services/Ai/Example.php', "<?php\n// merged change\n");
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'merged change'], $repo);
        $mergeHash = $this->gitOut(['git', 'rev-parse', 'HEAD'], $repo);

        $worktree = $this->tmp.'/worktree_'.uniqid('', true);
        File::ensureDirectoryExists($worktree.'/app/Services/Ai');
        $this->runGit(['git', 'init'], $worktree);
        $this->runGit(['git', 'config', 'user.email', 'atlas@example.test'], $worktree);
        $this->runGit(['git', 'config', 'user.name', 'Atlas Test'], $worktree);
        file_put_contents($worktree.'/app/Services/Ai/Example.php', "<?php\n// baseline\n");
        $this->runGit(['git', 'add', '.'], $worktree);
        $this->runGit(['git', 'commit', '-m', 'init'], $worktree);
        $this->runGit(['git', 'branch', '-M', 'atlas/area-focus/agentic_engineering_os/atlas_dev/outcome'], $worktree);
        file_put_contents($worktree.'/app/Services/Ai/Example.php', "<?php\n// owner-flow change\n");

        return [$repo, $mergeHash, $worktree];
    }

    /**
     * @return array<string,mixed>
     */
    private function finding(string $id, string $title): array
    {
        return [
            'finding_id' => $id,
            'finding_hash' => 'sha256:'.$id,
            'title' => $title,
            'detail' => $title.' detail',
            'why_it_matters' => 'Throughput matters.',
            'kind' => 'bug',
            'severity' => 'high',
            'owner_candidate' => 'atlas_dev',
            'affected_files' => ['app/Services/Ai/Example.php'],
            'affected_docs' => [],
            'evidence_refs' => ['expected_test:ExampleTest.php'],
            'origin_type' => 'runtime_gap',
            'auto_execution_allowed' => true,
            'operator_review_required' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function ownerFlowReport(int $providerCalls): array
    {
        return [
            'status' => Ap786OwnerFlowExecutor::STATUS_COMPLETED,
            'uses_full_owner_runtime_chain' => true,
            'provider_router_used' => false,
            'merge_allowed' => true,
            'consumption_id' => 'afcons_outcome',
            'release_id' => 'afrel_outcome',
            'queue_item_id' => 'afq_outcome',
            'owner_execution_id' => 'afexec_outcome',
            'owner_sandbox_run_id' => 'afrun_outcome',
            'owner_result' => [
                'result_id' => 'afrunres_outcome',
                'result_status' => 'completed',
                'provider_invoked' => true,
                'runtime_invocation' => [
                    'command_result' => ['owner_cli_provider_calls' => $providerCalls],
                ],
            ],
            'result_bridge' => ['status' => 'ready_for_operator_result_review', 'result_bridge_id' => 'afobr_outcome'],
            'result_bridge_id' => 'afobr_outcome',
            'execution_result' => [
                'result_status' => 'completed',
                'provider_invoked' => true,
                'summary' => 'Atlas Dev senior loop completed in the AP-756 worktree.',
                'changed_files' => ['app/Services/Ai/Example.php'],
                'tests' => ['php artisan test --filter=Example'],
            ],
            'steps' => [],
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

    /**
     * @param  list<string>  $command
     */
    private function gitOut(array $command, string $cwd): string
    {
        $process = new Process($command, $cwd);
        $process->setTimeout(30);
        $process->run();
        $this->assertTrue($process->isSuccessful(), implode(' ', $command)."\n".$process->getErrorOutput());

        return trim($process->getOutput());
    }
}
