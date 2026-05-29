<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

/**
 * M5 · minimal data contract for quality-bar telemetry before the auto-blocking
 * immune gate wires into AtlasUniversalGatesEvaluator. Step-1 shape only — no
 * runtime wiring.
 */
final class QualityBarTelemetryContract
{
    public const SCHEMA = 'atlas.aaeos.quality_bar_telemetry.v1';

    public const QUALITY_BAR_SCHEMA = 'atlas.aaeos.quality_bar.v1';

    /** Immune gate id that pauses the loop when quality-bar breaches accumulate. */
    public const IMMUNE_GATE_ID = 'quality_bar_auto_block';

    /** Observability signal emitted on threshold breach (see T3.3 quality bar matrix). */
    public const BREACH_SIGNAL = 'dept_quality_bar_breach_count';

    public const CANONICAL_SOURCE = 'atlas.aaeos.quality_bar';

    public const EVALUATED_WINDOW_DAYS = 30;

    public const AUTO_BLOCK_ON_BREACH = true;

    /**
     * Gate evidence required — not boolean-only pass/fail.
     *
     * @var list<string>
     */
    public const EVIDENCE_REQUIRED = [
        'quality_bar_report_hash',
        'breach_metrics',
        'evaluated_at',
    ];

    /**
     * Telemetry payload fields surfaced to the immune gate evaluator.
     *
     * @var list<string>
     */
    public const TELEMETRY_FIELDS = [
        'department_id',
        'breach_count',
        'threshold_breaches',
        'evaluated_window_days',
        'evidence_hash',
    ];

    /**
     * @param  list<array{metric:string,comparator:string,value:float,observed:float,unit:string}>  $thresholdBreaches
     */
    private function __construct(
        public readonly string $departmentId,
        public readonly int $breachCount,
        public readonly int $evaluatedWindowDays,
        public readonly string $evidenceHash,
        public readonly array $thresholdBreaches,
    ) {}

    public static function defaults(): self
    {
        return new self(
            departmentId: '',
            breachCount: 0,
            evaluatedWindowDays: self::EVALUATED_WINDOW_DAYS,
            evidenceHash: '',
            thresholdBreaches: [],
        );
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $breaches = $input['threshold_breaches'] ?? [];
        if (! is_array($breaches)) {
            $breaches = [];
        }

        return new self(
            departmentId: trim((string) ($input['department_id'] ?? '')),
            breachCount: max(0, (int) ($input['breach_count'] ?? 0)),
            evaluatedWindowDays: max(1, (int) ($input['evaluated_window_days'] ?? self::EVALUATED_WINDOW_DAYS)),
            evidenceHash: trim((string) ($input['evidence_hash'] ?? '')),
            thresholdBreaches: array_values($breaches),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'quality_bar_schema' => self::QUALITY_BAR_SCHEMA,
            'immune_gate_id' => self::IMMUNE_GATE_ID,
            'breach_signal' => self::BREACH_SIGNAL,
            'canonical_source' => self::CANONICAL_SOURCE,
            'evaluated_window_days' => self::EVALUATED_WINDOW_DAYS,
            'auto_block_on_breach' => self::AUTO_BLOCK_ON_BREACH,
            'evidence_required' => self::EVIDENCE_REQUIRED,
            'telemetry_fields' => self::TELEMETRY_FIELDS,
            'inputs' => [
                'department_id' => $this->departmentId,
                'breach_count' => $this->breachCount,
                'evaluated_window_days' => $this->evaluatedWindowDays,
                'evidence_hash' => $this->evidenceHash,
                'threshold_breaches' => $this->thresholdBreaches,
            ],
        ];
    }
}
