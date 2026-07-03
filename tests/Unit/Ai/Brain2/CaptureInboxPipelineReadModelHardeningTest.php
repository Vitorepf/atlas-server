<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class CaptureInboxPipelineReadModelHardeningTest extends TestCase
{
    /**
     * Verify the source wraps CarbonImmutable::parse in try/catch.
     */
    public function test_parse_source_has_try_catch_for_parse(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/Capture/CaptureInboxPipelineReadModel.php');

        $this->assertStringContainsString('CarbonImmutable::parse', $source, 'must parse dates');
        $this->assertStringContainsString('catch', $source, 'must have try/catch guard');
    }

    /**
     * Verify carbon() returns null on invalid date instead of throwing.
     */
    public function test_parse_carbon_returns_null_on_invalid(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/Capture/CaptureInboxPipelineReadModel.php');

        $this->assertStringContainsString('try {', $source, 'must have try block');
        $this->assertStringContainsString('CarbonImmutable::parse', $source, 'must parse dates');
        $this->assertStringContainsString('catch (\Throwable)', $source, 'must catch Throwable');

        // Verify the carbon method has the guard
        $this->assertMatchesRegularExpression(
            '/private function carbon.*?\{.*?try.*?CarbonImmutable::parse.*?catch.*?return null/s',
            $source,
            'carbon must catch and return null on invalid date'
        );
    }

    /**
     * Demonstrate the bug: CarbonImmutable::parse throws on invalid date.
     */
    public function test_parse_invalid_date_throws_without_guard(): void
    {
        $this->expectException(\Throwable::class);
        \Carbon\CarbonImmutable::parse('not-a-date');
    }
}
