<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\PatternEmergence\AtlasLoopCrossCyclePatternMiner;
use App\Services\Ai\AutonomousEvolution\PatternEmergence\AtlasLoopCrossCyclePatternMinerInsufficientEpisodesException;
use PHPUnit\Framework\TestCase;

final class AtlasLoopCrossCyclePatternMinerTest extends TestCase
{
    public function test_mine_is_byte_identical_for_fixed_episode_fixtures(): void
    {
        $miner = new AtlasLoopCrossCyclePatternMiner($this->episodes(), 3);

        $first = json_encode($miner->mine(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $second = json_encode($miner->mine(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertSame($first, $second);
    }

    public function test_mine_throws_when_fewer_than_n_episodes_exist(): void
    {
        $this->expectException(AtlasLoopCrossCyclePatternMinerInsufficientEpisodesException::class);

        (new AtlasLoopCrossCyclePatternMiner(array_slice($this->episodes(), 0, 2), 3))->mine();
    }

    public function test_each_pattern_entry_contains_verbatim_token_count_and_explicit_source_episode_ids(): void
    {
        $result = (new AtlasLoopCrossCyclePatternMiner($this->episodes(), 3))->mine();

        $this->assertSame(
            [
                ['token' => 'App/Services/Ai/AutonomousEvolution/Foo.php', 'count' => 2, 'source_episode_ids' => ['ep-1', 'ep-2']],
                ['token' => 'orphan-wire', 'count' => 3, 'source_episode_ids' => ['ep-1', 'ep-2', 'ep-3']],
            ],
            $result['patterns'],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function episodes(): array
    {
        return [
            [
                'episode_id' => 'ep-1',
                'inventory_snapshot' => ['App/Services/Ai/AutonomousEvolution/Foo.php', 'App/Services/Ai/AutonomousEvolution/OnlyEp1.php'],
                'intent_records' => ['orphan-wire'],
                'outcome_ledger' => ['merged'],
            ],
            [
                'episode_id' => 'ep-2',
                'inventory_snapshot' => ['App/Services/Ai/AutonomousEvolution/Foo.php'],
                'orphan_registry' => ['orphan-wire'],
                'outcome_ledger' => ['parked'],
            ],
            [
                'episode_id' => 'ep-3',
                'inventory_snapshot' => ['App/Services/Ai/AutonomousEvolution/OnlyEp3.php'],
                'intent_records' => ['orphan-wire'],
                'outcome_ledger' => ['converged'],
            ],
        ];
    }
}
