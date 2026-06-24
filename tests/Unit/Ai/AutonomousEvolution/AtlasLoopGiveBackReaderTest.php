<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Feedback\AtlasLoopGiveBackReader;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasLoopGiveBackReaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_recent_reads_completed_cancelled_and_give_back_outcomes_from_the_canonical_queue_store(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $this->seedGiveBackRecord($queue);
        $this->seedCompletedRecord($queue);
        $this->seedCancelledRecord($queue);

        $reader = new AtlasLoopGiveBackReader($queue);
        $outcomes = $reader->recent();

        $this->assertCount(3, $outcomes);
        $indexed = [];
        foreach ($outcomes as $outcome) {
            $indexed[$outcome['packet_id'].'|'.$outcome['outcome']] = $outcome;
        }
        $keys = array_keys($indexed);
        sort($keys);

        $this->assertSame(
            [
                'cancel-77|cancelled',
                'complete-88|completed',
                'loop-feedback-w16-01-givebackreader|give_back',
            ],
            $keys
        );
        $this->assertSame('cancel', $indexed['cancel-77|cancelled']['packet_class']);
        $this->assertSame('operator_cancelled', $indexed['cancel-77|cancelled']['reason']);
        $this->assertSame('', $indexed['cancel-77|cancelled']['worker']);
        $this->assertSame('complete', $indexed['complete-88|completed']['packet_class']);
        $this->assertSame('committed_to_main', $indexed['complete-88|completed']['reason']);
        $this->assertSame('worker-b', $indexed['complete-88|completed']['worker']);
        $this->assertSame('loop', $indexed['loop-feedback-w16-01-givebackreader|give_back']['packet_class']);
        $this->assertSame('client_reported_give_back', $indexed['loop-feedback-w16-01-givebackreader|give_back']['reason']);
        $this->assertSame('worker-a', $indexed['loop-feedback-w16-01-givebackreader|give_back']['worker']);

        $recordedAt = array_column($outcomes, 'recorded_at');
        $sorted = $recordedAt;
        rsort($sorted);
        $this->assertSame($sorted, $recordedAt, 'recent() returns newest-first across the shared queue store');
    }

    public function test_empty_queue_returns_no_outcomes(): void
    {
        $reader = new AtlasLoopGiveBackReader(new AgentControlPlaneTaskPacketQueueRepository);

        $this->assertSame([], $reader->recent());
    }

    public function test_malformed_records_are_skipped_and_counted_in_stats(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $this->seedGiveBackRecord($queue, 'good-1');

        $disk = Storage::disk('local');
        $registry = json_decode((string) $disk->get(AgentControlPlaneTaskPacketQueueRepository::REGISTRY_PATH), true, flags: JSON_THROW_ON_ERROR);
        $registry['entries'][] = [
            'task_packet_id' => 'broken-1',
            'task_packet_hash' => 'broken-hash',
            'enqueued_at' => '2026-06-24T00:00:00+00:00',
            'updated_at' => '2026-06-24T00:00:00+00:00',
            'status' => 'released',
            'priority' => 5,
            'tags' => [],
        ];
        $disk->put(AgentControlPlaneTaskPacketQueueRepository::REGISTRY_PATH, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $disk->put(AgentControlPlaneTaskPacketQueueRepository::STORAGE_PREFIX.'/task_broken-1.json', '{"bad-json"');

        $reader = new AtlasLoopGiveBackReader($queue);
        $outcomes = $reader->recent();
        $stats = $reader->stats();

        $this->assertCount(1, $outcomes);
        $this->assertSame('good-1', $outcomes[0]['packet_id']);
        $this->assertSame(2, $stats['records_scanned']);
        $this->assertSame(1, $stats['outcomes_emitted']);
        $this->assertSame(1, $stats['malformed_records_skipped']);
        $this->assertSame(0, $stats['malformed_outcomes_skipped']);
    }

    private function seedGiveBackRecord(AgentControlPlaneTaskPacketQueueRepository $queue, string $taskPacketId = 'loop-feedback-w16-01-givebackreader'): void
    {
        $queue->enqueue($this->packet($taskPacketId, 'planned'));
        $queue->compareAndSwapStatus($taskPacketId, 'claimable', 'claimed', [
            'lease_id' => 'lease-giveback',
            'agent_id' => 'worker-a',
        ]);
        $queue->updateStatus($taskPacketId, 'released', [
            'lease_id' => 'lease-giveback',
            'release_reason' => 'client_reported_give_back',
            'give_back_count' => 1,
            'last_give_back_by' => 'worker-a',
        ]);
        $queue->appendReceipt($taskPacketId, [
            'receipt_kind' => 'task_given_back',
            'agent_id' => 'worker-a',
            'give_back_count' => 1,
        ]);
    }

    private function seedCompletedRecord(AgentControlPlaneTaskPacketQueueRepository $queue): void
    {
        $queue->enqueue($this->packet('complete-88', 'planned'));
        $queue->compareAndSwapStatus('complete-88', 'claimable', 'claimed', [
            'lease_id' => 'lease-complete',
            'agent_id' => 'worker-b',
        ]);
        $queue->updateStatus('complete-88', 'completed_dry_run', [
            'lease_id' => 'lease-complete',
            'agent_id' => 'worker-b',
            'resolution' => 'committed_to_main',
        ]);
    }

    private function seedCancelledRecord(AgentControlPlaneTaskPacketQueueRepository $queue): void
    {
        $queue->enqueue($this->packet('cancel-77', 'planned'));
        $queue->updateStatus('cancel-77', 'cancelled', [
            'reason' => 'operator_cancelled',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function packet(string $taskPacketId, string $status): array
    {
        return [
            'task_packet_id' => $taskPacketId,
            'task_packet_hash' => hash('sha256', $taskPacketId),
            'objective' => 'reader test '.$taskPacketId,
            'operator_id' => 'tester',
            'status' => $status,
            'allowed_files' => ['app/Services/Ai/AutonomousEvolution/Feedback/'.$taskPacketId.'.php'],
            'scope_in' => ['app/Services/Ai/AutonomousEvolution/Feedback/'.$taskPacketId.'.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ];
    }
}
