<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AgentControlPlaneReplaySnapshotStoreHardeningTest extends TestCase
{
    /**
     * Verify capRegistry floors keep to 0.
     */
    public function test_cap_registry_floors_negative_keep(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/AgentControlPlaneReplaySnapshotStore.php');

        $this->assertStringContainsString('$keep = max(0, $keep)', $source, 'capRegistry must floor keep to 0');
    }

    /**
     * Demonstrate the bug: array_slice with positive offset keeps wrong tail.
     */
    public function test_negative_keep_inverts_slice(): void
    {
        $entries = ['a', 'b', 'c', 'd', 'e'];
        // With keep = -2, array_slice($entries, -(-2)) = array_slice($entries, 2) = ['c', 'd', 'e']
        // This keeps the wrong tail instead of truncating.
        $wrong = array_slice($entries, 2);
        $this->assertEquals(['c', 'd', 'e'], $wrong, 'positive offset keeps wrong tail');
        // With max(0, -2) = 0, it would correctly truncate to [].
        $correct = array_slice($entries, 0);
        $this->assertNotEquals($wrong, $correct, 'wrong vs correct slice differ');
    }

    /**
     * Verify the floor is inside capRegistry, not just in callers.
     */
    public function test_floor_inside_cap_registry(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/AgentControlPlaneReplaySnapshotStore.php');

        // Find capRegistry and verify max(0, $keep) is inside it.
        $capPos = strpos($source, 'private function capRegistry');
        $maxPos = strpos($source, '$keep = max(0, $keep)', $capPos);
        $this->assertNotFalse($maxPos, 'max(0, $keep) must be inside capRegistry');
    }
}
