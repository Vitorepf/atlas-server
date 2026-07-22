<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\AutonomousEvolution\AtlasLoopProjectionOutcomeLedger;
use App\Services\Ai\AutonomousEvolution\TaskClassDiscovery\AtlasLoopTaskClassMiner;
use Tests\TestCase;

/**
 * Proves AtlasLoopTaskClassMiner::mine does not divide by zero when a cluster
 * has zero members (the < 3 guard already prevents this, but the guard is
 * verified here).
 */
final class AtlasLoopTaskClassMinerHardeningTest extends TestCase
{
    public function test_mine_skips_clusters_with_fewer_than_three_members(): void
    {
        $ledger = new AtlasLoopProjectionOutcomeLedger;
        $ledger->record('camp-1', 'app/Alpha/One.php', AtlasLoopProjectionOutcomeLedger::STATUS_CONVERGED);
        $ledger->record('camp-1', 'app/Alpha/Two.php', AtlasLoopProjectionOutcomeLedger::STATUS_CONVERGED);

        $miner = new AtlasLoopTaskClassMiner($ledger);

        // Only 2 records in the same cluster — below the minimum of 3
        $result = $miner->mine('camp-1', [
            $this->record('alpha-1', 'alpha', 'app/Alpha/One.php', ['projection']),
            $this->record('alpha-2', 'alpha', 'app/Alpha/Two.php', ['projection']),
        ]);

        // Should not throw and should return empty (cluster skipped)
        $this->assertSame([], $result);
    }

    public function test_mine_with_three_members_does_not_divide_by_zero(): void
    {
        $ledger = new AtlasLoopProjectionOutcomeLedger;
        $ledger->record('camp-1', 'app/Alpha/One.php', AtlasLoopProjectionOutcomeLedger::STATUS_CONVERGED);
        $ledger->record('camp-1', 'app/Alpha/Two.php', AtlasLoopProjectionOutcomeLedger::STATUS_CONVERGED);
        $ledger->record('camp-1', 'app/Alpha/Three.php', AtlasLoopProjectionOutcomeLedger::STATUS_CONVERGED);

        $miner = new AtlasLoopTaskClassMiner($ledger);

        $result = $miner->mine('camp-1', [
            $this->record('alpha-1', 'alpha', 'app/Alpha/One.php', ['projection']),
            $this->record('alpha-2', 'alpha', 'app/Alpha/Two.php', ['projection']),
            $this->record('alpha-3', 'alpha', 'app/Alpha/Three.php', ['projection']),
        ]);

        // Should succeed with one cluster
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('success_rate', $result[0]);
    }

    /**
     * @param  list<string>  $evidenceKinds
     * @return array<string, mixed>
     */
    private function record(string $packetId, string $taskClassKey, string $targetPath, array $evidenceKinds): array
    {
        return [
            'task_packet_id' => $packetId,
            'task_class_key' => $taskClassKey,
            'target_path' => $targetPath,
            'evidence_kinds' => $evidenceKinds,
        ];
    }
}
