<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AtlasForge;

use App\Services\Ai\AtlasForge\ForgeSymbolCollisionClassifierService;
use PHPUnit\Framework\TestCase;

final class ForgeSymbolCollisionClassifierServiceTest extends TestCase
{
    private ForgeSymbolCollisionClassifierService $service;

    protected function setUp(): void
    {
        $this->service = new ForgeSymbolCollisionClassifierService();
    }

    public function test_exact_symbol_collision_includes_only_sharing_agent_plus_self(): void
    {
        $result = $this->service->classify(
            ['App\\A::run'],
            [
                'agent2' => ['App\\A::run'],
                'agent3' => ['App\\Z::x'],
            ],
        );

        $this->assertSame('atlas.collision.report.v1', $result['schema_version']);
        $this->assertCount(1, $result['collisions']);

        $collision = $result['collisions'][0];
        $this->assertSame('symbol', $collision['kind']);
        $this->assertSame('App\\A::run', $collision['ref']);
        $this->assertSame('exact', $collision['match']);
        $this->assertSame(['agent2', 'self'], $collision['agents']);

        $this->assertFalse($result['auto_resolvable']);
        $this->assertTrue($result['blocking']);
    }

    public function test_class_versus_its_own_member_is_namespace_prefix(): void
    {
        $result = $this->service->classify(
            ['App\\Svc\\Parser'],
            ['a' => ['App\\Svc\\Parser::parse']],
        );

        $this->assertCount(1, $result['collisions']);

        $collision = $result['collisions'][0];
        $this->assertSame('symbol', $collision['kind']);
        $this->assertSame('namespace_prefix', $collision['match']);
        $this->assertSame('App\\Svc\\Parser', $collision['ref']);
        $this->assertSame(['a', 'self'], $collision['agents']);

        $this->assertFalse($result['auto_resolvable']);
        $this->assertTrue($result['blocking']);
    }

    public function test_sibling_fqn_is_not_a_prefix_collision(): void
    {
        $result = $this->service->classify(
            ['App\\A'],
            ['a' => ['App\\Ab']],
        );

        $this->assertSame([], $result['collisions']);
        $this->assertTrue($result['auto_resolvable']);
        $this->assertFalse($result['blocking']);
    }

    public function test_empty_inputs_are_auto_resolvable_and_non_blocking(): void
    {
        $result = $this->service->classify([], []);

        $this->assertTrue($result['auto_resolvable']);
        $this->assertFalse($result['blocking']);
        $this->assertSame([], $result['collisions']);
        $this->assertSame('atlas.collision.report.v1', $result['schema_version']);
    }

    public function test_two_agents_sharing_one_symbol_collapse_to_single_sorted_entry(): void
    {
        $result = $this->service->classify(
            ['App\\X::m'],
            [
                'a2' => ['App\\X::m'],
                'a1' => ['App\\X::m'],
            ],
        );

        $this->assertCount(1, $result['collisions']);

        $collision = $result['collisions'][0];
        $this->assertSame('App\\X::m', $collision['ref']);
        $this->assertSame('exact', $collision['match']);
        $this->assertSame(['a1', 'a2', 'self'], $collision['agents']);

        $this->assertFalse($result['auto_resolvable']);
        $this->assertTrue($result['blocking']);
    }

    public function test_collisions_are_sorted_by_ref_then_agents_and_deterministic(): void
    {
        $branchSymbols = ['App\\Beta::run', 'App\\Alpha::run'];
        $otherBranches = [
            'zeta' => ['App\\Beta::run'],
            'mu' => ['App\\Alpha::run'],
        ];

        $first = $this->service->classify($branchSymbols, $otherBranches);
        $second = $this->service->classify($branchSymbols, $otherBranches);

        $this->assertSame($first, $second);

        $refs = array_map(static fn (array $row): string => $row['ref'], $first['collisions']);
        $this->assertSame(['App\\Alpha::run', 'App\\Beta::run'], $refs);

        $this->assertSame(['mu', 'self'], $first['collisions'][0]['agents']);
        $this->assertSame(['self', 'zeta'], $first['collisions'][1]['agents']);
    }
}
