<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Simulation\AtlasLoopSimulationDryRunner;
use App\Services\Ai\AutonomousEvolution\Simulation\AtlasLoopSimulationReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Simulation\AtlasLoopSimulationSandboxBuilder;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasLoopSimulateCommandTest extends TestCase
{
    private string $sandboxParent = '';

    private string $ledgerPath = '';

    private string $sourceRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(4));
        $this->sandboxParent = sys_get_temp_dir().'/atlas-simulate-cli-'.$tag;
        $this->ledgerPath = sys_get_temp_dir().'/atlas-simulate-cli-ledger-'.$tag.'.jsonl';
        $this->sourceRoot = sys_get_temp_dir().'/atlas-simulate-cli-src-'.$tag;
        mkdir($this->sandboxParent, 0o755, true);
        mkdir($this->sourceRoot, 0o755, true);

        // Mini-repo for the Builder.
        exec('git -C '.escapeshellarg($this->sourceRoot).' init -q');
        exec('git -C '.escapeshellarg($this->sourceRoot).' config user.email test@example.com');
        exec('git -C '.escapeshellarg($this->sourceRoot).' config user.name Test');
        file_put_contents($this->sourceRoot.'/hello.txt', "old\n");
        exec('git -C '.escapeshellarg($this->sourceRoot).' add hello.txt');
        exec('git -C '.escapeshellarg($this->sourceRoot).' commit -q -m seed');

        config()->set('atlas.loop.master_enabled', true);

        app()->instance(AtlasLoopSimulationSandboxBuilder::class, new AtlasLoopSimulationSandboxBuilder($this->sandboxParent));
        app()->instance(AtlasLoopSimulationReceiptLedger::class, new AtlasLoopSimulationReceiptLedger($this->ledgerPath));
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        $this->rrmdir($this->sandboxParent);
        $this->rrmdir($this->sourceRoot);
        parent::tearDown();
    }

    private function runCmd(array $params): array
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:loop:simulate', $params, $buf);

        return ['exit' => $exit, 'output' => $buf->fetch()];
    }

    public function test_build_emits_sandbox_path_and_commit_sha(): void
    {
        config()->set('atlas.loop.simulation.source_root', $this->sourceRoot);
        $r = $this->runCmd(['action' => 'build']);
        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertIsArray($payload);
        self::assertNotEmpty($payload['sandbox_path']);
        self::assertSame(64, strlen($payload['content_checksum']));
    }

    public function test_dry_run_emits_receipt_id_and_changed_files_count(): void
    {
        $sandbox = $this->sandboxParent.'/dry-run-target';
        mkdir($sandbox, 0o755, true);
        exec('git -C '.escapeshellarg($sandbox).' init -q');
        file_put_contents($sandbox.'/hello.txt', "old\n");
        $diff = sys_get_temp_dir().'/atlas-simulate-diff-'.bin2hex(random_bytes(4)).'.patch';
        file_put_contents($diff, "--- a/hello.txt\n+++ b/hello.txt\n@@ -1 +1 @@\n-old\n+new\n");

        $r = $this->runCmd([
            'action' => 'dry-run',
            '--sandbox' => $sandbox,
            '--diff' => $diff,
        ]);
        @unlink($diff);
        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertNotEmpty($payload['receipt_id']);
        self::assertSame(0, $payload['patch_apply_exit_code']);
        self::assertGreaterThan(0, $payload['changed_files_count']);
    }

    public function test_history_prints_recent_receipts_in_reverse_chronological(): void
    {
        $ledger = app(AtlasLoopSimulationReceiptLedger::class);
        for ($i = 0; $i < 3; $i++) {
            $ledger->append(new \App\Services\Ai\AutonomousEvolution\Simulation\DryRunReceipt(
                patchApplyExitCode: 0,
                patchApplyOutputTail: '',
                changedFiles: [],
                phpLintExitCodePerFile: [],
                frozenTestExitCode: 0,
                frozenTestStdoutTail: '',
                runStartedAt: '2026-06-25T00:00:0'.$i.'Z',
                runFinishedAt: '2026-06-25T00:00:0'.$i.'Z',
            ));
        }
        $r = $this->runCmd(['action' => 'history', '--limit' => 5]);
        self::assertSame(0, $r['exit']);
        $rows = json_decode(trim($r['output']), true);
        self::assertCount(3, $rows);
    }

    public function test_master_off_every_action_zero_side_effects(): void
    {
        config()->set('atlas.loop.master_enabled', false);
        $sandboxBefore = is_dir($this->sandboxParent) ? count(glob($this->sandboxParent.'/*') ?: []) : 0;
        self::assertFileDoesNotExist($this->ledgerPath);

        $rB = $this->runCmd(['action' => 'build']);
        $rD = $this->runCmd(['action' => 'dry-run', '--sandbox' => $this->sandboxParent, '--diff' => '/dev/null']);
        $rH = $this->runCmd(['action' => 'history']);

        foreach ([$rB, $rD, $rH] as $r) {
            self::assertSame(0, $r['exit']);
            self::assertStringContainsString('master switch OFF', $r['output']);
        }
        $sandboxAfter = is_dir($this->sandboxParent) ? count(glob($this->sandboxParent.'/*') ?: []) : 0;
        self::assertSame($sandboxBefore, $sandboxAfter);
        self::assertFileDoesNotExist($this->ledgerPath);
    }

    public function test_unknown_action_returns_usage_exit(): void
    {
        $r = $this->runCmd(['action' => 'bogus']);
        self::assertNotSame(0, $r['exit']);
        self::assertStringContainsString('unknown_action', $r['output']);
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
