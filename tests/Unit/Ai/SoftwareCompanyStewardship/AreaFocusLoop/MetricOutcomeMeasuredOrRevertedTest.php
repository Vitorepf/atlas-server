<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusCandidateQuarantineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDeepFindingEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowExecutor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowRunner;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\MetricLedgerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchMergeGovernor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchMergeGovernorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipPriorityRanker;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultProjector;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * M keystone E2E: measured-or-reverted at the VALUE level.
 *
 * Drives the REAL session run() -> runOwnerFlowCycle() path with a real git repo
 * and a real merge commit on main. A FAKE metric measurer is injected via the
 * MetricLedgerService seam (no real atlas:aaeos / provider invocation). Two
 * cases are proven end-to-end:
 *   - the declared metric MOVED  -> merge is KEPT, cycle completed, outcome_measured
 *   - the declared metric DID NOT move -> merge is git-reverted, cycle BLOCKED,
 *     ledger event recorded, and main's HEAD is the revert commit (real Git proof).
 */
final class MetricOutcomeMeasuredOrRevertedTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_metric_outcome_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_merge_whose_metric_did_not_move_is_reverted_and_cycle_blocked(): void
    {
        [$repo, $mergeHash, $preMergeHead, $worktree] = $this->prepareMergedRepo();

        $service = $this->driveMergedCycle($repo, $worktree, $mergeHash, function (string $command): array {
            // Declared baseline=75; this returns 72 -> delta -3 < target +3 -> UNMET.
            return ['ok' => true, 'exit_code' => 0, 'out' => '{"metric": 72.0}', 'err' => ''];
        });

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
        $this->assertSame('blocked', $cycle['final_status']);
        $this->assertFalse($cycle['merge_performed']);
        $this->assertTrue($cycle['merge_reverted']);
        $this->assertFalse($cycle['outcome_measured']);
        $this->assertContains('outcome_contract_unmet:service_maturity', $cycle['blockers']);
        $this->assertSame('reverted', $cycle['outcome_revert']['status']);

        // Real Git proof: main advanced past the merge with a revert commit.
        $head = $this->gitOut(['git', 'rev-parse', 'HEAD'], $repo);
        $this->assertNotSame($mergeHash, $head, 'HEAD must be the revert commit, not the merge.');
        $this->assertNotSame($preMergeHead, $head, 'A revert is a NEW commit, not a reset.');

        // The merge tree change must be gone after the revert.
        $contents = (string) file_get_contents($repo.'/app/Services/Ai/Example.php');
        $this->assertStringNotContainsString('merged change', $contents);

        // Ledger event recorded with the unmet verdict (append-only audit).
        $ledger = $this->ledgerEvents();
        $this->assertNotEmpty($ledger);
        $last = end($ledger);
        $this->assertSame(MetricLedgerService::STATUS_OUTCOME_NOT_MET, $last['status']);
        $this->assertEquals(72.0, $last['measured_value']);
        $this->assertEquals(-3.0, $last['measured_delta']);
        $this->assertSame($mergeHash, $last['merge_hash']);
    }

    public function test_merge_whose_metric_moved_is_kept_and_cycle_completed(): void
    {
        [$repo, $mergeHash, , $worktree] = $this->prepareMergedRepo();

        $service = $this->driveMergedCycle($repo, $worktree, $mergeHash, function (string $command): array {
            // baseline=75; this returns 80 -> delta +5 >= target +3 -> MET.
            return ['ok' => true, 'exit_code' => 0, 'out' => '{"metric": 80.0}', 'err' => ''];
        });

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
        $this->assertTrue($cycle['merge_performed']);
        $this->assertTrue($cycle['outcome_measured']);
        $this->assertArrayNotHasKey('merge_reverted', $cycle);

        // The merge is KEPT: HEAD is still the merge commit (no revert).
        $head = $this->gitOut(['git', 'rev-parse', 'HEAD'], $repo);
        $this->assertSame($mergeHash, $head);

        $ledger = $this->ledgerEvents();
        $last = end($ledger);
        $this->assertSame(MetricLedgerService::STATUS_OUTCOME_MET, $last['status']);
        $this->assertEquals(80.0, $last['measured_value']);
    }

    /**
     * Build a real git repo on main with an init commit and a "merged" commit
     * (the change the cycle's merge represents). Returns [repo, mergeHash,
     * preMergeHead].
     *
     * @return array{0:string,1:string,2:string,3:string}
     */
    private function prepareMergedRepo(): array
    {
        $git = new Process(['git', '--version']);
        $git->run();
        if (! $git->isSuccessful()) {
            $this->markTestSkipped('git binary is required for the M keystone E2E test.');
        }

        $repo = $this->tmp.'/repo';
        File::ensureDirectoryExists($repo.'/app/Services/Ai');
        $this->runGit(['git', 'init'], $repo);
        $this->runGit(['git', 'config', 'user.email', 'atlas@example.test'], $repo);
        $this->runGit(['git', 'config', 'user.name', 'Atlas Test'], $repo);
        file_put_contents($repo.'/app/Services/Ai/Example.php', "<?php\n// baseline\n");
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'init'], $repo);
        $this->runGit(['git', 'branch', '-M', 'main'], $repo);
        $preMergeHead = $this->gitOut(['git', 'rev-parse', 'HEAD'], $repo);

        // The merge commit the cycle "performed" (the change landing on main).
        file_put_contents($repo.'/app/Services/Ai/Example.php', "<?php\n// merged change\n");
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'merged change'], $repo);
        $mergeHash = $this->gitOut(['git', 'rev-parse', 'HEAD'], $repo);

        // The sandbox worktree is a SEPARATE checkout with a pending change so the
        // real commitSandbox/owner path has a real diff to commit. The merge
        // governor is mocked, so the merge SHA the cycle records is the pre-built
        // commit on the repo_root above (the revert target).
        $worktree = $this->tmp.'/worktree';
        File::ensureDirectoryExists($worktree.'/app/Services/Ai');
        $this->runGit(['git', 'init'], $worktree);
        $this->runGit(['git', 'config', 'user.email', 'atlas@example.test'], $worktree);
        $this->runGit(['git', 'config', 'user.name', 'Atlas Test'], $worktree);
        file_put_contents($worktree.'/app/Services/Ai/Example.php', "<?php\n// baseline\n");
        $this->runGit(['git', 'add', '.'], $worktree);
        $this->runGit(['git', 'commit', '-m', 'init'], $worktree);
        $this->runGit(['git', 'branch', '-M', 'atlas/area-focus/agentic_engineering_os/atlas_dev/outcome'], $worktree);
        file_put_contents($worktree.'/app/Services/Ai/Example.php', "<?php\n// owner-flow change\n");

        return [$repo, $mergeHash, $preMergeHead, $worktree];
    }

    /**
     * Wire the session for the merged-cycle path: deep scan/ranker/sandbox/owner-
     * flow are doubled, the merge governor returns STATUS_MERGED carrying the real
     * merge SHA, and a FAKE metric measurer is injected (no real command runs).
     *
     * @param  callable(string,string):array{ok:bool,exit_code:?int,out:string,err:string}  $measurer
     */
    private function driveMergedCycle(string $repo, string $worktree, string $mergeHash, callable $measurer): AutonomousEvolutionSessionService
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
        $this->mock(Ap786OwnerFlowRunner::class, function ($mock): void {
            $mock->shouldReceive('execute')->once()->andReturn($this->ownerFlowReport());
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

        $service = app(AutonomousEvolutionSessionService::class);
        $service->setStorageDirForTesting($this->tmp.'/sessions');
        $quarantine = app(AreaFocusCandidateQuarantineService::class);
        $quarantine->setStorageRootForTesting($this->tmp.'/quarantine');
        $service->setCandidateQuarantineForTesting($quarantine);

        $ledger = new MetricLedgerService();
        $ledger->setStorageRootForTesting($this->tmp.'/metric_ledger');
        $ledger->setMeasurerForTesting($measurer);
        $service->setMetricLedgerForTesting($ledger);

        return $service;
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
    private function ownerFlowReport(): array
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
            'owner_result' => ['result_id' => 'afrunres_outcome', 'result_status' => 'completed'],
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
     * @return list<array<string,mixed>>
     */
    private function ledgerEvents(): array
    {
        $path = $this->tmp.'/metric_ledger/agentic_engineering_os.jsonl';
        if (! is_file($path)) {
            return [];
        }
        $events = [];
        foreach (preg_split('/\r?\n/', (string) file_get_contents($path)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        return $events;
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
