<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AtlasMaestroRetryEvidenceMinerHardeningTest extends TestCase
{
    /**
     * Verify the source uses json_encode for injective key encoding.
     */
    public function test_source_uses_json_encode_for_key(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/Maestro/Retry/AtlasMaestroRetryEvidenceMiner.php');

        $this->assertStringContainsString('json_encode', $source, 'must use json_encode for injective key');
        $this->assertStringContainsString('JSON_THROW_ON_ERROR', $source, 'must throw on encode failure');
    }

    /**
     * Verify the old pipe-implode pattern is gone.
     */
    public function test_old_pipe_implode_removed(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/Maestro/Retry/AtlasMaestroRetryEvidenceMiner.php');

        $this->assertStringNotContainsString(
            "implode('|'",
            $source,
            'old pipe-implode must be replaced'
        );
    }

    /**
     * Demonstrate the principle: pipe delimiter is not injective.
     */
    public function test_pipe_is_not_injective(): void
    {
        // A field containing a pipe makes the key ambiguous.
        // e.g. ['a', 'b', 'c'] and ['a', 'b|c', ''] both have 3 fields
        // but the pipe-joined string 'a|b|c' vs 'a|b|c|' differ by trailing pipe.
        // The real risk: ['a|b', 'c', 'd'] vs ['a', 'b|c', 'd'] both produce 'a|b|c|d'.
        $key1 = implode('|', ['a|b', 'c', 'd']);
        $key2 = implode('|', ['a', 'b|c', 'd']);
        $this->assertEquals($key1, $key2, 'pipe delimiter is not injective');

        // json_encode keeps them distinct
        $json1 = json_encode(['a|b', 'c', 'd']);
        $json2 = json_encode(['a', 'b|c', 'd']);
        $this->assertNotEquals($json1, $json2, 'json_encode is injective');
    }
}
