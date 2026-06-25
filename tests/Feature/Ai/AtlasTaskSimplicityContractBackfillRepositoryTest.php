<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasTaskSimplicityContractBackfillRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function repo(): AgentControlPlaneTaskPacketQueueRepository
    {
        return new AgentControlPlaneTaskPacketQueueRepository;
    }

    private function packet(string $id): array
    {
        return (new AgentControlPlaneTaskPacketBuilder)->build([
            'task_packet_id' => $id,
            'objective' => 'backfill repo test packet',
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ]);
    }

    private function corruptContract(AgentControlPlaneTaskPacketQueueRepository $repo, string $id): void
    {
        $record = $repo->get($id);
        $this->assertNotNull($record);
        // Simulate a legacy/drifted packet: blank the simplicity contract on disk.
        $record['task_packet']['simplicity_contract'] = ['final_runtime_owner' => 'external_provider'];
        $record['task_packet_hash'] = 'legacy-drifted-hash';
        $disk = Storage::disk('local');
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $id) ?? $id;
        $path = AgentControlPlaneTaskPacketQueueRepository::STORAGE_PREFIX.'/task_'.$safe.'.json';
        $disk->put($path, json_encode($record, JSON_PRETTY_PRINT));
    }

    public function test_backfills_legacy_claimable_packet_and_recomputes_hash(): void
    {
        $repo = $this->repo();
        $repo->enqueue($this->packet('backfill-legacy-1'));
        $this->corruptContract($repo, 'backfill-legacy-1');

        $result = $repo->backfillSimplicityContract('backfill-legacy-1');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('simplicity_contract_backfilled', $result['event']);
        $this->assertSame('legacy-drifted-hash', $result['previous_task_packet_hash']);
        $this->assertNotSame('legacy-drifted-hash', $result['task_packet_hash']);

        $refreshed = $repo->get('backfill-legacy-1');
        $this->assertSame('claimable', $refreshed['status'], 'status must be preserved');
        $this->assertSame(
            AgentControlPlaneTaskPacketBuilder::defaultSimplicityContract(),
            $refreshed['task_packet']['simplicity_contract'],
        );
        $this->assertSame($result['task_packet_hash'], $refreshed['task_packet_hash']);
        $this->assertSame($result['task_packet_hash'], $refreshed['task_packet']['task_packet_hash']);

        $events = array_column($refreshed['history'], 'event');
        $this->assertContains('task_packet_simplicity_contract_backfilled', $events);
    }

    public function test_idempotent_when_contract_already_conforming(): void
    {
        $repo = $this->repo();
        $repo->enqueue($this->packet('backfill-conforming-1'));
        $before = $repo->get('backfill-conforming-1');

        $result = $repo->backfillSimplicityContract('backfill-conforming-1');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('simplicity_contract_already_conforming', $result['event']);
        $this->assertTrue($result['idempotent']);

        $after = $repo->get('backfill-conforming-1');
        $this->assertSame($before['task_packet_hash'], $after['task_packet_hash'], 'hash must not change');
        $this->assertSame($before['updated_at'], $after['updated_at'], 'updated_at must not change');
        $this->assertCount(count($before['history']), $after['history']);
    }

    public function test_blocks_claimed_record(): void
    {
        $repo = $this->repo();
        $repo->enqueue($this->packet('backfill-claimed-1'));
        $this->corruptContract($repo, 'backfill-claimed-1');
        $repo->compareAndSwapStatus('backfill-claimed-1', 'claimable', 'claimed', [
            'lease_id' => 'lease-x',
            'agent_id' => 'agent-x',
        ]);

        $result = $repo->backfillSimplicityContract('backfill-claimed-1');

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('task_packet_status_blocks_backfill', $result['reason']);
        $this->assertSame('claimed', $result['actual_status']);
    }

    public function test_hash_is_consistent_with_builder_normalization(): void
    {
        $repo = $this->repo();
        $repo->enqueue($this->packet('backfill-hash-1'));
        $this->corruptContract($repo, 'backfill-hash-1');

        $result = $repo->backfillSimplicityContract('backfill-hash-1');
        $hash1 = $result['task_packet_hash'];

        // Same input → same hash (deterministic, key-order independent).
        $repo->enqueue($this->packet('backfill-hash-2'));
        $this->corruptContract($repo, 'backfill-hash-2');
        $result2 = $repo->backfillSimplicityContract('backfill-hash-2');

        // Packets share content (only id differs which is stripped by normalization) → equal hash.
        $this->assertSame($hash1, $result2['task_packet_hash']);
    }

    public function test_registry_entry_picks_up_new_hash_and_status(): void
    {
        $repo = $this->repo();
        $repo->enqueue($this->packet('backfill-registry-1'));
        $this->corruptContract($repo, 'backfill-registry-1');

        $result = $repo->backfillSimplicityContract('backfill-registry-1');

        $entries = $repo->registry()['entries'];
        $entry = null;
        foreach ($entries as $candidate) {
            if (($candidate['task_packet_id'] ?? '') === 'backfill-registry-1') {
                $entry = $candidate;
                break;
            }
        }
        $this->assertNotNull($entry, 'registry must still index the packet');
        $this->assertSame($result['task_packet_hash'], $entry['task_packet_hash']);
        $this->assertSame('claimable', $entry['status']);
    }
}
