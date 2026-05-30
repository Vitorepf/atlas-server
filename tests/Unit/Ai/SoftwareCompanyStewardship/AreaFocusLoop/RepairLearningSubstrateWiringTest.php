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
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\RepairLearningRegistryService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchMergeGovernor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipPriorityRanker;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultProjector;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Compounding repair-learning substrate E2E.
 *
 * Drives the REAL session run() -> runOwnerFlowCycle() path twice against the
 * SAME repair-learning storage. The owner flow is faked to return a BLOCKED
 * (merge_allowed=false) verdict so the cycle is genuinely blocked through the
 * real governCycleOutcome funnel — no live provider. Proves:
 *   - Cycle 1 (cold registry): no prior learning recalled; the blocker is
 *     RECORDED into the append-only registry.
 *   - Cycle 2 (warm registry, same task class): the SAME blocker is recalled
 *     and carried forward as a repair hint on the live cycle AND injected into
 *     the finding handed to the owner flow — the loop now self-repairs with
 *     memory instead of rediscovering the same wall episode-by-episode.
 */
final class RepairLearningSubstrateWiringTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_repair_learning_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_blocked_cycle_is_learned_and_recalled_into_the_next_same_class_cycle(): void
    {
        $this->requireGit();
        $repo = $this->prepareRepo();
        $worktree = $this->prepareWorktree();

        // ---- Cycle 1: cold registry. The owner flow blocks; the cycle is
        // genuinely BLOCKED through the real funnel, recording the blocker.
        $findingHandedToOwnerFlow = [];
        $service1 = $this->driveBlockedCycle($repo, $worktree, function (array $finding) use (&$findingHandedToOwnerFlow): void {
            $findingHandedToOwnerFlow = $finding;
        });
        $payload1 = $service1->run($this->runInput($repo));

        $cycle1 = $payload1['cycles'][0];
        $this->assertSame('blocked', $cycle1['final_status']);
        $this->assertFalse($cycle1['merge_performed']);
        $this->assertContains('owner_runtime_result_not_completed', $cycle1['blockers']);
        // Cold registry: nothing recalled yet on cycle 1.
        $this->assertNull($cycle1['repair_learning_recall']);
        // But the blocker was RECORDED (write-side wiring through governCycleOutcome).
        $this->assertNotEmpty($cycle1['repair_learning_recorded']);
        $this->assertArrayNotHasKey('repair_learning', $findingHandedToOwnerFlow);

        // ---- Cycle 2: warm registry, SAME task class -> the prior blocker is
        // recalled and carried forward.
        $findingHandedToOwnerFlow = [];
        $service2 = $this->driveBlockedCycle($repo, $worktree, function (array $finding) use (&$findingHandedToOwnerFlow): void {
            $findingHandedToOwnerFlow = $finding;
        });
        $payload2 = $service2->run($this->runInput($repo));

        $cycle2 = $payload2['cycles'][0];
        $this->assertSame('blocked', $cycle2['final_status']);

        // Read-side wiring: the learned prior is recalled onto the live cycle.
        $recall = $cycle2['repair_learning_recall'];
        $this->assertIsArray($recall);
        $this->assertSame(RepairLearningRegistryService::SCHEMA, $recall['schema_version']);
        $this->assertSame('bug', $recall['task_class']);
        $this->assertContains('owner_runtime_result_not_completed', $recall['top_prior_blockers']);
        $this->assertSame(1, $recall['prior_blocked_occurrences']);

        // And it was injected into the finding the owner flow actually received,
        // so the runtime carries learned context (not just an audit field).
        $this->assertArrayHasKey('repair_learning', $findingHandedToOwnerFlow);
        $this->assertContains(
            'owner_runtime_result_not_completed',
            $findingHandedToOwnerFlow['repair_learning']['top_prior_blockers'],
        );

        // Durable, append-only proof: the registry holds the recorded rows.
        $registry = new RepairLearningRegistryService();
        $registry->setStorageRootForTesting($this->tmp.'/sessions/repair-learning');
        $directRecall = $registry->recallForTaskClass('agentic_engineering_os', AutonomousEvolutionSessionService::DEFAULT_FOCUS, 'bug');
        $this->assertSame(2, $directRecall['total_blocked_occurrences']);
    }

    /**
     * @return array<string,mixed>
     */
    private function runInput(string $repo): array
    {
        return [
            'execute' => true,
            'cycles' => 1,
            'auto_merge' => true,
            'repo_root' => $repo,
            'continue_on_blocked' => true,
            'validation_commands' => [],
        ];
    }

    /**
     * @param  callable(array<string,mixed>):void  $captureFinding
     */
    private function driveBlockedCycle(string $repo, string $worktree, callable $captureFinding): AutonomousEvolutionSessionService
    {
        $finding = $this->finding('afdf_repair', 'Repair-learning candidate');

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($finding): void {
            $mock->shouldReceive('scan')->once()->andReturn(['findings' => [$finding], 'status' => 'ready']);
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->andReturn(['top_candidate' => ['candidate_id' => 'afdf_repair']]);
        });
        $this->mock(AreaFocusBranchSandboxMaterializer::class, function ($mock) use ($worktree): void {
            $mock->shouldReceive('materialize')->once()->andReturn([
                'status' => AreaFocusBranchSandboxMaterializerService::STATUS_MATERIALIZED,
                'sandbox_id' => 'afbs_repair',
                'materialization' => [
                    'worktree_path' => $worktree,
                    'branch_name' => 'atlas/area-focus/agentic_engineering_os/atlas_dev/repair',
                ],
            ]);
        });
        $this->mock(AtlasForgeProviderInvocationDriverRouter::class)->shouldNotReceive('driverInvoke');
        // Owner flow BLOCKS: merge_allowed=false -> cycle is genuinely blocked.
        $this->mock(Ap786OwnerFlowRunner::class, function ($mock) use ($captureFinding): void {
            $mock->shouldReceive('execute')->once()->andReturnUsing(function (array $input) use ($captureFinding): array {
                $captureFinding(is_array($input['finding'] ?? null) ? $input['finding'] : []);

                return [
                    'status' => Ap786OwnerFlowExecutor::STATUS_COMPLETED,
                    'uses_full_owner_runtime_chain' => true,
                    'provider_router_used' => false,
                    'merge_allowed' => false,
                    'blockers' => ['owner_runtime_result_not_completed'],
                    'execution_result' => ['result_status' => 'failed', 'provider_invoked' => true],
                    'steps' => [],
                ];
            });
        });
        $this->mock(StewardshipRuntimeResultProjector::class, function ($mock): void {
            $mock->shouldReceive('project')->andReturn([
                'result_bridge_id' => 'srrb_repair',
                'inbox_item_id' => 'inbox_repair',
            ]);
        });
        $this->mock(StewardshipBranchMergeGovernor::class)->shouldNotReceive('evaluate');

        $service = app(AutonomousEvolutionSessionService::class);
        $service->setStorageDirForTesting($this->tmp.'/sessions');
        $quarantine = app(AreaFocusCandidateQuarantineService::class);
        $quarantine->setStorageRootForTesting($this->tmp.'/quarantine');
        $service->setCandidateQuarantineForTesting($quarantine);

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
            'why_it_matters' => 'Repair latency matters.',
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

    private function prepareRepo(): string
    {
        $repo = $this->tmp.'/repo';
        File::ensureDirectoryExists($repo.'/app/Services/Ai');
        $this->runGit(['git', 'init'], $repo);
        $this->runGit(['git', 'config', 'user.email', 'atlas@example.test'], $repo);
        $this->runGit(['git', 'config', 'user.name', 'Atlas Test'], $repo);
        file_put_contents($repo.'/app/Services/Ai/Example.php', "<?php\n// baseline\n");
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'init'], $repo);
        $this->runGit(['git', 'branch', '-M', 'main'], $repo);

        return $repo;
    }

    private function prepareWorktree(): string
    {
        $worktree = $this->tmp.'/worktree';
        File::ensureDirectoryExists($worktree.'/app/Services/Ai');
        $this->runGit(['git', 'init'], $worktree);
        $this->runGit(['git', 'config', 'user.email', 'atlas@example.test'], $worktree);
        $this->runGit(['git', 'config', 'user.name', 'Atlas Test'], $worktree);
        file_put_contents($worktree.'/app/Services/Ai/Example.php', "<?php\n// baseline\n");
        $this->runGit(['git', 'add', '.'], $worktree);
        $this->runGit(['git', 'commit', '-m', 'init'], $worktree);
        $this->runGit(['git', 'branch', '-M', 'atlas/area-focus/agentic_engineering_os/atlas_dev/repair'], $worktree);
        file_put_contents($worktree.'/app/Services/Ai/Example.php', "<?php\n// owner-flow change\n");

        return $worktree;
    }

    private function requireGit(): void
    {
        $git = new Process(['git', '--version']);
        $git->run();
        if (! $git->isSuccessful()) {
            $this->markTestSkipped('git binary is required for the repair-learning E2E test.');
        }
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
