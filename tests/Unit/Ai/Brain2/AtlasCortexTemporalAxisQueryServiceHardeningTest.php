<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Temporal\AtlasCortexTemporalAxisQueryService;

final class AtlasCortexTemporalAxisQueryServiceHardeningTest extends TestCase
{
    private function createService(): AtlasCortexTemporalAxisQueryService
    {
        $dummy = new class { public function __call($name, $args) { return null; } };
        return new AtlasCortexTemporalAxisQueryService($dummy, $dummy);
    }

    /**
     * timestamp() must return null on an invalid date string instead of throwing.
     */
    public function test_invalid_date_returns_null(): void
    {
        $service = $this->createService();

        $method = new \ReflectionMethod($service, 'timestamp');
        $result = $method->invoke($service, 'not-a-date');

        $this->assertNull($result);
    }

    /**
     * Valid date strings are parsed correctly.
     */
    public function test_valid_date_parsed(): void
    {
        $service = $this->createService();

        $method = new \ReflectionMethod($service, 'timestamp');
        $result = $method->invoke($service, '2026-01-15T10:30:00+00:00');

        $this->assertInstanceOf(\DateTimeImmutable::class, $result);
    }

    /**
     * Empty string returns null.
     */
    public function test_empty_string_returns_null(): void
    {
        $service = $this->createService();

        $method = new \ReflectionMethod($service, 'timestamp');
        $result = $method->invoke($service, '');

        $this->assertNull($result);
    }

    /**
     * Verify the source has the try/catch guard.
     */
    public function test_source_has_try_catch(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/AutonomousEvolution/Discovery/Cortex/Temporal/AtlasCortexTemporalAxisQueryService.php');

        $this->assertStringContainsString('try {', $source, 'timestamp must wrap DateTimeImmutable in try/catch');
        $this->assertStringContainsString('Throwable', $source, 'timestamp must catch Throwable');
    }
}
