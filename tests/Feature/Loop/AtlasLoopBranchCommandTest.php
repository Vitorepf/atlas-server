<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasLoopBranchCommandTest extends TestCase
{
    private string $branchesRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->branchesRoot = sys_get_temp_dir().'/atlas-branch-cli-'.bin2hex(random_bytes(4));
        mkdir($this->branchesRoot, 0o755, true);
        config()->set('atlas.loop.master_enabled', true);
        config()->set('atlas.loop.branches.root', $this->branchesRoot);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->branchesRoot);
        parent::tearDown();
    }

    private function runCmd(array $params): array
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:loop:branch', $params, $buf);

        return ['exit' => $exit, 'output' => $buf->fetch()];
    }

    public function test_spawn_with_master_on_emits_envelope_and_creates_manifest(): void
    {
        $r = $this->runCmd([
            'action' => 'spawn',
            '--parent-cycle' => 'cyc-A',
            '--hypothesis' => 'try faster path',
            '--json' => true,
        ]);
        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertSame('spawn', $payload['action']);
        self::assertSame('open', $payload['terminal_state']);
        self::assertNull($payload['reason']);
        self::assertNotEmpty($payload['branch_id']);

        $manifest = $this->branchesRoot.'/'.$payload['branch_id'].'/manifest.json';
        self::assertFileExists($manifest);
    }

    public function test_spawn_with_master_off_refuses_with_loop_master_off_reason(): void
    {
        config()->set('atlas.loop.master_enabled', false);
        $r = $this->runCmd([
            'action' => 'spawn',
            '--parent-cycle' => 'cyc-B',
            '--hypothesis' => 'x',
        ]);
        self::assertSame(1, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertSame('loop_master_off', $payload['reason']);

        $files = glob($this->branchesRoot.'/*/manifest.json') ?: [];
        self::assertSame([], $files);
    }

    public function test_history_works_read_only_with_master_off(): void
    {
        // Seed an archived history record.
        $historyDir = $this->branchesRoot.'/_history';
        mkdir($historyDir, 0o755, true);
        file_put_contents($historyDir.'/abc123.json', json_encode([
            'branch_id' => 'abc123',
            'terminal_state' => 'merged',
            'manifest' => [
                'branch_id' => 'abc123',
                'parent_cycle_id' => 'cyc-P',
                'hypothesis' => 'h1',
                'created_at' => '2026-06-25T00:00:00Z',
            ],
        ]));
        config()->set('atlas.loop.master_enabled', false);

        $r = $this->runCmd(['action' => 'history', '--json' => true]);
        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertSame('history', $payload['action']);
        self::assertCount(1, $payload['rows']);
        self::assertSame('cyc-P', $payload['rows'][0]['parent_cycle_id']);
        self::assertSame('merged', $payload['rows'][0]['terminal_state']);
    }

    public function test_unknown_subaction_exits_with_code_two(): void
    {
        $r = $this->runCmd(['action' => 'bogus']);
        self::assertSame(2, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertSame('unknown_subaction', $payload['reason']);
    }

    public function test_merge_and_discard_require_branch_id(): void
    {
        $rM = $this->runCmd(['action' => 'merge']);
        self::assertSame(1, $rM['exit']);
        self::assertSame('missing_branch_id', json_decode(trim($rM['output']), true)['reason']);

        $rD = $this->runCmd(['action' => 'discard']);
        self::assertSame(1, $rD['exit']);
        self::assertSame('missing_branch_id', json_decode(trim($rD['output']), true)['reason']);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($dir);
    }
}
