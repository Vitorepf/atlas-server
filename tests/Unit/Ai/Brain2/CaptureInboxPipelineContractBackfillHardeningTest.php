<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\Capture\CaptureInboxPipelineContractBackfill;
use Tests\TestCase;

/**
 * Proves CaptureInboxPipelineContractBackfill does not throw when a capture-item
 * timestamp is an invalid date.
 */
final class CaptureInboxPipelineContractBackfillHardeningTest extends TestCase
{
    private CaptureInboxPipelineContractBackfill $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CaptureInboxPipelineContractBackfill();
    }

    private function dateString(mixed $value): ?string
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('dateString');
        return $method->invoke($this->service, $value);
    }

    public function test_invalid_date_string_returns_null(): void
    {
        $result = $this->dateString('not-a-valid-date');

        $this->assertNull($result);
    }

    public function test_valid_date_string_returns_iso8601(): void
    {
        $result = $this->dateString('2026-01-01T00:00:00Z');

        $this->assertNotNull($result);
        $this->assertStringContainsString('2026-01-01', $result);
    }

    public function test_null_returns_null(): void
    {
        $result = $this->dateString(null);

        $this->assertNull($result);
    }
}
