<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainDecisionLedgerCompactorHardeningTest extends TestCase
{
    /**
     * Verify the source has a lengthPrefix method.
     */
    public function test_source_has_length_prefix_method(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainDecisionLedgerCompactor.php');

        $this->assertStringContainsString('private function lengthPrefix', $source, 'must have lengthPrefix method');
        $this->assertStringContainsString("strlen(\$value).':'.\$value", $source, 'must length-prefix');
    }

    /**
     * Verify groupKey uses lengthPrefix for all fields.
     */
    public function test_source_group_key_uses_length_prefix(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainDecisionLedgerCompactor.php');

        $this->assertStringContainsString('$this->lengthPrefix($causesStr)', $source, 'must length-prefix causes');
        $this->assertStringContainsString('$this->lengthPrefix($outcome)', $source, 'must length-prefix outcome');
        $this->assertStringContainsString('$this->lengthPrefix($scope)', $source, 'must length-prefix scope');
    }

    /**
     * Verify the old bare implode pattern is gone.
     */
    public function test_source_no_bare_implode_in_group_key(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainDecisionLedgerCompactor.php');

        $this->assertStringNotContainsString(
            "hash('sha256', implode(',', \$sortedCauses).'|'.\$outcome.'|'.\$scope)",
            $source,
            'old bare implode in groupKey must be replaced'
        );
    }
}
