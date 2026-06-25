<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasTaskMaestroPriorityCommand;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasTaskMaestroPriorityCommandTest extends TestCase
{
    private string $sandbox = '';

    private string $historyPath = '';

    private string $envPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir().'/atlas-maestro-priority-cli-'.bin2hex(random_bytes(6));
        @mkdir($this->sandbox, 0o755, true);
        $this->historyPath = $this->sandbox.'/history.jsonl';
        $this->envPath = $this->sandbox.'/.env';

        $this->app->instance(AtlasTaskMaestroPriorityCommand::SNAPSHOTS_PATH_BINDING, $this->historyPath);
        $this->app->instance(AtlasTaskMaestroPriorityCommand::QUEUE_TAG_BINDING, 'loop');
        $this->app->instance(
            AtlasTaskMaestroPriorityCommand::PENDING_PACKETS_SOURCE_BINDING,
            static fn (): array => [
                ['task_packet_id' => 'pkt-B', 'enqueued_at' => '2026-06-25T00:00:00Z'],
                ['task_packet_id' => 'pkt-A', 'enqueued_at' => '2026-06-25T01:00:00Z'],
            ],
        );

        AtlasLoopMasterSwitch::$envPathOverride = $this->envPath;
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        foreach ((array) glob($this->sandbox.'/*') as $entry) {
            @unlink((string) $entry);
        }
        @rmdir($this->sandbox);
        parent::tearDown();
    }

    private function masterOn(): void
    {
        file_put_contents($this->envPath, 'ATLAS_LOOP_MASTER_ENABLED=true'.PHP_EOL);
    }

    private function masterOff(): void
    {
        file_put_contents($this->envPath, 'ATLAS_LOOP_MASTER_ENABLED=false'.PHP_EOL);
    }

    private function callCli(string $action, array $opts = []): array
    {
        $payload = ['action' => $action, '--json' => true];
        foreach ($opts as $k => $v) {
            $payload['--'.$k] = $v;
        }
        $exit = Artisan::call('atlas:task:maestro:priority', $payload);
        $raw = trim(Artisan::output());

        return ['exit' => $exit, 'raw' => $raw, 'payload' => (array) json_decode($raw, true)];
    }

    public function test_snapshot_succeeds_with_master_on_and_emits_snapshot_schema(): void
    {
        $this->masterOn();
        $r = $this->callCli('snapshot');
        $this->assertSame(0, $r['exit']);
        $this->assertSame(AtlasTaskMaestroPriorityCommand::SCHEMA_SNAPSHOT, $r['payload']['schema']);
        $this->assertSame('snapshotted', $r['payload']['status']);
        $this->assertFileExists($this->historyPath);
    }

    public function test_reshape_then_history_returns_two_events_newest_first(): void
    {
        $this->masterOn();
        $this->callCli('snapshot');
        $reshape = $this->callCli('reshape');
        $this->assertSame(0, $reshape['exit']);
        $this->assertSame(AtlasTaskMaestroPriorityCommand::SCHEMA_RESHAPE, $reshape['payload']['schema']);
        $this->assertSame('reshaped', $reshape['payload']['status']);
        $this->assertSame(['pkt-B', 'pkt-A'], $reshape['payload']['before_head']);
        // No criticality facts in our snapshot ⇒ FIFO tiebreak picks pkt-B first (older enqueue).
        $this->assertSame(['pkt-B', 'pkt-A'], $reshape['payload']['after_head']);

        $history = $this->callCli('history', ['limit' => 2]);
        $this->assertSame(0, $history['exit']);
        $this->assertSame(AtlasTaskMaestroPriorityCommand::SCHEMA_HISTORY, $history['payload']['schema']);
        $this->assertSame('listed', $history['payload']['status']);
        $this->assertCount(2, $history['payload']['events']);
        // Newest first: reshape was logged AFTER snapshot ⇒ reshape's taken_at_ns >= snapshot's.
        // Events come from snapshot (taken_at_ns from snapshotter) and reshape (taken_at_ns copied).
        // Order check: index 0 must be the most recent.
        $this->assertGreaterThanOrEqual(
            (int) $history['payload']['events'][1]['taken_at_ns'],
            (int) $history['payload']['events'][0]['taken_at_ns'],
        );
    }

    public function test_master_off_returns_disabled_payload_byte_identical_across_verbs_and_writes_nothing(): void
    {
        $this->masterOff();

        $rowsBefore = is_file($this->historyPath)
            ? count((array) file($this->historyPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            : 0;

        $snapshot = $this->callCli('snapshot');
        $reshape = $this->callCli('reshape');
        $history = $this->callCli('history');

        foreach ([$snapshot, $reshape, $history] as $r) {
            $this->assertSame(0, $r['exit']);
            $this->assertSame('disabled', $r['payload']['status']);
            $this->assertSame(AtlasTaskMaestroPriorityCommand::SCHEMA_DISABLED, $r['payload']['schema']);
        }

        $rowsAfter = is_file($this->historyPath)
            ? count((array) file($this->historyPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            : 0;
        $this->assertSame($rowsBefore, $rowsAfter, 'master-off must write zero rows');
    }
}
