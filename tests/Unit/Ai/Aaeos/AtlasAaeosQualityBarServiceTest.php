<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos;

use App\Services\Ai\Aaeos\AtlasAaeosQualityBarService;
use PHPUnit\Framework\TestCase;

final class AtlasAaeosQualityBarServiceTest extends TestCase
{
    private AtlasAaeosQualityBarService $service;

    protected function setUp(): void
    {
        $this->service = new AtlasAaeosQualityBarService();
    }

    public function testReturnsCorrectSchemaVersion(): void
    {
        $report = $this->service->qualityBar();

        $this->assertArrayHasKey('schema_version', $report);
        $this->assertSame('atlas.aaeos.quality_bar.v1', $report['schema_version']);
    }

    public function testReturnsElevenDepartments(): void
    {
        $report = $this->service->qualityBar();

        $this->assertArrayHasKey('departments', $report);
        $this->assertCount(11, $report['departments']);
    }

    public function testDepartmentEntriesContainRequiredKeys(): void
    {
        $report = $this->service->qualityBar();

        foreach ($report['departments'] as $entry) {
            $this->assertArrayHasKey('department', $entry);
            $this->assertArrayHasKey('threshold', $entry);
            $this->assertArrayHasKey('current', $entry);
            $this->assertArrayHasKey('breached', $entry);
        }
    }

    public function testBreachedIsTrueWhenCurrentBelowThreshold(): void
    {
        $report = $this->service->qualityBar();

        foreach ($report['departments'] as $entry) {
            $expectedBreached = $entry['current'] < $entry['threshold'];
            $this->assertSame(
                $expectedBreached,
                $entry['breached'],
                sprintf(
                    'Department "%s": breached should be %s when current=%s and threshold=%s',
                    $entry['department'],
                    $expectedBreached ? 'true' : 'false',
                    $entry['current'],
                    $entry['threshold']
                )
            );
        }
    }

    public function testThresholdAndCurrentAreFloats(): void
    {
        $report = $this->service->qualityBar();

        foreach ($report['departments'] as $entry) {
            $this->assertIsFloat($entry['threshold']);
            $this->assertIsFloat($entry['current']);
        }
    }

    public function testDepartmentNamesAreStrings(): void
    {
        $report = $this->service->qualityBar();

        foreach ($report['departments'] as $entry) {
            $this->assertIsString($entry['department']);
        }
    }

    public function testBreachedIsBoolean(): void
    {
        $report = $this->service->qualityBar();

        foreach ($report['departments'] as $entry) {
            $this->assertIsBool($entry['breached']);
        }
    }
}