<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AtlasTaskFabricSpecEntropyMonitorHardeningTest extends TestCase
{
    /**
     * Verify the source has a signature() method with length-prefix escaping.
     */
    public function test_source_has_signature_method(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/TaskFabric/AtlasTaskFabricSpecEntropyMonitor.php');

        $this->assertStringContainsString('private function signature', $source, 'must have signature() method');
        $this->assertStringContainsString("strlen(\$p).':'.\$p", $source, 'must length-prefix parts');
    }

    /**
     * Verify the old bare implode('|', $shapeParts) is gone.
     */
    public function test_source_no_bare_implode_for_shapes(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/TaskFabric/AtlasTaskFabricSpecEntropyMonitor.php');

        $this->assertStringNotContainsString(
            "implode('|', \$shapeParts)",
            $source,
            'old bare implode for acceptance shapes must be replaced'
        );
        $this->assertStringNotContainsString(
            "implode('|', \$evidenceParts)",
            $source,
            'old bare implode for evidence shapes must be replaced'
        );
    }

    /**
     * Verify the source uses $this->signature() for both shapes.
     */
    public function test_source_uses_signature_for_shapes(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/TaskFabric/AtlasTaskFabricSpecEntropyMonitor.php');

        $this->assertStringContainsString('$this->signature($shapeParts)', $source, 'must use signature for acceptance');
        $this->assertStringContainsString('$this->signature($evidenceParts)', $source, 'must use signature for evidence');
    }
}
