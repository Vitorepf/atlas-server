<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\EngineeringKernel\Adapters\AtlasAutonomosGateAdapter;
use App\Services\Ai\EngineeringKernel\Adapters\AtlasDevGateAdapter;
use App\Services\Ai\EngineeringKernel\Adapters\AtlasForgeGateAdapter;
use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\EngineeringKernel\FalseClaimInvariant;
use App\Services\Ai\EngineeringKernel\OutcomeProofGate;
use App\Services\Ai\EngineeringKernel\Repair\RepairDiagnosisStage;
use App\Services\Ai\EngineeringKernel\SovereignHonestyFloor;
use App\Services\Ai\Context\AtlasContextRuntime;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AtlasTaskCommitVerificationGate;
use App\Services\Ai\SelfConstruction\AtlasTaskScopedCommitter;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use App\Services\Ai\SelfConstruction\AtlasTaskServingSwitch;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskCommitGovernanceChain;
use App\Services\Ai\ExecutionAuthority\AwisExecutionGatePort;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Concerns\MakesAgentControlPlaneTaskQueueOrchestrator;
use Tests\TestCase;

/**
 * Proves AtlasTaskServingService::report() explicitly whitelists outcome to
 * success|failed|give_back — an unknown outcome (typo or hostile string) returns
 * invalid_report with lease_closed=false and mutates nothing, instead of silently
 * falling through into the give_back path.
 */
final class AtlasTaskServingServiceTest extends TestCase
{
    use MakesAgentControlPlaneTaskQueueOrchestrator;
    private string $envFile = '';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.programming.strict_retrieval_gate', false);
        Storage::fake('local');
        $this->envFile = sys_get_temp_dir().'/atlas-report-env-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
        AtlasTaskServingSwitch::on();
        app()->instance(AwisExecutionGatePort::class, new class implements AwisExecutionGatePort
        {
            public function gate(?string $workspace = null, string $mode = 'conversation', string $task = '', array $conversationTexts = []): array
            {
                return [
                    'allowed' => true,
                    'status' => 'passed',
                    'mode' => $mode,
                    'workspace' => $workspace,
                    'blockers' => [],
                ];
            }
        });
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        AtlasTaskServingSwitch::$envPathOverride = null;
        @unlink($this->envFile);
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        parent::tearDown();
    }

    /** @return array{client:string, task_packet_id:string, lease_id:string} */
    private function servedTask(string $id, array $allow = ['app/Services/Foo/Bar.php']): array
    {
        $exit = Artisan::call('atlas:task:enqueue', [
            '--objective' => 'wire the FooBar into the registry',
            '--allow' => $allow,
            '--accept' => ['the FooBar resolves from the container'],
            '--evidence' => ['tests_or_gates_result'],
            '--id' => $id,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $serving = new AtlasTaskServingService($this->orchestrator());
        $res = $serving->next('client-'.$id);
        $this->assertSame('served', $res['status']);

        return [
            'client' => 'client-'.$id,
            'task_packet_id' => (string) $res['task']['task_packet_id'],
            'lease_id' => (string) $res['task']['lease_id'],
        ];
    }

    public function test_post_commit_elite_kernel_block_keeps_lease_open_and_does_not_resolve(): void
    {
        $allowedFile = 'app/Services/Foo/PostCommitGate.php';
        $repo = $this->tempGitRepoWithChangedFile($allowedFile);
        $served = $this->servedTask('op-post-commit-kernel-red', [$allowedFile]);
        $serving = new AtlasTaskServingService(
            $this->orchestrator(),
            committer: new AtlasTaskScopedCommitter(repoRootOverride: $repo),
            verifier: new AtlasTaskCommitVerificationGate(
                repoRootOverride: $repo,
                runner: static fn (array $cmd, string $cwd, float $timeout): array => ['ran' => true, 'ok' => true, 'out' => 'ok'],
            ),
            governance: new AtlasTaskCommitGovernanceChain(modeOverride: AtlasTaskCommitGovernanceChain::MODE_OFF),
            eliteKernel: $this->eliteKernel(),
            contextRuntime: $this->contextRuntime(),
        );

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'success',
            'commit' => true,
        ]);

        $this->assertSame('commit_failed', $result['status']);
        $this->assertSame('elite_kernel_fake_green', $result['reason'], json_encode($result));
        $this->assertFalse($result['lease_closed']);
        $this->assertSame($served['task_packet_id'], $result['task_packet_id']);
    }

    public function test_unknown_outcome_returns_invalid_report_and_does_not_close_lease(): void
    {
        $served = $this->servedTask('op-unknown-outcome');
        $serving = new AtlasTaskServingService($this->orchestrator());

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'definitely_not_a_real_outcome',
        ]);

        $this->assertSame('invalid_report', $result['status']);
        $this->assertSame('invalid_outcome', $result['reason']);
        $this->assertFalse($result['lease_closed']);
    }

    public function test_unknown_outcome_does_not_mutate_the_task(): void
    {
        $served = $this->servedTask('op-unknown-outcome-2');
        $serving = new AtlasTaskServingService($this->orchestrator());

        $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'hostile-injection-string',
        ]);

        // The lease is still valid: a SECOND report with a real outcome can still close it cleanly,
        // proving the invalid report above never released, completed, committed, or quarantined anything.
        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'success',
        ]);

        $this->assertSame('reported', $result['status']);
        $this->assertSame('success', $result['outcome']);
    }

    public function test_success_outcome_still_reports(): void
    {
        $served = $this->servedTask('op-success-path');
        $serving = new AtlasTaskServingService($this->orchestrator());

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'success',
        ]);

        $this->assertSame('reported', $result['status']);
        $this->assertSame('success', $result['outcome']);
    }

    public function test_failed_outcome_still_reports_as_give_back_path(): void
    {
        $served = $this->servedTask('op-failed-path');
        $serving = new AtlasTaskServingService($this->orchestrator());

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'failed',
        ]);

        $this->assertSame('reported', $result['status']);
        $this->assertSame('failed', $result['outcome']);
    }

    public function test_give_back_outcome_still_reports(): void
    {
        $served = $this->servedTask('op-give-back-path');
        $serving = new AtlasTaskServingService($this->orchestrator());

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'give_back',
        ]);

        $this->assertSame('reported', $result['status']);
        $this->assertSame('give_back', $result['outcome']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC2/AC3/AC4: contract — disabled/empty_client, self-sufficient, valid outcomes
    // ═══════════════════════════════════════════════════════════════════════

    public function test_next_returns_invalid_client_for_empty_client_id(): void
    {
        AtlasTaskServingSwitch::on();
        $serving = new AtlasTaskServingService($this->orchestrator());
        $res = $serving->next('');

        $this->assertSame('invalid_client', $res['status']);
    }

    public function test_report_validates_outcome_against_whitelist(): void
    {
        // A report may only be filed by the current lease owner and consumes its lease (A1-SC-0133).
        // The invalid-outcome branch rejects WITHOUT touching the lease, so one owned lease proves both
        // sides: a hostile/typo outcome is refused, then a whitelisted outcome on the same lease is
        // accepted. Per-outcome acceptance is covered by the dedicated success/failed/give_back tests.
        $served = $this->servedTask('op-whitelist');
        $serving = new AtlasTaskServingService($this->orchestrator());

        $bad = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], ['outcome' => 'sneaky_giveback']);
        $this->assertSame('invalid_report', $bad['status'], 'a non-whitelisted outcome is refused');

        $good = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], ['outcome' => 'give_back']);
        $this->assertNotSame('invalid_report', $good['status'], 'a whitelisted outcome on the owned lease is accepted');
    }

    private function eliteKernel(): EliteExecutorKernel
    {
        $falseClaim = new FalseClaimInvariant;
        $floor = new SovereignHonestyFloor;

        return new EliteExecutorKernel(
            new OutcomeProofGate($falseClaim),
            $falseClaim,
            $floor,
            new RepairDiagnosisStage,
            new AtlasDevGateAdapter($floor, new OutcomeProofGate($falseClaim)),
            new AtlasForgeGateAdapter,
            new AtlasAutonomosGateAdapter($floor),
        );
    }

    private function contextRuntime(): AtlasContextRuntime
    {
        return app(AtlasContextRuntime::class);
    }

    private function tempGitRepoWithChangedFile(string $relativePath): string
    {
        $repo = sys_get_temp_dir().'/atlas-task-serving-post-commit-'.bin2hex(random_bytes(5));
        mkdir($repo, 0o755, true);
        $this->tempDirs[] = $repo;

        $this->git($repo, ['init']);
        $this->git($repo, ['config', 'user.email', 'atlas-test@example.test']);
        $this->git($repo, ['config', 'user.name', 'Atlas Test']);

        $absolute = $repo.'/'.ltrim($relativePath, '/');
        mkdir(dirname($absolute), 0o755, true);
        file_put_contents($absolute, "<?php\n\nfinal class PostCommitGateFixture {}\n");
        $this->git($repo, ['add', '--', $relativePath]);
        $this->git($repo, ['commit', '-m', 'initial fixture']);

        file_put_contents($absolute, "<?php\n\nfinal class PostCommitGateFixture { public function changed(): bool { return true; } }\n");

        return $repo;
    }

    /** @param list<string> $args */
    private function git(string $cwd, array $args): void
    {
        $process = new Process(array_merge(['git'], $args), $cwd);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path) && ! is_link($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
