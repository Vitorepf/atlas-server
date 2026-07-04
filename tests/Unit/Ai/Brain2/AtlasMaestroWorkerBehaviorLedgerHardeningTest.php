<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AtlasMaestroWorkerBehaviorLedgerHardeningTest extends TestCase
{
    /**
     * Verify the source uses json_encode for keys instead of '|' or ':' delimiter.
     */
    public function test_source_uses_json_encode_for_keys(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/Maestro/Adaptive/AtlasMaestroWorkerBehaviorLedger.php');

        $this->assertStringContainsString('json_encode', $source, 'must use json_encode for keys');
        $this->assertStringContainsString('JSON_THROW_ON_ERROR', $source, 'must throw on encode failure');
    }

    /**
     * Verify the old '|' and ':' delimiter concatenations are gone.
     */
    public function test_old_delimiter_concatenation_removed(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/Maestro/Adaptive/AtlasMaestroWorkerBehaviorLedger.php');

        $this->assertStringNotContainsString(".'|'.", $source, 'must not use | delimiter');
        $this->assertStringNotContainsString(".':'.", $source, 'must not use : delimiter');
    }

    /**
     * Demonstrate the collision: '|' in client_id merges two workers.
     */
    public function test_delimiter_collision_exists(): void
    {
        $a = 'a|b' . '|' . 'c';
        $b = 'a' . '|' . 'b|c';
        $this->assertSame($a, $b, 'delimiter collision');
        // json_encode avoids this
        $ja = json_encode(['a|b', 'c']);
        $jb = json_encode(['a', 'b|c']);
        $this->assertNotSame($ja, $jb, 'json_encode avoids collision');
    }
}
