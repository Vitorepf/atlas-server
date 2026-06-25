<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\ApiDiff\AtlasCortexApiSurfaceExtractor;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasLoopCortexApiCommandTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/atlas-cortex-api-cli-'.bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iter as $info) {
                /** @var \SplFileInfo $info */
                $info->isDir() ? @rmdir($info->getPathname()) : @unlink($info->getPathname());
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    private function runCmd(array $params): array
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:loop:cortex:api', $params, $buf);

        return ['exit' => $exit, 'output' => $buf->fetch()];
    }

    public function test_extract_writes_snapshot_matching_direct_extractor_call_byte_for_byte(): void
    {
        $class = AtlasCortexApiSurfaceExtractor::class;
        $out = $this->tmpDir.'/snap.json';
        $r = $this->runCmd(['action' => 'extract', '--class' => $class, '--out' => $out, '--format' => 'json']);
        self::assertSame(0, $r['exit']);
        self::assertFileExists($out);

        $direct = (new AtlasCortexApiSurfaceExtractor())->extractForClass($class);
        $expected = (string) json_encode($this->ksortRecursive($direct), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertSame($expected, (string) file_get_contents($out));
    }

    public function test_diff_prints_structured_rows_for_two_snapshots(): void
    {
        $class = AtlasCortexApiSurfaceExtractor::class;
        $a = $this->tmpDir.'/a.json';
        $b = $this->tmpDir.'/b.json';
        $this->runCmd(['action' => 'extract', '--class' => $class, '--out' => $a, '--format' => 'json']);
        // Pretend b has zero methods (simulates "all removed").
        file_put_contents($b, json_encode([]));

        $r = $this->runCmd(['action' => 'diff', '--class' => $class, '--a' => $a, '--b' => $b, '--format' => 'json']);
        self::assertSame(0, $r['exit']);
        $rows = json_decode(trim($r['output']), true);
        self::assertIsArray($rows);
        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertArrayHasKey('classification', $row);
            self::assertArrayNotHasKey('severity', $row);
            self::assertArrayNotHasKey('risk', $row);
        }
    }

    public function test_breaking_exits_zero_and_emits_no_severity_or_risk_field(): void
    {
        $class = AtlasCortexApiSurfaceExtractor::class;
        $diffPath = $this->tmpDir.'/diff.json';
        // Empty diff yields empty report.
        file_put_contents($diffPath, json_encode([]));
        $r = $this->runCmd(['action' => 'breaking', '--class' => $class, '--diff' => $diffPath, '--format' => 'json']);
        self::assertSame(0, $r['exit']);
        $rows = json_decode(trim($r['output']), true);
        self::assertSame([], $rows);
    }

    public function test_history_lists_snapshots_chronologically_for_class(): void
    {
        $class = AtlasCortexApiSurfaceExtractor::class;
        // Two extracts produce two history snapshots.
        $this->runCmd(['action' => 'extract', '--class' => $class, '--out' => $this->tmpDir.'/h1.json', '--format' => 'json']);
        $this->runCmd(['action' => 'extract', '--class' => $class, '--out' => $this->tmpDir.'/h2.json', '--format' => 'json']);

        $r = $this->runCmd(['action' => 'history', '--class' => $class, '--format' => 'json']);
        self::assertSame(0, $r['exit']);
        $rows = json_decode(trim($r['output']), true);
        self::assertIsArray($rows);
        self::assertNotEmpty($rows);
        $paths = array_column($rows, 'path');
        $sorted = $paths;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $paths);
    }

    public function test_unknown_action_exits_non_zero(): void
    {
        $r = $this->runCmd(['action' => 'bogus']);
        self::assertNotSame(0, $r['exit']);
        self::assertStringContainsString('unknown_action', $r['output']);
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function ksortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $v): mixed => $this->ksortRecursive($v), $value);
        }
        ksort($value, SORT_STRING);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $this->ksortRecursive($v);
        }

        return $out;
    }
}
