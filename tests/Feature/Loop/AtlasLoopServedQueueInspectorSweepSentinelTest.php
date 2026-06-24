<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Sentinels\AtlasLoopServedQueueInspectorSweepSentinel;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Proves the live-queue quality sweep sentinel against a real (faked-disk) serving queue: it flags a packet
 * that demands test evidence without a tests/ path in allowed_files (today's 18-packet bug), and returns clean
 * once that packet is removed — no false positives on a healthy queue. Reads through the repository, no mock.
 */
final class AtlasLoopServedQueueInspectorSweepSentinelTest extends TestCase
{
    private AgentControlPlaneTaskPacketQueueRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->repo = new AgentControlPlaneTaskPacketQueueRepository;
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

    private function sentinel(): AtlasLoopServedQueueInspectorSweepSentinel
    {
        return new AtlasLoopServedQueueInspectorSweepSentinel($this->repo);
    }

    public function test_flags_the_offending_packet_on_the_live_queue(): void
    {
        $this->assertSame('ok', $this->repo->enqueue($this->validPacket())['status']);
        $this->assertSame('ok', $this->repo->enqueue($this->offendingPacket())['status']);

        $result = $this->sentinel()->sweep();

        $this->assertFalse($result['clean'], 'a broken packet on the queue ⇒ not clean');
        $this->assertCount(1, $result['offenders']);
        $this->assertSame('sweep-offender', $result['offenders'][0]['id']);
        $this->assertContains('test_evidence_without_test_in_allowed_files', $result['offenders'][0]['blocking_deficiencies']);
    }

    public function test_returns_clean_after_the_offender_is_removed(): void
    {
        $this->repo->enqueue($this->validPacket());
        $this->repo->enqueue($this->offendingPacket());

        // Purge the offender from the claimable set.
        $this->assertSame('ok', $this->repo->updateStatus('sweep-offender', 'cancelled')['status']);

        $result = $this->sentinel()->sweep();

        $this->assertTrue($result['clean'], 'no false positive on a healthy queue');
        $this->assertSame([], $result['offenders']);
        $this->assertSame(1, $result['scanned'], 'only the valid packet remains claimable');
    }
}
