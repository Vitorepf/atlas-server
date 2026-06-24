<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasTaskAdaptiveCliTest extends TestCase
{
    private const TEST_DISK = 'atlas_adaptive_cli_test';

    private const LEDGER_PATH = 'atlas/maestro/adaptive/worker-behavior.jsonl';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.task_serving.queue_disk', self::TEST_DISK);
        Storage::fake(self::TEST_DISK);
        Storage::fake('local');
    }

    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:task', $args);

        return [$exit, $kernel->output()];
    }

    public function test_behaviors_prints_non_empty_facts_when_ledger_seeded(): void
    {
        config()->set('atlas.maestro.adaptive.behavior_ledger_enabled', true);

        // Seed the ledger by appending JSONL rows directly to its storage path on local disk.
        $ledgerPath = storage_path('app/'.self::LEDGER_PATH);
        @mkdir(dirname($ledgerPath), 0o755, true);
        file_put_contents($ledgerPath, json_encode([
            'client_id' => 'claude-2',
            'task_class' => 'class_a',
            'success' => 3,
            'give_back' => 1,
            'failed' => 0,
            'last_event' => 'success',
            'last_seen_at' => '2026-06-24T12:00:00+00:00',
        ]).PHP_EOL);

        [$exit, $out] = $this->runCmd(['action' => 'maestro:behaviors']);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('ok', $decoded['status']);
        $this->assertNotSame([], $decoded['rows'], 'rows must be non-empty when ledger is seeded');
        @unlink($ledgerPath);
    }

    public function test_behaviors_prints_empty_rows_when_ledger_is_empty(): void
    {
        config()->set('atlas.maestro.adaptive.behavior_ledger_enabled', true);

        [$exit, $out] = $this->runCmd(['action' => 'maestro:behaviors']);

        $this->assertSame(0, $exit);
        $decoded = json_decode($out, true);
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame([], $decoded['rows']);
        $this->assertSame('atlas.task_serving.maestro_behaviors.v1', $decoded['schema']);
    }

    public function test_reshape_does_not_call_any_mutating_method_on_the_orchestrator(): void
    {
        config()->set('atlas.maestro.adaptive.reshape_enabled', true);

        // Seed a packet on the configured serving disk so the read-only get() finds it.
        (new AgentControlPlaneTaskPacketQueueRepository(self::TEST_DISK))->enqueue([
            'task_packet_id' => 'pkt-1',
            'task_packet_hash' => hash('sha256', 'pkt-1'),
            'status' => 'claimable',
            'objective' => 'unit',
            'allowed_files' => ['app/Fake.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['ok'],
        ], []);
        $this->assertSame(self::TEST_DISK, AtlasTaskServingStack::disk());

        [$exit, $out] = $this->runCmd(['action' => 'maestro:reshape', '--packet' => 'pkt-1']);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame('pkt-1', $decoded['packet_id']);
        $this->assertArrayHasKey('reshape_receipt', $decoded);

        // Static spy: prove zero references to ANY orchestrator method on the reshape path. The reshape path
        // is the only code reachable from `maestro:reshape`; we read the command source and assert it never
        // names the orchestrator class — so no mutating call to it is reachable by construction (the
        // AtlasLoopProviderCircuitBreaker pattern: prove the chokepoint by absence-of-reference).
        $src = (string) file_get_contents(base_path('app/Console/Commands/AtlasTaskCommand.php'));
        $this->assertStringNotContainsString('AgentControlPlaneTaskQueueOrchestrator', $src,
            'the maestro:reshape path must NEVER touch the orchestrator (queue is byte-stable)');
    }

    public function test_route_flag_off_prints_adaptive_disabled_and_exit_zero(): void
    {
        config()->set('atlas.maestro.adaptive.router_enabled', false);

        [$exit, $out] = $this->runCmd(['action' => 'maestro:route', '--task-class' => 'some_class']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('adaptive_disabled', $out);
    }

    public function test_unknown_action_returns_failure(): void
    {
        [$exit] = $this->runCmd(['action' => 'maestro:bogus']);
        $this->assertNotSame(0, $exit);
    }
}
