<?php

declare(strict_types=1);

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Console\Commands\AtlasAaelExecutionRollbackCommand;
use App\Console\Commands\AtlasAaelExecutionRollbackOperatorPort;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

// PSR-4 only autoloads the file's primary class; force-load to surface the in-file port interface.
\class_exists(AtlasAaelExecutionRollbackCommand::class);

class AtlasAaelExecutionRollbackCommandTest extends TestCase
{
    private FakeRollbackOperatorPort $port;

    protected function setUp(): void
    {
        parent::setUp();
        $this->port = new FakeRollbackOperatorPort();
        app()->instance(AtlasAaelExecutionRollbackOperatorPort::class, $this->port);
        AtlasLoopMasterSwitch::$envPathOverride = (static function () { $p = sys_get_temp_dir().'/atlas-mst-'.bin2hex(random_bytes(4)).'.env'; file_put_contents($p, "ATLAS_AUTONOMOS_MASTER_ENABLED=true
ATLAS_LOOP_MASTER_ENABLED=true
"); return $p; })();
    }

    private function runCmd(array $params): array
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:aael:rollback', $params, $buf);

        return ['exit' => $exit, 'output' => $buf->fetch()];
    }

    public function test_inspect_prints_manifest_summary_and_does_not_mutate(): void
    {
        $this->port->manifests['exec-1'] = [
            'execution_id' => 'exec-1',
            'manifest_sha' => str_repeat('a', 64),
            'target_count' => 2,
            'total_bytes' => 100,
            'targets' => [
                ['path' => 'app/Services/Ai/AutonomousEvolution/X.php', 'pre_sha' => 'p1', 'current_sha' => 'p1', 'matches_pre' => true],
                ['path' => 'app/Services/Ai/AutonomousEvolution/Y.php', 'pre_sha' => 'p2', 'current_sha' => 'c2', 'matches_pre' => false],
            ],
        ];
        $r = $this->runCmd(['action' => 'inspect', '--execution-id' => 'exec-1', '--json' => true]);
        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertSame('exec-1', $payload['execution_id']);
        self::assertSame(2, $payload['target_count']);
        self::assertCount(2, $payload['targets']);
        self::assertSame([], $this->port->executeCalls);
    }

    public function test_execute_without_confirm_refuses_and_no_ledger_write(): void
    {
        $r = $this->runCmd(['action' => 'execute', '--execution-id' => 'exec-1']);
        self::assertSame(AtlasAaelExecutionRollbackCommand::EXIT_REFUSED, $r['exit']);
        self::assertStringContainsString('refused', $r['output']);
        self::assertSame([], $this->port->executeCalls);
    }

    public function test_execute_with_confirm_appends_receipt_and_exits_zero_on_restored(): void
    {
        $this->port->nextExecuteResult = [
            'execution_id' => 'exec-1',
            'status' => 'restored',
            'restored_paths' => ['app/Services/Ai/AutonomousEvolution/X.php'],
        ];
        $r = $this->runCmd([
            'action' => 'execute',
            '--execution-id' => 'exec-1',
            '--confirm' => true,
            '--json' => true,
        ]);
        self::assertSame(0, $r['exit']);
        self::assertCount(1, $this->port->executeCalls);
        self::assertSame('exec-1', $this->port->executeCalls[0][0]);
        self::assertSame('operator_request', $this->port->executeCalls[0][1]);
    }

    public function test_history_streams_receipts_in_append_order(): void
    {
        $this->port->receipts['exec-1'] = [
            ['execution_id' => 'exec-1', 'status' => 'restored', 'seq' => 1],
            ['execution_id' => 'exec-1', 'status' => 'already_restored', 'seq' => 2],
            ['execution_id' => 'exec-1', 'status' => 'restored', 'seq' => 3],
        ];
        $r = $this->runCmd(['action' => 'history', '--execution-id' => 'exec-1', '--json' => true]);
        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertCount(3, $payload);
        self::assertSame([1, 2, 3], array_column($payload, 'seq'));
    }

    public function test_execute_refused_when_master_switch_off_inspect_and_history_still_work(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = (static function () { $p = sys_get_temp_dir().'/atlas-mst-'.bin2hex(random_bytes(4)).'.env'; file_put_contents($p, "ATLAS_AUTONOMOS_MASTER_ENABLED=false
ATLAS_LOOP_MASTER_ENABLED=false
"); return $p; })();

        $rE = $this->runCmd([
            'action' => 'execute',
            '--execution-id' => 'exec-1',
            '--confirm' => true,
        ]);
        self::assertSame(AtlasAaelExecutionRollbackCommand::EXIT_REFUSED, $rE['exit']);
        self::assertStringContainsString('master switch OFF', $rE['output']);
        self::assertSame([], $this->port->executeCalls);

        $rI = $this->runCmd(['action' => 'inspect', '--execution-id' => 'exec-1', '--json' => true]);
        self::assertSame(0, $rI['exit']);
        $rH = $this->runCmd(['action' => 'history', '--execution-id' => 'exec-1', '--json' => true]);
        self::assertSame(0, $rH['exit']);
    }

    public function test_invalid_trigger_reason_refused(): void
    {
        $r = $this->runCmd([
            'action' => 'execute',
            '--execution-id' => 'exec-1',
            '--reason' => 'bogus_reason',
            '--confirm' => true,
        ]);
        self::assertSame(AtlasAaelExecutionRollbackCommand::EXIT_REFUSED, $r['exit']);
        self::assertStringContainsString('invalid_reason', $r['output']);
        self::assertSame([], $this->port->executeCalls);
    }

    public function test_partial_status_exits_with_partial_code(): void
    {
        $this->port->nextExecuteResult = ['execution_id' => 'exec-1', 'status' => 'partial'];
        $r = $this->runCmd([
            'action' => 'execute',
            '--execution-id' => 'exec-1',
            '--confirm' => true,
            '--json' => true,
        ]);
        self::assertSame(AtlasAaelExecutionRollbackCommand::EXIT_PARTIAL, $r['exit']);
    }
}

final class FakeRollbackOperatorPort implements AtlasAaelExecutionRollbackOperatorPort
{
    /** @var array<string,array<string,mixed>> */
    public array $manifests = [];

    /** @var array<string,list<array<string,mixed>>> */
    public array $receipts = [];

    /** @var array<string,mixed> */
    public array $nextExecuteResult = [];

    /** @var list<array{string,string}> */
    public array $executeCalls = [];

    public function inspectManifest(string $executionId): array
    {
        return $this->manifests[$executionId] ?? [
            'execution_id' => $executionId,
            'manifest_sha' => '',
            'target_count' => 0,
            'total_bytes' => 0,
            'targets' => [],
        ];
    }

    public function executeRollback(string $executionId, string $reason): array
    {
        $this->executeCalls[] = [$executionId, $reason];

        return $this->nextExecuteResult ?: [
            'execution_id' => $executionId,
            'status' => 'restored',
            'trigger_reason' => $reason,
        ];
    }

    public function listReceipts(?string $executionId, int $limit): iterable
    {
        $rows = $executionId !== null ? ($this->receipts[$executionId] ?? []) : array_merge(...array_values($this->receipts));
        foreach (array_slice($rows, 0, $limit) as $row) {
            yield $row;
        }
    }
}
