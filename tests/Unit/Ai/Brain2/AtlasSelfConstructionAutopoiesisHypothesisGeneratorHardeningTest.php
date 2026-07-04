<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionAutopoiesisHypothesisGeneratorHardeningTest extends TestCase
{
    /**
     * Verify the source uses json_encode for dedup key instead of '::' delimiter.
     */
    public function test_source_uses_json_encode_for_dedup_key(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/Autopoiesis/AtlasSelfConstructionAutopoiesisHypothesisGenerator.php');

        $this->assertStringContainsString('json_encode', $source, 'must use json_encode for dedup key');
        $this->assertStringContainsString('JSON_THROW_ON_ERROR', $source, 'must throw on encode failure');
    }

    /**
     * Verify the old '::' concatenation is gone.
     */
    public function test_old_delimiter_concatenation_removed(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/Autopoiesis/AtlasSelfConstructionAutopoiesisHypothesisGenerator.php');

        $this->assertStringNotContainsString(".'::'.", $source, 'must not use :: concatenation');
    }

    /**
     * Demonstrate the collision: '::' in target_organ collides with class.
     */
    public function test_delimiter_collision_exists(): void
    {
        $a = 'OrganX::extra' . '::' . 'foo';
        $b = 'OrganX' . '::' . 'extra::foo';
        $this->assertSame($a, $b, 'delimiter collision');
        // json_encode avoids this
        $ja = json_encode(['OrganX::extra', 'foo']);
        $jb = json_encode(['OrganX', 'extra::foo']);
        $this->assertNotSame($ja, $jb, 'json_encode avoids collision');
    }
}
