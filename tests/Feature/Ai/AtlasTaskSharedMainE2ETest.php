<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AtlasTaskScopedCommitter;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * PART 2 — the OPERATOR's end-to-end flow, ready today: N AIs share ONE local main branch, each pulls a task,
 * edits ONLY its allowed_files, and `report --commit` lands a commit containing ONLY that AI's files. The
 * conflict-free serving guarantees the two AIs never get overlapping scopes, so committing on a shared tree is
 * safe. Runs in a THROWAWAY git repo.
 */
final class AtlasTaskSharedMainE2ETest extends TestCase
{
    private string $repo = '';

    private string $envFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->repo = sys_get_temp_dir().'/atlas-e2e-'.bin2hex(random_bytes(5));
        @mkdir($this->repo, 0775, true);
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'test@atlas.local']);
        $this->git(['config', 'user.name', 'Atlas Test']);
        @file_put_contents($this->repo.'/README.md', "seed\n");
        $this->git(['add', 'README.md']);
        $this->git(['commit', '-q', '-m', 'seed']);

        $this->envFile = sys_get_temp_dir().'/atlas-e2e-env-'.bin2hex(random_bytes(5)).'.env';
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

    public function test_two_ais_resolve_disjoint_tasks_on_one_shared_main_each_committing_only_its_files(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->task('task-alpha', 'app/A/Alpha.php')]);
        $orch->prepareAndEnqueue(['task_packet' => $this->task('task-beta', 'app/B/Beta.php')]);

        $serving = new AtlasTaskServingService($orch, null, null, new AtlasTaskScopedCommitter(null, $this->repo));

        // Two AIs pull — conflict-free guarantees DISJOINT scopes.
        $a = $serving->next('claude-code');
        $b = $serving->next('codex');
        $this->assertSame('served', $a['status']);
        $this->assertSame('served', $b['status']);
        $this->assertNotSame($a['task']['task_packet_id'], $b['task']['task_packet_id']);

        // Each AI edits ONLY its allowed_files — on the SAME working tree, at the same time.
        $this->editScope($a['task']['allowed_files']);
        $this->editScope($b['task']['allowed_files']);
        $this->assertCount(2, $this->dirtyFiles(), 'both AIs\' edits coexist uncommitted on the shared tree');

        // Each resolves with --commit: Atlas commits ONLY that AI's files.
        $ra = $serving->report('claude-code', $a['task']['task_packet_id'], $a['task']['lease_id'], ['outcome' => 'success', 'commit' => true]);
        $rb = $serving->report('codex', $b['task']['task_packet_id'], $b['task']['lease_id'], ['outcome' => 'success', 'commit' => true]);

        $this->assertSame('resolved', $ra['status']);
        $this->assertTrue($ra['lease_closed']);
        $this->assertSame($a['task']['allowed_files'], $ra['files_committed']);
        $this->assertSame('resolved', $rb['status']);
        $this->assertSame($b['task']['allowed_files'], $rb['files_committed']);
        $this->assertNotSame($ra['commit_sha'], $rb['commit_sha'], 'two distinct commits, one per AI');

        // The shared tree is now clean; each commit holds exactly one AI's file.
        $this->assertSame([], $this->dirtyFiles(), 'after both resolves the working tree is clean');
        $this->assertStringContainsString('app/A/Alpha.php', $this->commitFiles($ra['commit_sha']));
        $this->assertStringNotContainsString('app/B/Beta.php', $this->commitFiles($ra['commit_sha']), 'claude-code\'s commit never holds codex\'s file');

        // The queue is drained; the next pull is honestly empty.
        $next = $serving->next('claude-code');
        $this->assertSame('no_claimable_task', $next['status']);
    }

    public function test_commit_failure_keeps_the_lease_so_the_ai_can_retry(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->task('task-noop', 'app/N/Noop.php')]);
        $serving = new AtlasTaskServingService($orch, null, null, new AtlasTaskScopedCommitter(null, $this->repo));

        $served = $serving->next('claude-code');
        // The AI reports success WITHOUT having edited the file (nothing to commit).
        $res = $serving->report('claude-code', $served['task']['task_packet_id'], $served['task']['lease_id'], ['outcome' => 'success', 'commit' => true]);

        $this->assertSame('commit_failed', $res['status']);
        $this->assertFalse($res['lease_closed'], 'a failed commit keeps the lease — no work is lost');
        $this->assertSame('nothing_to_commit_in_scope', $res['commit']['reason']);
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

    /** @return array<string, mixed> */
    private function task(string $id, string $file): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => 'implement '.$id,
            'operator_id' => 'operator',
            'allowed_files' => [$file],
            'scope_in' => [$file],
            'acceptance_criteria' => ['the change is in place'],
            'required_evidence' => ['tests_or_gates_result'],
        ];
    }

    /** @param list<string> $files */
    private function editScope(array $files): void
    {
        foreach ($files as $rel) {
            $path = $this->repo.'/'.$rel;
            @mkdir(\dirname($path), 0775, true);
            @file_put_contents($path, "<?php // implemented by the AI for ".$rel."\n");
        }
    }

    /** @return list<string> */
    private function dirtyFiles(): array
    {
        // -uall lists each untracked FILE individually (git otherwise collapses a new dir like `app/` to one entry).
        $out = trim($this->git(['status', '--porcelain', '--untracked-files=all'])['out']);

        return $out === '' ? [] : array_values(array_filter(explode("\n", $out)));
    }

    private function commitFiles(string $sha): string
    {
        return $this->git(['show', '--name-only', '--pretty=format:', $sha])['out'];
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
