<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\ModelCheck\AtlasLoopCycleDeadlockChecker;
use App\Services\Ai\AutonomousEvolution\ModelCheck\AtlasLoopCycleModelCheckCli;
use App\Services\Ai\AutonomousEvolution\ModelCheck\AtlasLoopCycleStateMachineExtractor;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasLoopModelCheckCommandTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-model-check-cli-'.bin2hex(random_bytes(4));
        config()->set('atlas.loop.master_enabled', true);
        $this->bindCli(true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            foreach (glob($this->root.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->root);
        }
        parent::tearDown();
    }

    private function bindCli(bool $masterOn): void
    {
        $cli = new AtlasLoopCycleModelCheckCli(
            new AtlasLoopCycleStateMachineExtractor(),
            new AtlasLoopCycleDeadlockChecker(),
            $this->root,
            masterEnabledReader: fn (): bool => $masterOn,
            clock: fn (): string => '2026-06-25T00:00:00Z',
        );
        app()->instance(AtlasLoopCycleModelCheckCli::class, $cli);
    }

    private function runCmd(array $params): array
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:loop:model-check', $params, $buf);

        return ['exit' => $exit, 'output' => $buf->fetch()];
    }

    public function test_extract_writes_fsm_and_appends_history_line(): void
    {
        $r = $this->runCmd(['action' => 'extract', '--json' => true]);
        self::assertSame(0, $r['exit']);
        self::assertFileExists($this->root.'/fsm.json');
        self::assertFileExists($this->root.'/history.ndjson');

        $payload = json_decode(trim($r['output']), true);
        self::assertSame('extract', $payload['action']);
        self::assertNotEmpty($payload['sha256']);

        $historyLines = file($this->root.'/history.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(1, $historyLines);
        $row = json_decode($historyLines[0], true);
        self::assertSame('extract', $row['action']);
        self::assertSame($payload['sha256'], $row['sha256']);
    }

    public function test_deadlock_writes_verdict_and_appends_history(): void
    {
        $this->runCmd(['action' => 'extract', '--json' => true]);
        $r = $this->runCmd(['action' => 'deadlock', '--json' => true]);
        self::assertSame(0, $r['exit']);
        self::assertFileExists($this->root.'/verdict.json');

        $payload = json_decode(trim($r['output']), true);
        self::assertSame('deadlock', $payload['action']);
        $historyLines = file($this->root.'/history.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(2, $historyLines);
    }

    public function test_history_returns_rows_after_two_writes(): void
    {
        $this->runCmd(['action' => 'extract', '--json' => true]);
        $this->runCmd(['action' => 'deadlock', '--json' => true]);

        $r = $this->runCmd(['action' => 'history', '--json' => true]);
        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertCount(2, $payload['rows']);
    }

    public function test_master_off_extract_is_fail_closed_no_file_writes(): void
    {
        $this->bindCli(masterOn: false);
        $r = $this->runCmd(['action' => 'extract', '--json' => true]);
        self::assertNotSame(0, $r['exit']);

        self::assertFileDoesNotExist($this->root.'/fsm.json');
        self::assertFileDoesNotExist($this->root.'/history.ndjson');
    }

    public function test_master_off_deadlock_is_fail_closed_no_file_writes(): void
    {
        $this->bindCli(masterOn: false);
        $r = $this->runCmd(['action' => 'deadlock', '--json' => true]);
        self::assertNotSame(0, $r['exit']);
        self::assertFileDoesNotExist($this->root.'/verdict.json');
    }

    public function test_json_output_is_single_valid_document_with_no_log_noise(): void
    {
        $r = $this->runCmd(['action' => 'extract', '--json' => true]);
        $lines = preg_split("/\r?\n/", trim($r['output'])) ?: [];
        self::assertCount(1, $lines);
        $decoded = json_decode($lines[0], true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('action', $decoded);
    }

    public function test_unknown_action_emits_refused_json(): void
    {
        $r = $this->runCmd(['action' => 'bogus', '--json' => true]);
        self::assertNotSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertTrue($payload['refused']);
        self::assertStringContainsString('unknown_action', $payload['reason']);
    }

    public function test_command_is_registered_in_artisan_list(): void
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->call('list', [], $buf);
        self::assertStringContainsString('atlas:loop:model-check', $buf->fetch());
    }
}
