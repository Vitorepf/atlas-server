<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos;

use App\Services\Ai\Aaeos\AtlasAaeosDepartmentMaturityService;
use PHPUnit\Framework\TestCase;

final class AtlasAaeosDepartmentMaturityServiceTest extends TestCase
{
    private AtlasAaeosDepartmentMaturityService $service;

    protected function setUp(): void
    {
        $this->service = new AtlasAaeosDepartmentMaturityService();
    }

    public function testMaturityReturnsCorrectSchemaVersion(): void
    {
        $result = $this->service->maturity();

        $this->assertArrayHasKey('schema_version', $result);
        $this->assertSame('atlas.aaeos.department_maturity.v1', $result['schema_version']);
    }

    public function testMaturityReturnsElevenDepartments(): void
    {
        $result = $this->service->maturity();

        $this->assertArrayHasKey('departments', $result);
        $this->assertCount(11, $result['departments']);
    }

    public function testEachDepartmentHasRequiredFields(): void
    {
        $result = $this->service->maturity();

        foreach ($result['departments'] as $department) {
            $this->assertArrayHasKey('department', $department);
            $this->assertArrayHasKey('maturity_tier', $department);
            $this->assertArrayHasKey('signals', $department);
        }
    }

    public function testMaturityTierIsIntegerWithinRange(): void
    {
        $result = $this->service->maturity();

        foreach ($result['departments'] as $department) {
            $this->assertIsInt($department['maturity_tier']);
            $this->assertGreaterThanOrEqual(0, $department['maturity_tier']);
            $this->assertLessThanOrEqual(5, $department['maturity_tier']);
        }
    }

    public function testSignalsIsList(): void
    {
        $result = $this->service->maturity();

        foreach ($result['departments'] as $department) {
            $this->assertIsArray($department['signals']);
        }
    }

    public function testDepartmentNamesAreStrings(): void
    {
        $result = $this->service->maturity();

        foreach ($result['departments'] as $department) {
            $this->assertIsString($department['department']);
            $this->assertNotEmpty($department['department']);
        }
    }

    public function testResultIsDeterministic(): void
    {
        $first = $this->service->maturity();
        $second = $this->service->maturity();

        $this->assertEquals($first, $second);
    }
}