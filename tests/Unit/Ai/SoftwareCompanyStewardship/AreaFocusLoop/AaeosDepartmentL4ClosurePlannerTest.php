<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AaeosDepartmentL4ClosurePlanner;
use PHPUnit\Framework\TestCase;

final class AaeosDepartmentL4ClosurePlannerTest extends TestCase
{
    /**
     * @return array<string,array<string,mixed>>
     */
    private function indexByDepartment(array $rows): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            $indexed[$row['department_id']] = $row;
        }

        return $indexed;
    }

    public function testSchemaVersionTargetLevelAndOwnerDocAreCanonical(): void
    {
        $plan = (new AaeosDepartmentL4ClosurePlanner())->plan([], []);

        $this->assertSame('atlas.aaeos.department_l4_closure_plan.v1', $plan['schema_version']);
        $this->assertSame('L4', $plan['target_level']);
        $this->assertSame(4, $plan['target_level_value']);
        $this->assertSame(
            'docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md',
            $plan['owner_doc'],
        );
    }

    public function testPlanReturnsAllElevenCanonicalDepartments(): void
    {
        $plan = (new AaeosDepartmentL4ClosurePlanner())->plan([], []);

        $ids = array_map(
            static fn (array $dept): string => $dept['department_id'],
            $plan['departments'],
        );

        $this->assertSame(
            ['product', 'architect', 'research', 'dev', 'debug', 'review', 'qa', 'security', 'forge', 'delivery', 'memory'],
            $ids,
        );
        $this->assertCount(11, $plan['departments']);
    }

    public function testEmptyInputLeavesEveryDepartmentBelowL4WithCurrentLevelAndPackets(): void
    {
        $plan = (new AaeosDepartmentL4ClosurePlanner())->plan([], []);

        // No maturity input => every department computes to L0 and needs a packet.
        $this->assertCount(11, $plan['missing_packets']);
        $this->assertSame([], $plan['marked_l4_departments']);
        $this->assertFalse($plan['all_departments_l4']);

        $departments = $this->indexByDepartment($plan['departments']);
        $this->assertSame('L0', $departments['dev']['current_level']);
        $this->assertSame(0, $departments['dev']['current_level_value']);
        $this->assertSame(4, $departments['dev']['level_gap']);
        $this->assertTrue($departments['dev']['needs_packet']);
        $this->assertFalse($departments['dev']['marked_l4']);
    }

    public function testCurrentLevelAndLevelGapAreComputedPerDepartment(): void
    {
        $plan = (new AaeosDepartmentL4ClosurePlanner())->plan([
            'product' => ['current_level' => 'L3'],
            'dev' => ['current_level' => 'L1'],
            'research' => ['level' => 2],
        ], []);

        $departments = $this->indexByDepartment($plan['departments']);

        $this->assertSame('L3', $departments['product']['current_level']);
        $this->assertSame(3, $departments['product']['current_level_value']);
        $this->assertSame(1, $departments['product']['level_gap']);

        $this->assertSame('L1', $departments['dev']['current_level']);
        $this->assertSame(3, $departments['dev']['level_gap']);

        // Integer level input is honoured too.
        $this->assertSame('L2', $departments['research']['current_level']);
        $this->assertSame(2, $departments['research']['level_gap']);
    }

    public function testPacketIsExecutableOnlyWithAllowedFilesAndTestCommand(): void
    {
        $plan = (new AaeosDepartmentL4ClosurePlanner())->plan([
            'dev' => [
                'current_level' => 'L1',
                'allowed_files' => ['app/Services/Ai/Foo.php', 'tests/Unit/FooTest.php'],
                'test_command' => 'php artisan test --filter=FooTest',
            ],
        ], []);

        $packets = $this->indexByDepartment($plan['missing_packets']);
        $devPacket = $packets['dev'];

        $this->assertTrue($devPacket['executable']);
        $this->assertSame(['app/Services/Ai/Foo.php', 'tests/Unit/FooTest.php'], $devPacket['allowed_files']);
        $this->assertSame('php artisan test --filter=FooTest', $devPacket['test_command']);
        $this->assertNotContains('allowed_files_missing', $devPacket['blockers']);
        $this->assertNotContains('test_command_missing', $devPacket['blockers']);
    }

    public function testPacketIsNotExecutableWhenAllowedFilesMissing(): void
    {
        $plan = (new AaeosDepartmentL4ClosurePlanner())->plan([
            'dev' => [
                'current_level' => 'L1',
                'test_command' => 'php artisan test --filter=FooTest',
            ],
        ], []);

        $packets = $this->indexByDepartment($plan['missing_packets']);
        $devPacket = $packets['dev'];

        $this->assertFalse($devPacket['executable']);
        $this->assertSame([], $devPacket['allowed_files']);
        $this->assertContains('allowed_files_missing', $devPacket['blockers']);
    }

    public function testPacketIsNotExecutableWhenTestCommandMissing(): void
    {
        $plan = (new AaeosDepartmentL4ClosurePlanner())->plan([
            'dev' => [
                'current_level' => 'L1',
                'allowed_files' => ['app/Services/Ai/Foo.php'],
            ],
        ], []);

        $packets = $this->indexByDepartment($plan['missing_packets']);
        $devPacket = $packets['dev'];

        $this->assertFalse($devPacket['executable']);
        $this->assertSame('', $devPacket['test_command']);
        $this->assertContains('test_command_missing', $devPacket['blockers']);
    }

    public function testDepartmentMarksL4OnlyWithComputedEvidence(): void
    {
        $plan = (new AaeosDepartmentL4ClosurePlanner())->plan([
            'forge' => [
                'current_level' => 'L4',
                'evidence' => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#forge'],
            ],
        ], []);

        $departments = $this->indexByDepartment($plan['departments']);
        $forge = $departments['forge'];

        $this->assertTrue($forge['marked_l4']);
        $this->assertTrue($forge['has_computed_evidence']);
        $this->assertFalse($forge['needs_packet']);
        $this->assertSame(0, $forge['level_gap']);
        $this->assertContains('forge', $plan['marked_l4_departments']);
        $this->assertNotContains('forge', $plan['departments_needing_packets']);

        // The forge evidence is surfaced as a computed plan-level evidence ref.
        $this->assertContains(
            'forge:docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#forge',
            $plan['evidence_refs'],
        );
    }

    public function testDepartmentCannotMarkL4WithoutComputedEvidenceEvenAtLevelL4(): void
    {
        $plan = (new AaeosDepartmentL4ClosurePlanner())->plan([
            'forge' => [
                'current_level' => 'L4',
                'evidence' => [],
            ],
        ], []);

        $departments = $this->indexByDepartment($plan['departments']);
        $forge = $departments['forge'];

        // Level says L4 but there is no computed evidence => not marked, still needs packet.
        $this->assertFalse($forge['marked_l4']);
        $this->assertFalse($forge['has_computed_evidence']);
        $this->assertTrue($forge['needs_packet']);
        $this->assertNotContains('forge', $plan['marked_l4_departments']);

        $packets = $this->indexByDepartment($plan['missing_packets']);
        $this->assertArrayHasKey('forge', $packets);
        $this->assertContains('computed_evidence_missing', $packets['forge']['blockers']);
    }

    public function testMaturityProseDoesNotCountAsL4(): void
    {
        // DoD: Department L4 is computed; maturity prose does not count.
        $plan = (new AaeosDepartmentL4ClosurePlanner())->plan([
            'security' => [
                'current_level' => 'L3',
                'maturity_prose' => 'security is effectively L4 across all flows',
            ],
        ], []);

        $departments = $this->indexByDepartment($plan['departments']);
        $security = $departments['security'];

        $this->assertFalse($security['marked_l4']);
        $this->assertTrue($security['needs_packet']);
        $this->assertSame('L3', $security['current_level']);
        $this->assertSame(1, $security['level_gap']);
        $this->assertNotContains('security', $plan['marked_l4_departments']);
    }

    public function testQualityBarMetIsComputedFromQualityBarInput(): void
    {
        $plan = (new AaeosDepartmentL4ClosurePlanner())->plan(
            [
                'dev' => [
                    'current_level' => 'L1',
                    'allowed_files' => ['app/Services/Ai/Foo.php'],
                    'test_command' => 'php artisan test --filter=FooTest',
                ],
                'qa' => ['current_level' => 'L2'],
            ],
            [
                'dev' => ['latency_p95_ok' => true, 'tests_pass_ok' => true, 'scope_violation_zero' => true],
                'qa' => ['contract_tests_ok' => true, 'regression_catch_ok' => false],
            ],
        );

        $departments = $this->indexByDepartment($plan['departments']);
        $packets = $this->indexByDepartment($plan['missing_packets']);

        $this->assertTrue($departments['dev']['quality_bar_met']);
        $this->assertNotContains('quality_bar_unmet', $packets['dev']['blockers']);

        $this->assertFalse($departments['qa']['quality_bar_met']);
        $this->assertContains('quality_bar_unmet', $packets['qa']['blockers']);
    }

    public function testAllElevenDepartmentsAtL4WithEvidenceClosesThePlan(): void
    {
        $maturity = [];
        foreach (['product', 'architect', 'research', 'dev', 'debug', 'review', 'qa', 'security', 'forge', 'delivery', 'memory'] as $departmentId) {
            $maturity[$departmentId] = [
                'current_level' => 'L5',
                'evidence' => ['evidence/'.$departmentId.'-l4-proof'],
            ];
        }

        $plan = (new AaeosDepartmentL4ClosurePlanner())->plan($maturity, []);

        $this->assertSame([], $plan['missing_packets']);
        $this->assertTrue($plan['all_departments_l4']);
        $this->assertCount(11, $plan['marked_l4_departments']);
        $this->assertSame([], $plan['departments_needing_packets']);

        $departments = $this->indexByDepartment($plan['departments']);
        // Level above L4 keeps gap clamped at 0 (never negative).
        $this->assertSame(0, $departments['forge']['level_gap']);
        $this->assertSame('L5', $departments['forge']['current_level']);
    }
}
