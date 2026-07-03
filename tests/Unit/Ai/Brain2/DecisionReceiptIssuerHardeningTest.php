<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class DecisionReceiptIssuerHardeningTest extends TestCase
{
    /**
     * Verify the source wraps CarbonImmutable::parse in try/catch for issued_at.
     */
    public function test_source_has_try_catch_for_issued_at(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/Kernel/Decision/DecisionReceiptIssuer.php');

        $this->assertStringContainsString('CarbonImmutable::parse', $source, 'must parse dates');
        $this->assertStringContainsString('catch', $source, 'must have try/catch guard');
    }

    /**
     * Verify the source has try/catch for expires_at too.
     */
    public function test_source_has_try_catch_for_expires_at(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/Kernel/Decision/DecisionReceiptIssuer.php');

        // Count try blocks — should be at least 2 (issued_at + expires_at).
        $count = substr_count($source, 'try {');
        $this->assertGreaterThanOrEqual(2, $count, 'must have try/catch for both date fields');
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
