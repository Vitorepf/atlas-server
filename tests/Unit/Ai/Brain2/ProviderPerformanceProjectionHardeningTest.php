<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class ProviderPerformanceProjectionHardeningTest extends TestCase
{
    /**
     * Verify the source uses json_encode for injective group key.
     */
    public function test_source_uses_json_encode_for_key(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/Kernel/Evidence/ProviderPerformanceProjection.php');

        $this->assertStringContainsString('json_encode', $source, 'must use json_encode for injective key');
        $this->assertStringContainsString('JSON_THROW_ON_ERROR', $source, 'must throw on encode failure');
    }

    /**
     * Verify the old pipe-implode pattern is gone.
     */
    public function test_old_pipe_implode_removed(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/Kernel/Evidence/ProviderPerformanceProjection.php');

        $this->assertStringNotContainsString(
            "implode('|'",
            $source,
            'old pipe-implode must be replaced'
        );
    }

    /**
     * Demonstrate the collision: pipe delimiter is not injective.
     */
    public function test_pipe_is_not_injective(): void
    {
        $key1 = implode('|', ['a|b', 'c', 'd', 'e']);
        $key2 = implode('|', ['a', 'b|c', 'd', 'e']);
        $this->assertEquals($key1, $key2, 'pipe delimiter is not injective');

        $json1 = json_encode(['a|b', 'c', 'd', 'e']);
        $json2 = json_encode(['a', 'b|c', 'd', 'e']);
        $this->assertNotEquals($json1, $json2, 'json_encode is injective');
    }
}
