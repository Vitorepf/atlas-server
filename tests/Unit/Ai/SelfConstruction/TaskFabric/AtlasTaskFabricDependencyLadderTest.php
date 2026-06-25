<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricDependencyLadder;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasTaskFabricDependencyLadder: independent tasks share a single wave; a linear chain produces
 * one packet per wave with depends_on edges; a fan-in puts consumers in a later wave than each producer;
 * a cycle produces cycle_detected:<id> blockers; two packets in the same wave writing the same
 * allowed_files yields allowed_files_conflict:<path>.
 */
final class AtlasTaskFabricDependencyLadderTest extends TestCase
{
    public function test_independent_packets_collapse_into_one_wave(): void
    {
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'A', 'produces' => [], 'consumes' => [], 'allowed_files' => ['app/A.php']],
            ['id' => 'B', 'produces' => [], 'consumes' => [], 'allowed_files' => ['app/B.php']],
        ]);
        $this->assertSame([['A', 'B']], $r['waves']);
        $this->assertSame([], $r['blockers']);
    }

    public function test_linear_chain_yields_one_packet_per_wave_with_depends_on_edges(): void
    {
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'svc', 'produces' => ['Service'], 'consumes' => [], 'allowed_files' => ['app/S.php']],
            ['id' => 'cli', 'produces' => ['Cli'], 'consumes' => ['Service'], 'allowed_files' => ['app/Cli.php']],
            ['id' => 'integ', 'produces' => [], 'consumes' => ['Cli'], 'allowed_files' => ['tests/Integ.php']],
        ]);
        $this->assertSame([['svc'], ['cli'], ['integ']], $r['waves']);
        $this->assertSame(['svc'], $r['depends_on']['cli']);
        $this->assertSame(['cli'], $r['depends_on']['integ']);
        $this->assertSame([], $r['blockers']);
    }

    public function test_fan_in_places_consumer_after_every_producer(): void
    {
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'p1', 'produces' => ['A'], 'allowed_files' => ['app/A.php']],
            ['id' => 'p2', 'produces' => ['B'], 'allowed_files' => ['app/B.php']],
            ['id' => 'c1', 'consumes' => ['A', 'B'], 'allowed_files' => ['app/Joined.php']],
        ]);
        $this->assertSame([['p1', 'p2'], ['c1']], $r['waves']);
        $this->assertSame(['p1', 'p2'], $r['depends_on']['c1']);
    }

    public function test_cycle_yields_cycle_detected_blockers(): void
    {
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'X', 'produces' => ['x_sym'], 'consumes' => ['y_sym']],
            ['id' => 'Y', 'produces' => ['y_sym'], 'consumes' => ['x_sym']],
        ]);
        $this->assertContains('cycle_detected:X', $r['blockers']);
        $this->assertContains('cycle_detected:Y', $r['blockers']);
    }

    public function test_missing_producer_yields_missing_producer_blocker(): void
    {
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'consumer', 'consumes' => ['MissingThing']],
        ]);
        $this->assertContains('missing_producer_for:MissingThing', $r['blockers']);
    }

    public function test_same_file_conflict_within_a_wave_is_blocker(): void
    {
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'a', 'allowed_files' => ['app/Shared.php']],
            ['id' => 'b', 'allowed_files' => ['app/Shared.php']],
        ]);
        $this->assertContains('allowed_files_conflict:app/Shared.php', $r['blockers']);
    }

    public function test_determinism_two_calls_same_input_identical_output(): void
    {
        $input = [
            ['id' => 'svc', 'produces' => ['S']],
            ['id' => 'cli', 'consumes' => ['S']],
        ];
        $l = new AtlasTaskFabricDependencyLadder;
        $this->assertSame(json_encode($l->ladder($input)), json_encode($l->ladder($input)));
    }
}
