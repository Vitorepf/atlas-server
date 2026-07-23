<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Aaeos;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves AtlasDepartmentLevelClassifier is wired into a real call path: the
 * atlas:aaeos:department-status command now runs it per department alongside the existing
 * quality-bar band classification. It is no longer an orphan.
 */
final class AaeosDepartmentLevelClassifierWiringWiredTest extends TestCase
{
    public function test_department_level_classification_is_present_in_output(): void
    {
        Artisan::call('atlas:aaeos:department-status', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('department_level_classification', $decoded);
        $this->assertSame(
            'atlas.aaeos.department_level_classification_batch.v1',
            $decoded['department_level_classification']['schema_version'],
        );
        $this->assertArrayHasKey('departments', $decoded['department_level_classification']);
    }

    public function test_each_classified_department_carries_earned_level_shape(): void
    {
        Artisan::call('atlas:aaeos:department-status', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $departments = (array) $decoded['department_level_classification']['departments'];
        foreach ($departments as $departmentId => $result) {
            $this->assertSame(
                'atlas.aaeos.department_level_classification.v1',
                $result['schema_version'],
                "department {$departmentId} missing classifier schema",
            );
            $this->assertArrayHasKey('earned_level', $result);
            $this->assertArrayHasKey('all_bands_satisfied', $result);
            $this->assertArrayHasKey('capping_metric', $result);
        }
    }
}
