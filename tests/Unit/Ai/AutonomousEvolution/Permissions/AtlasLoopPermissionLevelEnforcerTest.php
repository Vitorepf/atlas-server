<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Permissions;

use App\Services\Ai\AutonomousEvolution\Permissions\AtlasLoopPermissionDeniedException;
use App\Services\Ai\AutonomousEvolution\Permissions\AtlasLoopPermissionLevelEnforcer;
use App\Services\Ai\AutonomousEvolution\Permissions\AtlasLoopPermissionLevelRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AtlasLoopPermissionLevelEnforcerTest extends TestCase
{
    private function enforcer(bool $masterOn = true): AtlasLoopPermissionLevelEnforcer
    {
        return new AtlasLoopPermissionLevelEnforcer(
            new AtlasLoopPermissionLevelRegistry(),
            fn (): bool => $masterOn,
        );
    }

    /**
     * @return iterable<string,array{string,string,bool}>
     */
    public static function levelMatrix(): iterable
    {
        // (phase, operationLevel, expectedAllowed)
        // observe → READ (max READ)
        yield 'observe-READ-ok' => ['observe', 'READ', true];
        yield 'observe-PROPOSE-denied' => ['observe', 'PROPOSE', false];
        yield 'observe-WRITE-denied' => ['observe', 'WRITE', false];
        yield 'observe-MERGE-denied' => ['observe', 'MERGE', false];

        // implement → WRITE (max WRITE)
        yield 'implement-READ-ok' => ['implement', 'READ', true];
        yield 'implement-PROPOSE-ok' => ['implement', 'PROPOSE', true];
        yield 'implement-WRITE-ok' => ['implement', 'WRITE', true];
        yield 'implement-MERGE-denied' => ['implement', 'MERGE', false];

        // merge → MERGE (max MERGE)
        yield 'merge-READ-ok' => ['merge', 'READ', true];
        yield 'merge-PROPOSE-ok' => ['merge', 'PROPOSE', true];
        yield 'merge-WRITE-ok' => ['merge', 'WRITE', true];
        yield 'merge-MERGE-ok' => ['merge', 'MERGE', true];
    }

    #[DataProvider('levelMatrix')]
    public function test_level_matrix_across_three_phases(string $phase, string $operationLevel, bool $expectedAllowed): void
    {
        $enforcer = $this->enforcer();
        if ($expectedAllowed) {
            $enforcer->assert($phase, $operationLevel);
            self::assertTrue(true, 'allowed silently');

            return;
        }
        $this->expectException(AtlasLoopPermissionDeniedException::class);
        $enforcer->assert($phase, $operationLevel);
    }

    public function test_unknown_phase_throws_permission_denied(): void
    {
        $this->expectException(AtlasLoopPermissionDeniedException::class);
        $this->enforcer()->assert('not-a-phase', 'READ');
    }

    public function test_unknown_operation_level_throws_permission_denied(): void
    {
        $this->expectException(AtlasLoopPermissionDeniedException::class);
        $this->enforcer()->assert('observe', 'GODMODE');
    }

    public function test_master_off_pins_allowed_level_to_read(): void
    {
        $off = $this->enforcer(masterOn: false);
        // READ still passes (since READ <= READ).
        $off->assert('merge', 'READ');
        self::assertTrue(true);

        $this->expectException(AtlasLoopPermissionDeniedException::class);
        $off->assert('merge', 'MERGE'); // would normally be allowed; refused under fail-closed
    }

    public function test_container_resolves_enforcer_singleton_without_side_effects(): void
    {
        $a = app(AtlasLoopPermissionLevelEnforcer::class);
        $b = app(AtlasLoopPermissionLevelEnforcer::class);
        self::assertSame($a, $b);
    }
}
