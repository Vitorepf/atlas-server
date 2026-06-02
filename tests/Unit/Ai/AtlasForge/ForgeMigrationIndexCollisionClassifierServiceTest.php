<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AtlasForge;

use App\Services\Ai\AtlasForge\ForgeMigrationIndexCollisionClassifierService;
use PHPUnit\Framework\TestCase;

final class ForgeMigrationIndexCollisionClassifierServiceTest extends TestCase
{
    private ForgeMigrationIndexCollisionClassifierService $service;

    protected function setUp(): void
    {
        $this->service = new ForgeMigrationIndexCollisionClassifierService();
    }

    public function test_single_shared_index_reports_one_blocking_collision(): void
    {
        $result = $this->service->classify([5], ['a' => [5], 'b' => [7]]);

        $this->assertSame('atlas.collision.report.v1', $result['schema_version']);
        $this->assertCount(1, $result['collisions']);

        $collision = $result['collisions'][0];
        $this->assertSame('migration', $collision['kind']);
        $this->assertSame(5, $collision['index']);
        $this->assertSame('5', $collision['ref']);
        $this->assertSame(['a', 'self'], $collision['agents']);

        $this->assertFalse($result['auto_resolvable']);
        $this->assertTrue($result['blocking']);
    }

    public function test_any_single_collision_forces_auto_resolvable_false(): void
    {
        foreach ([[2], [10], [3, 8], [42]] as $candidate) {
            $shared = $candidate[0];
            $result = $this->service->classify($candidate, ['x' => [$shared]]);

            $this->assertNotSame([], $result['collisions']);
            $this->assertFalse(
                $result['auto_resolvable'],
                'auto_resolvable must never be true when collisions are non-empty',
            );
            $this->assertTrue($result['blocking']);
        }
    }

    public function test_collisions_sorted_by_index_with_deduped_numeric_agents(): void
    {
        $result = $this->service->classify([9, 3], ['a' => [3], 'b' => [9, 3]]);

        $this->assertCount(2, $result['collisions']);

        $first = $result['collisions'][0];
        $this->assertSame(3, $first['index']);
        $this->assertSame('3', $first['ref']);
        $this->assertSame(['a', 'b', 'self'], $first['agents']);

        $second = $result['collisions'][1];
        $this->assertSame(9, $second['index']);
        $this->assertSame('9', $second['ref']);
        $this->assertSame(['b', 'self'], $second['agents']);

        $this->assertFalse($result['auto_resolvable']);
        $this->assertTrue($result['blocking']);
    }

    public function test_disjoint_indexes_yield_no_collision_and_auto_resolvable(): void
    {
        $result = $this->service->classify([1, 2], ['a' => [3, 4]]);

        $this->assertSame([], $result['collisions']);
        $this->assertTrue($result['auto_resolvable']);
        $this->assertFalse($result['blocking']);
    }

    public function test_empty_candidate_against_other_indexes_is_clean(): void
    {
        $result = $this->service->classify([], ['a' => [5]]);

        $this->assertSame([], $result['collisions']);
        $this->assertTrue($result['auto_resolvable']);
        $this->assertFalse($result['blocking']);
    }

    public function test_fully_empty_inputs_are_clean(): void
    {
        $result = $this->service->classify([], []);

        $this->assertSame('atlas.collision.report.v1', $result['schema_version']);
        $this->assertSame([], $result['collisions']);
        $this->assertTrue($result['auto_resolvable']);
        $this->assertFalse($result['blocking']);
    }

    public function test_generalises_to_unseen_inputs(): void
    {
        // Candidate adds 11, 4, 7; only 4 and 7 are shared elsewhere, by different agents.
        $result = $this->service->classify(
            [11, 4, 7],
            ['zeta' => [7, 99], 'alpha' => [4], 'mid' => [4, 7]],
        );

        $this->assertCount(2, $result['collisions']);

        $this->assertSame(4, $result['collisions'][0]['index']);
        $this->assertSame('4', $result['collisions'][0]['ref']);
        $this->assertSame(['alpha', 'mid', 'self'], $result['collisions'][0]['agents']);

        $this->assertSame(7, $result['collisions'][1]['index']);
        $this->assertSame('7', $result['collisions'][1]['ref']);
        $this->assertSame(['mid', 'self', 'zeta'], $result['collisions'][1]['agents']);

        $this->assertFalse($result['auto_resolvable']);
        $this->assertTrue($result['blocking']);
    }

    public function test_identical_inputs_are_deterministic(): void
    {
        $candidate = [9, 3, 9];
        $others = ['a' => [3], 'b' => [9, 3]];

        $first = $this->service->classify($candidate, $others);
        $second = $this->service->classify($candidate, $others);

        $this->assertSame($first, $second);
    }

    public function test_numeric_string_agent_ids_stay_strings_and_sort_lexically(): void
    {
        // Agent ids are opaque strings per the spec (otherAgentId(string)=>list<int>),
        // even when they look numeric. PHP array keys silently coerce '10'/'2' to ints,
        // so this guards the list<string> agents contract and the string ordering.
        $result = $this->service->classify([5], ['10' => [5], '2' => [5], 'a' => [5]]);

        $this->assertCount(1, $result['collisions']);
        $agents = $result['collisions'][0]['agents'];

        foreach ($agents as $agent) {
            $this->assertIsString($agent, 'every agent id must be a string, including self');
        }

        // Lexical (SORT_STRING) order: '10' < '2' < 'a' < 'self'.
        $this->assertSame(['10', '2', 'a', 'self'], $agents);
    }
}
