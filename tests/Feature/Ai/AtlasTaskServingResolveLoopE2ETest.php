<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AtlasTaskBrainReplenisher;
use App\Services\Ai\SelfConstruction\AtlasTaskCommitVerificationGate;
use App\Services\Ai\SelfConstruction\AtlasTaskScopedCommitter;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * THE GUARANTEE — the FULL loop runs without breaking, looping, or corrupting main:
 * brain replenishes doc-gap tasks → a worker pulls → creates the class+test in its allowed_files → reports with
 * --commit (Atlas commits the scope) → loops → the queue drains and the loop TERMINATES. Mechanical worker (not
 * a real AI) so the SERVING + COMMIT + LOOP machinery is what is proven. Runs in a throwaway git repo.
 */
final class AtlasTaskServingResolveLoopE2ETest extends TestCase
{
    private string $repo = '';

    private string $envFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->repo = sys_get_temp_dir().'/atlas-resolve-'.bin2hex(random_bytes(5));
        @mkdir($this->repo, 0775, true);
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'test@atlas.local']);
        $this->git(['config', 'user.name', 'Atlas Test']);
        @file_put_contents($this->repo.'/README.md', "seed\n");
        $this->git(['add', 'README.md']);
        $this->git(['commit', '-q', '-m', 'seed']);

        $this->envFile = sys_get_temp_dir().'/atlas-resolve-env-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        @unlink($this->envFile);
        $this->rmrf($this->repo);
        parent::tearDown();
    }

    public function test_the_full_brain_to_resolved_loop_runs_clean_and_terminates(): void
    {
        $orch = $this->orchestrator();
        $enqueued = (new AtlasTaskBrainReplenisher($orch))->replenishFromModel($this->model(), 'app/Demo', targetMin: 50, maxPerRun: 50);
        $this->assertSame(3, $enqueued['enqueued_count'], 'the brain filled the queue with 3 resolvable doc-gap tasks');

        // Verifier + committer MUST share the same repo (the throwaway one), or the server-side verification
        // checks the wrong tree. The full chain — Fase-2 verify → governance (observe) → scoped commit — is
        // proven here against ONE repo.
        $serving = new AtlasTaskServingService(
            $orch,
            null,
            null,
            new AtlasTaskScopedCommitter(null, $this->repo),
            new AtlasTaskCommitVerificationGate($this->repo),
        );

        $resolved = [];
        $iterations = 0;
        $maxIterations = 30; // a bound that PROVES termination — a real infinite loop would blow past it.
        while ($iterations++ < $maxIterations) {
            $res = $serving->next('worker-1');
            if ($res['status'] !== 'served') {
                break; // queue drained → honest empty → the loop ENDS (no spin).
            }
            $task = $res['task'];

            // The worker "implements": create the class + test at the EXACT allowed_files paths.
            foreach ($task['allowed_files'] as $rel) {
                $this->writeStub($rel);
            }

            $report = $serving->report('worker-1', $task['task_packet_id'], $task['lease_id'], ['outcome' => 'success', 'commit' => true]);
            $this->assertSame('resolved', $report['status'], 'each task resolves (Atlas commits its scope)');
            $this->assertSame($task['allowed_files'], $report['files_committed'], 'the commit holds exactly the task scope');
            $resolved[] = $task['task_packet_id'];
        }

        // GUARANTEES:
        $this->assertLessThan($maxIterations, $iterations, 'the loop TERMINATED — it never spun forever');
        $this->assertCount(3, array_unique($resolved), 'all 3 tasks were resolved exactly once');
        $this->assertSame('no_claimable_task', $serving->next('worker-1')['status'], 'the drained queue is honestly empty');
        $this->assertSame([], $this->dirtyFiles(), 'the working tree is CLEAN — nothing half-committed left on main');
        $this->assertSame(4, $this->commitCount(), 'seed + 3 scoped commits, nothing extra');
    }

    private function writeStub(string $rel): void
    {
        $path = $this->repo.'/'.$rel;
        @mkdir(\dirname($path), 0775, true);
        // Minimal valid content (the committer commits files, it does not run them — the AI runs the real tests).
        $isTest = str_contains($rel, 'tests/');
        $class = pathinfo($rel, PATHINFO_FILENAME);
        @file_put_contents($path, "<?php\n\n// ".($isTest ? 'test for' : 'implementation of')." {$class}\n");
    }

    /** @return list<string> */
    private function dirtyFiles(): array
    {
        $out = trim($this->git(['status', '--porcelain', '--untracked-files=all'])['out']);

        return $out === '' ? [] : array_values(array_filter(explode("\n", $out)));
    }

    private function commitCount(): int
    {
        return (int) trim($this->git(['rev-list', '--count', 'HEAD'])['out']);
    }

    private function model(): AtlasLoopScopeComprehensionModel
    {
        return new AtlasLoopScopeComprehensionModel(
            inventory: [['rel_path' => 'app/Demo/Existing.php', 'fqcn' => 'App\\Demo\\Existing', 'is_orphan' => false, 'clone_cluster_id' => null]],
            edges: [],
            orphans: [],
            cloneClusters: [],
            forbidden: [],
            docPurposes: [],
            docStatedGaps: [
                'a durable retry budget for provider calls',
                'a circuit breaker for the lease registry',
                'an append only audit of every served packet',
            ],
            snapshotId: 'resolve-e2e',
        );
    }

    private function orchestrator(): AgentControlPlaneTaskQueueOrchestrator
    {
        return new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneClaimLeaseRepository,
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );
    }

    /** @param list<string> $args @return array{code:int,out:string,err:string} */
    private function git(array $args): array
    {
        $p = new Process(array_merge(['git'], $args), $this->repo);
        $p->run();

        return ['code' => (int) $p->getExitCode(), 'out' => $p->getOutput(), 'err' => $p->getErrorOutput()];
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $i) {
            if ($i === '.' || $i === '..') {
                continue;
            }
            $p = $dir.'/'.$i;
            is_dir($p) ? $this->rmrf($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
