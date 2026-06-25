<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\LiveCycle\Nesting\AtlasLoopSubCycleReceiptLedger;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasLoopCycleNestCommandTest extends TestCase
{
    private string $ledgerPath = '';

    private string $payloadPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(4));
        $this->ledgerPath = sys_get_temp_dir().'/atlas-nest-cli-'.$tag.'.jsonl';
        $this->payloadPath = sys_get_temp_dir().'/atlas-nest-cli-payload-'.$tag.'.json';
        app()->instance(AtlasLoopSubCycleReceiptLedger::class, new AtlasLoopSubCycleReceiptLedger($this->ledgerPath));
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        @unlink($this->payloadPath);
        parent::tearDown();
    }

    private function runCmd(array $params): array
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:loop:cycle:nest', $params, $buf);

        return ['exit' => $exit, 'output' => $buf->fetch()];
    }

    public function test_inspect_emits_max_depth_and_receipts_by_type(): void
    {
        $r = $this->runCmd(['action' => 'inspect', '--json' => true]);
        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertArrayHasKey('max_depth', $payload);
        self::assertArrayHasKey('receipts_by_type', $payload);
        self::assertIsInt($payload['max_depth']);
        self::assertIsArray($payload['receipts_by_type']);
    }

    public function test_spawn_writes_spawn_event_to_ledger_and_exit_zero(): void
    {
        $r = $this->runCmd([
            'action' => 'spawn',
            '--parent' => 'cyc-X',
            '--phase' => 'ARCHITECT',
            '--json' => true,
        ]);
        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertIsArray($payload);
        self::assertSame('cyc-X', $payload['parent_cycle_id']);

        self::assertFileExists($this->ledgerPath);
        $lines = file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(1, $lines);
        $row = json_decode($lines[0], true);
        self::assertSame('SPAWN', $row['event_type']);
        self::assertSame('cyc-X', $row['parent_cycle_id']);
    }

    public function test_history_returns_chain_in_seq_order_after_spawn_and_merge(): void
    {
        $this->runCmd(['action' => 'spawn', '--parent' => 'cyc-X', '--phase' => 'ARCHITECT', '--json' => true]);

        file_put_contents($this->payloadPath, json_encode(['alternative_X_cost' => 5, 'gate_g_failed_for' => ['Z']]));
        $this->runCmd([
            'action' => 'merge',
            '--parent' => 'cyc-X',
            '--child' => 'cyc-X|ARCHITECT|0',
            '--payload' => $this->payloadPath,
            '--json' => true,
        ]);

        $r = $this->runCmd(['action' => 'history', '--parent' => 'cyc-X', '--json' => true]);
        self::assertSame(0, $r['exit']);
        $chain = json_decode(trim($r['output']), true);
        self::assertCount(2, $chain);
        self::assertSame(1, $chain[0]['seq']);
        self::assertSame(2, $chain[1]['seq']);
        self::assertSame('SPAWN', $chain[0]['event_type']);
        self::assertSame('MERGE', $chain[1]['event_type']);
    }

    public function test_merge_with_forbidden_scalar_key_returns_rejection_and_no_ledger_write(): void
    {
        $this->runCmd(['action' => 'spawn', '--parent' => 'cyc-Y', '--phase' => 'ARCHITECT', '--json' => true]);
        $linesBefore = count(file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);

        file_put_contents($this->payloadPath, json_encode(['score' => 0.9]));
        $r = $this->runCmd([
            'action' => 'merge',
            '--parent' => 'cyc-Y',
            '--child' => 'cyc-Y|ARCHITECT|0',
            '--payload' => $this->payloadPath,
            '--json' => true,
        ]);

        self::assertNotSame(0, $r['exit']);
        $linesAfter = count(file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        self::assertSame($linesBefore, $linesAfter);
    }

    public function test_unknown_action_exits_non_zero_and_does_not_write_ledger(): void
    {
        $r = $this->runCmd(['action' => 'bogus']);
        self::assertNotSame(0, $r['exit']);
        self::assertStringContainsString('unknown_action', $r['output']);
        self::assertFileDoesNotExist($this->ledgerPath);
    }
}
