<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\AgenticEngineeringOs\QualityBarTelemetryContract;

/**
 * Minimal data contract for the quality-bar breach auto-block gate in
 * {@see StewardshipAutonomyEnvelopeService}. Step 1 of 3: shape only — no
 * envelope service wiring in this class.
 */
final class QualityBarBreachAutoBlockGateContract
{
    public const SCHEMA = 'atlas.software_company_stewardship.quality_bar_breach_auto_block_gate.v1';

    public const QUALITY_BAR_MATRIX_CANONICAL = 'docs/engineering-knowledge-base/atlas-aaeos-department-quality-bar-matrix.md';

    public const IMMUNE_GATE_ID = QualityBarTelemetryContract::IMMUNE_GATE_ID;

    public const BREACH_SIGNAL = QualityBarTelemetryContract::BREACH_SIGNAL;

    public const AUTO_BLOCK_ON_BREACH = QualityBarTelemetryContract::AUTO_BLOCK_ON_BREACH;

    /**
     * Matrix rule: promotion blocked on ANY metric breach — block when count exceeds this floor.
     */
    public const MAX_ALLOWED_DEPT_QUALITY_BAR_BREACH_COUNT = 0;

    private function __construct(
        public readonly string $areaId,
        public readonly string $focus,
        public readonly string $departmentId,
        public readonly int $breachCount,
        public readonly int $evaluatedWindowDays,
    ) {}

    public static function defaults(
        string $areaId = 'agentic_engineering_os',
        string $focus = 'dev_forge',
    ): self {
        return new self(
            areaId: $areaId,
            focus: $focus,
            departmentId: '',
            breachCount: 0,
            evaluatedWindowDays: QualityBarTelemetryContract::EVALUATED_WINDOW_DAYS,
        );
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            areaId: trim((string) ($input['area_id'] ?? 'agentic_engineering_os')),
            focus: trim((string) ($input['focus'] ?? 'dev_forge')),
            departmentId: trim((string) ($input['department_id'] ?? '')),
            breachCount: max(0, (int) ($input['breach_count'] ?? 0)),
            evaluatedWindowDays: max(
                1,
                (int) ($input['evaluated_window_days'] ?? QualityBarTelemetryContract::EVALUATED_WINDOW_DAYS),
            ),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $blocks = self::AUTO_BLOCK_ON_BREACH
            && $this->breachCount > self::MAX_ALLOWED_DEPT_QUALITY_BAR_BREACH_COUNT;

        return [
            'schema_version' => self::SCHEMA,
            'immune_gate_id' => self::IMMUNE_GATE_ID,
            'breach_signal' => self::BREACH_SIGNAL,
            'auto_block_on_breach' => self::AUTO_BLOCK_ON_BREACH,
            'quality_bar_matrix_canonical' => self::QUALITY_BAR_MATRIX_CANONICAL,
            'max_allowed_dept_quality_bar_breach_count' => self::MAX_ALLOWED_DEPT_QUALITY_BAR_BREACH_COUNT,
            'area_id' => $this->areaId,
            'focus' => $this->focus,
            'inputs' => [
                'department_id' => $this->departmentId,
                'breach_count' => $this->breachCount,
                'evaluated_window_days' => $this->evaluatedWindowDays,
            ],
            'outputs' => [
                'blocks_24h_autonomy' => $blocks,
                'blocker_id' => $blocks ? self::IMMUNE_GATE_ID : null,
            ],
        ];
    }
}
