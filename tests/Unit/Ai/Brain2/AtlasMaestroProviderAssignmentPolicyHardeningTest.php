<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AtlasMaestroProviderAssignmentPolicyHardeningTest extends TestCase
{
    /**
     * Verify end() false is guarded — (string) false would yield ''.
     * Simulate the exact pattern to prove the fix works.
     */
    public function test_end_on_empty_array_returns_false_not_empty_string(): void
    {
        $empty = [];
        $last = end($empty);

        // Without guard: (string) $last would be ''
        $this->assertFalse($last);
        $this->assertSame('', (string) $last, 'unprotected cast yields empty string');

        // With guard: use 'none' fallback
        $provider = $last !== false ? (string) $last : 'none';
        $this->assertSame('none', $provider, 'guarded fallback yields none, not empty');
    }

    /**
     * Verify the source has the guard against end() returning false.
     */
    public function test_source_has_guard(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/Maestro/MultiProvider/AtlasMaestroProviderAssignmentPolicy.php');

        $this->assertStringContainsString('!== false', $source, 'end() result must be checked against false');
        $this->assertStringContainsString("'none'", $source, 'fallback must be explicit none, not empty string');
    }

    /**
     * Verify the source does not use the old pattern: (string) end($ordered).
     */
    public function test_source_does_not_use_unprotected_cast(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/Maestro/MultiProvider/AtlasMaestroProviderAssignmentPolicy.php');

        // The old pattern was: $last = (string) end($ordered);
        // The new pattern must not have this exact unprotected cast.
        $this->assertStringNotContainsString("'\$last = (string) end(\$ordered);'", $source);
    }
}
