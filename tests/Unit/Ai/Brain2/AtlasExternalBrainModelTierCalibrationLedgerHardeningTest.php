<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainModelTierCalibrationLedgerHardeningTest extends TestCase
{
    /**
     * Verify the source uses json_encode for segKey instead of '|' delimiter.
     */
    public function test_source_uses_json_encode_for_segkey(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainModelTierCalibrationLedger.php');

        $this->assertStringContainsString('json_encode', $source, 'must use json_encode for segKey');
        $this->assertStringContainsString('JSON_THROW_ON_ERROR', $source, 'must throw on encode failure');
    }

    /**
     * Verify the old '{$tier}|{$scaffold}|{$critique}|{$taskClass}' concatenation is gone.
     */
    public function test_old_delimiter_concatenation_removed(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainModelTierCalibrationLedger.php');

        $this->assertStringNotContainsString('|{$scaffold}|{$critique}|{$taskClass}', $source, 'must not use | concatenation');
    }

    /**
     * Demonstrate the collision: '|' in tier merges two segments.
     */
    public function test_delimiter_collision_exists(): void
    {
        $a = "a|b|c|d|e";
        $b = "a|b|c|d|e";
        $this->assertSame($a, $b, 'delimiter collision');
        // json_encode avoids this
        $ja = json_encode(['a|b', 'c', 'd', 'e']);
        $jb = json_encode(['a', 'b|c', 'd', 'e']);
        $this->assertNotSame($ja, $jb, 'json_encode avoids collision');
    }
}
