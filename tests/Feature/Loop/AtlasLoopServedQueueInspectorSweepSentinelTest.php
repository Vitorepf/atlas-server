<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Sentinels\AtlasLoopServedQueueInspectorSweepSentinel;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Proves the live-queue quality sweep sentinel reads from the OPERATOR SERVING
 * disk (the same disk a worker serves from), NOT the shared 'local' default disk
 * (which is flooded with certification probes and would hide real offenders).
 *
 * Regression: sweep() used to fall back to `new AgentControlPlaneTaskPacketQueueRepository`
 * (no disk arg => DEFAULT_DISK='local'), so AppServiceProvider's plain singleton binding
 * resolved the sentinel against the wrong queue — the certification-spam local disk — and
 * the sweep would never see broken packets actually claimable on atlas_serving.
 */
final class AtlasLoopServedQueueInspectorSweepSentinelTest extends TestCase
{
    private const SERVING_DISK = 'atlas_serving_sweep_sentinel_test';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.task_serving.queue_disk', self::SERVING_DISK);
        Storage::fake(self::SERVING_DISK);
        Storage::fake('local');
    }

    /** @return array<string,mixed> */
    private function validPacket(): array
    {
        return [
            'task_packet_id' => 'sweep-valid',
            'task_packet_hash' => 'hash-valid',
            'status' => 'planned',
            'objective' => 'Create the class Foo and prove it.',
            'allowed_files' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
            'acceptance_criteria' => ['the class Foo exists', 'create a passing PHPUnit test for Foo'],
            'required_evidence' => ['tests_or_gates_result'],
        ];
    }

    /** A packet that demands test-authoring evidence but grants NO tests/ path — the 18-packet bug. */
    private function offendingPacket(): array
    {
        return [
            'task_packet_id' => 'sweep-offender',
            'task_packet_hash' => 'hash-offender',
            'status' => 'planned',
            'objective' => 'Do a thing, prove with a test.',
            'allowed_files' => ['app/Foo.php'], // NO tests/ entry
            'acceptance_criteria' => ['create a passing PHPUnit test proving Foo works'],
            'required_evidence' => ['tests_or_gates_result'],
        ];
    }

    public function test_flags_offending_packet_on_the_serving_disk(): void
    {
        $queue = AtlasTaskServingStack::queueRepo();

        $this->assertSame('ok', $queue->enqueue($this->validPacket())['status']);
        $this->assertSame('ok', $queue->enqueue($this->offendingPacket())['status']);

        // Construct the sentinel with NO injected queue — this is how the AppServiceProvider
        // singleton resolves it. The fix makes its fallback target the serving disk; the bug
        // made it read 'local' and report clean.
        $result = (new AtlasLoopServedQueueInspectorSweepSentinel())->sweep();

        $this->assertFalse($result['clean'], 'a broken packet on the serving queue => not clean');
        $this->assertCount(1, $result['offenders']);
        $this->assertSame('sweep-offender', $result['offenders'][0]['id']);
        $this->assertContains('test_evidence_without_test_in_allowed_files', $result['offenders'][0]['blocking_deficiencies']);
    }

    public function test_returns_clean_on_a_healthy_serving_queue(): void
    {
        $queue = AtlasTaskServingStack::queueRepo();

        $this->assertSame('ok', $queue->enqueue($this->validPacket())['status']);

        $result = (new AtlasLoopServedQueueInspectorSweepSentinel())->sweep();

        $this->assertTrue($result['clean'], 'no false positive on a healthy serving queue');
        $this->assertSame([], $result['offenders']);
        $this->assertSame(1, $result['scanned']);
    }

    public function test_returns_clean_when_serving_disk_has_no_claimable_packets(): void
    {
        $result = (new AtlasLoopServedQueueInspectorSweepSentinel())->sweep();

        $this->assertTrue($result['clean']);
        $this->assertSame(0, $result['scanned']);
        $this->assertSame([], $result['offenders']);
    }
}
