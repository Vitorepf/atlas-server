<?php

declare(strict_types=1);

namespace Tests\Feature\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasArtisanBootSmokeGate;
use App\Services\Ai\SelfConstruction\AtlasTaskScopedCommitter;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * EVI-03 — boot-smoke gate on pre-landing paths (pregate absolute, committer differential).
 */
final class BootSmokeGateTest extends TestCase
{
    private string $repo = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/atlas-boot-smoke-'.bin2hex(random_bytes(5));
        @mkdir($this->repo, 0775, true);
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'test@atlas.local']);
        $this->git(['config', 'user.name', 'Atlas Test']);
        @file_put_contents($this->repo.'/README.md', "seed\n");
        $this->git(['add', 'README.md']);
        $this->git(['commit', '-q', '-m', 'seed']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repo);
        parent::tearDown();
    }

    public function test_task_introduced_boot_failure_blocks_commit_with_give_back(): void
    {
        $this->writeFile('app/Commands/BrokenByTask.php', "<?php\nsyntax error\n");
        $gate = $this->fakeGate(introducedFailure: true, stderr: 'PHP Fatal error: BrokenByTask');

        $result = (new AtlasTaskScopedCommitter(null, $this->repo, null, $gate))
            ->commitScope(['app/Commands/BrokenByTask.php'], 'task-boot-fail', 'client-a');

        $this->assertFalse($result['committed']);
        $this->assertSame('boot_smoke_introduced_failure', $result['reason']);
        $this->assertSame('task-boot-fail', $result['task_packet_id']);
        $this->assertTrue($result['give_back']);
        $this->assertStringContainsString('PHP Fatal error', (string) ($result['stderr'] ?? ''));
    }

    public function test_other_agent_broken_uncommitted_file_does_not_block_scoped_commit(): void
    {
        $this->writeFile('app/A/Good.php', "<?php\n// good\n");
        $this->writeFile('app/B/NeighbourBroken.php', "<?php\nsyntax error\n");
        $gate = $this->fakeGate(introducedFailure: false);

        $result = (new AtlasTaskScopedCommitter(null, $this->repo, null, $gate))
            ->commitScope(['app/A/Good.php'], 'task-neighbour-safe', 'client-b');

        $this->assertTrue($result['committed'], json_encode($result));
        $this->assertNull(data_get($result, 'boot_smoke'));
    }

    public function test_baseline_already_broken_allows_land_with_warning(): void
    {
        $this->writeFile('app/Fix/Boot.php', "<?php\n// boot fix\n");
        $gate = $this->fakeGate(
            introducedFailure: false,
            warning: 'baseline_boot_already_broken',
            baselineOk: false,
            snapshotOk: true,
        );

        $result = (new AtlasTaskScopedCommitter(null, $this->repo, null, $gate))
            ->commitScope(['app/Fix/Boot.php'], 'task-boot-fix', 'client-c');

        $this->assertTrue($result['committed'], json_encode($result));
        $this->assertSame('baseline_boot_already_broken', data_get($result, 'boot_smoke.warning'));
    }

    public function test_healthy_tree_commit_flow_unchanged(): void
    {
        $this->writeFile('app/Healthy/Ok.php', "<?php\n// ok\n");
        $gate = $this->fakeGate(introducedFailure: false);

        $result = (new AtlasTaskScopedCommitter(null, $this->repo, null, $gate))
            ->commitScope(['app/Healthy/Ok.php'], 'task-healthy', 'client-d', 'healthy land');

        $this->assertTrue($result['committed']);
        $this->assertSame('committed', $result['reason']);
        $this->assertNull(data_get($result, 'boot_smoke'));
    }

    private function fakeGate(
        bool $introducedFailure,
        ?string $stderr = null,
        ?string $warning = null,
        bool $baselineOk = true,
        bool $snapshotOk = true,
    ): AtlasArtisanBootSmokeGate {
        return new class($introducedFailure, $stderr, $warning, $baselineOk, $snapshotOk) extends AtlasArtisanBootSmokeGate
        {
            public function __construct(
                private readonly bool $introducedFailure,
                private readonly ?string $stderr,
                private readonly ?string $warning,
                private readonly bool $baselineOk,
                private readonly bool $snapshotOk,
            ) {}

            public function differentialForScopedCommit(string $repoRoot, array $allowedFiles): array
            {
                return [
                    'baseline' => ['ok' => $this->baselineOk, 'exit_code' => $this->baselineOk ? 0 : 255, 'stderr_tail' => ''],
                    'snapshot' => ['ok' => $this->snapshotOk, 'exit_code' => $this->snapshotOk ? 0 : 255, 'stderr_tail' => (string) $this->stderr],
                    'introduced_failure' => $this->introducedFailure,
                    'baseline_already_broken' => ! $this->baselineOk,
                    'warning' => $this->warning,
                ];
            }
        };
    }

    private function writeFile(string $rel, string $content): void
    {
        $path = $this->repo.'/'.$rel;
        @mkdir(dirname($path), 0775, true);
        @file_put_contents($path, $content);
    }

    /** @param list<string> $args @return array{code:int,out:string,err:string} */
    private function git(array $args): array
    {
        $p = new Process(array_merge(['git'], $args), $this->repo);
        $p->run();

        return ['code' => (int) $p->getExitCode(), 'out' => $p->getOutput(), 'err' => $p->getErrorOutput()];
    }
}
