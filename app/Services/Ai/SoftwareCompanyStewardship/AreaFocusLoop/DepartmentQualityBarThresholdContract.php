<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\AgenticEngineeringOs\QualityBarTelemetryContract;

/**
 * Minimal data contract for department quality bar L3 thresholds in
 * {@see LongRunCertificationLadderService}. Step 1 of 3: shape only — no
 * ladder service wiring in this class.
 */
final class DepartmentQualityBarThresholdContract
{
    public const SCHEMA = 'atlas.software_company_stewardship.department_quality_bar_threshold.v1';

    public const QUALITY_BAR_MATRIX_CANONICAL = 'docs/engineering-knowledge-base/atlas-aaeos-department-quality-bar-matrix.md';

    public const QUALITY_BAR_SCHEMA = QualityBarTelemetryContract::QUALITY_BAR_SCHEMA;

    public const BREACH_SIGNAL = QualityBarTelemetryContract::BREACH_SIGNAL;

    /** Long-run certification ladder promotion floor from the canonical quality bar matrix. */
    public const PROMOTION_FLOOR_LEVEL = 'L3';

    public const EVALUATED_WINDOW_DAYS = QualityBarTelemetryContract::EVALUATED_WINDOW_DAYS;

    public const BLOCKER_DEPT_QUALITY_BAR_L3_BREACH = 'dept_quality_bar_l3_threshold_breach';

    /** Step 1: contract shape only — ladder service does not consume this gate yet. */
    public const INFORMATIONAL_SIGNAL_ONLY = true;

    /**
     * L3 metric floors from the canonical quality bar matrix (Dev + Forge snapshot).
     *
     * @var array<string, list<array{metric: string, comparator: string, value: float, unit: string}>>
     */
    public const L3_THRESHOLDS = [
        'dev' => [
            ['metric' => 'latency_p95', 'comparator' => '<=', 'value' => 60.0, 'unit' => 'seconds'],
            ['metric' => 'tests_pass_rate', 'comparator' => '>=', 'value' => 0.96, 'unit' => 'ratio'],
            ['metric' => 'scope_violation_rate', 'comparator' => '<=', 'value' => 0.01, 'unit' => 'ratio'],
            ['metric' => 'repair_loop_avg', 'comparator' => '<=', 'value' => 1.0, 'unit' => 'cycles'],
        ],
        'forge' => [
            ['metric' => 'obra_completion_rate', 'comparator' => '>=', 'value' => 0.85, 'unit' => 'ratio'],
            ['metric' => 'cert_pass_rate', 'comparator' => '>=', 'value' => 0.93, 'unit' => 'ratio'],
            ['metric' => 'rollback_rate', 'comparator' => '<=', 'value' => 0.04, 'unit' => 'ratio'],
            ['metric' => 'multi_agent_collision_rate', 'comparator' => '<=', 'value' => 0.02, 'unit' => 'ratio'],
        ],
    ];

    /**
     * @param  array<string, array<string, float>>  $departmentMetricsSnapshot
     */
    private function __construct(
        public readonly string $areaId,
        public readonly string $focus,
        public readonly array $departmentMetricsSnapshot,
    ) {}

    public static function defaults(
        string $areaId = 'agentic_engineering_os',
        string $focus = 'dev_forge',
    ): self {
        return new self(
            areaId: $areaId,
            focus: $focus,
            departmentMetricsSnapshot: [],
        );
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $snapshot = [];
        foreach ((array) ($input['department_metrics_snapshot'] ?? []) as $departmentId => $metrics) {
            if (! is_string($departmentId) || ! is_array($metrics)) {
                continue;
            }

            $normalizedMetrics = [];
            foreach ($metrics as $metric => $value) {
                if (! is_string($metric) || ! is_numeric($value)) {
                    continue;
                }

                $normalizedMetrics[trim($metric)] = (float) $value;
            }

            $snapshot[trim($departmentId)] = $normalizedMetrics;
        }

        return new self(
            areaId: trim((string) ($input['area_id'] ?? 'agentic_engineering_os')),
            focus: trim((string) ($input['focus'] ?? 'dev_forge')),
            departmentMetricsSnapshot: $snapshot,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $thresholdBreaches = $this->evaluateThresholdBreaches();
        $breachCount = count($thresholdBreaches);
        $wouldBlockLadder = $breachCount > 0;

        return [
            'schema_version' => self::SCHEMA,
            'quality_bar_schema' => self::QUALITY_BAR_SCHEMA,
            'quality_bar_matrix_canonical' => self::QUALITY_BAR_MATRIX_CANONICAL,
            'breach_signal' => self::BREACH_SIGNAL,
            'promotion_floor_level' => self::PROMOTION_FLOOR_LEVEL,
            'evaluated_window_days' => self::EVALUATED_WINDOW_DAYS,
            'informational_signal_only' => self::INFORMATIONAL_SIGNAL_ONLY,
            'l3_thresholds' => self::L3_THRESHOLDS,
            'area_id' => $this->areaId,
            'focus' => $this->focus,
            'inputs' => [
                'department_metrics_snapshot' => $this->departmentMetricsSnapshot,
            ],
            'outputs' => [
                'breach_count' => $breachCount,
                'threshold_breaches' => $thresholdBreaches,
                'would_block_ladder_promotion' => $wouldBlockLadder,
                'blocks_ladder_promotion' => self::INFORMATIONAL_SIGNAL_ONLY ? false : $wouldBlockLadder,
                'blocker_id' => $wouldBlockLadder ? self::BLOCKER_DEPT_QUALITY_BAR_L3_BREACH : null,
            ],
        ];
    }

    /**
     * @return list<array{department_id: string, metric: string, comparator: string, threshold: float, observed: float|null, unit: string}>
     */
    private function evaluateThresholdBreaches(): array
    {
        $breaches = [];

        foreach (self::L3_THRESHOLDS as $departmentId => $thresholds) {
            $observedMetrics = $this->departmentMetricsSnapshot[$departmentId] ?? [];

            foreach ($thresholds as $threshold) {
                $metric = $threshold['metric'];
                $observed = $observedMetrics[$metric] ?? null;

                if ($observed === null || ! $this->meetsThreshold($observed, $threshold['comparator'], $threshold['value'])) {
                    $breaches[] = [
                        'department_id' => $departmentId,
                        'metric' => $metric,
                        'comparator' => $threshold['comparator'],
                        'threshold' => $threshold['value'],
                        'observed' => $observed,
                        'unit' => $threshold['unit'],
                    ];
                }
            }
        }

        return $breaches;
    }

    private function meetsThreshold(float $observed, string $comparator, float $threshold): bool
    {
        return match ($comparator) {
            '>=' => $observed + 1e-9 >= $threshold,
            '<=' => $observed - 1e-9 <= $threshold,
            default => false,
        };
    }
}
