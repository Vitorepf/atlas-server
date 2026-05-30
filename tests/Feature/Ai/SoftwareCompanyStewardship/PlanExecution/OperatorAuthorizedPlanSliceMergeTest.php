<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SoftwareCompanyStewardship\PlanExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowExecutor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowRunner;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchMergeGovernorService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultProjector;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * FASE 0 close-out · operator-authorized injected plan-slice MUST merge.
 *
 * This is the END-TO-END proof the prior workflow never produced: a completed,
 * operator-authorized, clean 1-file plan slice is driven through the REAL
 * {@see AutonomousEvolutionSessionService::runOwnerFlowCycle} merge path and
 * actually fast-forward merges to `main`, with a real merge hash on the real
 * {@see StewardshipBranchMergeGovernorService} (real git ff-only merge).
 *
 * No live provider is invoked: the {@see Ap786OwnerFlowRunner} seam is injected
 * with a deterministic completed owner-flow result (provider_calls=2, real
 * changed file inside allowed_files). The result-bridge projector is faked to a
 * STATUS_READY receipt so the upstream AP-765/AP-791 evidence gates pass without
 * a migrated DB — that is upstream evidence, NOT the merge gate; the merge gate
 * (governor + git) remains fully real and is the subject under test.
 *
 * The negative test proves the gate is not weakened: a slice that is NOT
 * operator-authorized (no autonomous_execution_reason) does NOT merge — the
 * governor withholds it for operator review (STATUS_AUTO_MERGE_ELIGIBLE), so
 * main is never touched.
 */
final class OperatorAuthorizedPlanSliceMergeTest extends TestCase
{
    private string $repoRoot = '';

    private string $worktree = '';

    protected function tearDown(): void
    {
        foreach ([$this->worktree, $this->repoRoot] as $dir) {
            if ($dir !== '' && is_dir($dir)) {
                (new Process(['rm', '-rf', $dir]))->run();
            }
        }
        parent::tearDown();
    }

    public function test_operator_authorized_plan_slice_fast_forward_merges_to_main(): void
    {
        $allowedFile = 'app/Services/Ai/PlanSliceFaseZeroProof.php';
        [$branch, $finding] = $this->seedRepoWithCleanSlice($allowedFile, autonomous: true);

        $cycle = $this->runOwnerFlowCycle($branch, $finding, $allowedFile, autoMerge: true);

        $this->assertSame('cycle_completed', $cycle['final_status'] ?? null, json_encode($cycle['blockers'] ?? []));
        $this->assertTrue($cycle['merge_performed'] ?? false, 'the operator-authorized slice must really merge to main');
        $this->assertNotSame('', (string) ($cycle['merge_hash'] ?? ''), 'a real merge hash must be recorded');
        $this->assertSame(
            StewardshipBranchMergeGovernorService::STATUS_MERGED,
            (string) data_get($cycle, 'merge_governance.status'),
        );
        $this->assertTrue(
            (bool) data_get($cycle, 'merge_governance.auto_merge_policy.injected_plan_slice_code_auto_merge_authorized'),
            'the injected-plan-slice auto-merge exception must be the authority that merged',
        );
        // The branch commit really advanced main (ff-only): main HEAD == branch HEAD.
        $this->assertSame($this->headOf('main'), $this->headOf($branch), 'main must fast-forward to the branch tip');
        $this->assertSame((string) ($cycle['merge_hash'] ?? ''), $this->headOf('main'));
    }

    public function test_unauthorized_slice_does_not_merge_to_main(): void
    {
        $allowedFile = 'app/Services/Ai/PlanSliceFaseZeroUnauth.php';
        [$branch, $finding] = $this->seedRepoWithCleanSlice($allowedFile, autonomous: false);
        $mainBefore = $this->headOf('main');

        $cycle = $this->runOwnerFlowCycle($branch, $finding, $allowedFile, autoMerge: true);

        $this->assertFalse($cycle['merge_performed'] ?? true, 'an unauthorized slice must NOT auto-merge to main');
        $this->assertSame('', (string) ($cycle['merge_hash'] ?? ''));
        $this->assertSame($mainBefore, $this->headOf('main'), 'main must be byte-identical (untouched)');
        $this->assertFalse(
            (bool) data_get($cycle, 'merge_governance.auto_merge_policy.injected_plan_slice_code_auto_merge_authorized'),
        );
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private function seedRepoWithCleanSlice(string $allowedFile, bool $autonomous): array
    {
        $this->repoRoot = sys_get_temp_dir().'/atlas_fase0_repo_'.uniqid('', true);
        mkdir($this->repoRoot, 0777, true);
        $this->git($this->repoRoot, ['init', '-q', '-b', 'main']);
        $this->git($this->repoRoot, ['config', 'user.email', 'test@atlas.local']);
        $this->git($this->repoRoot, ['config', 'user.name', 'Atlas Test']);
        $this->git($this->repoRoot, ['config', 'commit.gpgsign', 'false']);
        file_put_contents($this->repoRoot.'/README.md', "# fixture\n");
        $this->git($this->repoRoot, ['add', '-A']);
        $this->git($this->repoRoot, ['commit', '-q', '-m', 'baseline']);

        $branch = 'atlas/plan-slice-fase0-'.substr(md5($allowedFile), 0, 8);
        $this->worktree = $this->repoRoot.'_wt';
        $this->git($this->repoRoot, ['worktree', 'add', '-q', '-b', $branch, $this->worktree, 'main']);

        // A real, clean, final delivery (no scaffold/TODO/placeholder markers).
        $target = $this->worktree.'/'.$allowedFile;
        mkdir(dirname($target), 0777, true);
        file_put_contents($target, $this->cleanPhpClass());
        // Leave the file UNCOMMITTED — the cycle's commitSandbox stages+commits it,
        // exactly as the live owner-flow path does after the worker writes the diff.

        $finding = [
            'finding_id' => 'FASE0_'.($autonomous ? 'AUTH' : 'UNAUTH'),
            'finding_hash' => 'sha256:fase0_'.($autonomous ? 'auth' : 'unauth'),
            'title' => 'Fase 0 plan slice proof',
            'detail' => 'Materialize a bounded plan-slice file.',
            'kind' => 'plan_slice',
            'severity' => 'medium',
            'owner_candidate' => 'atlas_dev',
            'affected_files' => [$allowedFile],
            'acceptance_criteria' => ['behaviour proven by a test'],
            'auto_execution_allowed' => $autonomous,
            'operator_review_required' => ! $autonomous,
        ];
        if ($autonomous) {
            $finding['autonomous_execution_reason'] = 'operator_authorized_plan_execution';
        }

        return [$branch, $finding];
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function runOwnerFlowCycle(string $branch, array $finding, string $allowedFile, bool $autoMerge): array
    {
        $allowedFiles = [$allowedFile];

        // Inject a deterministic completed owner-flow result (no live provider).
        $this->app->instance(Ap786OwnerFlowRunner::class, new class($allowedFiles) implements Ap786OwnerFlowRunner {
            /** @param list<string> $allowedFiles */
            public function __construct(private array $allowedFiles) {}

            public function execute(array $input): array
            {
                $result = [
                    'result_status' => 'completed',
                    'summary' => 'plan slice materialized',
                    'changed_files' => $this->allowedFiles,
                    'validation_commands' => ['git diff --check'],
                    'tests' => ['git diff --check'],
                    'runtime_invocation' => [
                        'command_result' => ['owner_cli_provider_calls' => 2],
                    ],
                ];

                return [
                    'status' => Ap786OwnerFlowExecutor::STATUS_COMPLETED,
                    'owner' => 'atlas_dev',
                    'merge_allowed' => true,
                    'execution_result' => $result,
                    'owner_result' => $result,
                    'result_bridge_id' => 'rb_fake_'.substr(md5(json_encode($input)), 0, 12),
                    'blockers' => [],
                ];
            }
        });

        // Fake the result-bridge projector so the upstream AP-765/AP-791 evidence
        // gate passes deterministically (STATUS_READY + result_bridge_id) without a
        // migrated DB. This is upstream evidence, NOT the merge authority — the real
        // governor + real git ff-merge below remain the subject under test.
        $this->app->instance(StewardshipRuntimeResultProjector::class, new class implements StewardshipRuntimeResultProjector {
            public function project(array $input): array
            {
                return [
                    'status' => StewardshipRuntimeResultBridgeService::STATUS_READY,
                    'result_bridge_id' => 'rb_proj_'.substr(md5(json_encode($input)), 0, 12),
                    'inbox_item_id' => 'inbox_proj_1',
                ];
            }
        });

        $session = $this->app->make(AutonomousEvolutionSessionService::class);

        $input = [
            'area_id' => 'plan_fase0',
            'focus' => 'dev_forge',
            'repo_root' => $this->repoRoot,
            'actor' => 'operator_test',
            'auto_merge' => $autoMerge,
            'allow_code_auto_merge' => true,
            'max_auto_merge_files' => 5,
            'validation_commands' => [],
            'continue_on_blocked' => false,
            'pull_main' => false,
        ];

        $method = new ReflectionMethod($session, 'runOwnerFlowCycle');

        return (array) $method->invoke(
            $session,
            'cycle_fase0_1',
            0,
            $input,
            $finding,
            ['priority_report' => ['plan_injected' => true], 'selection_rejections' => []],
            'balanced',
            'atlas_dev',
            $allowedFiles,
            'code_or_mixed',
            ['status' => 'ready'],
            ['sandbox_id' => 'sbx_fase0_1'],
            $this->worktree,
            $branch,
            ['status' => 'ready'],
            ['status' => 'ready'],
        );
    }

    private function cleanPhpClass(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Fase 0 plan-slice proof: a fully delivered, bounded capability with no
 * scaffold or deferred work. Returns a deterministic signal.
 */
final class PlanSliceFaseZeroProof
{
    public function signal(): string
    {
        return 'fase_zero_delivered';
    }
}
PHP;
    }

    private function headOf(string $ref): string
    {
        return trim((string) ($this->git($this->repoRoot, ['rev-parse', $ref])['out'] ?? ''));
    }

    /**
     * @param  list<string>  $args
     * @return array{ok:bool,out:string,err:string}
     */
    private function git(string $cwd, array $args): array
    {
        $process = new Process(array_merge(['git'], $args), $cwd);
        $process->run();

        return [
            'ok' => $process->isSuccessful(),
            'out' => $process->getOutput(),
            'err' => $process->getErrorOutput(),
        ];
    }
}
