<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Feedback\AtlasLoopGiveBackHonestyAuditor;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasLoopGiveBackHonestyAuditorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_wrong_path_with_subsequent_commit_on_allowed_files_is_suspect(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $queue->enqueue($this->packet('suspect-1', ['app/Loop/Suspect.php']));

        $auditor = new AtlasLoopGiveBackHonestyAuditor(
            $this->readerFixture(),
            $queue,
            static fn (array $allowedFiles, string $recordedAt, int $windowHours): array => $allowedFiles === ['app/Loop/Suspect.php']
                ? [['sha' => 'abc123def456', 'committed_at' => '2026-06-24T01:05:00+00:00']]
                : []
        );

        $result = $auditor->audit();

        $this->assertSame(
            [
                'packet_id' => 'suspect-1',
                'reason' => 'wrong_path',
                'reason_category' => 'wrong_path',
                'verdict' => 'suspect',
                'evidence_pointer' => 'abc123def456',
            ],
            $result[0]
        );
    }

    public function test_wrong_path_with_no_follow_up_commit_is_honest(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $queue->enqueue($this->packet('honest-1', ['app/Loop/Honest.php']));

        $auditor = new AtlasLoopGiveBackHonestyAuditor(
            new class
            {
                public function recentOutcomes(int $limit = 200): array
                {
                    return [[
                        'packet_id' => 'honest-1',
                        'packet_class' => 'loop',
                        'outcome' => 'give_back',
                        'reason' => 'wrong_path',
                        'worker' => 'worker-b',
                        'recorded_at' => '2026-06-24T01:00:00+00:00',
                    ]];
                }
            },
            $queue,
            static fn (array $allowedFiles, string $recordedAt, int $windowHours): array => []
        );

        $result = $auditor->audit();

        $this->assertSame('honest', $result[0]['verdict']);
        $this->assertSame('no_evidence', $result[0]['evidence_pointer']);
        $this->assertSame('wrong_path', $result[0]['reason_category']);
    }

    public function test_non_scope_reason_is_unverifiable(): void
    {
        $auditor = new AtlasLoopGiveBackHonestyAuditor(
            new class
            {
                public function recentOutcomes(int $limit = 200): array
                {
                    return [[
                        'packet_id' => 'unknown-1',
                        'packet_class' => 'loop',
                        'outcome' => 'give_back',
                        'reason' => 'client_reported_give_back',
                        'worker' => 'worker-c',
                        'recorded_at' => '2026-06-24T01:00:00+00:00',
                    ]];
                }
            },
            new AgentControlPlaneTaskPacketQueueRepository,
            static fn (array $allowedFiles, string $recordedAt, int $windowHours): array => []
        );

        $result = $auditor->audit();

        $this->assertSame('unverifiable', $result[0]['verdict']);
        $this->assertSame('other', $result[0]['reason_category']);
        $this->assertSame('no_evidence', $result[0]['evidence_pointer']);
    }

    private function readerFixture(): object
    {
        return new class
        {
            public function recentOutcomes(int $limit = 200): array
            {
                return [[
                    'packet_id' => 'suspect-1',
                    'packet_class' => 'loop',
                    'outcome' => 'give_back',
                    'reason' => 'wrong_path',
                    'worker' => 'worker-a',
                    'recorded_at' => '2026-06-24T01:00:00+00:00',
                ]];
            }
        };
    }

    /**
     * @param  list<string>  $allowedFiles
     * @return array<string, mixed>
     */
    private function packet(string $taskPacketId, array $allowedFiles): array
    {
        return [
            'task_packet_id' => $taskPacketId,
            'task_packet_hash' => hash('sha256', $taskPacketId),
            'objective' => 'auditor test '.$taskPacketId,
            'operator_id' => 'tester',
            'status' => 'planned',
            'allowed_files' => $allowedFiles,
            'scope_in' => $allowedFiles,
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ];
    }
}
