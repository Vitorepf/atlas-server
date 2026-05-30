<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Rsi;

use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use App\Services\Ai\Rsi\GroundTruthValueAdapterService;
use App\Services\Ai\Rsi\OperatorAcceptanceSignalPort;
use App\Services\Ai\Rsi\RsiGitRevertPort;
use App\Services\Ai\Rsi\RsiOutcomeMaterializerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusCandidateQuarantineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDeepFindingEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowExecutor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowRunner;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\MetricLedgerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Rsi\ComponentValueLedgerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Rsi\SelfTargetSelectorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchMergeGovernor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchMergeGovernorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipPriorityRanker;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultProjector;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * RSI Part 3 E2E: META measured-or-reverted at the COMPONENT-VALUE level.
 *
 * Drives the REAL session run() -> runOwnerFlowCycle() path with a real git repo
 * and a real merge commit on main, for a SELF-improvement cycle (the finding
 * carries capability_gap.drift_kind = loop_component_low_value_per_token and an
 * outcome_contract whose metric is the target component's value-per-token).
 *
 * A FAKE metric measurer keeps the internal outcome MET (so the merge survives
 * the M keystone), and a FAKE RsiOutcomeMaterializer is injected whose
 * GroundTruthValueAdapter folds an injected ComponentValueLedger and whose
 * real-world OPERATOR-ACCEPTANCE signal + git-revert are deterministic fakes (no
 * real operator query, no real git revert run in the test). Two cases proven:
 *
 *   - operator ACCEPTED + component value-per-token ROSE past baseline+delta ->
 *     self-improvement KEPT, cycle completed, rsi_self_improvement_proven=true.
 *   - operator accepted but value did NOT rise -> self-improvement git-REVERTED,
 *     cycle BLOCKED + learned, real Git proof (HEAD is the revert commit).
 */
final class RsiMetaMeasuredOrRevertedE2ETest extends TestCase
{
    private const COMPONENT_ID = 'repair_loop';

    private const COMPONENT_PATH = 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/RepairAgentFeedbackContextBuilderService.php';

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_rsi_meta_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_self_improvement_that_raised_value_per_token_is_kept(): void
    {
        [$repo, $mergeHash, , $worktree] = $this->prepareMergedRepo();

        // Post-merge ground-truth value rose to 5.0 (baseline 1.0 + delta 1.0 => required 2.0).
        $reverted = [];
        $service = $this->driveSelfImprovementCycle($repo, $worktree, $mergeHash, postValuePerToken: 5.0, operatorAccepted: true, reverted: $reverted);

        $cycle = $this->runSelfCycle($service, $repo, $mergeHash);

        $this->assertSame('cycle_completed', $cycle['final_status']);
        $this->assertTrue($cycle['merge_performed']);
        $this->assertTrue($cycle['outcome_measured']);
        $this->assertTrue($cycle['rsi_self_improvement_proven']);
        $this->assertSame(RsiOutcomeMaterializerService::STATUS_CONSOLIDATED, $cycle['rsi_meta_outcome']['status']);
        $this->assertSame('proven', $cycle['rsi_meta_outcome']['outcome']['state']);

        // KEPT: HEAD is still the merge commit and the git-revert port was never called.
        $this->assertSame($mergeHash, $this->gitOut(['git', 'rev-parse', 'HEAD'], $repo));
        $this->assertSame([], $reverted, 'A proven self-improvement must NEVER be reverted.');
    }

    public function test_self_improvement_that_did_not_raise_value_is_reverted_and_learned(): void
    {
        [$repo, $mergeHash, $preMergeHead, $worktree] = $this->prepareMergedRepo();

        // Post-merge ground-truth value is 1.0 == baseline (no rise; required 2.0).
        $reverted = [];
        $service = $this->driveSelfImprovementCycle($repo, $worktree, $mergeHash, postValuePerToken: 1.0, operatorAccepted: true, reverted: $reverted);

        $cycle = $this->runSelfCycle($service, $repo, $mergeHash);

        $this->assertSame('blocked', $cycle['final_status']);
        $this->assertFalse($cycle['merge_performed']);
        $this->assertTrue($cycle['merge_reverted']);
        $this->assertFalse($cycle['outcome_measured']);
        $this->assertFalse($cycle['rsi_self_improvement_proven']);
        $this->assertTrue($cycle['rsi_self_improvement_learned']);
        $this->assertContains('rsi_self_improvement_value_not_raised:'.self::COMPONENT_ID, $cycle['blockers']);

        $meta = $cycle['rsi_meta_outcome'];
        $this->assertSame(RsiOutcomeMaterializerService::STATUS_REVERTED, $meta['status']);
        $this->assertSame('reverted', $meta['outcome']['action']);
        $this->assertTrue($meta['outcome']['refuted_by_reality']);
        $this->assertTrue($meta['outcome']['learned']);

        // The git-revert PORT was invoked once for the real merge hash (revert, not reset).
        $this->assertCount(1, $reverted);
        $this->assertSame($mergeHash, $reverted[0][1]);
        $this->assertSame(realpath($repo), realpath($reverted[0][0]));
        // Real Git proof: a revert commit advanced main past the merge (NOT a reset).
        $head = $this->gitOut(['git', 'rev-parse', 'HEAD'], $repo);
        $this->assertNotSame($mergeHash, $head);
        $this->assertNotSame($preMergeHead, $head);
    }

    public function test_self_improvement_without_operator_acceptance_is_reverted(): void
    {
        [$repo, $mergeHash, , $worktree] = $this->prepareMergedRepo();

        // Value rose, but the operator did NOT accept -> reality alone is not enough.
        $reverted = [];
        $service = $this->driveSelfImprovementCycle($repo, $worktree, $mergeHash, postValuePerToken: 9.0, operatorAccepted: false, reverted: $reverted);

        $cycle = $this->runSelfCycle($service, $repo, $mergeHash);

        $this->assertSame('blocked', $cycle['final_status']);
        $this->assertTrue($cycle['merge_reverted']);
        $this->assertFalse($cycle['rsi_meta_outcome']['outcome']['operator_accepted']);
        $this->assertCount(1, $reverted);
        $this->assertSame($mergeHash, $reverted[0][1]);
    }

    /**
     * @return array<string,mixed>
     */
    private function runSelfCycle(AutonomousEvolutionSessionService $service, string $repo, string $mergeHash): array
    {
        $payload = $service->run([
            'execute' => true,
            'cycles' => 1,
            'auto_merge' => true,
            'repo_root' => $repo,
            'continue_on_blocked' => true,
            'validation_commands' => [],
            'outcome_contract' => [
                'metric_id' => 'rsi_value_per_token_'.self::COMPONENT_ID,
                'baseline' => 1.0,
                'target_delta' => 1.0,
                'measure_command' => 'echo metric',
                'metric_json_path' => 'metric',
            ],
        ]);

        return $payload['cycles'][0];
    }

    /**
     * Build a real git repo on main with an init commit and a "merged" commit.
     *
     * @return array{0:string,1:string,2:string,3:string}
     */
    private function prepareMergedRepo(): array
    {
        $git = new Process(['git', '--version']);
        $git->run();
        if (! $git->isSuccessful()) {
            $this->markTestSkipped('git binary is required for the RSI meta E2E test.');
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

        file_put_contents($repo.'/app/Services/Ai/Example.php', "<?php\n// self-improvement merged change\n");
        $this->runGit(['git', 'add', '.'], $repo);
        $this->runGit(['git', 'commit', '-m', 'self-improvement merged change'], $repo);
        $mergeHash = $this->gitOut(['git', 'rev-parse', 'HEAD'], $repo);

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
     * Wire the session for the SELF-improvement merged-cycle path, injecting a
     * FAKE RsiOutcomeMaterializer whose GroundTruthValueAdapter returns a
     * controlled post-merge value-per-token + operator-acceptance, and whose
     * git-revert port records the call instead of running real git.
     *
     * @param  list<array{0:string,1:string}>  $reverted  by-ref capture of revert(repo, hash) calls
     */
    private function driveSelfImprovementCycle(string $repo, string $worktree, string $mergeHash, float $postValuePerToken, bool $operatorAccepted, array &$reverted): AutonomousEvolutionSessionService
    {
        $finding = $this->selfFinding();

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($finding): void {
            $mock->shouldReceive('scan')->once()->andReturn(['findings' => [$finding], 'status' => 'ready']);
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->andReturn(['top_candidate' => ['candidate_id' => 'rsi_self']]);
        });
        $this->mock(AreaFocusBranchSandboxMaterializer::class, function ($mock) use ($worktree): void {
            $mock->shouldReceive('materialize')->once()->andReturn([
                'status' => AreaFocusBranchSandboxMaterializerService::STATUS_MATERIALIZED,
                'sandbox_id' => 'afbs_rsi',
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
            $mock->shouldReceive('project')->once()->andReturn(['result_bridge_id' => 'srrb_rsi', 'inbox_item_id' => 'inbox_rsi']);
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

        // Internal M keystone: keep the merge (outcome MET) so the meta gate runs.
        $metric = new MetricLedgerService;
        $metric->setStorageRootForTesting($this->tmp.'/metric_ledger');
        $metric->setMeasurerForTesting(static fn (): array => ['ok' => true, 'exit_code' => 0, 'out' => '{"metric": 2.0}', 'err' => '']);
        $service->setMetricLedgerForTesting($metric);

        $componentLedger = new ComponentValueLedgerService;
        $componentLedger->setStorageRootForTesting($this->tmp.'/component_ledger');
        $service->setComponentValueLedgerForTesting($componentLedger);

        // Fake real-signal seams: operator acceptance + ground-truth value + revert.
        $operatorPort = new class($operatorAccepted) implements OperatorAcceptanceSignalPort
        {
            public function __construct(private readonly bool $accepted) {}

            public function isAcceptedByOperator(string $mergeHash, string $componentId): array
            {
                return $this->accepted
                    ? ['accepted' => true, 'evidence_ref' => 'fake_evidence', 'confidence' => 1.0, 'reason' => 'operator_accepted']
                    : ['accepted' => false, 'evidence_ref' => null, 'confidence' => null, 'reason' => 'no_operator_acceptance_evidence'];
            }
        };

        // The ground-truth adapter folds a ComponentValueLedger seeded with a
        // single PROVEN cycle whose value-per-token == the desired post-merge value.
        $groundTruthLedger = new ComponentValueLedgerService;
        $groundTruthLedger->setStorageRootForTesting($this->tmp.'/ground_truth_ledger');
        $this->seedProvenValuePerToken($groundTruthLedger, $postValuePerToken);
        $adapter = new GroundTruthValueAdapterService($groundTruthLedger, $operatorPort);

        $revertPort = new class($reverted) implements RsiGitRevertPort
        {
            /** @param list<array{0:string,1:string}> $log */
            public function __construct(private array &$log) {}

            public function revert(string $repoRoot, string $mergeHash): array
            {
                $this->log[] = [$repoRoot, $mergeHash];

                // Deterministic fake: simulate a real `git revert --no-edit` by
                // committing a revert on the test repo (real Git proof, no reset).
                $revert = new Process(['git', 'revert', '--no-edit', $mergeHash], $repoRoot);
                $revert->setTimeout(30);
                $revert->run();
                $head = new Process(['git', 'rev-parse', 'HEAD'], $repoRoot);
                $head->setTimeout(30);
                $head->run();

                return ['reverted' => true, 'revert_commit_hash' => trim($head->getOutput()), 'detail' => 'git_revert_no_edit'];
            }
        };

        $materializer = new RsiOutcomeMaterializerService($adapter, $revertPort);
        $materializer->setStorageRootForTesting($this->tmp.'/rsi_outcomes');
        $service->setRsiOutcomeMaterializerForTesting($materializer);

        return $service;
    }

    /**
     * Append one PROVEN ComponentValueLedger cycle so the ground-truth adapter
     * folds the target component's value-per-token to exactly $valuePerToken.
     */
    private function seedProvenValuePerToken(ComponentValueLedgerService $ledger, float $valuePerToken): void
    {
        // proven_value_delta / total_tokens == value_per_token. Use 1 token.
        $ledger->recordCycle(
            ['area_id' => 'agentic_engineering_os', 'focus' => 'dev_forge', 'cycle_id' => 'seed_proven', 'merge_hash' => 'seed'],
            ['outcome_met' => true, 'measured_delta' => $valuePerToken],
            [self::COMPONENT_ID => ['tokens' => 1, 'flags' => []]],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function selfFinding(): array
    {
        return [
            'finding_id' => 'rsi_self_repair_loop',
            'finding_hash' => 'sha256:rsi_self_repair_loop',
            'title' => 'Capability drift ('.SelfTargetSelectorService::SELF_DRIFT_KIND.'): loop_component:'.self::COMPONENT_ID,
            'detail' => 'Self-improvement target for under-delivering loop component.',
            'why_it_matters' => 'Value-per-token matters.',
            'kind' => 'implementation',
            'severity' => 'high',
            'owner_candidate' => 'atlas_dev',
            'affected_files' => ['app/Services/Ai/Example.php'],
            'affected_docs' => [],
            'evidence_refs' => ['expected_test:RepairTest.php'],
            'origin_type' => 'capability_drift_'.SelfTargetSelectorService::SELF_DRIFT_KIND,
            'auto_execution_allowed' => true,
            'operator_review_required' => false,
            'capability_gap' => [
                'capability' => 'loop_component:'.self::COMPONENT_ID,
                'drift_kind' => SelfTargetSelectorService::SELF_DRIFT_KIND,
                'anchor_id' => 'self_'.self::COMPONENT_ID,
                'anchor_verdict' => 'confirmed',
                'gap_hash' => 'sha256:self_gap',
            ],
            'outcome_contract' => [
                'metric_id' => 'rsi_value_per_token_'.self::COMPONENT_ID,
                'baseline' => 1.0,
                'target_delta' => 1.0,
                'measure_command' => 'echo metric',
                'metric_json_path' => 'metric',
            ],
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
            'consumption_id' => 'afcons_rsi',
            'release_id' => 'afrel_rsi',
            'queue_item_id' => 'afq_rsi',
            'owner_execution_id' => 'afexec_rsi',
            'owner_sandbox_run_id' => 'afrun_rsi',
            'owner_result' => ['result_id' => 'afrunres_rsi', 'result_status' => 'completed'],
            'result_bridge' => ['status' => 'ready_for_operator_result_review', 'result_bridge_id' => 'afobr_rsi'],
            'result_bridge_id' => 'afobr_rsi',
            'execution_result' => [
                'result_status' => 'completed',
                'provider_invoked' => true,
                'owner_cli_provider_calls' => 1,
                'summary' => 'Atlas Dev senior loop completed in the AP-756 worktree.',
                'changed_files' => ['app/Services/Ai/Example.php'],
                'tests' => ['php artisan test --filter=Repair'],
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
