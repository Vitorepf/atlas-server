<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\LongHorizon\StrategicForgettingService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Proves StrategicForgettingService does not throw when a memory-entry recorded-at
 * is an invalid date.
 */
final class StrategicForgettingServiceHardeningTest extends TestCase
{
    private StrategicForgettingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StrategicForgettingService();
    }

    private function decide(AtlasMemoryEntry $entry, CarbonImmutable $now): array
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('decide');
        return $method->invoke($this->service, $entry, $now);
    }

    public function test_invalid_recorded_at_does_not_throw(): void
    {
        $entry = $this->createMock(AtlasMemoryEntry::class);
        $entry->id = 1;
        $entry->metadata = [];
        $entry->confidence = 0.9;
        $entry->recorded_at = 'not-a-valid-date';
        $entry->last_used_at = null;
        $entry->privacy_class = 'public';
        $entry->superseded_by_id = null;
        $entry->stale_after = null;
        $entry->valid_until = null;
        $entry->authority_level = 'standard';
        $entry->source_type = null;
        $entry->source_id = null;

        $result = $this->decide($entry, CarbonImmutable::now());

        $this->assertIsArray($result);
    }

    public function test_invalid_last_used_at_does_not_throw(): void
    {
        $entry = $this->createMock(AtlasMemoryEntry::class);
        $entry->id = 1;
        $entry->metadata = [];
        $entry->confidence = 0.9;
        $entry->recorded_at = '2026-01-01T00:00:00Z';
        $entry->last_used_at = 'not-a-valid-date';
        $entry->privacy_class = 'public';
        $entry->superseded_by_id = null;
        $entry->stale_after = null;
        $entry->valid_until = null;
        $entry->authority_level = 'standard';
        $entry->source_type = null;
        $entry->source_id = null;

        $result = $this->decide($entry, CarbonImmutable::now());

        $this->assertIsArray($result);
    }

    public function test_invalid_stale_after_does_not_throw(): void
    {
        $entry = $this->createMock(AtlasMemoryEntry::class);
        $entry->id = 1;
        $entry->metadata = [];
        $entry->confidence = 0.9;
        $entry->recorded_at = '2026-01-01T00:00:00Z';
        $entry->last_used_at = null;
        $entry->privacy_class = 'public';
        $entry->superseded_by_id = null;
        $entry->stale_after = 'not-a-valid-date';
        $entry->valid_until = null;
        $entry->authority_level = 'standard';
        $entry->source_type = null;
        $entry->source_id = null;

        $result = $this->decide($entry, CarbonImmutable::now());

        $this->assertIsArray($result);
    }
}
