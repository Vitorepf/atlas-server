<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class ProviderReleaseIngestionProposalHardeningTest extends TestCase
{
    /**
     * Verify the source checks json_encode for false before hashing.
     */
    public function test_source_checks_encode_failure(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/Kernel/Architecture/ProviderReleaseIngestionProposal.php');

        $this->assertStringContainsString('$encoded === false', $source, 'must check for encode failure');
        $this->assertStringContainsString('RuntimeException', $source, 'must throw on encode failure');
    }

    /**
     * Verify the old ?: '' fallback is gone.
     */
    public function test_old_empty_fallback_removed(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/Kernel/Architecture/ProviderReleaseIngestionProposal.php');

        $this->assertStringNotContainsString(
            "?: ''",
            $source,
            'old ?: empty fallback must be replaced'
        );
    }

    /**
     * Demonstrate the bug: json_encode false falls back to empty string.
     */
    public function test_false_fallback_hashes_empty(): void
    {
        $encoded = false;
        $fallback = $encoded ?: '';
        $this->assertEquals('', $fallback, 'false ?: empty yields empty string');
        $this->assertEquals(hash('sha256', ''), hash('sha256', $fallback), 'hash of fallback = hash of empty');
    }
}
