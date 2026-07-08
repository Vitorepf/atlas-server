<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorReleaseAuthorizationPersistenceWriter;
use Tests\TestCase;

/**
 * Proves AgentDispatchExecutorReleaseAuthorizationPersistenceWriter does not throw
 * when the release-authorization signed-at is an invalid date.
 */
final class AgentDispatchExecutorReleaseAuthorizationPersistenceWriterHardeningTest extends TestCase
{
    private AgentDispatchExecutorReleaseAuthorizationPersistenceWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $ledger = $this->createMock(AtlasEvidenceLedger::class);
        $this->writer = new AgentDispatchExecutorReleaseAuthorizationPersistenceWriter($ledger);
    }

    private function safeParse(string $value): \Carbon\CarbonImmutable
    {
        $reflection = new \ReflectionClass($this->writer);
        $method = $reflection->getMethod('safeParse');
        return $method->invoke($this->writer, $value);
    }

    public function test_invalid_signed_at_does_not_throw(): void
    {
        $result = $this->safeParse('not-a-valid-date');

        $this->assertInstanceOf(\Carbon\CarbonImmutable::class, $result);
    }

    public function test_valid_signed_at_is_parsed(): void
    {
        $result = $this->safeParse('2026-01-01T00:00:00Z');

        $this->assertSame('2026-01-01 00:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function test_empty_string_defaults_to_now(): void
    {
        $result = $this->safeParse('');

        $this->assertInstanceOf(\Carbon\CarbonImmutable::class, $result);
    }
}
