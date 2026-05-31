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

    public function testDepartmentIdsAreValid(): void
    {
        $result = $this->service->maturity();
        $validIds = [
            'product', 'architect', 'research', 'dev', 'debug',
            'review', 'qa', 'security', 'forge', 'delivery', 'memory',
        ];

        foreach ($result['departments'] as $department) {
            $this->assertContains($department['department'], $validIds);
        }
    }

    public function testMaturityTierMappingIsCorrect(): void
    {
        $result = $this->service->maturity();
        $expectedLevels = [
            'product' => 3,
            'architect' => 3,
            'research' => 2,
            'dev' => 1,
            'debug' => 2,
            'review' => 2,
            'qa' => 2,
            'security' => 3,
            'forge' => 4,
            'delivery' => 2,
            'memory' => 3,
        ];

        $indexed = [];
        foreach ($result['departments'] as $dept) {
            $indexed[$dept['department']] = $dept['maturity_tier'];
        }

        foreach ($expectedLevels as $deptId => $level) {
            $this->assertArrayHasKey($deptId, $indexed);
            $this->assertSame($level, $indexed[$deptId]);
        }
    }

    public function testSignalsContainEvidenceAndBlockerInfo(): void
    {
        $result = $this->service->maturity();

        foreach ($result['departments'] as $department) {
            $this->assertArrayHasKey('evidence', $department['signals']);
            $this->assertArrayHasKey('primary_blocker', $department['signals']);
            $this->assertArrayHasKey('blocker_summary', $department['signals']);
            $this->assertArrayHasKey('blocker_severity', $department['signals']);
            $this->assertArrayHasKey('current_level', $department['signals']);
        }
    }

    public function testBlockersToNextIsPresent(): void
    {
        $result = $this->service->maturity();

        foreach ($result['departments'] as $department) {
            $this->assertArrayHasKey('blockers_to_next', $department);
            $this->assertIsArray($department['blockers_to_next']);
            $this->assertNotEmpty($department['blockers_to_next']);
        }
    }

    public function testEvaluationDatesArePresent(): void
    {
        $result = $this->service->maturity();

        foreach ($result['departments'] as $department) {
            $this->assertArrayHasKey('last_evaluation', $department);
            $this->assertArrayHasKey('next_evaluation_due', $department);
        }
    }
}