<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AtlasArchitectureCouncilBoundaryMapEdgeDedupHardeningTest extends TestCase
{
    /**
     * Verify the source has an edgeKey method with length-prefix escaping.
     */
    public function test_source_has_edge_key_method(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/ArchitectureCouncil/AtlasArchitectureCouncilBoundaryMap.php');

        $this->assertStringContainsString('private function edgeKey', $source, 'must have edgeKey method');
        $this->assertStringContainsString("strlen(\$from).':'.\$from", $source, 'must length-prefix from');
    }

    /**
     * Verify the allowed-edge dedup uses the collision-safe key.
     */
    public function test_source_uses_dedup_key_for_allowed(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/ArchitectureCouncil/AtlasArchitectureCouncilBoundaryMap.php');

        $this->assertStringContainsString('$this->edgeKey($from, $to, $action)', $source, 'must call edgeKey');
        $this->assertStringContainsString('$seenAllowed[$dedupKey]', $source, 'must use dedupKey for allowed dedup');
    }

    /**
     * Verify the forbidden edge lookup still uses the legacy key format.
     */
    public function test_source_forbidden_uses_legacy_key(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/ArchitectureCouncil/AtlasArchitectureCouncilBoundaryMap.php');

        $this->assertStringContainsString('$legacyKey', $source, 'forbidden lookup must use legacy format');
    }
}
