<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Spatial\AtlasCortexCallGraphLocalityReporter;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Spatial\AtlasCortexFsLocalityReporter;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Spatial\AtlasCortexLocalityIntersectionEmitter;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasLoopCortexSpatialCommandTest extends TestCase
{
    private string $storageRoot = '';

    private string $fixtureRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = storage_path('atlas/cortex/spatial');
        config()->set('atlas.loop.master_enabled', true);

        // Bind controlled reporters. FS scans a SMALL tmp fixture tree that satisfies the
        // allowed-scope boundary — never the live app tree: the per-file neighbor scan over
        // the real AutonomousEvolution dir is O(n²), emits hundreds of MB of JSON and OOMs
        // the process. The CallGraph reporter receives an empty adjacency source.
        $this->fixtureRoot = sys_get_temp_dir().'/atlas-spatial-'.bin2hex(random_bytes(6));
        $scope = $this->fixtureRoot.'/'.AtlasCortexFsLocalityReporter::ALLOWED_SCOPE_RELATIVE;
        @mkdir($scope.'/Discovery', 0o755, true);
        file_put_contents($scope.'/Foo.php', '<?php');
        file_put_contents($scope.'/Discovery/Bar.php', '<?php');
        app()->instance(
            AtlasCortexFsLocalityReporter::class,
            new AtlasCortexFsLocalityReporter($scope, $this->storageRoot.'/fs'),
        );
        app()->instance(
            AtlasCortexCallGraphLocalityReporter::class,
            new AtlasCortexCallGraphLocalityReporter(
                static fn (): array => [],
                $this->storageRoot.'/callgraph/locality.jsonl',
                [1, 2],
            ),
        );
        app()->instance(
            AtlasCortexLocalityIntersectionEmitter::class,
            new AtlasCortexLocalityIntersectionEmitter(
                $this->storageRoot.'/fs/locality.jsonl',
                $this->storageRoot.'/callgraph/locality.jsonl',
                $this->storageRoot.'/intersection/locality.jsonl',
            ),
        );
    }

    protected function tearDown(): void
    {
        (new \Symfony\Component\Process\Process(['rm', '-rf', $this->fixtureRoot]))->run();
        parent::tearDown();
    }

    private function runCmd(array $params): array
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:loop:cortex:spatial', $params, $buf);

        return ['exit' => $exit, 'output' => $buf->fetch()];
    }

    public function test_fs_mode_emits_valid_json_array_of_records(): void
    {
        $r = $this->runCmd(['mode' => 'fs', '--depth' => ['1'], '--json' => true]);
        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertIsArray($payload);
        // Não-vácuo: com o master armado via config o scan da fixture EMITE records
        // (o guard getenv antigo fazia este teste passar com report vazio).
        self::assertNotEmpty($payload);
    }

    public function test_callgraph_mode_emits_json_with_known_schema(): void
    {
        $r = $this->runCmd(['mode' => 'callgraph', '--json' => true]);
        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertIsArray($payload);
    }

    public function test_intersect_mode_runs_without_error(): void
    {
        $r = $this->runCmd(['mode' => 'intersect', '--json' => true]);
        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertIsArray($payload);
    }

    public function test_history_mode_filters_by_fqcn(): void
    {
        $dir = $this->storageRoot.'/fs';
        @mkdir($dir, 0o755, true);
        file_put_contents($dir.'/seed.jsonl', json_encode(['file' => 'App\\Foo', 'neighbors' => ['App\\Bar']])."\n".
            json_encode(['file' => 'App\\Other', 'neighbors' => ['App\\Baz']])."\n");

        $r = $this->runCmd(['mode' => 'history', '--fqcn' => 'App\\\\Bar', '--json' => true]);
        self::assertSame(0, $r['exit']);
        $rows = json_decode(trim($r['output']), true);
        self::assertCount(1, $rows);
    }

    public function test_master_off_emits_disabled_message_no_writes(): void
    {
        config()->set('atlas.loop.master_enabled', false);
        $before = $this->snapshotDir($this->storageRoot);

        foreach (['fs', 'callgraph', 'intersect', 'history'] as $mode) {
            $r = $this->runCmd(['mode' => $mode]);
            self::assertSame(0, $r['exit']);
            self::assertStringContainsString('loop master switch is OFF', $r['output']);
        }
        $after = $this->snapshotDir($this->storageRoot);
        self::assertSame($before, $after);
    }

    /**
     * @return array<string,string>
     */
    private function snapshotDir(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }
        $map = [];
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $info) {
            /** @var \SplFileInfo $info */
            if ($info->isFile()) {
                $map[$info->getPathname()] = (string) hash_file('sha256', $info->getPathname());
            }
        }
        ksort($map, SORT_STRING);

        return $map;
    }
}
