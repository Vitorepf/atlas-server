<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

final class AtlasAaeosQualityBarService
{
    private const SCHEMA_VERSION = 'atlas.aaeos.quality_bar.v1';

    private const DEPARTMENT_DATA = [
        ['department' => 'Engineering', 'threshold' => 0.85, 'current' => 0.92],
        ['department' => 'Product', 'threshold' => 0.80, 'current' => 0.75],
        ['department' => 'Design', 'threshold' => 0.80, 'current' => 0.88],
        ['department' => 'Marketing', 'threshold' => 0.75, 'current' => 0.70],
        ['department' => 'Sales', 'threshold' => 0.80, 'current' => 0.85],
        ['department' => 'Operations', 'threshold' => 0.78, 'current' => 0.72],
        ['department' => 'Finance', 'threshold' => 0.82, 'current' => 0.90],
        ['department' => 'Human Resources', 'threshold' => 0.75, 'current' => 0.68],
        ['department' => 'Legal', 'threshold' => 0.85, 'current' => 0.79],
        ['department' => 'Customer Success', 'threshold' => 0.80, 'current' => 0.81],
        ['department' => 'Research', 'threshold' => 0.77, 'current' => 0.74],
    ];

    public function qualityBar(): array
    {
        $departments = [];
        foreach (self::DEPARTMENT_DATA as $data) {
            $departments[] = [
                'department' => $data['department'],
                'threshold' => (float) $data['threshold'],
                'current' => (float) $data['current'],
                'breached' => $data['current'] < $data['threshold'],
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'departments' => $departments,
        ];
    }
}