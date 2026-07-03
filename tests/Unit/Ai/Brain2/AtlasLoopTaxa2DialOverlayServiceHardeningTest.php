<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AtlasLoopTaxa2DialOverlayServiceHardeningTest extends TestCase
{
    /**
     * Verify the source has a presence guard for pending_tasks.
     */
    public function test_source_has_pending_tasks_guard(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/AutonomousEvolution/AtlasLoopTaxa2DialOverlayService.php');

        $this->assertStringContainsString("array_key_exists('pending_tasks', \$m)", $source, 'must guard pending_tasks presence');
    }

    /**
     * Verify the old unguarded cast is gone.
     */
    public function test_old_uncast_pattern_removed(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/AutonomousEvolution/AtlasLoopTaxa2DialOverlayService.php');

        $this->assertStringNotContainsString(
            "'active' => (int) \$m['pending_tasks'] < \$base['queue_low_watermark']",
            $source,
            'unguarded cast must be replaced'
        );
    }

    /**
     * Demonstrate the bug: (int) null is 0, always below watermark.
     */
    public function test_null_cast_is_zero(): void
    {
        $this->assertEquals(0, (int) null, '(int) null yields 0');
        $this->assertTrue(0 < 4, '0 is always below any positive watermark');
    }
}
