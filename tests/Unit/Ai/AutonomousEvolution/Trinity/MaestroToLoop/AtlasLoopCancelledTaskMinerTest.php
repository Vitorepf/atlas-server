<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Trinity\MaestroToLoop;

use App\Services\Ai\AutonomousEvolution\Trinity\MaestroToLoop\AtlasLoopCancelledTaskMiner;
use Tests\TestCase;

final class AtlasLoopCancelledTaskMinerTest extends TestCase
{
    public function test_mine_returns_one_deterministic_avoid_pattern_for_repeated_doomed_records_with_shared_prefix(): void
    {
        $miner = new AtlasLoopCancelledTaskMiner($this->reader([
            $this->record('pkt-1', 'app/Services/Ai/AutonomousEvolution/AtlasLoopXAlpha.php'),
            $this->record('pkt-2', 'app/Services/Ai/AutonomousEvolution/AtlasLoopXBeta.php'),
            $this->record('pkt-3', 'app/Services/Ai/AutonomousEvolution/AtlasLoopXGamma.php'),
        ]), now: fn () => '2026-06-24T12:00:00+00:00');

        $first = $miner->mine();
        $second = $miner->mine();

        $this->assertCount(1, $first);
        $this->assertSame($first, $second);
        $this->assertSame($first[0]['cluster_id'], $second[0]['cluster_id']);
        $this->assertSame(3, $first[0]['member_count']);
        $this->assertSame('app/Services/Ai/AutonomousEvolution/AtlasLoopX', $first[0]['shared_prefix']);
    }

    public function test_mine_returns_zero_patterns_for_structurally_disjoint_noise(): void
    {
        $miner = new AtlasLoopCancelledTaskMiner($this->reader([
            $this->record('pkt-a', 'app/Services/Ai/AutonomousEvolution/AlphaSlice.php', reason: 'doomed_after_repeated_give_back', gate: 'gate-a'),
            $this->record('pkt-b', 'app/Services/Ai/AutonomousEvolution/BetaSlice.php', reason: 'operator_cancelled', gate: 'gate-b'),
            $this->record('pkt-c', 'app/Services/Ai/AutonomousEvolution/GammaSlice.php', reason: 'packet_not_self_sufficient', gate: 'gate-c'),
        ]), now: fn () => '2026-06-24T12:00:00+00:00');

        $this->assertSame([], $miner->mine());
    }

    public function test_stale_records_are_excluded_from_cluster_membership_using_the_config_window_default(): void
    {
        $miner = new AtlasLoopCancelledTaskMiner($this->reader([
            $this->record('fresh-1', 'app/Services/Ai/AutonomousEvolution/AtlasLoopXAlpha.php', updatedAt: '2026-06-24T00:00:00+00:00'),
            $this->record('fresh-2', 'app/Services/Ai/AutonomousEvolution/AtlasLoopXBeta.php', updatedAt: '2026-06-20T00:00:00+00:00'),
            $this->record('fresh-3', 'app/Services/Ai/AutonomousEvolution/AtlasLoopXGamma.php', updatedAt: '2026-06-18T00:00:00+00:00'),
            $this->record('stale-1', 'app/Services/Ai/AutonomousEvolution/AtlasLoopXDelta.php', updatedAt: '2026-05-01T00:00:00+00:00'),
        ]), now: fn () => '2026-06-24T12:00:00+00:00');

        $patterns = $miner->mine();

        $this->assertCount(1, $patterns);
        $this->assertSame(3, $patterns[0]['member_count']);
        $this->assertSame(['fresh-1', 'fresh-2', 'fresh-3'], $patterns[0]['member_task_ids']);
        $this->assertNotContains('stale-1', $patterns[0]['member_task_ids']);
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function reader(array $records): object
    {
        return new class($records)
        {
            /**
             * @param  list<array<string, mixed>>  $records
             */
            public function __construct(
                private readonly array $records,
            ) {}

            /**
             * @return list<array<string, mixed>>
             */
            public function records(int $limit = 200): array
            {
                return array_slice($this->records, 0, $limit);
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function record(
        string $taskPacketId,
        string $allowedFile,
        string $reason = 'doomed_after_repeated_give_back',
        string $gate = 'gate-loop-freeze',
        string $updatedAt = '2026-06-24T00:00:00+00:00',
    ): array {
        return [
            'task_packet_id' => $taskPacketId,
            'allowed_files' => [$allowedFile],
            'reason' => $reason,
            'gate' => $gate,
            'updated_at' => $updatedAt,
        ];
    }
}
