<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog\Checks;

use App\Services\Ai\Cognition\Watchdog\AtlasAcosWatchdogHealthService;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;
use InvalidArgumentException;

/**
 * Descriptor-backed ACOS health watchdog check.
 *
 * Collapses the former one-class-per-method pass-through wrappers that all did:
 * health->{reportMethod}() → toCheckResult(report, alertCode, message).
 * IDs, alert codes, and messages are preserved byte-for-byte.
 */
final readonly class HealthReportWatchdogCheck implements AtlasWatchdogCheck
{
    /**
     * Canonical catalog of former thin health wrappers (ELEV-31: remove a layer).
     *
     * @var list<array{id:string, report_method:string, alert_code:string, message:string}>
     */
    public const FIELD_ID = 'id';
    public const FIELD_REPORT_METHOD = 'report_method';
    public const FIELD_ALERT_CODE = 'alert_code';
    public const FIELD_MESSAGE = 'message';
    public const FIELD_AURG_COVERAGE_GATE_FAILED = 'aurg_coverage_gate_failed';
    public const FIELD_COMPACTION_SOAK_NOT_READY = 'compaction_soak_not_ready';
    public const FIELD_CONTEXT_FEEDBACK_HEALTH_FAILED = 'context_feedback_health_failed';
    public const FIELD_ENGINEERING_ENFORCE_READINESS_NOT_READY = 'engineering_enforce_readiness_not_ready';
    public const FIELD_LEARNING_CADENCE_STALLED = 'learning_cadence_stalled';
    public const FIELD_LIFT_CYCLE_CLOSURE_STALLED = 'lift_cycle_closure_stalled';
    public const FIELD_MEMORY_QUALITY_CHECK_FAILED = 'memory_quality_check_failed';
    public const FIELD_RAG_DIMENSION_WATCHDOG_FAILED = 'rag_dimension_watchdog_failed';
    public const FIELD_SCORECARD_RECEIPTS_DIAGNOSIS_FAILED = 'scorecard_receipts_diagnosis_failed';
    public const FIELD_SCORECARD_STABILITY_FAILED = 'scorecard_stability_failed';
    public const FIELD_AURG_COVERAGE_REPORT = 'aurgCoverageReport';
    public const FIELD_COMPACTION_SOAK_WATCH_REPORT = 'compactionSoakWatchReport';
    public const FIELD_CONTEXT_FEEDBACK_HEALTH_REPORT = 'contextFeedbackHealthReport';
    public const FIELD_ENGINEERING_ENFORCE_READINESS_REPORT = 'engineeringEnforceReadinessReport';
    public const FIELD_LEARNING_CADENCE_REPORT = 'learningCadenceReport';
    public const FIELD_LIFT_CYCLE_CLOSURE_REPORT = 'liftCycleClosureReport';
    public const FIELD_MEMORY_QUALITY_CHECK = 'memoryQualityCheck';
    public const FIELD_PIPELINE_STABILITY_REPORT = 'pipelineStabilityReport';
    public const FIELD_RAG_DIMENSION_REPORT = 'ragDimensionReport';
    public const FIELD_SCORECARD_RECEIPTS_DIAGNOSIS_REPORT = 'scorecardReceiptsDiagnosisReport';
    public const FIELD_COM_10_CONTEXT_FEEDBACK_HEALTH = 'com-10.context_feedback_health';
    public const FIELD_CPT_09_COMPACTION_SOAK = 'cpt-09.compaction_soak';
    public const FIELD_ENG_11_ENFORCE_READINESS = 'eng-11.enforce_readiness';
    public const FIELD_FEE_13_LEARNING_CADENCE = 'fee-13.learning_cadence';
    public const FIELD_MEM_09_MEMORY_QUALITY = 'mem-09.memory_quality';
    public const FIELD_OPE_08_LIFT_CYCLE_CLOSURE = 'ope-08.lift_cycle_closure';
    public const FIELD_OPE_10_SCORECARD_RECEIPTS_DIAGNOSIS = 'ope-10.scorecard_receipts_diagnosis';
    public const FIELD_PIP_08_SCORECARD_STABILITY = 'pip-08.scorecard_stability';
    public const FIELD_RAG_10_AURG_COVERAGE = 'rag-10.aurg_coverage';
    public const FIELD_RAG_12_RAG_DIMENSION = 'rag-12.rag_dimension';
    public const FIELD_COM_10_CONTEXT_FEEDBACK_HEALTH_IS_BELOW_THE_PINNED_FLOOR_ = 'COM-10 context feedback health is below the pinned floor.';
    public const FIELD_CPT_09_COMPACTION_SOAK_IS_NOT_READY_FOR_ENFORCE_ = 'CPT-09 compaction soak is not ready for enforce.';

    public const CATALOG = [
        [
            self::FIELD_ID => self::FIELD_MEM_09_MEMORY_QUALITY,
            self::FIELD_REPORT_METHOD => self::FIELD_MEMORY_QUALITY_CHECK,
            self::FIELD_ALERT_CODE => self::FIELD_MEMORY_QUALITY_CHECK_FAILED,
            self::FIELD_MESSAGE => 'MEM-09 memory quality watchdog is not green.',
        ],
        [
            self::FIELD_ID => self::FIELD_FEE_13_LEARNING_CADENCE,
            self::FIELD_REPORT_METHOD => self::FIELD_LEARNING_CADENCE_REPORT,
            self::FIELD_ALERT_CODE => self::FIELD_LEARNING_CADENCE_STALLED,
            self::FIELD_MESSAGE => 'FEE-13 learning cadence is stalled or under-evidenced.',
        ],
        [
            self::FIELD_ID => self::FIELD_RAG_10_AURG_COVERAGE,
            self::FIELD_REPORT_METHOD => self::FIELD_AURG_COVERAGE_REPORT,
            self::FIELD_ALERT_CODE => self::FIELD_AURG_COVERAGE_GATE_FAILED,
            self::FIELD_MESSAGE => 'RAG-10 AURG cross-layer coverage is below floor.',
        ],
        [
            self::FIELD_ID => self::FIELD_RAG_12_RAG_DIMENSION,
            self::FIELD_REPORT_METHOD => self::FIELD_RAG_DIMENSION_REPORT,
            self::FIELD_ALERT_CODE => self::FIELD_RAG_DIMENSION_WATCHDOG_FAILED,
            self::FIELD_MESSAGE => 'RAG-12 retrieval dimension watchdog found a regression or masking issue.',
        ],
        [
            self::FIELD_ID => self::FIELD_COM_10_CONTEXT_FEEDBACK_HEALTH,
            self::FIELD_REPORT_METHOD => self::FIELD_CONTEXT_FEEDBACK_HEALTH_REPORT,
            self::FIELD_ALERT_CODE => self::FIELD_CONTEXT_FEEDBACK_HEALTH_FAILED,
            self::FIELD_MESSAGE => self::FIELD_COM_10_CONTEXT_FEEDBACK_HEALTH_IS_BELOW_THE_PINNED_FLOOR_,
        ],
        [
            self::FIELD_ID => self::FIELD_CPT_09_COMPACTION_SOAK,
            self::FIELD_REPORT_METHOD => self::FIELD_COMPACTION_SOAK_WATCH_REPORT,
            self::FIELD_ALERT_CODE => self::FIELD_COMPACTION_SOAK_NOT_READY,
            self::FIELD_MESSAGE => self::FIELD_CPT_09_COMPACTION_SOAK_IS_NOT_READY_FOR_ENFORCE_,
        ],
        [
            self::FIELD_ID => self::FIELD_PIP_08_SCORECARD_STABILITY,
            self::FIELD_REPORT_METHOD => self::FIELD_PIPELINE_STABILITY_REPORT,
            self::FIELD_ALERT_CODE => self::FIELD_SCORECARD_STABILITY_FAILED,
            self::FIELD_MESSAGE => 'PIP-08 scorecard stability has not reached a green pipeline series.',
        ],
        [
            self::FIELD_ID => self::FIELD_OPE_08_LIFT_CYCLE_CLOSURE,
            self::FIELD_REPORT_METHOD => self::FIELD_LIFT_CYCLE_CLOSURE_REPORT,
            self::FIELD_ALERT_CODE => self::FIELD_LIFT_CYCLE_CLOSURE_STALLED,
            self::FIELD_MESSAGE => 'OPE-08 lift cycle blockers are not closing.',
        ],
        [
            self::FIELD_ID => self::FIELD_OPE_10_SCORECARD_RECEIPTS_DIAGNOSIS,
            self::FIELD_REPORT_METHOD => self::FIELD_SCORECARD_RECEIPTS_DIAGNOSIS_REPORT,
            self::FIELD_ALERT_CODE => self::FIELD_SCORECARD_RECEIPTS_DIAGNOSIS_FAILED,
            self::FIELD_MESSAGE => 'OPE-10 found persistent partial scorecard receipts.',
        ],
        [
            self::FIELD_ID => self::FIELD_ENG_11_ENFORCE_READINESS,
            self::FIELD_REPORT_METHOD => self::FIELD_ENGINEERING_ENFORCE_READINESS_REPORT,
            self::FIELD_ALERT_CODE => self::FIELD_ENGINEERING_ENFORCE_READINESS_NOT_READY,
            self::FIELD_MESSAGE => 'ENG-11 enforcement flips are not ready for promotion.',
        ],
    ];

    /**
     * @param  non-empty-string  $checkId
     * @param  non-empty-string  $reportMethod  method on {@see AtlasAcosWatchdogHealthService}
     * @param  non-empty-string  $alertCode
     * @param  non-empty-string  $message
     */
    public function __construct(
        private AtlasAcosWatchdogHealthService $health,
        private string $checkId,
        private string $reportMethod,
        private string $alertCode,
        private string $message,
    ) {
        if ($checkId === '' || $reportMethod === '' || $alertCode === '' || $message === '') {
            throw new InvalidArgumentException('HealthReportWatchdogCheck requires non-empty id/method/alert/message.');
        }
        if (! method_exists($this->health, $reportMethod)) {
            throw new InvalidArgumentException("Unknown health report method: {$reportMethod}");
        }
    }

    public function id(): string
    {
        return $this->checkId;
    }

    public function run(): AtlasWatchdogCheckResult
    {
        /** @var array<string,mixed> $report */
        $report = $this->health->{$this->reportMethod}();

        return $this->health->toCheckResult($report, $this->alertCode, $this->message);
    }

    /**
     * Canonical catalog of former thin health wrappers (ELEV-31: remove a layer).
     *
     * @return list<array{id:string, report_method:string, alert_code:string, message:string}>
     */
    public static function catalog(): array
    {
        return self::CATALOG;
    }

    /**
     * @return list<self>
     */
    public static function makeAll(AtlasAcosWatchdogHealthService $health): array
    {
        $checks = [];
        foreach (self::catalog() as $row) {
            $checks[] = new self(
                $health,
                $row[self::FIELD_ID],
                $row[self::FIELD_REPORT_METHOD],
                $row[self::FIELD_ALERT_CODE],
                $row[self::FIELD_MESSAGE],
            );
        }

        return $checks;
    }
}
