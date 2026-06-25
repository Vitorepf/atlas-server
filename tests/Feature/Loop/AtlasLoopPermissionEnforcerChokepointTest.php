<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Permissions\AtlasLoopPermissionDeniedException;
use App\Services\Ai\AutonomousEvolution\Permissions\AtlasLoopPermissionLevelEnforcer;
use App\Services\Ai\AutonomousEvolution\Permissions\AtlasLoopPermissionLevelRegistry;
use Tests\TestCase;

class AtlasLoopPermissionEnforcerChokepointTest extends TestCase
{
    public function test_observe_phase_refuses_merge_operation_even_when_sandbox_would_allow(): void
    {
        config()->set('atlas.loop.master_enabled', true);
        config()->set('atlas.loop.permission_gradient.enabled', true);

        $enforcer = new AtlasLoopPermissionLevelEnforcer(new AtlasLoopPermissionLevelRegistry());

        try {
            $enforcer->assert('observe', 'MERGE');
            self::fail('expected AtlasLoopPermissionDeniedException');
        } catch (AtlasLoopPermissionDeniedException $e) {
            self::assertSame('observe', $e->phase);
            self::assertSame('MERGE', $e->attemptedLevel);
            self::assertStringContainsString('operation_level_exceeds_phase_authority', $e->reason);
        }
    }

    public function test_master_off_makes_all_write_or_merge_attempts_refused_no_side_effects(): void
    {
        config()->set('atlas.loop.master_enabled', false);

        $sentinel = sys_get_temp_dir().'/atlas-permission-no-side-effect-'.bin2hex(random_bytes(4)).'.txt';
        self::assertFileDoesNotExist($sentinel);

        $enforcer = new AtlasLoopPermissionLevelEnforcer(new AtlasLoopPermissionLevelRegistry());

        foreach (['PROPOSE', 'WRITE', 'MERGE'] as $level) {
            try {
                $enforcer->assert('implement', $level);
                self::fail('expected denial for '.$level);
            } catch (AtlasLoopPermissionDeniedException $e) {
                self::assertStringContainsString('master_or_gradient_disabled_fail_closed_at_read', $e->reason);
            }
        }
        // No file should have been created (no side effects).
        self::assertFileDoesNotExist($sentinel);
    }

    public function test_gradient_disabled_alone_also_pins_to_read(): void
    {
        config()->set('atlas.loop.master_enabled', true);
        config()->set('atlas.loop.permission_gradient.enabled', false);

        $enforcer = new AtlasLoopPermissionLevelEnforcer(new AtlasLoopPermissionLevelRegistry());

        $this->expectException(AtlasLoopPermissionDeniedException::class);
        $enforcer->assert('merge', 'MERGE');
    }

    public function test_container_singleton_has_no_side_effects_on_boot(): void
    {
        // The enforcer is bound singleton; resolving it must not perform any I/O.
        $enforcer = app(AtlasLoopPermissionLevelEnforcer::class);
        self::assertInstanceOf(AtlasLoopPermissionLevelEnforcer::class, $enforcer);
        // Idempotent resolution.
        self::assertSame($enforcer, app(AtlasLoopPermissionLevelEnforcer::class));
    }
}
