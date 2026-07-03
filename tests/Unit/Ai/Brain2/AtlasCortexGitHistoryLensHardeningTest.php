<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AtlasCortexGitHistoryLensHardeningTest extends TestCase
{
    /**
     * Verify the source has the field-count guard for git commit lines.
     */
    public function test_source_has_field_count_guard(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/AutonomousEvolution/Discovery/Cortex/Council/AtlasCortexGitHistoryLens.php');

        $this->assertStringContainsString('count($parts) < 3', $source, 'must guard against truncated git lines');
    }

    /**
     * Verify the source no longer uses the old pattern: (string) ($parts[N] ?? '').
     * The fix replaces these with direct access after the count guard.
     */
    public function test_source_no_longer_defaults_parts(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/AutonomousEvolution/Discovery/Cortex/Council/AtlasCortexGitHistoryLens.php');

        // The old pattern was: (string) ($parts[0] ?? ''), ($parts[1] ?? ''), ($parts[2] ?? '')
        // After the fix, parts are accessed directly: (string) $parts[0], etc.
        $this->assertStringContainsString('(string) $parts[0]', $source, 'parts[0] must be direct access');
        $this->assertStringContainsString('(string) $parts[2]', $source, 'parts[2] must be direct access');
    }

    /**
     * Demonstrate the bug: truncated line yields empty author without guard.
     */
    public function test_truncated_line_without_guard_yields_empty_author(): void
    {
        $line = "__C__sha_only";
        $parts = explode("\t", substr($line, strlen('__C__')));

        // Without guard: parts[2] ?? '' yields ''
        $this->assertCount(1, $parts);
        $this->assertSame('', $parts[2] ?? '', 'unprotected access yields empty string');
        $this->assertLessThan(3, count($parts), 'guard would skip this line');
    }
}
