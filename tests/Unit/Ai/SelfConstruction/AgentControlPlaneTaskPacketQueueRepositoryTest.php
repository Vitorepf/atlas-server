<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentControlPlaneTaskPacketQueueRepositoryTest extends TestCase
{
    private AgentControlPlaneTaskPacketQueueRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->repo = new AgentControlPlaneTaskPacketQueueRepository;
    }

    private function enqueuePacket(string $taskPacketId): array
    {
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build([
            'task_packet_id' => $taskPacketId,
            'objective' => 'integrity report fixture',
            'operator_id' => 'integrity-test-operator',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/__integrity_fixture__/'.$taskPacketId.'.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/__integrity_fixture__/'.$taskPacketId.'.php'],
            'acceptance_criteria' => ['probe_ok'],
            'required_evidence' => ['task_packet_created'],
            'risk_level' => 'low',
        ]);

        return $this->repo->enqueue($packet);
    }

    private function taskPath(string $taskPacketId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $taskPacketId) ?? $taskPacketId;

        return AgentControlPlaneTaskPacketQueueRepository::STORAGE_PREFIX.'/task_'.$safe.'.json';
    }

    public function test_clean_registry_reports_ok_with_no_issues(): void
    {
        $this->enqueuePacket('integrity_clean_1');
        $this->enqueuePacket('integrity_clean_2');

        $report = $this->repo->integrityReport();

        $this->assertSame('ok', $report['status']);
        $this->assertSame(0, $report['issue_count']);
        $this->assertSame([], $report['issues']);
        $this->assertSame(2, $report['checked_count']);
    }

    public function test_missing_task_file_is_reported(): void
    {
        $this->enqueuePacket('integrity_missing_file');
        Storage::disk('local')->delete($this->taskPath('integrity_missing_file'));

        $report = $this->repo->integrityReport();

        $this->assertSame('drift_detected', $report['status']);
        $this->assertSame(1, $report['issue_count']);
        $this->assertSame('registry_entry_without_task_file', $report['issues'][0]['issue']);
        $this->assertSame('integrity_missing_file', $report['issues'][0]['task_packet_id']);
    }

    public function test_hash_mismatch_is_reported(): void
    {
        $this->enqueuePacket('integrity_hash_mismatch');
        $path = $this->taskPath('integrity_hash_mismatch');
        $record = json_decode((string) Storage::disk('local')->get($path), true);
        $record['task_packet_hash'] = 'tampered-hash';
        Storage::disk('local')->put($path, json_encode($record));

        $report = $this->repo->integrityReport();

        $this->assertSame('drift_detected', $report['status']);
        $issue = $report['issues'][0];
        $this->assertSame('task_file_hash_mismatch', $issue['issue']);
        $this->assertSame('integrity_hash_mismatch', $issue['task_packet_id']);
    }

    public function test_status_mismatch_is_reported(): void
    {
        $this->enqueuePacket('integrity_status_mismatch');
        $path = $this->taskPath('integrity_status_mismatch');
        $record = json_decode((string) Storage::disk('local')->get($path), true);
        $record['status'] = 'cancelled';
        Storage::disk('local')->put($path, json_encode($record));

        $report = $this->repo->integrityReport();

        $this->assertSame('drift_detected', $report['status']);
        $issue = $report['issues'][0];
        $this->assertSame('registry_status_mismatch', $issue['issue']);
        $this->assertSame('integrity_status_mismatch', $issue['task_packet_id']);
    }

    public function test_missing_updated_at_is_reported(): void
    {
        $this->enqueuePacket('integrity_missing_updated_at');
        $path = $this->taskPath('integrity_missing_updated_at');
        $record = json_decode((string) Storage::disk('local')->get($path), true);
        unset($record['updated_at']);
        Storage::disk('local')->put($path, json_encode($record));

        $report = $this->repo->integrityReport();

        $this->assertSame('drift_detected', $report['status']);
        $codes = array_column($report['issues'], 'issue');
        $this->assertContains('missing_updated_at', $codes);
    }

    public function test_integrity_report_is_read_only(): void
    {
        $this->enqueuePacket('integrity_readonly_1');
        $path = $this->taskPath('integrity_readonly_1');
        $before = (string) Storage::disk('local')->get($path);
        $registryBefore = (string) Storage::disk('local')->get(AgentControlPlaneTaskPacketQueueRepository::REGISTRY_PATH);

        $this->repo->integrityReport();

        $after = (string) Storage::disk('local')->get($path);
        $registryAfter = (string) Storage::disk('local')->get(AgentControlPlaneTaskPacketQueueRepository::REGISTRY_PATH);

        $this->assertSame($before, $after);
        $this->assertSame($registryBefore, $registryAfter);

        $record = json_decode($after, true);
        $this->assertSame([], $record['receipts']);
        $this->assertCount(1, $record['history']);
        $this->assertSame('claimable', $record['status']);
    }
}
