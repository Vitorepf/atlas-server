<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AtlasMaestroGiveBackPatternMinerHardeningTest extends TestCase
{
    /**
     * Verify the source has a collisionSafeImplode method with length-prefix.
     */
    public function test_source_has_collision_safe_implode(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/Maestro/Adaptive/AtlasMaestroGiveBackPatternMiner.php');

        $this->assertStringContainsString('collisionSafeImplode', $source, 'must have collisionSafeImplode');
        $this->assertStringContainsString("strlen(\$p).':'.\$p", $source, 'must length-prefix parts');
    }

    /**
     * Verify the old bare implode('|', [...]) in shapeKey is gone.
     */
    public function test_source_no_bare_implode_in_shape_key(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/Maestro/Adaptive/AtlasMaestroGiveBackPatternMiner.php');

        // The old pattern was return implode('|', [
        $this->assertStringNotContainsString(
            "return implode('|', [",
            $source,
            'old bare implode in shapeKey must be replaced'
        );
    }

    /**
     * Verify shapeKey uses collisionSafeImplode.
     */
    public function test_source_shape_key_uses_safe_implode(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/Maestro/Adaptive/AtlasMaestroGiveBackPatternMiner.php');

        $this->assertStringContainsString('$this->collisionSafeImplode($parts)', $source, 'shapeKey must use collisionSafeImplode');
    }
}
