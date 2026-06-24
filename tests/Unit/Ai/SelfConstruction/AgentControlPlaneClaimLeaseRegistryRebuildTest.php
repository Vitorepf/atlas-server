<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentControlPlaneClaimLeaseRegistryRebuildTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_corrupt_registry_auto_rebuilds_before_claim_so_conflicts_are_not_lost(): void
    {
        $repo = new AgentControlPlaneClaimLeaseRepository;
        $repo->claim('task-old-a', 'agent-a', $this->scope(['app/A.php']));
        $repo->claim('task-old-b', 'agent-b', $this->scope(['app/B.php']));
        $repo->claim('task-old-c', 'agent-c', $this->scope(['app/C.php']));

        Storage::disk('local')->put(AgentControlPlaneClaimLeaseRepository::REGISTRY_PATH, '{ entries: GARBAGE');

        $claim = $repo->claim('task-new', 'agent-new', $this->scope(['app/A.php', 'app/B.php', 'app/C.php']));

        $this->assertSame('blocked', $claim['status']);
        $this->assertSame('write_set_overlap', $claim['reason']);
        $this->assertCount(3, $claim['conflict']);
        $conflictingTasks = array_column($claim['conflict'], 'task_packet_id');
        sort($conflictingTasks);
        $this->assertSame([
            'task-old-a',
            'task-old-b',
            'task-old-c',
        ], $conflictingTasks);

        $registry = $this->registry();
        $this->assertArrayNotHasKey('corrupt', $registry);
        $this->assertSame(3, $registry['rebuilt_from_file_count']);
        $this->assertSame(3, $registry['recovered_active_count']);
        $this->assertCount(3, $registry['entries']);
    }

    public function test_rebuild_registry_from_lease_files_skips_corrupt_lease_files_and_is_idempotent(): void
    {
        $repo = new AgentControlPlaneClaimLeaseRepository;
        $repo->claim('task-a', 'agent-a', $this->scope(['app/A.php']));
        $repo->claim('task-b', 'agent-b', $this->scope(['app/B.php']));
        $repo->claim('task-c', 'agent-c', $this->scope(['app/C.php']));
        Storage::disk('local')->put(AgentControlPlaneClaimLeaseRepository::STORAGE_PREFIX.'/lease_corrupt.json', 'not-json');

        $first = $repo->rebuildRegistryFromLeaseFiles();
        $second = $repo->rebuildRegistryFromLeaseFiles();

        foreach ([$first, $second] as $result) {
            $this->assertSame('rebuilt', $result['status']);
            $this->assertSame(3, $result['rebuilt_from_file_count']);
            $this->assertSame(3, $result['recovered_active_count']);
            $this->assertSame(1, $result['skipped_corrupt_lease_files']);
            $this->assertCount(3, $result['entries']);
            $this->assertFalse($result['runtime_execution_allowed']);
            $this->assertFalse($result['dispatch_allowed']);
            $this->assertFalse($result['ledger_write_allowed']);
        }

        $registry = $this->registry();
        $registryTasks = array_column($registry['entries'], 'task_packet_id');
        sort($registryTasks);
        $this->assertCount(3, $registry['entries']);
        $this->assertSame(['task-a', 'task-b', 'task-c'], $registryTasks);
    }

    /**
     * @param  list<string>  $writeSet
     * @return array<string, mixed>
     */
    private function scope(array $writeSet): array
    {
        return [
            'write_set' => $writeSet,
            'read_set' => $writeSet,
            'scope_lock_plan_hash' => 'test_scope_lock',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function registry(): array
    {
        return json_decode(
            (string) Storage::disk('local')->get(AgentControlPlaneClaimLeaseRepository::REGISTRY_PATH),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
