<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

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
        $this->sandbox = sys_get_temp_dir().'/atlas-maestro-priority-age-value-cli-'.bin2hex(random_bytes(6));
        @mkdir($this->sandbox, 0o755, true);
        $this->historyPath = $this->sandbox.'/history.jsonl';
        $this->envPath = $this->sandbox.'/.env';

        $this->app->instance(AtlasTaskMaestroPriorityCommand::SNAPSHOTS_PATH_BINDING, $this->historyPath);
        $this->app->instance(AtlasTaskMaestroPriorityCommand::QUEUE_TAG_BINDING, 'loop');

        AtlasLoopMasterSwitch::$envPathOverride = $this->envPath;
        file_put_contents($this->envPath, 'ATLAS_LOOP_MASTER_ENABLED=true'.PHP_EOL);
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

    private function bindPackets(array $packets): void
    {
        $this->app->instance(
            AtlasTaskMaestroPriorityCommand::PENDING_PACKETS_SOURCE_BINDING,
            static fn (): array => $packets,
        );
    }

    private function snapshot(): array
    {
        $exit = Artisan::call('atlas:task:maestro:priority', ['action' => 'snapshot', '--json' => true]);
        $payload = (array) json_decode(trim(Artisan::output()), true);

        return ['exit' => $exit, 'payload' => $payload];
    }

    public function test_snapshot_includes_age_value_pressure_with_valid_band(): void
    {
        $this->bindPackets([
            ['task_packet_id' => 'pkt-A', 'tags' => []],
        ]);

        $r = $this->snapshot();

        $this->assertSame(0, $r['exit']);
        $this->assertArrayHasKey('age_value_pressure', $r['payload']);
        $this->assertContains(
            $r['payload']['age_value_pressure']['band'],
            ['low', 'watch', 'replenish_soon', 'urgent'],
        );
    }

    public function test_blocked_and_quarantined_packets_are_not_counted_as_implementable_supply(): void
    {
        $this->bindPackets([
            ['task_packet_id' => 'pkt-A', 'tags' => [], 'blocked' => true],
            ['task_packet_id' => 'pkt-B', 'tags' => [], 'quarantined' => true],
        ]);

        $r = $this->snapshot();

        $pressure = $r['payload']['age_value_pressure'];
        $this->assertSame(0, $pressure['implementable_supply_count']);
        $this->assertSame(2, $pressure['blocked_or_quarantined_count']);
        $this->assertSame('urgent', $pressure['band']);
        $this->assertSame('replenish', $pressure['recommendation']);
    }

    public function test_stale_claimable_queue_with_active_workers_recommends_wait_not_replenish(): void
    {
        // Many claimable packets, but worker_idle_prediction_ms comes out to 0 (no lease history
        // ⇒ workers are effectively active/busy right now, not about to go idle) — the queue is
        // stale in the sense that it isn't moving, but it IS servable, so origination must wait.
        $this->bindPackets(array_map(
            static fn (int $i): array => ['task_packet_id' => "pkt-{$i}", 'tags' => []],
            range(1, 10),
        ));

        $r = $this->snapshot();

        $pressure = $r['payload']['age_value_pressure'];
        $this->assertSame(10, $pressure['implementable_supply_count']);
        $this->assertSame(0, $pressure['worker_idle_prediction_ms']);
        $this->assertSame('watch', $pressure['band']);
        $this->assertSame('wait', $pressure['recommendation']);
    }

    public function test_low_claimable_supply_recommends_replenish_soon(): void
    {
        $this->bindPackets([]);

        $r = $this->snapshot();

        $pressure = $r['payload']['age_value_pressure'];
        $this->assertSame(0, $pressure['implementable_supply_count']);
        $this->assertSame('urgent', $pressure['band']);
    }

    public function test_master_off_returns_neutral_age_value_pressure_and_writes_nothing(): void
    {
        file_put_contents($this->envPath, 'ATLAS_LOOP_MASTER_ENABLED=false'.PHP_EOL);
        $this->bindPackets([['task_packet_id' => 'pkt-A', 'tags' => []]]);

        $exit = Artisan::call('atlas:task:maestro:priority', ['action' => 'snapshot', '--json' => true]);
        $payload = (array) json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('disabled', $payload['status']);
        $this->assertArrayNotHasKey('age_value_pressure', $payload);
    }
}
