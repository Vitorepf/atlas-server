<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\Kernel\Envelope\Provenance;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Proves Provenance::fromArray does not throw when received_at is an invalid date.
 */
final class ProvenanceHardeningTest extends TestCase
{
    public function test_invalid_received_at_does_not_throw(): void
    {
        $provenance = Provenance::fromArray([
            'surface_id' => 'test',
            'surface_version' => '1.0',
            'session_id' => 'session-1',
            'received_at' => 'not-a-date',
        ]);

        $this->assertInstanceOf(CarbonImmutable::class, $provenance->receivedAt);
    }

    public function test_valid_received_at_is_parsed(): void
    {
        $provenance = Provenance::fromArray([
            'surface_id' => 'test',
            'surface_version' => '1.0',
            'session_id' => 'session-1',
            'received_at' => '2026-01-01T00:00:00Z',
        ]);

        $this->assertSame('2026-01-01 00:00:00', $provenance->receivedAt->format('Y-m-d H:i:s'));
    }

    public function test_missing_received_at_uses_now(): void
    {
        $provenance = Provenance::fromArray([
            'surface_id' => 'test',
            'surface_version' => '1.0',
            'session_id' => 'session-1',
        ]);

        $this->assertInstanceOf(CarbonImmutable::class, $provenance->receivedAt);
    }
}
