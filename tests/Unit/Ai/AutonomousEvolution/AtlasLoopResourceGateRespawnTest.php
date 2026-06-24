<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopResourceGate;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasLoopResourceGate::admitRespawn — the SUPERVISOR-level disk floor that stops the keepalive from
 * resurrecting a campaign onto a near-full disk (which would busy-fail admitting zero scenarios). Pure: same
 * @disk_free_space probe as admitScenario, no live-workspace count, no DB, no filesystem mutation.
 */
final class AtlasLoopResourceGateRespawnTest extends TestCase
{
    private function gate(): AtlasLoopResourceGate
    {
        return new AtlasLoopResourceGate;
    }

    // (a) plenty of free space ⇒ admit.
    public function test_admits_when_free_space_above_floor(): void
    {
        $result = $this->gate()->admitRespawn(sys_get_temp_dir(), 1);

        $this->assertTrue($result['admit']);
        $this->assertSame('ok', $result['reason']);
        $this->assertGreaterThan(0, $result['free_mb']);
        $this->assertArrayNotHasKey('live', $result, 'respawn gate has NO live-workspace count (supervisor-level)');
    }

    // (b) an absurd floor on a real tmp dir ⇒ refuse with reason disk_floor.
    public function test_refuses_when_floor_is_above_available(): void
    {
        $result = $this->gate()->admitRespawn(sys_get_temp_dir(), PHP_INT_MAX);

        $this->assertFalse($result['admit']);
        $this->assertSame('disk_floor', $result['reason']);
        $this->assertIsInt($result['free_mb']);
    }

    // (c) the method is pure / side-effect-free — nothing is written under tmpRoot.
    public function test_is_side_effect_free(): void
    {
        $dir = sys_get_temp_dir().'/atlas-respawn-gate-'.bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);
        try {
            $before = scandir($dir);

            $this->gate()->admitRespawn($dir, 1);
            $this->gate()->admitRespawn($dir, PHP_INT_MAX);

            $after = scandir($dir);
            $this->assertSame($before, $after, 'admitRespawn must not create or remove anything under tmpRoot');
            $this->assertSame(['.', '..'], array_values((array) $after), 'tmpRoot stays empty');
        } finally {
            @rmdir($dir);
        }
    }
}
