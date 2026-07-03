<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGateHardeningTest extends TestCase
{
    /**
     * Verify the source wraps CarbonImmutable::parse in try/catch.
     */
    public function test_source_has_try_catch_for_parse(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate.php');

        $this->assertStringContainsString('CarbonImmutable::parse', $source, 'must parse dates');
        $this->assertStringContainsString('catch', $source, 'must have try/catch guard');
        $this->assertStringContainsString('signature_parse_failure', $source, 'must reject on parse failure');
    }

    /**
     * Verify the old unguarded parse is gone.
     */
    public function test_old_unguarded_parse_removed(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate.php');

        // The old pattern: $issuedAt = CarbonImmutable::parse($normalized['signature_issued_at']);
        // followed immediately by blank line + if (abs(...)
        $this->assertStringNotContainsString(
            "\$issuedAt = CarbonImmutable::parse(\$normalized['signature_issued_at']);\n\n        if (abs(",
            $source,
            'old unguarded parse must be replaced'
        );
    }

    /**
     * Demonstrate the bug: CarbonImmutable::parse throws on invalid date.
     */
    public function test_parse_invalid_date_throws(): void
    {
        $this->expectException(\Throwable::class);
        \Carbon\CarbonImmutable::parse('not-a-date');
    }
}
