<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AgentAutomaticDispatchSchedulerOneShotTickMutatingWriterHardeningTest extends TestCase
{
    /**
     * Verify the source wraps CarbonImmutable::parse in try/catch.
     */
    public function test_source_has_try_catch_for_parse(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickMutatingWriter.php');

        $this->assertStringContainsString('CarbonImmutable::parse', $source, 'must parse dates');
        $this->assertStringContainsString('catch', $source, 'must have try/catch guard');
        $this->assertStringContainsString('invalid_dispatch_signed_at', $source, 'must reject invalid signed_at');
        $this->assertStringContainsString('invalid_dispatch_expires_at', $source, 'must reject invalid expires_at');
    }

    /**
     * Verify the parse is wrapped in try/catch.
     */
    public function test_parse_is_guarded(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickMutatingWriter.php');

        $this->assertMatchesRegularExpression(
            '/try\s*\{[\s\S]*?CarbonImmutable::parse[\s\S]*?\}\s*catch/s',
            $source,
            'parse must be wrapped in try/catch'
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
