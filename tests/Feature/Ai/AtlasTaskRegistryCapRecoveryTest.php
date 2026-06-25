<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PART 2 — the registry index must NEVER lose servable work. The old FIFO cap (array_slice(-200)) evicted the
 * oldest entries regardless of status, so once the queue passed 200 a claimable task fell out of the index and
 * became invisible to next()/health (confirmed live: 576 task files, 201 indexed → 323 claimable tasks lost).
 * The cap now only bounds TERMINAL history; rebuildRegistryFromDisk() recovers anything the old cap dropped.
 */
final class AtlasTaskRegistryCapRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function writeTask(string $id, string $status): void
    {
        Storage::disk('local')->put(
            AgentControlPlaneTaskPacketQueueRepository::STORAGE_PREFIX."/task_{$id}.json",
            (string) json_encode([
                'task_packet_id' => $id,
                'status' => $status,
                'task_packet_hash' => 'h_'.$id,
                'priority' => 5,
                'tags' => [],
                'task_packet' => ['task_packet_id' => $id],
            ]),
        );
    }

    public function test_reindex_recovers_all_claimable_even_far_above_the_cap(): void
    {
        // 230 claimable + 40 completed = 270 task files on disk, but the registry starts empty (the lost state).
        for ($i = 0; $i < 230; $i++) {
            $this->writeTask('clm-'.$i, 'claimable');
        }
        for ($i = 0; $i < 40; $i++) {
            $this->writeTask('done-'.$i, 'completed_dry_run');
        }
        $repo = new AgentControlPlaneTaskPacketQueueRepository;

        // Before the rebuild every task is invisible (no registry entries).
        $this->assertSame(0, count($repo->list(['status' => 'claimable'])));

        $result = $repo->rebuildRegistryFromDisk();
        $this->assertSame('ok', $result['status']);

        // ALL 230 claimable are visible again — never evicted, even though total (270) is well over the 200 cap.
        $this->assertSame(230, count($repo->list(['status' => 'claimable'])), 'claimable work is never dropped by the cap');
    }

    public function test_cap_evicts_only_terminal_history_never_live_work(): void
    {
        // 150 claimable + 100 completed = 250 (> 200 cap). Live must survive whole; terminal capped to fit.
        for ($i = 0; $i < 150; $i++) {
            $this->writeTask('clm-'.$i, 'claimable');
        }
        for ($i = 0; $i < 100; $i++) {
            $this->writeTask('done-'.$i, 'completed_dry_run');
        }
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $repo->rebuildRegistryFromDisk();

        $this->assertSame(150, count($repo->list(['status' => 'claimable'])), 'all live work kept');
        // cap=200, live=150 ⇒ room for 50 terminal entries; the older 50 completed are evicted (harmless history).
        $this->assertSame(50, count($repo->list(['status' => 'completed_dry_run'])), 'only terminal history is capped');
    }

    public function test_reindex_is_idempotent(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->writeTask('t-'.$i, 'claimable');
        }
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $first = $repo->rebuildRegistryFromDisk();
        $second = $repo->rebuildRegistryFromDisk();

        $this->assertSame(10, $first['entries_after']);
        $this->assertSame(10, $second['entries_after'], 'rebuilding twice yields the same index');
        $this->assertSame(0, $second['recovered'], 'a second rebuild recovers nothing new');
    }
}
