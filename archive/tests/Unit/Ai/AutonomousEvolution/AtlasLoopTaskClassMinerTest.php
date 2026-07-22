<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopProjectionOutcomeLedger;
use App\Services\Ai\AutonomousEvolution\TaskClassDiscovery\AtlasLoopTaskClassMiner;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasLoopTaskClassMinerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_mine_emits_only_supported_fact_clusters_and_is_byte_stable(): void
    {
        $ledger = new AtlasLoopProjectionOutcomeLedger;
        $ledger->record('camp-1', 'app/Alpha/One.php', AtlasLoopProjectionOutcomeLedger::STATUS_CONVERGED);
        $ledger->record('camp-1', 'app/Alpha/Two.php', AtlasLoopProjectionOutcomeLedger::STATUS_CONVERGED);
        $ledger->record('camp-1', 'app/Alpha/Three.php', AtlasLoopProjectionOutcomeLedger::STATUS_CONVERGED);
        $ledger->record('camp-1', 'app/Beta/One.php', AtlasLoopProjectionOutcomeLedger::STATUS_CONVERGED);
        $ledger->record('camp-1', 'app/Beta/Two.php', AtlasLoopProjectionOutcomeLedger::STATUS_PARKED, 'forbidden_target_petreo');
        $ledger->record('camp-1', 'app/Beta/Three.php', AtlasLoopProjectionOutcomeLedger::STATUS_CONVERGED);
        $ledger->record('camp-1', 'app/Gamma/One.php', AtlasLoopProjectionOutcomeLedger::STATUS_CONVERGED);
        $ledger->record('camp-1', 'app/Gamma/Two.php', AtlasLoopProjectionOutcomeLedger::STATUS_CONVERGED);

        $miner = new AtlasLoopTaskClassMiner($ledger);

        $records = [
            $this->record('alpha-1', 'alpha', 'app/Alpha/One.php', ['projection', 'coverage']),
            $this->record('alpha-2', 'alpha', 'app/Alpha/Two.php', ['projection', 'coverage']),
            $this->record('alpha-3', 'alpha', 'app/Alpha/Three.php', ['projection']),
            $this->record('beta-1', 'beta', 'app/Beta/One.php', ['projection']),
            $this->record('beta-2', 'beta', 'app/Beta/Two.php', ['projection']),
            $this->record('beta-3', 'beta', 'app/Beta/Three.php', ['projection']),
            $this->record('gamma-1', 'gamma', 'app/Gamma/One.php', ['coverage']),
            $this->record('gamma-2', 'gamma', 'app/Gamma/Two.php', ['coverage']),
        ];

        $first = $miner->mine('camp-1', $records);
        $second = $miner->mine('camp-1', $records);

        $this->assertCount(1, $first);
        $this->assertSame($first, $second);
        $this->assertSame(
            json_encode($first, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            json_encode($second, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        $cluster = $first[0];
        $this->assertSame(
            ['cluster_id', 'member_packet_ids', 'cohesion_metric', 'support_count', 'success_rate', 'evidence_kind_histogram'],
            array_keys($cluster)
        );
        $this->assertSame(['alpha-1', 'alpha-2', 'alpha-3'], $cluster['member_packet_ids']);
        $this->assertSame(3, $cluster['support_count']);
        $this->assertSame(1.0, $cluster['success_rate']);
        $this->assertSame(['coverage' => 2, 'projection' => 3], $cluster['evidence_kind_histogram']);
        $this->assertSame(1.0, $cluster['cohesion_metric']);

        foreach ($this->collectKeys($cluster) as $key) {
            $this->assertNotContains($key, ['score', 'verdict', 'recommendation']);
        }
    }

    public function test_empty_ledger_returns_an_empty_cluster_list(): void
    {
        $miner = new AtlasLoopTaskClassMiner(new AtlasLoopProjectionOutcomeLedger);

        $this->assertSame([], $miner->mine('camp-empty', [
            $this->record('alpha-1', 'alpha', 'app/Alpha/One.php', ['projection']),
            $this->record('alpha-2', 'alpha', 'app/Alpha/Two.php', ['projection']),
            $this->record('alpha-3', 'alpha', 'app/Alpha/Three.php', ['projection']),
        ]));
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

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     * @return list<string>
     */
    private function collectKeys(array $payload): array
    {
        $keys = [];
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }

            if (is_array($value)) {
                array_push($keys, ...$this->collectKeys($value));
            }
        }

        return $keys;
    }
}
