<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

use App\Services\Ai\Support\AiValueNormalizer;

final class AtlasAaeosQualityBarService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.quality_bar.v1';

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
            $threshold = AiValueNormalizer::finiteFloatOrNull($data['threshold']) ?? 0.0;
            $current = AiValueNormalizer::finiteFloatOrNull($data['current']) ?? 0.0;
            $departments[] = [
                'department' => AiValueNormalizer::trimmedStringOrNull($data['department']) ?? '',
                'threshold' => $threshold,
                'current' => $current,
                'breached' => $current < $threshold,
                'deficit' => $current < $threshold ? round($threshold - $current, 4) : 0.0,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'departments' => $departments,
            'signal' => $this->emitSignal(),
        ];
    }

    public function emitSignal(): array
    {
        $breaches = $this->collectBreaches();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'breach_count' => count($breaches),
            'breaches' => $breaches,
            'worst_breach' => $breaches[0] ?? null,
            'emitted_at' => gmdate('c'),
        ];
    }

    private function collectBreaches(): array
    {
        $breaches = [];
        foreach (self::DEPARTMENT_DATA as $data) {
            $threshold = AiValueNormalizer::finiteFloatOrNull($data['threshold']) ?? 0.0;
            $current = AiValueNormalizer::finiteFloatOrNull($data['current']) ?? 0.0;
            if ($current < $threshold) {
                $breaches[] = [
                    'department' => AiValueNormalizer::trimmedStringOrNull($data['department']) ?? '',
                    'threshold' => $threshold,
                    'current' => $current,
                    'breached' => true,
                    'deficit' => round($threshold - $current, 4),
                ];
            }
        }

        usort(
            $breaches,
            static fn (array $left, array $right): int => ($right['deficit'] <=> $left['deficit'])
                ?: ($left['department'] <=> $right['department'])
        );

        return $breaches;
    }
}
