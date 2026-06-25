<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\ReentrySafety\AtlasLoopCycleCheckpointReader;
use App\Services\Ai\AutonomousEvolution\ReentrySafety\AtlasLoopCycleCheckpointWriter;
use App\Services\Ai\AutonomousEvolution\ReentrySafety\AtlasLoopCycleReentryReceiptLedger;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasLoopCycleReentryCommandTest extends TestCase
{
    private string $baseDir = '';

    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(4));
        $this->baseDir = sys_get_temp_dir().'/atlas-loop-reentry-cli-'.$tag;
        @mkdir($this->baseDir, 0o755, true);
        $this->ledgerPath = sys_get_temp_dir().'/atlas-loop-reentry-cli-ledger-'.$tag.'.jsonl';

        app()->instance(AtlasLoopCycleCheckpointReader::class, new AtlasLoopCycleCheckpointReader($this->baseDir));
        app()->instance(AtlasLoopCycleCheckpointWriter::class, new AtlasLoopCycleCheckpointWriter($this->baseDir));
        app()->instance(AtlasLoopCycleReentryReceiptLedger::class, new AtlasLoopCycleReentryReceiptLedger($this->ledgerPath));
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        foreach (glob($this->baseDir.'/*') ?: [] as $f) {
            is_dir($f) ? array_map('unlink', glob($f.'/*') ?: []) : @unlink($f);
            is_dir($f) && @rmdir($f);
        }
        @rmdir($this->baseDir);
        parent::tearDown();
    }

    private function runCmd(array $params): array
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:loop:cycle:reentry', $params, $buf);

        return ['exit' => $exit, 'output' => trim($buf->fetch())];
    }

    public function test_recover_subcommand_prints_recovery_fact_json(): void
    {
        $r = $this->runCmd(['action' => 'recover', '--cycle' => 'cycle-x1', '--json' => true]);

        self::assertSame(0, $r['exit'], 'recover should exit 0; output: '.$r['output']);
        $payload = json_decode($r['output'], true);
        self::assertIsArray($payload);
        foreach (['last_completed_phase', 'next_phase_to_run', 'base_commit_sha', 'merged_sha', 'torn_tail'] as $field) {
            self::assertArrayHasKey($field, $payload);
        }
    }

    public function test_history_subcommand_prints_chronological_ledger_entries(): void
    {
        $ledger = app(AtlasLoopCycleReentryReceiptLedger::class);
        $ledger->record('cycle-h1', 'selected', 'merge_to_main', 'sha-a', 'FRESH', null, 1000);
        $ledger->record('cycle-h1', 'planned', 'emit_receipt', 'r-1', 'FRESH', null, 1001);
        $ledger->record('cycle-h1', 'executed', 'claim_task', 't-1', 'ALREADY_DONE', null, 1002);
        $ledger->record('cycle-other', 'selected', 'merge_to_main', 'sha-z', 'FRESH', null, 1003);

        $r = $this->runCmd(['action' => 'history', '--cycle' => 'cycle-h1', '--json' => true]);

        self::assertSame(0, $r['exit'], 'history should exit 0; output: '.$r['output']);
        $payload = json_decode($r['output'], true);
        self::assertIsArray($payload);
        self::assertSame('cycle-h1', $payload['cycle_id']);
        self::assertCount(3, $payload['rows']);
        self::assertSame(['merge_to_main', 'emit_receipt', 'claim_task'], array_column($payload['rows'], 'kind'));
        self::assertSame(['sha-a', 'r-1', 't-1'], array_column($payload['rows'], 'key'));
    }

    public function test_checkpoint_without_confirm_exits_non_zero_and_writes_nothing(): void
    {
        $sizeBefore = is_dir($this->baseDir) ? count(scandir($this->baseDir) ?: []) : 0;

        $r = $this->runCmd(['action' => 'checkpoint', '--cycle' => 'cycle-c1', '--phase' => 'selected', '--base-sha' => 'abc']);

        self::assertNotSame(0, $r['exit']);
        self::assertStringContainsString('checkpoint_refused', $r['output']);
        $sizeAfter = is_dir($this->baseDir) ? count(scandir($this->baseDir) ?: []) : 0;
        self::assertSame($sizeBefore, $sizeAfter, 'no files must be written without --confirm');
    }

    public function test_checkpoint_with_confirm_and_required_args_exits_zero(): void
    {
        $r = $this->runCmd([
            'action' => 'checkpoint',
            '--cycle' => 'cycle-c2',
            '--phase' => 'selected',
            '--base-sha' => 'sha-abc-123',
            '--confirm' => true,
            '--json' => true,
        ]);

        self::assertSame(0, $r['exit'], 'checkpoint should exit 0; output: '.$r['output']);
        $payload = json_decode($r['output'], true);
        self::assertIsArray($payload);
        self::assertSame('cycle-c2', $payload['cycle_id']);
        self::assertSame('selected', $payload['phase']);
        self::assertArrayHasKey('verdict', $payload);
    }

    public function test_unknown_action_fails_with_usage_message(): void
    {
        $r = $this->runCmd(['action' => 'bogus']);

        self::assertNotSame(0, $r['exit']);
        self::assertStringContainsString('unknown_action', $r['output']);
    }
}
