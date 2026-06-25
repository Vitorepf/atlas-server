<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Console\Commands\AtlasAaelExecutionDebuggerCommand;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\Debugger\AtlasAaelExecutionDebuggerReceiptLedger;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasAaelExecutionDebuggerCommandTest extends TestCase
{
    private string $pauseRoot = '';

    private string $ledgerRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir().'/atlas-aael-debug-'.bin2hex(random_bytes(6));
        $this->pauseRoot = $base.'/pause';
        $this->ledgerRoot = $base.'/ledger';
        @mkdir($this->pauseRoot, 0o755, true);
        @mkdir($this->ledgerRoot, 0o755, true);

        $this->app->instance(AtlasAaelExecutionDebuggerCommand::PAUSE_STORAGE_ROOT_BINDING, $this->pauseRoot);
        $this->app->instance(AtlasAaelExecutionDebuggerCommand::LEDGER_STORAGE_ROOT_BINDING, $this->ledgerRoot);
        $this->app->instance(
            AtlasAaelExecutionDebuggerCommand::SNAPSHOT_SOURCE_BINDING,
            static fn (string $runId): array => [
                'step_index' => 3,
                'step_kind' => 'tool_call',
                'files_touched' => [
                    ['path' => 'app/Foo.php', 'sha256' => str_repeat('a', 64)],
                ],
                'diff_counts' => ['added' => 1, 'removed' => 0],
            ],
        );
    }

    protected function tearDown(): void
    {
        $base = \dirname($this->pauseRoot);
        $this->rrmdir($base);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) glob($dir.'/{,.}*', GLOB_BRACE) as $entry) {
            $b = basename((string) $entry);
            if ($b === '.' || $b === '..') {
                continue;
            }
            is_dir($entry) ? $this->rrmdir((string) $entry) : @unlink((string) $entry);
        }
        @rmdir($dir);
    }

    private function callCli(string $action, array $options = []): array
    {
        $payload = ['action' => $action];
        foreach ($options as $k => $v) {
            $payload['--'.$k] = $v;
        }
        $exit = Artisan::call('atlas:aael:debug', $payload);
        $raw = trim(Artisan::output());

        return ['exit' => $exit, 'raw' => $raw];
    }

    private function ledgerForRun(string $runId): array
    {
        $ledger = new AtlasAaelExecutionDebuggerReceiptLedger($this->ledgerRoot);

        return $ledger->replay($runId);
    }

    public function test_pause_arms_gate_and_appends_pause_armed_receipt(): void
    {
        $r = $this->callCli('pause', ['run' => 'R', 'at-step' => 3, 'json' => true]);
        $this->assertSame(0, $r['exit']);

        $payload = json_decode($r['raw'], true);
        $this->assertIsArray($payload);
        $this->assertSame('pause_armed', $payload['event']);
        $this->assertSame('R', $payload['run']);
        $this->assertSame(3, $payload['at_step']);

        $rows = $this->ledgerForRun('R');
        $this->assertNotEmpty($rows);
        $this->assertSame('pause_armed', $rows[count($rows) - 1]['event']);
    }

    public function test_inspect_emits_facts_only_with_no_denylist_keys(): void
    {
        $this->callCli('pause', ['run' => 'R', 'at-step' => 3, 'json' => true]);
        $r = $this->callCli('inspect', ['run' => 'R', 'json' => true]);
        $this->assertSame(0, $r['exit']);
        $payload = json_decode($r['raw'], true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('step_index', $payload);
        $this->assertArrayHasKey('step_kind', $payload);
        $this->assertArrayHasKey('files_touched', $payload);
        $this->assertArrayHasKey('diff_counts', $payload);
        $this->assertSame(str_repeat('a', 64), (string) $payload['files_touched'][0]['sha256']);

        foreach (['score', 'grade', 'judge', 'looks_good'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $payload, 'denylist key present: '.$forbidden);
        }
    }

    public function test_step_without_active_pause_exits_3_and_continue_on_unknown_run_exits_2(): void
    {
        // No pause armed yet, but run exists (created by writing a ledger entry).
        $this->callCli('pause', ['run' => 'R', 'at-step' => 1, 'json' => true]);
        $this->callCli('continue', ['run' => 'R', 'json' => true]); // disarm

        $r1 = $this->callCli('step', ['run' => 'R', 'json' => true]);
        $this->assertSame(3, $r1['exit'], 'step without armed pause must exit 3');

        $r2 = $this->callCli('continue', ['run' => 'NOPE', 'json' => true]);
        $this->assertSame(2, $r2['exit'], 'continue on unknown run must exit 2');
    }

    public function test_end_to_end_pause_inspect_step_step_continue_yields_5_hash_linked_receipts(): void
    {
        $this->callCli('pause', ['run' => 'R', 'at-step' => 1, 'json' => true]);
        $this->callCli('inspect', ['run' => 'R', 'json' => true]);
        $this->callCli('step', ['run' => 'R', 'json' => true]);
        $this->callCli('step', ['run' => 'R', 'json' => true]);
        $this->callCli('continue', ['run' => 'R', 'json' => true]);

        $rows = $this->ledgerForRun('R');
        $this->assertCount(5, $rows);
        $events = array_column($rows, 'event');
        $this->assertSame(
            ['pause_armed', 'inspect_pre', 'step_advanced', 'step_advanced', 'resumed'],
            $events,
        );
    }
}
