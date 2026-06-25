<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\LiveCycle\Nesting\AtlasLoopSubCycleSpawner;
use App\Services\Ai\AutonomousEvolution\LiveCycle\Nesting\SubCycleSpawnRecord;
use Tests\TestCase;

// SubCycleSpawnRecord is declared in the spawner file (PSR-4 one-class-per-file).
\class_exists(AtlasLoopSubCycleSpawner::class);

class AtlasLoopSubCycleSpawnerTest extends TestCase
{
    public function test_happy_path_returns_granted_record_with_deterministic_child_id(): void
    {
        $spawner = new AtlasLoopSubCycleSpawner(
            maxDepthResolver: fn (): int => 2,
            clock: fn (): string => '2026-06-25T00:00:00Z',
        );

        $record = $spawner->spawn(
            parent: ['cycle_id' => 'cyc-A', 'phase' => 'ARCHITECT', 'depth' => 0],
            childScope: ['focus' => 'research-alternatives'],
            childIndex: 0,
        );

        self::assertInstanceOf(SubCycleSpawnRecord::class, $record);
        self::assertTrue($record->granted);
        self::assertSame('cyc-A|ARCHITECT|0', $record->childCycleId);
        self::assertSame(1, $record->depth);
        self::assertNull($record->refusalReason);
    }

    public function test_depth_cap_refuses_fail_closed_without_throwing(): void
    {
        $spawner = new AtlasLoopSubCycleSpawner(
            maxDepthResolver: fn (): int => 2,
            clock: fn (): string => '2026-06-25T00:00:00Z',
        );

        $record = $spawner->spawn(
            parent: ['cycle_id' => 'cyc-A', 'phase' => 'ARCHITECT', 'depth' => 2],
            childScope: [],
            childIndex: 0,
        );

        self::assertFalse($record->granted);
        self::assertStringContainsString('depth_cap_exceeded', (string) $record->refusalReason);
        self::assertSame(3, $record->depth);
    }

    public function test_missing_parent_identity_fails_closed(): void
    {
        $spawner = new AtlasLoopSubCycleSpawner(
            maxDepthResolver: fn (): int => 2,
            clock: fn (): string => '2026-06-25T00:00:00Z',
        );

        $record = $spawner->spawn(
            parent: ['cycle_id' => '', 'phase' => 'ARCHITECT', 'depth' => 0],
            childScope: [],
            childIndex: 0,
        );

        self::assertFalse($record->granted);
        self::assertSame('missing_parent_identity', $record->refusalReason);
    }

    public function test_scope_digest_is_deterministic_across_key_order(): void
    {
        $spawner = new AtlasLoopSubCycleSpawner(
            maxDepthResolver: fn (): int => 2,
            clock: fn (): string => '2026-06-25T00:00:00Z',
        );
        $a = $spawner->spawn(['cycle_id' => 'P', 'phase' => 'A', 'depth' => 0], ['a' => 1, 'b' => 2]);
        $b = $spawner->spawn(['cycle_id' => 'P', 'phase' => 'A', 'depth' => 0], ['b' => 2, 'a' => 1]);
        self::assertSame($a->scopeDigest, $b->scopeDigest);
    }

    public function test_falls_back_to_config_default_when_no_resolver_given(): void
    {
        // The Laravel test bootstrap loads config; the default for missing key is 2.
        $spawner = new AtlasLoopSubCycleSpawner();
        $record = $spawner->spawn(
            parent: ['cycle_id' => 'cyc-X', 'phase' => 'P', 'depth' => 0],
            childScope: [],
            childIndex: 0,
        );
        self::assertTrue($record->granted);
    }

    public function test_singleton_binding_resolves_through_container(): void
    {
        $a = app(AtlasLoopSubCycleSpawner::class);
        $b = app(AtlasLoopSubCycleSpawner::class);
        self::assertSame($a, $b);
    }

    public function test_construct_has_no_io_side_effects(): void
    {
        // No exceptions, no methods called: pure construct.
        $spawner = new AtlasLoopSubCycleSpawner();
        self::assertInstanceOf(AtlasLoopSubCycleSpawner::class, $spawner);
    }
}
