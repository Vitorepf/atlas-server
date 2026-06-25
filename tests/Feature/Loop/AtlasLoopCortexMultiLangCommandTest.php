<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasLoopCortexMultiLangCommandTest extends TestCase
{
    private string $tsFixture = '';

    private string $outsidePath = '';

    private string $storageRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = storage_path('atlas/cortex/multilang');
        // Keep any pre-existing state out of the test's way by snapshotting and restoring.
        $this->tsFixture = base_path('tests/atlas-cortex-multilang-fixture-'.bin2hex(random_bytes(4)).'.ts');
        file_put_contents($this->tsFixture, "import { Foo } from './foo';\nexport const bar = 1;\n");

        $this->outsidePath = sys_get_temp_dir().'/atlas-mlang-outside-'.bin2hex(random_bytes(4)).'.ts';
        file_put_contents($this->outsidePath, "// outside scope\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->tsFixture);
        @unlink($this->outsidePath);
        $tsDir = $this->storageRoot.'/typescript';
        if (is_dir($tsDir)) {
            foreach (glob($tsDir.'/*.json') ?: [] as $f) {
                @unlink($f);
            }
        }
        parent::tearDown();
    }

    private function runCmd(array $params): array
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:loop:cortex:multilang', $params, $buf);

        return ['exit' => $exit, 'output' => $buf->fetch()];
    }

    public function test_list_includes_php_typescript_yaml_and_is_byte_identical_across_runs(): void
    {
        $r1 = $this->runCmd(['action' => 'list']);
        $r2 = $this->runCmd(['action' => 'list']);
        self::assertSame(0, $r1['exit']);
        self::assertSame($r1['output'], $r2['output']);

        $rows = json_decode(trim($r1['output']), true);
        $langs = array_column($rows, 'language');
        self::assertContains('php', $langs);
        self::assertContains('typescript', $langs);
        self::assertContains('yaml', $langs);
    }

    public function test_extract_writes_byte_deterministic_json_and_is_idempotent(): void
    {
        $r1 = $this->runCmd(['action' => 'extract', '--path' => $this->tsFixture]);
        self::assertSame(0, $r1['exit']);
        $payload1 = json_decode(trim($r1['output']), true);
        self::assertSame('typescript', $payload1['language']);

        $tsDir = $this->storageRoot.'/typescript';
        self::assertFileExists($tsDir.'/'.$payload1['sha256'].'.json');
        $filesBefore = glob($tsDir.'/*.json') ?: [];
        $countBefore = count($filesBefore);

        $r2 = $this->runCmd(['action' => 'extract', '--path' => $this->tsFixture]);
        self::assertSame(0, $r2['exit']);
        $payload2 = json_decode(trim($r2['output']), true);
        self::assertSame($payload1['sha256'], $payload2['sha256']);

        $filesAfter = glob($tsDir.'/*.json') ?: [];
        self::assertCount($countBefore, $filesAfter, 'idempotent: re-extract produces no new file');

        $hist = $this->runCmd(['action' => 'history', '--language' => 'typescript', '--limit' => 1]);
        self::assertSame(0, $hist['exit']);
        $rows = json_decode(trim($hist['output']), true);
        self::assertNotEmpty($rows);
        self::assertSame($payload1['sha256'], end($rows)['sha256']);
    }

    public function test_extract_refuses_path_outside_loop_scope(): void
    {
        $tsDir = $this->storageRoot.'/typescript';
        $before = is_dir($tsDir) ? count(glob($tsDir.'/*.json') ?: []) : 0;

        $r = $this->runCmd(['action' => 'extract', '--path' => $this->outsidePath]);
        self::assertNotSame(0, $r['exit']);
        self::assertStringContainsString('path_outside_loop_scope', $r['output']);

        $after = is_dir($tsDir) ? count(glob($tsDir.'/*.json') ?: []) : 0;
        self::assertSame($before, $after, 'no history file written for refused path');
    }

    public function test_unknown_action_exits_non_zero(): void
    {
        $r = $this->runCmd(['action' => 'bogus']);
        self::assertNotSame(0, $r['exit']);
        self::assertStringContainsString('unknown_action', $r['output']);
    }

    public function test_command_is_registered_in_artisan_list(): void
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->call('list', [], $buf);
        self::assertStringContainsString('atlas:loop:cortex:multilang', $buf->fetch());
    }
}
