<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos;

use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDepartmentQualityBarService;
use PHPUnit\Framework\TestCase;

final class AtlasAaeosQualityBarServiceTest extends TestCase
{
    private AtlasDepartmentQualityBarService $service;

    protected function setUp(): void
    {
        $this->service = new AtlasDepartmentQualityBarService();
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

    public function testQualityBarIncludesSignal(): void
    {
        $report = $this->service->qualityBar();

        $this->assertArrayHasKey('signal', $report);
        $this->assertIsArray($report['signal']);
    }

    public function testSignalContainsSchemaVersion(): void
    {
        $signal = $this->service->emitSignal();

        $this->assertArrayHasKey('schema_version', $signal);
        $this->assertSame('atlas.aaeos.quality_bar.v1', $signal['schema_version']);
    }

    public function testSignalContainsBreachCount(): void
    {
        $signal = $this->service->emitSignal();

        $this->assertArrayHasKey('breach_count', $signal);
        $this->assertIsInt($signal['breach_count']);
    }

    public function testSignalContainsBreachesArray(): void
    {
        $signal = $this->service->emitSignal();

        $this->assertArrayHasKey('breaches', $signal);
        $this->assertIsArray($signal['breaches']);
    }

    public function testSignalContainsEmittedAt(): void
    {
        $signal = $this->service->emitSignal();

        $this->assertArrayHasKey('emitted_at', $signal);
        $this->assertIsString($signal['emitted_at']);
    }

    public function testSignalBreachesContainDepartment(): void
    {
        $signal = $this->service->emitSignal();

        foreach ($signal['breaches'] as $breach) {
            $this->assertArrayHasKey('department', $breach);
            $this->assertIsString($breach['department']);
        }
    }

    public function testSignalBreachesContainThresholdAndCurrent(): void
    {
        $signal = $this->service->emitSignal();

        foreach ($signal['breaches'] as $breach) {
            $this->assertArrayHasKey('threshold', $breach);
            $this->assertArrayHasKey('current', $breach);
            $this->assertIsFloat($breach['threshold']);
            $this->assertIsFloat($breach['current']);
        }
    }

    public function testSignalBreachesContainDeficit(): void
    {
        $signal = $this->service->emitSignal();

        foreach ($signal['breaches'] as $breach) {
            $this->assertArrayHasKey('deficit', $breach);
            $this->assertIsFloat($breach['deficit']);
            $this->assertGreaterThan(0, $breach['deficit']);
        }
    }

    public function testSignalDetectsBreachesCorrectly(): void
    {
        $signal = $this->service->emitSignal();

        $breachedDepartments = array_column($signal['breaches'], 'department');
        $this->assertContains('Product', $breachedDepartments);
        $this->assertContains('Marketing', $breachedDepartments);
        $this->assertContains('Operations', $breachedDepartments);
        $this->assertContains('Human Resources', $breachedDepartments);
        $this->assertContains('Legal', $breachedDepartments);
        $this->assertContains('Research', $breachedDepartments);
    }

    public function testNonBreachedDepartmentsNotInSignal(): void
    {
        $signal = $this->service->emitSignal();

        $breachedDepartments = array_column($signal['breaches'], 'department');
        $this->assertNotContains('Engineering', $breachedDepartments);
        $this->assertNotContains('Design', $breachedDepartments);
        $this->assertNotContains('Sales', $breachedDepartments);
        $this->assertNotContains('Finance', $breachedDepartments);
        $this->assertNotContains('Customer Success', $breachedDepartments);
    }

    public function testBreachCountMatchesActualBreaches(): void
    {
        $signal = $this->service->emitSignal();

        $this->assertSame(count($signal['breaches']), $signal['breach_count']);
    }
}