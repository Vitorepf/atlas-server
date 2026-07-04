<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainBacklogAgingValueMonitorHardeningTest extends TestCase
{
    /**
     * Verify the source uses json_encode for group key instead of '::' delimiter.
     */
    public function test_source_uses_json_encode_for_group_key(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainBacklogAgingValueMonitor.php');

        $this->assertStringContainsString('json_encode', $source, 'must use json_encode for group key');
        $this->assertStringContainsString('JSON_THROW_ON_ERROR', $source, 'must throw on encode failure');
    }

    /**
     * Verify the old '::' delimiter concatenation is gone.
     */
    public function test_old_delimiter_concatenation_removed(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainBacklogAgingValueMonitor.php');

        $this->assertStringNotContainsString("'::'.", $source, 'must not use :: delimiter concatenation');
    }

    /**
     * Demonstrate the collision: two distinct (theme, target) pairs collide with '::' delimiter.
     */
    public function test_delimiter_collision_exists(): void
    {
        $a = 'a::b' . '::' . 'c';
        $b = 'a' . '::' . 'b::c';
        $this->assertSame($a, $b, 'delimiter collision: a::b::c == a::b::c');
        // json_encode avoids this
        $ja = json_encode(['a::b', 'c']);
        $jb = json_encode(['a', 'b::c']);
        $this->assertNotSame($ja, $jb, 'json_encode avoids collision');
    }
}
