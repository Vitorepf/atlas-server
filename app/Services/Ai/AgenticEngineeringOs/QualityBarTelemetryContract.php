<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * M5 · minimal data contract for quality-bar telemetry before the auto-blocking
 * immune gate wires into AtlasUniversalGatesEvaluator. Step-1 shape only — no
 * runtime wiring.
 */
final class QualityBarTelemetryContract
{
    public const FIELD_AUTO_BLOCK_ON_BREACH = 'auto_block_on_breach';
    public const FIELD_EVIDENCE_REQUIRED = 'evidence_required';
    public const SCHEMA = 'atlas.aaeos.quality_bar_telemetry.v1';

    public const QUALITY_BAR_SCHEMA = 'atlas.aaeos.quality_bar.v1';

    /** Immune gate id that pauses the loop when quality-bar breaches accumulate. */
    public const IMMUNE_GATE_ID = 'quality_bar_auto_block';

    /** Observability signal emitted on threshold breach (see T3.3 quality bar matrix). */
    public const BREACH_SIGNAL = 'dept_quality_bar_breach_count';

    public const CANONICAL_SOURCE = 'atlas.aaeos.quality_bar';

    public const EVALUATED_WINDOW_DAYS = 30;

    public const AUTO_BLOCK_ON_BREACH = true;
    public const FIELD_EVALUATED_WINDOW_DAYS = 'evaluated_window_days';
    public const FIELD_DEPARTMENT_ID = 'department_id';
    public const FIELD_BREACH_COUNT = 'breach_count';
    public const FIELD_EVIDENCE_HASH = 'evidence_hash';
    public const FIELD_THRESHOLD_BREACHES = 'threshold_breaches';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_QUALITY_BAR_SCHEMA = 'quality_bar_schema';
    public const FIELD_IMMUNE_GATE_ID = 'immune_gate_id';
    public const FIELD_BREACH_SIGNAL = 'breach_signal';
    public const FIELD_CANONICAL_SOURCE = 'canonical_source';
    public const FIELD_INPUTS = 'inputs';
    public const FIELD_TELEMETRY_FIELDS = 'telemetry_fields';
    public const FIELD_BREACH_METRICS = 'breach_metrics';
    public const FIELD_EVALUATED_AT = 'evaluated_at';
    public const FIELD_QUALITY_BAR_REPORT_HASH = 'quality_bar_report_hash';

    /**
     * Gate evidence required — not boolean-only pass/fail.
     *
     * @var list<string>
     */
    public const EVIDENCE_REQUIRED = [
        self::FIELD_QUALITY_BAR_REPORT_HASH,
        self::FIELD_BREACH_METRICS,
        self::FIELD_EVALUATED_AT,
    ];

    /**
     * Telemetry payload fields surfaced to the immune gate evaluator.
     *
     * @var list<string>
     */
    public const TELEMETRY_FIELDS = [
        'department_id',
        self::FIELD_BREACH_COUNT,
        'threshold_breaches',
        self::FIELD_EVALUATED_WINDOW_DAYS,
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
        $breaches = AiValueNormalizer::arrayOrEmpty($input[self::FIELD_THRESHOLD_BREACHES] ?? null);

        return new self(
            departmentId: AiValueNormalizer::trimmedStringOrNull($input[self::FIELD_DEPARTMENT_ID] ?? null) ?? '',
            breachCount: max(0, (int) (AiValueNormalizer::finiteFloatOrNull($input[self::FIELD_BREACH_COUNT] ?? null) ?? 0)),
            evaluatedWindowDays: max(1, (int) (AiValueNormalizer::finiteFloatOrNull($input[self::FIELD_EVALUATED_WINDOW_DAYS] ?? null) ?? self::EVALUATED_WINDOW_DAYS)),
            evidenceHash: AiValueNormalizer::trimmedStringOrNull($input[self::FIELD_EVIDENCE_HASH] ?? null) ?? '',
            thresholdBreaches: array_values($breaches),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA,
            self::FIELD_QUALITY_BAR_SCHEMA => self::QUALITY_BAR_SCHEMA,
            self::FIELD_IMMUNE_GATE_ID => self::IMMUNE_GATE_ID,
            self::FIELD_BREACH_SIGNAL => self::BREACH_SIGNAL,
            self::FIELD_CANONICAL_SOURCE => self::CANONICAL_SOURCE,
            self::FIELD_EVALUATED_WINDOW_DAYS => self::EVALUATED_WINDOW_DAYS,
            self::FIELD_AUTO_BLOCK_ON_BREACH => self::AUTO_BLOCK_ON_BREACH,
            self::FIELD_EVIDENCE_REQUIRED => self::EVIDENCE_REQUIRED,
            self::FIELD_TELEMETRY_FIELDS => self::TELEMETRY_FIELDS,
            self::FIELD_INPUTS => [
                self::FIELD_DEPARTMENT_ID => $this->departmentId,
                self::FIELD_BREACH_COUNT => $this->breachCount,
                self::FIELD_EVALUATED_WINDOW_DAYS => $this->evaluatedWindowDays,
                self::FIELD_EVIDENCE_HASH => $this->evidenceHash,
                self::FIELD_THRESHOLD_BREACHES => $this->thresholdBreaches,
            ],
        ];
    }
}
