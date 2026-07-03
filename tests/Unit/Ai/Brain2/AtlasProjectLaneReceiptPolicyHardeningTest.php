<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AtlasProjectLaneReceiptPolicyHardeningTest extends TestCase
{
    /**
     * Verify the source checks json_encode for false before hashing.
     */
    public function test_source_checks_encode_failure(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/MultiProject/AtlasProjectLaneReceiptPolicy.php');

        $this->assertStringContainsString('$encoded === false', $source, 'must check for encode failure');
        $this->assertStringContainsString('RuntimeException', $source, 'must throw on encode failure');
    }

    /**
     * Verify the old (string) json_encode pattern is gone.
     */
    public function test_old_string_cast_pattern_removed(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/MultiProject/AtlasProjectLaneReceiptPolicy.php');

        $this->assertStringNotContainsString(
            "(string) json_encode(\$canonical",
            $source,
            'old (string) json_encode must be replaced'
        );
    }

    /**
     * Demonstrate the bug: (string) false is empty string.
     */
    public function test_string_false_is_empty(): void
    {
        $this->assertEquals('', (string) false, '(string) false yields empty string');
        $this->assertEquals(hash('sha256', ''), hash('sha256', (string) false), 'hash of false = hash of empty');
    }
}
