<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Feedback\AtlasLoopGiveBackReader;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Proves the give-back feedback reader mines outcomes from the OPERATOR SERVING
 * disk (the same disk a worker serves from), NOT the shared 'local' default disk
 * (which is flooded with certification probes and would hide real give-backs).
 *
 * Regression: scan() used to fall back to `new AgentControlPlaneTaskPacketQueueRepository`
 * (no disk arg => DEFAULT_DISK='local'), so the operator-facing feedback loop — the
 * pattern miner, honesty auditor, and the CLI container injection — read the wrong
 * queue and saw zero real outcomes.
 */
final class AtlasLoopGiveBackReaderTest extends TestCase
{
    private const SERVING_DISK = 'atlas_serving_giveback_reader_test';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.task_serving.queue_disk', self::SERVING_DISK);
        Storage::fake(self::SERVING_DISK);
        Storage::fake('local');
    }

    public function test_reader_reads_outcomes_from_the_serving_disk_not_the_default_local_disk(): void
    {
        $queue = AtlasTaskServingStack::queueRepo();

        $queue->enqueue([
            'task_packet_id' => 'giveback-serving-disk-1',
            'task_packet_hash' => 'hash-serving-1',
            'status' => 'planned',
            'objective' => 'seed give_back onto serving disk',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/giveback-serving-disk-1.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ]);

        $queue->updateStatus('giveback-serving-disk-1', 'claimed', [
            'lease_id' => 'lease-serving-1',
            'agent_id' => 'worker-serving-1',
        ]);

        $queue->updateStatus('giveback-serving-disk-1', 'released', [
            'release_reason' => 'client_reported_give_back',
            'last_give_back_by' => 'hermes-1',
        ]);

        // Construct the reader with NO injected queue — this is how the operator CLI,
        // pattern miner, and honesty auditor resolve it from the container. The fix
        // makes its fallback target the serving disk; the bug made it read 'local'.
        $reader = new AtlasLoopGiveBackReader();

        $recent = $reader->recent(10);

        $this->assertCount(1, $recent, 'reader must find the give_back seeded on the serving disk');
        $this->assertSame('giveback-serving-disk-1', $recent[0]['packet_id']);
        $this->assertSame('give_back', $recent[0]['outcome']);
        $this->assertSame('client_reported_give_back', $recent[0]['reason']);
        $this->assertSame('hermes-1', $recent[0]['worker']);
    }

    public function test_reader_finds_completed_outcome_on_the_serving_disk(): void
    {
        $queue = AtlasTaskServingStack::queueRepo();

        $queue->enqueue([
            'task_packet_id' => 'completed-serving-disk-1',
            'task_packet_hash' => 'hash-completed-1',
            'status' => 'planned',
            'objective' => 'seed completed onto serving disk',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/completed-serving-disk-1.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ]);

        $queue->updateStatus('completed-serving-disk-1', 'claimed', [
            'lease_id' => 'lease-completed-1',
            'agent_id' => 'worker-completed-1',
        ]);

        $queue->updateStatus('completed-serving-disk-1', 'completed_dry_run', [
            'resolution' => 'success',
            'agent_id' => 'worker-completed-1',
        ]);

        $reader = new AtlasLoopGiveBackReader();

        $recent = $reader->recent(10);

        $this->assertCount(1, $recent, 'reader must find the completed outcome seeded on the serving disk');
        $this->assertSame('completed-serving-disk-1', $recent[0]['packet_id']);
        $this->assertSame('completed', $recent[0]['outcome']);
    }

    public function test_colon_delimited_packet_id_resolves_to_first_segment_as_class(): void
    {
        $queue = AtlasTaskServingStack::queueRepo();
        $packetId = 'brain:c3q100:auto-merge-reverse-audit-cli-v1';

        $queue->enqueue([
            'task_packet_id' => $packetId,
            'task_packet_hash' => 'hash-colon-1',
            'status' => 'planned',
            'objective' => 'test colon-delimited packet class parsing',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/placeholder.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ]);

        $queue->updateStatus($packetId, 'claimed', [
            'lease_id' => 'lease-colon-1',
            'agent_id' => 'worker-colon-1',
        ]);

        $queue->updateStatus($packetId, 'released', [
            'release_reason' => 'client_reported_give_back',
            'last_give_back_by' => 'worker-colon-1',
        ]);

        $recent = (new AtlasLoopGiveBackReader())->recent(10);

        $this->assertCount(1, $recent);
        $this->assertSame($packetId, $recent[0]['packet_id']);
        $this->assertSame('brain', $recent[0]['packet_class'], 'colon-delimited id must resolve to its first segment');
    }

    public function test_reader_returns_empty_when_serving_disk_has_no_outcomes(): void
    {
        $reader = new AtlasLoopGiveBackReader();

        $this->assertSame([], $reader->recent(10));
    }
}
