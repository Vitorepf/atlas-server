<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SoftwareCompanyStewardship;

use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusCandidateQuarantineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDeepFindingEngineService;
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

/**
 * GOD-DEBULK · golden characterization for the four AutonomousEvolutionSession
 * orchestration giants (run / runCycle / selectCandidate / runOwnerFlowCycle).
 *
 * PURPOSE (operator-authorized semantic-split prep): pin the DETERMINISTIC
 * decision surface of the four giants against representative fixtures BEFORE any
 * extraction, so a later behavior-preserving split can be proven byte-equivalent
 * on this surface. Like the ControlPlaneSection golden, this pins the pure
 * flag-/status-derived decision surface — NOT the non-deterministic noise
 * (session_id / cycle_id / session_hash / generated_at / merge hashes / git
 * commit hashes / worktree tmp paths / provider spend / timestamps), which is
 * stripped in {@see self::pin()}.
 *
 * It does NOT re-pin the RSI Part-3 measured-or-reverted self-improvement path —
 * that is owned by the sacred RsiMetaMeasuredOrRevertedE2ETest, which must stay
 * green untouched; duplicating it here would add risk, not coverage. This golden
 * pins the ADJACENT deterministic branches: the no-contract merge (outcome
 * measure-exempt), the AP-806 backlog_exhausted honest-stop, the govern-sink
 * blocked path, and the selection ladder.
 */
final class AutonomousEvolutionSessionGoldenCharacterizationTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_aess_golden_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    // === Golden A: run + runCycle(dry-run) + selectCandidate happy path =======

    public function test_golden_dry_run_plans_top_candidate_without_side_effects(): void
    {
        $finding = $this->finding('afdf_top', 'Top candidate');

        $this->mockScan([$finding]);
        $this->mockRank('afdf_top');
        $this->mock(AtlasForgeProviderInvocationDriverRouter::class)->shouldNotReceive('driverInvoke');
        $this->mock(StewardshipBranchMergeGovernor::class)->shouldNotReceive('evaluate');

        $payload = $this->service()->run([
            'execute' => false,
            'cycles' => 1,
            'repo_root' => $this->emptyRepoRoot(),
        ]);

        $this->assertSame(AutonomousEvolutionSessionService::STATUS_DRY_RUN, $payload['status']);
        $this->assertFalse($payload['claim_policy']['provider_called']);
        $this->assertFalse($payload['claim_policy']['merge_performed']);

        $this->assertSame([
            'final_status' => 'dry_run_planned',
            'selected_finding_id' => 'afdf_top',
            'owner' => 'atlas_dev',
            'auto_merge_class' => 'bugfix',
            'continue_loop' => false,
            'blockers' => [],
            'provider_called' => null,
            'merge_performed' => null,
        ], $this->pin($payload['cycles'][0]));
    }

    // === Golden B: selectCandidate rejection ladder ============================

    public function test_golden_selection_rejects_operator_review_only_finding(): void
    {
        $reviewOnly = $this->finding('afdf_review', 'Operator review first', [
            'auto_execution_allowed' => false,
            'operator_review_required' => true,
        ]);
        $runnable = $this->finding('afdf_runnable', 'Autonomous-ready candidate');

        $this->mockScan([$reviewOnly, $runnable]);
        $this->mockRank('afdf_runnable');

        $payload = $this->service()->run([
            'execute' => false,
            'cycles' => 1,
            'repo_root' => $this->emptyRepoRoot(),
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertSame('dry_run_planned', $cycle['final_status']);
        $this->assertSame('afdf_runnable', $cycle['selected_finding']['finding_id']);

        $reasonsById = [];
        foreach ($cycle['selection_rejections'] ?? [] as $rejection) {
            $reasonsById[(string) ($rejection['finding_id'] ?? '')] = (string) ($rejection['reason'] ?? '');
        }
        $this->assertSame(['afdf_review' => 'auto_execution_not_allowed'], $reasonsById);
    }

    // === Golden C: runCycle AP-806 backlog_exhausted honest-stop ==============

    public function test_golden_ap806_starvation_recovery_finding_honestly_stops_without_merge(): void
    {
        // A starvation-recovery-shaped finding under execute=true is the signal
        // that the real backlog is exhausted; the loop MUST stop honestly with
        // backlog_exhausted and NEVER fabricate a merge. Injected here so the
        // honest-stop branch is pinned deterministically (no fragile lock fixture).
        $this->mock(AtlasForgeProviderInvocationDriverRouter::class)->shouldNotReceive('driverInvoke');
        $this->mock(StewardshipBranchMergeGovernor::class)->shouldNotReceive('evaluate');

        $payload = $this->service()->run([
            'execute' => true,
            'cycles' => 1,
            'scope_profile' => AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
            'repo_root' => $this->emptyRepoRoot(),
            'continue_on_blocked' => false,
            'injected_finding' => [
                'finding_id' => AutonomousEvolutionSessionService::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID,
                'finding_hash' => 'sha256:starvation',
                'title' => 'AP-790 candidate starvation recovery',
                'kind' => 'maintenance',
                'severity' => 'low',
                'owner_candidate' => 'atlas_dev',
                'origin_type' => 'ap790_candidate_starvation_recovery',
                'affected_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php'],
                'auto_execution_allowed' => true,
                'operator_review_required' => false,
            ],
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertContains($cycle['final_status'], ['blocked', 'cycle_blocked']);
        $this->assertContains('backlog_exhausted', $cycle['blockers']);
        $this->assertTrue((bool) ($cycle['provider_skipped'] ?? false));
        $this->assertTrue((bool) ($cycle['merge_skipped'] ?? false));
        $this->assertFalse($payload['claim_policy']['provider_called']);
        $this->assertFalse($payload['claim_policy']['merge_performed']);
    }

    // === Golden D: runOwnerFlowCycle full gauntlet -> merged (no contract) =====

    public function test_golden_owner_flow_completes_and_merges_a_no_contract_delivery(): void
    {
        $this->skipWithoutGit();
        [$repo, $mergeHash, $worktree] = $this->preparedRepo();

        $finding = $this->finding('afdf_merge', 'Wire the delivery end to end', [
            'affected_files' => ['app/Services/Ai/Example.php'],
            'evidence_refs' => ['expected_test:ExampleTest.php'],
        ]);

        $this->mockScan([$finding]);
        $this->mockRank('afdf_merge');
        $this->mockSandbox($worktree);
        $this->mock(AtlasForgeProviderInvocationDriverRouter::class)->shouldNotReceive('driverInvoke');
        $this->mock(Ap786OwnerFlowRunner::class, function ($mock): void {
            $mock->shouldReceive('execute')->once()->andReturn($this->ownerFlowReport(mergeAllowed: true));
        });
        $this->mock(StewardshipRuntimeResultProjector::class, function ($mock): void {
            $mock->shouldReceive('project')->once()->andReturn(['result_bridge_id' => 'srrb_g', 'inbox_item_id' => 'inbox_g']);
        });
        $this->mock(StewardshipBranchMergeGovernor::class, function ($mock) use ($mergeHash): void {
            $mock->shouldReceive('evaluate')->once()->andReturn([
                'status' => StewardshipBranchMergeGovernorService::STATUS_MERGED,
                'blockers' => [],
                'merge_result' => ['new_head' => $mergeHash, 'target' => 'main'],
            ]);
        });

        $payload = $this->service()->run([
            'execute' => true,
            'cycles' => 1,
            'auto_merge' => true,
            'repo_root' => $repo,
            'continue_on_blocked' => true,
            'validation_commands' => [],
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertSame('cycle_completed', $cycle['final_status'], json_encode($cycle['blockers'] ?? []));
        $this->assertTrue($cycle['merge_performed']);
        $this->assertTrue($cycle['continue_loop']);
        $this->assertSame([], $cycle['blockers']);
        // No outcome_contract => the M keystone is measure-EXEMPT (not reverted).
        $this->assertFalse($cycle['outcome_measured']);
        $this->assertFalse($cycle['outcome_contract_present']);
        // Cycle-usefulness verdict is recorded and is a real bool, never fabricated.
        $this->assertArrayHasKey('useful_runtime_wiring', $cycle);
        $this->assertIsBool($cycle['useful_runtime_wiring']);
        $this->assertArrayHasKey('cycle_usefulness_status', $cycle);
        $this->assertTrue($payload['claim_policy']['merge_performed']);
    }

    // === Golden E: runOwnerFlowCycle owner blocked -> govern sink =============

    public function test_golden_owner_flow_not_mergeable_blocks_through_the_govern_sink(): void
    {
        $this->skipWithoutGit();
        [$repo, , $worktree] = $this->preparedRepo();

        $finding = $this->finding('afdf_blocked', 'Owner runtime not mergeable', [
            'affected_files' => ['app/Services/Ai/Example.php'],
            'evidence_refs' => ['expected_test:ExampleTest.php'],
        ]);

        $this->mockScan([$finding]);
        $this->mockRank('afdf_blocked');
        $this->mockSandbox($worktree);
        $this->mock(AtlasForgeProviderInvocationDriverRouter::class)->shouldNotReceive('driverInvoke');
        $this->mock(Ap786OwnerFlowRunner::class, function ($mock): void {
            $mock->shouldReceive('execute')->once()->andReturn($this->ownerFlowReport(mergeAllowed: false));
        });
        $this->mock(StewardshipRuntimeResultProjector::class, function ($mock): void {
            $mock->shouldReceive('project')->once()->andReturn(['result_bridge_id' => 'srrb_gb', 'inbox_item_id' => 'inbox_gb']);
        });
        $this->mock(StewardshipBranchMergeGovernor::class)->shouldNotReceive('evaluate');

        $payload = $this->service()->run([
            'execute' => true,
            'cycles' => 1,
            'auto_merge' => true,
            'repo_root' => $repo,
            'continue_on_blocked' => true,
            'validation_commands' => [],
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertSame('blocked', $cycle['final_status']);
        $this->assertFalse($cycle['merge_performed']);
        $this->assertTrue((bool) ($cycle['merge_skipped'] ?? false));
        $this->assertNotSame([], $cycle['blockers']);
        // The govern sink ran: a blocked cycle always carries a repair policy and
        // an append-only repair-learning receipt (compounding failure memory).
        $this->assertArrayHasKey('repair_policy', $cycle);
        $this->assertArrayHasKey('repair_learning_recorded', $cycle);
        $this->assertFalse($payload['claim_policy']['merge_performed']);
    }

    // === helpers ==============================================================

    /**
     * Pin ONLY the deterministic decision surface. Volatile keys (ids, hashes,
     * tmp paths, timestamps, provider spend, verbose sub-reports) are excluded by
     * omission — this is the flag-/status-derived surface a split must preserve.
     *
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>
     */
    private function pin(array $cycle): array
    {
        return [
            'final_status' => $cycle['final_status'] ?? null,
            'selected_finding_id' => (string) data_get($cycle, 'selected_finding.finding_id', ''),
            'owner' => $cycle['owner'] ?? null,
            'auto_merge_class' => $cycle['auto_merge_class'] ?? null,
            'continue_loop' => $cycle['continue_loop'] ?? null,
            'blockers' => array_values((array) ($cycle['blockers'] ?? [])),
            'provider_called' => $cycle['provider_called'] ?? null,
            'merge_performed' => $cycle['merge_performed'] ?? null,
        ];
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
     * @param  list<array<string,mixed>>  $findings
     */
    private function mockScan(array $findings): void
    {
        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($findings): void {
            $mock->shouldReceive('scan')->once()->andReturn(['findings' => $findings, 'status' => 'ready']);
        });
    }

    private function mockRank(string $topCandidateId): void
    {
        $this->mock(StewardshipPriorityRanker::class, function ($mock) use ($topCandidateId): void {
            $mock->shouldReceive('rank')->andReturn(['top_candidate' => ['candidate_id' => $topCandidateId]]);
        });
    }

    private function mockSandbox(string $worktree): void
    {
        $this->mock(AreaFocusBranchSandboxMaterializer::class, function ($mock) use ($worktree): void {
            $mock->shouldReceive('materialize')->once()->andReturn([
                'status' => AreaFocusBranchSandboxMaterializerService::STATUS_MATERIALIZED,
                'sandbox_id' => 'afbs_golden',
                'materialization' => [
                    'worktree_path' => $worktree,
                    'branch_name' => 'atlas/area-focus/agentic_engineering_os/atlas_dev/golden',
                ],
            ]);
        });
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
     * @return array<string,mixed>
     */
    private function ownerFlowReport(bool $mergeAllowed): array
    {
        return [
            'status' => Ap786OwnerFlowExecutor::STATUS_COMPLETED,
            'uses_full_owner_runtime_chain' => true,
            'provider_router_used' => false,
            'merge_allowed' => $mergeAllowed,
            'consumption_id' => 'afcons_g',
            'release_id' => 'afrel_g',
            'queue_item_id' => 'afq_g',
            'owner_execution_id' => 'afexec_g',
            'owner_sandbox_run_id' => 'afrun_g',
            'owner_result' => ['result_id' => 'afrunres_g', 'result_status' => 'completed'],
            'result_bridge' => ['status' => 'ready_for_operator_result_review', 'result_bridge_id' => 'afobr_g'],
            'result_bridge_id' => 'afobr_g',
            'execution_result' => [
                'result_status' => 'completed',
                'provider_invoked' => true,
                'owner_cli_provider_calls' => 1,
                'summary' => 'Atlas Dev senior loop completed in the AP-756 worktree.',
                'changed_files' => ['app/Services/Ai/Example.php'],
                'tests' => ['php artisan test --filter=Example'],
            ],
            'steps' => [],
            'blockers' => $mergeAllowed ? [] : ['owner_runtime_no_patch_needed_without_proof'],
        ];
    }

    private function emptyRepoRoot(): string
    {
        $dir = $this->tmp.'/norepo';
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    private function skipWithoutGit(): void
    {
        $git = new Process(['git', '--version']);
        $git->run();
        if (! $git->isSuccessful()) {
            $this->markTestSkipped('git binary is required for the owner-flow golden cases.');
        }
    }

    /**
     * A real base repo on main (init + a "merged" commit) plus a materialized
     * worktree branch with a pending owner-flow change — the exact shape the
     * owner-flow gauntlet + commit + merge governor bind to.
     *
     * @return array{0:string,1:string,2:string}
     */
    private function preparedRepo(): array
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
        file_put_contents($repo.'/app/Services/Ai/Example.php', "<?php\n// merged change\n");
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'merged change'], $repo);
        $mergeHash = $this->gitOut(['git', 'rev-parse', 'HEAD'], $repo);

        $worktree = $this->tmp.'/worktree';
        File::ensureDirectoryExists($worktree.'/app/Services/Ai');
        $this->runGit(['git', 'init'], $worktree);
        $this->runGit(['git', 'config', 'user.email', 'atlas@example.test'], $worktree);
        $this->runGit(['git', 'config', 'user.name', 'Atlas Test'], $worktree);
        file_put_contents($worktree.'/app/Services/Ai/Example.php', "<?php\n// baseline\n");
        $this->runGit(['git', 'add', '.'], $worktree);
        $this->runGit(['git', 'commit', '-m', 'init'], $worktree);
        $this->runGit(['git', 'branch', '-M', 'atlas/area-focus/agentic_engineering_os/atlas_dev/golden'], $worktree);
        file_put_contents($worktree.'/app/Services/Ai/Example.php', "<?php\n// owner-flow change\n");

        return [$repo, $mergeHash, $worktree];
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
