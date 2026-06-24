<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\PatternEmergence\AtlasLoopCrossCyclePatternStabilityChecker;
use App\Services\Ai\AutonomousEvolution\PatternEmergence\AtlasLoopCrossCyclePatternStabilityCheckerInsufficientCyclesException;
use PHPUnit\Framework\TestCase;

final class AtlasLoopCrossCyclePatternStabilityCheckerTest extends TestCase
{
    public function test_stability_for_returns_only_patterns_with_longest_run_at_least_k(): void
    {
        $result = (new AtlasLoopCrossCyclePatternStabilityChecker($this->minerPayload()))->stabilityFor(2);

        $this->assertSame(
            [
                [
                    'token' => 'alpha-token',
                    'longest_consecutive_run' => 2,
                    'start_cycle_id' => 'cycle-01',
                    'end_cycle_id' => 'cycle-02',
                ],
                [
                    'token' => 'beta-token',
                    'longest_consecutive_run' => 3,
                    'start_cycle_id' => 'cycle-02',
                    'end_cycle_id' => 'cycle-04',
                ],
                [
                    'token' => 'zeta-token',
                    'longest_consecutive_run' => 2,
                    'start_cycle_id' => 'cycle-04',
                    'end_cycle_id' => 'cycle-05',
                ],
            ],
            $result['stable_patterns'],
        );
    }

    public function test_stability_for_is_byte_identical_for_same_payload(): void
    {
        $checker = new AtlasLoopCrossCyclePatternStabilityChecker($this->minerPayload());

        $first = json_encode($checker->stabilityFor(2), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $second = json_encode($checker->stabilityFor(2), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertSame($first, $second);
    }

    public function test_stability_for_throws_when_payload_spans_fewer_cycles_than_k(): void
    {
        $this->expectException(AtlasLoopCrossCyclePatternStabilityCheckerInsufficientCyclesException::class);

        (new AtlasLoopCrossCyclePatternStabilityChecker([
            'cycle_ids' => ['cycle-01', 'cycle-02'],
            'patterns' => [
                ['token' => 'alpha-token', 'source_episode_ids' => ['cycle-01', 'cycle-02']],
            ],
        ]))->stabilityFor(3);
    }

    /**
     * @return array{
     *     cycle_ids:list<string>,
     *     patterns:list<array{token:string,count:int,source_episode_ids:list<string>}>
     * }
     */
    private function minerPayload(): array
    {
        return [
            'cycle_ids' => ['cycle-01', 'cycle-02', 'cycle-03', 'cycle-04', 'cycle-05'],
            'patterns' => [
                ['token' => 'zeta-token', 'count' => 2, 'source_episode_ids' => ['cycle-04', 'cycle-05']],
                ['token' => 'gamma-token', 'count' => 3, 'source_episode_ids' => ['cycle-01', 'cycle-03', 'cycle-05']],
                ['token' => 'beta-token', 'count' => 3, 'source_episode_ids' => ['cycle-02', 'cycle-03', 'cycle-04']],
                ['token' => 'alpha-token', 'count' => 3, 'source_episode_ids' => ['cycle-01', 'cycle-02', 'cycle-04']],
            ],
        ];
    }
}
