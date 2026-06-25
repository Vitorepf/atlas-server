<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Aael\Parallel\AtlasAaelParallelExecutionReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Aael\Parallel\AtlasAaelParallelLockManager;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasAaelParallelCommandTest extends TestCase
{
    private string $inputPath = '';

    private string $lockLedger = '';

    private string $receiptRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(4));
        $this->inputPath = sys_get_temp_dir().'/atlas-aael-parallel-input-'.$tag.'.json';
        $this->lockLedger = sys_get_temp_dir().'/atlas-aael-parallel-locks-'.$tag.'.json';
        $this->receiptRoot = sys_get_temp_dir().'/atlas-aael-parallel-receipts-'.$tag;
        mkdir($this->receiptRoot, 0o755, true);

        config()->set('atlas.aael.parallel.cli_enabled', true);
        app()->instance(AtlasAaelParallelLockManager::class, new AtlasAaelParallelLockManager($this->lockLedger));
        app()->instance(
            AtlasAaelParallelExecutionReceiptLedger::class,
            new AtlasAaelParallelExecutionReceiptLedger($this->receiptRoot),
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->inputPath);
        @unlink($this->lockLedger);
        if (is_dir($this->receiptRoot)) {
            foreach (glob($this->receiptRoot.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->receiptRoot);
        }
        parent::tearDown();
    }

    private function runCmd(array $params): array
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:aael:parallel', $params, $buf);

        return ['exit' => $exit, 'output' => $buf->fetch()];
    }

    public function test_schedule_prints_groups_and_rationale_matching_planner(): void
    {
        file_put_contents($this->inputPath, json_encode([
            ['step_id' => 'a', 'allowed_files' => ['app/A.php'], 'depends_on' => []],
            ['step_id' => 'b', 'allowed_files' => ['app/B.php'], 'depends_on' => ['a']],
        ]));
        $r = $this->runCmd(['action' => 'schedule', '--input' => $this->inputPath, '--json' => true]);
        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertArrayHasKey('groups', $payload);
        self::assertArrayHasKey('rationale', $payload);
        self::assertNotEmpty($payload['groups']);
    }

    public function test_lock_acquire_dry_run_writes_nothing_to_ledger(): void
    {
        self::assertFileDoesNotExist($this->lockLedger);
        $r = $this->runCmd([
            'action' => 'lock',
            '--acquire' => 'step-A',
            '--write-set' => 'app/Foo.php',
            '--json' => true,
        ]);
        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertTrue($payload['dry_run']);
        self::assertSame('step-A', $payload['would_acquire']['step_id']);
        self::assertFileDoesNotExist($this->lockLedger);
    }

    public function test_lock_acquire_with_confirm_persists_handle(): void
    {
        $r = $this->runCmd([
            'action' => 'lock',
            '--acquire' => 'step-A',
            '--write-set' => 'app/Foo.php',
            '--confirm' => true,
            '--json' => true,
        ]);
        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertTrue($payload['acquired']);
        self::assertFileExists($this->lockLedger);
    }

    public function test_lock_show_returns_held_array(): void
    {
        // Seed via direct acquire.
        $manager = new AtlasAaelParallelLockManager($this->lockLedger);
        $manager->acquire('step-A', ['app/Foo.php']);

        $r = $this->runCmd(['action' => 'lock', '--json' => true]);
        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertArrayHasKey('held', $payload);
        self::assertCount(1, $payload['held']);
        self::assertSame('step-A', $payload['held'][0]['step_id']);
    }

    public function test_history_returns_empty_array_for_day_with_no_receipts(): void
    {
        $r = $this->runCmd(['action' => 'history', '--day' => '2026-06-23', '--json' => true]);
        self::assertSame(0, $r['exit']);
        self::assertSame([], json_decode(trim($r['output']), true));
    }

    public function test_master_switch_off_refuses_with_zero_side_effects(): void
    {
        config()->set('atlas.aael.parallel.cli_enabled', false);
        file_put_contents($this->inputPath, json_encode([]));
        $before = is_file($this->lockLedger) ? hash_file('sha256', $this->lockLedger) : '';

        $rS = $this->runCmd(['action' => 'schedule', '--input' => $this->inputPath]);
        $rL = $this->runCmd(['action' => 'lock', '--acquire' => 'x', '--write-set' => 'app/F.php', '--confirm' => true]);
        $rH = $this->runCmd(['action' => 'history']);

        self::assertSame(0, $rS['exit']);
        self::assertSame(0, $rL['exit']);
        self::assertSame(0, $rH['exit']);
        foreach ([$rS, $rL, $rH] as $r) {
            self::assertStringContainsString('disabled', $r['output']);
        }
        $after = is_file($this->lockLedger) ? hash_file('sha256', $this->lockLedger) : '';
        self::assertSame($before, $after);
    }

    public function test_command_is_registered_in_artisan_list(): void
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->call('list', [], $buf);
        self::assertStringContainsString('atlas:aael:parallel', $buf->fetch());
    }
}
