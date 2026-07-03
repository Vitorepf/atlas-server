<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AtlasMaestroTaskPinningRegistryHardeningTest extends TestCase
{
    /**
     * Verify the source uses json_encode for injective pin hash.
     */
    public function test_source_uses_json_encode_for_pin_hash(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/Maestro/Pinning/AtlasMaestroTaskPinningRegistry.php');

        $this->assertStringContainsString('json_encode', $source, 'must use json_encode for injective hash');
        $this->assertStringContainsString('JSON_THROW_ON_ERROR', $source, 'must throw on encode failure');
    }

    /**
     * Verify the old colon-joined pattern is gone.
     */
    public function test_old_colon_join_removed(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/Maestro/Pinning/AtlasMaestroTaskPinningRegistry.php');

        $this->assertStringNotContainsString(
            "\$taskPacketId.':'.\$workerId",
            $source,
            'old colon-join must be replaced'
        );
    }

    /**
     * Demonstrate the collision: colon delimiter is not injective.
     */
    public function test_colon_is_not_injective(): void
    {
        // Task packet ids contain colons: "brain:opus48b:task"
        $key1 = 'brain:opus48b:task' . ':' . 'worker-1' . ':' . 'reason';
        $key2 = 'brain' . ':' . 'opus48b:task:worker-1' . ':' . 'reason';
        $this->assertEquals($key1, $key2, 'colon delimiter is not injective');

        $json1 = json_encode(['brain:opus48b:task', 'worker-1', 'reason']);
        $json2 = json_encode(['brain', 'opus48b:task:worker-1', 'reason']);
        $this->assertNotEquals($json1, $json2, 'json_encode is injective');
    }
}
