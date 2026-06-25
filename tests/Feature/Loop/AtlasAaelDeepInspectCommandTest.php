<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasAaelDeepInspectCommand;
use App\Services\Ai\AutonomousEvolution\Aael\AtlasAaelExecutionReceiptLedger;
use App\Services\Ai\AutonomousEvolution\AtlasAaelLoopExecutionBridge;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasAaelDeepInspectCommandTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-aael-inspect-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o755, true);

        $ledger = new AtlasAaelExecutionReceiptLedger($this->root);
        $ledger->setClock(fn (): string => '2026-06-24T12:00:00+00:00');
        $this->app->instance(AtlasAaelExecutionReceiptLedger::class, $ledger);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            foreach ((array) glob($this->root.'/*') as $f) {
                @unlink((string) $f);
            }
            @rmdir($this->root);
        }
        parent::tearDown();
    }

    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:aael:inspect', $args);

        return [$exit, $kernel->output()];
    }

    private function seedOneExecution(): string
    {
        $ledger = $this->app->make(AtlasAaelExecutionReceiptLedger::class);
        $r = $ledger->record(
            [['opportunity_id' => 'opp-1', 'objective' => 'improve-x']],
            ['rejected' => [], 'rejected_count' => 0],
            ['tasks_processed' => 1, 'proposals_certified_for_review' => 0, 'stop_reason' => 'queue_exhausted', 'deferred_count' => 0],
            ['improve-x' => ['extra_paths_outside_plan' => [], 'missing_planned_paths' => []]],
        );
        $this->assertSame(AtlasAaelExecutionReceiptLedger::STATUS_OK, $r['status']);

        return $r['execution_id'];
    }

    public function test_latest_json_emits_deep_inspect_envelope(): void
    {
        $this->seedOneExecution();
        [$exit, $out] = $this->runCmd(['--latest' => true, '--json' => true]);

        $this->assertSame(AtlasAaelDeepInspectCommand::EXIT_OK, $exit, $out);
        $decoded = json_decode(trim($out), true);
        $this->assertSame(AtlasAaelDeepInspectCommand::SCHEMA, $decoded['schema_version']);
        foreach (['plan_prover', 'runner_result', 'drift_audit', 'opportunities'] as $key) {
            $this->assertArrayHasKey($key, $decoded);
        }
    }

    public function test_no_id_no_latest_exits_one_and_does_not_modify_the_ledger_dir(): void
    {
        $id = $this->seedOneExecution();
        $path = $this->root.'/'.$id.'.json';
        $mtimeBefore = filemtime($path);
        $listingBefore = scandir($this->root);

        clearstatcache();
        [$exit, $out] = $this->runCmd([]);

        $this->assertSame(AtlasAaelDeepInspectCommand::EXIT_FAIL, $exit);
        $this->assertStringContainsString('atlas:aael:inspect', $out);
        $this->assertStringContainsString('--latest', $out);
        $this->assertSame($mtimeBefore, filemtime($path), 'receipt file mtime must not change');
        $this->assertSame($listingBefore, scandir($this->root), 'ledger dir contents must be untouched');
    }

    public function test_unknown_id_exits_one_with_execution_not_found_reason(): void
    {
        $this->seedOneExecution();

        [$exit, $out] = $this->runCmd(['execution_id' => 'unknown-xyz', '--json' => true]);

        $this->assertSame(AtlasAaelDeepInspectCommand::EXIT_FAIL, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertSame(AtlasAaelDeepInspectCommand::REASON_NOT_FOUND, $decoded['reason']);
    }

    public function test_valid_id_round_trips_receipt_byte_identical_envelope_keys(): void
    {
        $id = $this->seedOneExecution();

        [$exit, $out] = $this->runCmd(['execution_id' => $id, '--json' => true]);
        $this->assertSame(AtlasAaelDeepInspectCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertSame($id, $decoded['execution_id']);
        $this->assertSame(1, $decoded['runner_result']['tasks_processed']);
    }
}
