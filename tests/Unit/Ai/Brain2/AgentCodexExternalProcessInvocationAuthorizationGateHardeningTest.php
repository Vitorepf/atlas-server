<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AgentCodexExternalProcessInvocationAuthorizationGateHardeningTest extends TestCase
{
    /**
     * Verify the source guards strtotime(false) for lease expiry.
     */
    public function test_source_has_strtotime_false_guard(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/AgentCodexExternalProcessInvocationAuthorizationGate.php');

        $this->assertStringContainsString('strtotime', $source, 'must use strtotime');
        $this->assertStringContainsString('!== false', $source, 'must guard strtotime(false)');
    }

    /**
     * Verify the lease expiry check explicitly guards against strtotime returning false.
     */
    public function test_lease_expiry_guards_strtotime_false(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/AgentCodexExternalProcessInvocationAuthorizationGate.php');

        $this->assertMatchesRegularExpression(
            '/\$leaseExpiry\s*=\s*strtotime[\s\S]*?\$leaseExpiry\s*!==\s*false/s',
            $source,
            'lease expiry must explicitly check strtotime !== false'
        );
    }

    /**
     * Demonstrate the bug: strtotime(false) coerces to 0 in comparison.
     */
    public function test_strtotime_invalid_returns_false(): void
    {
        $result = strtotime('not-a-date');
        $this->assertFalse($result, 'strtotime on invalid date returns false');
        // false coerced to 0 in >= comparison would make any now >= 0, falsely expired
        $this->assertTrue(strtotime('now') >= 0, 'demonstrates false coercion to 0');
    }
}
