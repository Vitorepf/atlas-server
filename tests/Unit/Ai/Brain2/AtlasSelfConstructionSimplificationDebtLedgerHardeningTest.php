<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionSimplificationDebtLedgerHardeningTest extends TestCase
{
    /**
     * Verify the source uses json_encode for dedup key instead of '|' + implode(',').
     */
    public function test_source_uses_json_encode_for_dedup_key(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/Simplification/AtlasSelfConstructionSimplificationDebtLedger.php');

        $this->assertStringContainsString('json_encode', $source, 'must use json_encode for dedup key');
        $this->assertStringContainsString('JSON_THROW_ON_ERROR', $source, 'must throw on encode failure');
    }

    /**
     * Verify the old '|' + implode(',', ...) concatenation is gone.
     */
    public function test_old_delimiter_concatenation_removed(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/Simplification/AtlasSelfConstructionSimplificationDebtLedger.php');

        $this->assertStringNotContainsString(".'|'.implode", $source, 'must not use | + implode concatenation');
    }

    /**
     * Demonstrate the collision: comma in target collides with two-element target set.
     */
    public function test_delimiter_collision_exists(): void
    {
        $a = 'cat' . '|' . implode(',', ['a,b']);
        $b = 'cat' . '|' . implode(',', ['a', 'b']);
        $this->assertSame($a, $b, 'delimiter collision: cat|a,b == cat|a,b');
        // json_encode avoids this
        $ja = json_encode(['cat', ['a,b']]);
        $jb = json_encode(['cat', ['a', 'b']]);
        $this->assertNotSame($ja, $jb, 'json_encode avoids collision');
    }
}
