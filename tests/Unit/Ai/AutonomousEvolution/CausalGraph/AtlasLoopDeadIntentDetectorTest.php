<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\CausalGraph;

use App\Services\Ai\AutonomousEvolution\CausalGraph\AtlasLoopDeadIntentDetector;
use PHPUnit\Framework\TestCase;

final class AtlasLoopDeadIntentDetectorTest extends TestCase
{
    public function test_symbol_with_zero_live_consumers_is_reported_as_dead_intent(): void
    {
        $result = (new AtlasLoopDeadIntentDetector)->detect([
            [
                'fqcn' => 'App\\Legacy\\UnusedPlanner',
                'rel_path' => 'app/Legacy/UnusedPlanner.php',
                'live_consumer_count' => 0,
                'is_forbidden' => false,
            ],
        ]);

        $this->assertSame(AtlasLoopDeadIntentDetector::SCHEMA_VERSION, $result['schema']);
        $this->assertSame([
            [
                'fqcn' => 'App\\Legacy\\UnusedPlanner',
                'reason' => 'zero_live_consumers',
                'live_consumer_count' => 0,
            ],
        ], $result['dead_intent']);
    }

    public function test_symbol_with_live_consumers_or_forbidden_target_is_never_reported(): void
    {
        $result = (new AtlasLoopDeadIntentDetector)->detect([
            [
                'fqcn' => 'App\\Live\\StillNeeded',
                'rel_path' => 'app/Live/StillNeeded.php',
                'live_consumers' => ['App\\Consumer'],
            ],
            [
                'fqcn' => 'App\\Kernel\\PetreoTarget',
                'rel_path' => 'app/Kernel/PetreoTarget.php',
                'live_consumer_count' => 0,
                'is_petreo' => true,
            ],
        ]);

        $this->assertSame([], $result['dead_intent']);
    }

    public function test_unknown_ungroundable_symbol_is_never_reported_fail_closed(): void
    {
        $result = (new AtlasLoopDeadIntentDetector)->detect([
            [
                'fqcn' => 'App\\Unknown\\MaybeDead',
                'live_consumer_count' => 0,
            ],
            'App\\Unknown\\BareString',
        ]);

        $this->assertSame([], $result['dead_intent']);
    }

    public function test_output_is_deterministic_and_fact_only(): void
    {
        $symbols = [
            ['fqcn' => 'App\\Zed', 'rel_path' => 'app/Zed.php', 'live_consumer_count' => 0],
            ['fqcn' => 'App\\Alpha', 'rel_path' => 'app/Alpha.php', 'live_consumer_count' => 0],
        ];

        $first = (new AtlasLoopDeadIntentDetector)->detect($symbols);
        $second = (new AtlasLoopDeadIntentDetector)->detect(array_reverse($symbols));

        $this->assertSame($first, $second);
        $this->assertSame('App\\Alpha', $first['dead_intent'][0]['fqcn']);
        foreach ($first['dead_intent'] as $row) {
            $this->assertSame(['fqcn', 'reason', 'live_consumer_count'], array_keys($row));
            $this->assertArrayNotHasKey('score', $row);
        }
    }
}
