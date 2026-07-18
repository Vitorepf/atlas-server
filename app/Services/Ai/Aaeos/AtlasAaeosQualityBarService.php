<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

use App\Services\Ai\Support\AiValueNormalizer;

final class AtlasAaeosQualityBarService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.quality_bar.v1';


    public const FIELD_DEPARTMENT = 'department';

    public const FIELD_THRESHOLD = 'threshold';

    public const FIELD_CURRENT = 'current';

    public const FIELD_BREACHED = 'breached';

    public const FIELD_DEFICIT = 'deficit';

    public const FIELD_SCHEMA_VERSION = 'schema_version';

    public const FIELD_DEPARTMENTS = 'departments';

    public const FIELD_SIGNAL = 'signal';

    public const FIELD_BREACH_COUNT = 'breach_count';

    public const FIELD_BREACHES = 'breaches';

    public const FIELD_WORST_BREACH = 'worst_breach';

    public const FIELD_EMITTED_AT = 'emitted_at';
    public const FIELD_DESIGN = 'Design';
    public const FIELD_ENGINEERING = 'Engineering';
    public const FIELD_FINANCE = 'Finance';
    public const FIELD_LEGAL = 'Legal';
    public const FIELD_MARKETING = 'Marketing';
    public const FIELD_OPERATIONS = 'Operations';
    public const FIELD_PRODUCT = 'Product';
    public const FIELD_RESEARCH = 'Research';
    public const FIELD_SALES = 'Sales';

    public const DEPARTMENT_DATA = [
        [self::FIELD_DEPARTMENT => self::FIELD_ENGINEERING, self::FIELD_THRESHOLD => 0.85, self::FIELD_CURRENT => 0.92],
        [self::FIELD_DEPARTMENT => self::FIELD_PRODUCT, self::FIELD_THRESHOLD => 0.80, self::FIELD_CURRENT => 0.75],
        [self::FIELD_DEPARTMENT => self::FIELD_DESIGN, self::FIELD_THRESHOLD => 0.80, self::FIELD_CURRENT => 0.88],
        [self::FIELD_DEPARTMENT => self::FIELD_MARKETING, self::FIELD_THRESHOLD => 0.75, self::FIELD_CURRENT => 0.70],
        [self::FIELD_DEPARTMENT => self::FIELD_SALES, self::FIELD_THRESHOLD => 0.80, self::FIELD_CURRENT => 0.85],
        [self::FIELD_DEPARTMENT => self::FIELD_OPERATIONS, self::FIELD_THRESHOLD => 0.78, self::FIELD_CURRENT => 0.72],
        [self::FIELD_DEPARTMENT => self::FIELD_FINANCE, self::FIELD_THRESHOLD => 0.82, self::FIELD_CURRENT => 0.90],
        [self::FIELD_DEPARTMENT => 'Human Resources', self::FIELD_THRESHOLD => 0.75, self::FIELD_CURRENT => 0.68],
        [self::FIELD_DEPARTMENT => self::FIELD_LEGAL, self::FIELD_THRESHOLD => 0.85, self::FIELD_CURRENT => 0.79],
        [self::FIELD_DEPARTMENT => 'Customer Success', self::FIELD_THRESHOLD => 0.80, self::FIELD_CURRENT => 0.81],
        [self::FIELD_DEPARTMENT => self::FIELD_RESEARCH, self::FIELD_THRESHOLD => 0.77, self::FIELD_CURRENT => 0.74],
    ];

    public function qualityBar(): array
    {
        $departments = [];
        foreach (self::DEPARTMENT_DATA as $data) {
            $threshold = AiValueNormalizer::finiteFloatOrNull($data[self::FIELD_THRESHOLD]) ?? 0.0;
            $current = AiValueNormalizer::finiteFloatOrNull($data[self::FIELD_CURRENT]) ?? 0.0;
            $departments[] = [
                self::FIELD_DEPARTMENT => AiValueNormalizer::trimmedStringOrNull($data[self::FIELD_DEPARTMENT]) ?? '',
                self::FIELD_THRESHOLD => $threshold,
                self::FIELD_CURRENT => $current,
                self::FIELD_BREACHED => $current < $threshold,
                self::FIELD_DEFICIT => $current < $threshold ? round($threshold - $current, 4) : 0.0,
            ];
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_DEPARTMENTS => $departments,
            self::FIELD_SIGNAL => $this->emitSignal(),
        ];
    }

    public function emitSignal(): array
    {
        $breaches = $this->collectBreaches();

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_BREACH_COUNT => count($breaches),
            self::FIELD_BREACHES => $breaches,
            self::FIELD_WORST_BREACH => $breaches[0] ?? null,
            self::FIELD_EMITTED_AT => gmdate('c'),
        ];
    }

    private function collectBreaches(): array
    {
        $breaches = [];
        foreach (self::DEPARTMENT_DATA as $data) {
            $threshold = AiValueNormalizer::finiteFloatOrNull($data[self::FIELD_THRESHOLD]) ?? 0.0;
            $current = AiValueNormalizer::finiteFloatOrNull($data[self::FIELD_CURRENT]) ?? 0.0;
            if ($current < $threshold) {
                $breaches[] = [
                    self::FIELD_DEPARTMENT => AiValueNormalizer::trimmedStringOrNull($data[self::FIELD_DEPARTMENT]) ?? '',
                    self::FIELD_THRESHOLD => $threshold,
                    self::FIELD_CURRENT => $current,
                    self::FIELD_BREACHED => true,
                    self::FIELD_DEFICIT => round($threshold - $current, 4),
                ];
            }
        }

        usort(
            $breaches,
            static fn (array $left, array $right): int => ($right[self::FIELD_DEFICIT] <=> $left[self::FIELD_DEFICIT])
                ?: ($left[self::FIELD_DEPARTMENT] <=> $right[self::FIELD_DEPARTMENT])
        );

        return $breaches;
    }
}
