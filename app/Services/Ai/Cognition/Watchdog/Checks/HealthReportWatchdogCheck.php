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
    public const CATALOG = [
        [
            'id' => 'mem-09.memory_quality',
            'report_method' => 'memoryQualityCheck',
            'alert_code' => 'memory_quality_check_failed',
            'message' => 'MEM-09 memory quality watchdog is not green.',
        ],
        [
            'id' => 'fee-13.learning_cadence',
            'report_method' => 'learningCadenceReport',
            'alert_code' => 'learning_cadence_stalled',
            'message' => 'FEE-13 learning cadence is stalled or under-evidenced.',
        ],
        [
            'id' => 'rag-10.aurg_coverage',
            'report_method' => 'aurgCoverageReport',
            'alert_code' => 'aurg_coverage_gate_failed',
            'message' => 'RAG-10 AURG cross-layer coverage is below floor.',
        ],
        [
            'id' => 'rag-12.rag_dimension',
            'report_method' => 'ragDimensionReport',
            'alert_code' => 'rag_dimension_watchdog_failed',
            'message' => 'RAG-12 retrieval dimension watchdog found a regression or masking issue.',
        ],
        [
            'id' => 'com-10.context_feedback_health',
            'report_method' => 'contextFeedbackHealthReport',
            'alert_code' => 'context_feedback_health_failed',
            'message' => 'COM-10 context feedback health is below the pinned floor.',
        ],
        [
            'id' => 'cpt-09.compaction_soak',
            'report_method' => 'compactionSoakWatchReport',
            'alert_code' => 'compaction_soak_not_ready',
            'message' => 'CPT-09 compaction soak is not ready for enforce.',
        ],
        [
            'id' => 'pip-08.scorecard_stability',
            'report_method' => 'pipelineStabilityReport',
            'alert_code' => 'scorecard_stability_failed',
            'message' => 'PIP-08 scorecard stability has not reached a green pipeline series.',
        ],
        [
            'id' => 'ope-08.lift_cycle_closure',
            'report_method' => 'liftCycleClosureReport',
            'alert_code' => 'lift_cycle_closure_stalled',
            'message' => 'OPE-08 lift cycle blockers are not closing.',
        ],
        [
            'id' => 'ope-10.scorecard_receipts_diagnosis',
            'report_method' => 'scorecardReceiptsDiagnosisReport',
            'alert_code' => 'scorecard_receipts_diagnosis_failed',
            'message' => 'OPE-10 found persistent partial scorecard receipts.',
        ],
        [
            'id' => 'eng-11.enforce_readiness',
            'report_method' => 'engineeringEnforceReadinessReport',
            'alert_code' => 'engineering_enforce_readiness_not_ready',
            'message' => 'ENG-11 enforcement flips are not ready for promotion.',
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
                $row['id'],
                $row['report_method'],
                $row['alert_code'],
                $row['message'],
            );
        }

        return $checks;
    }
}
