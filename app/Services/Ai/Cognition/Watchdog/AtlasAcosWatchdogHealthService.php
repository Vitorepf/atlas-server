<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog;

use App\Models\AiRagFeedbackEvent;
use App\Models\AiRunOutcome;
use App\Models\AtlasAemorExecutionEpisode;
use App\Models\AtlasLedgerEvent;
use App\Models\AtlasLongHorizonCompactionReceipt;
use App\Models\AtlasMemoryEntryUsage;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AtlasMemoryQualityService;
use App\Services\Ai\Cognition\AtlasCognitionEvidenceResolver;
use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use App\Services\Ai\Compounding\AtlasLearningRecallUseLiftService;
use App\Services\Ai\Governance\GovernanceFloorRegistry;
use App\Services\Ai\Governance\ProviderGovernanceCoverageLedger;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\LongHorizon\LongHorizonCrossWeekRecallLiftGateService;
use App\Services\Ai\Reality\AtlasRealityGraphStatusService;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Throwable;

final class AtlasAcosWatchdogHealthService
{
    public const MEMORY_QUALITY_SCHEMA = 'atlas.memory.quality_check.v1';

    public const CONTEXT_FEEDBACK_SCHEMA = 'atlas.context.feedback_health.v1';

    public const COMPACTION_SOAK_SCHEMA = 'atlas.compaction.soak_watch.v1';

    public const ENGINEERING_READINESS_SCHEMA = 'atlas.engineering.enforce_readiness.v1';

    public const AURG_COVERAGE_SCHEMA = 'atlas.aurg.coverage_gate.v1';

    public const RAG_DIMENSION_SCHEMA = 'atlas.rag.dimension_watchdog.v1';

    public const PIPELINE_SCORECARD_STABILITY_SCHEMA = 'atlas.pipeline.scorecard_stability_watch.v1';

    public const OPE_LIFT_CYCLE_CLOSURE_SCHEMA = 'atlas.ope.lift_cycle_closure_watch.v1';

    public const OPE_SCORECARD_RECEIPTS_DIAGNOSIS_SCHEMA = 'atlas.ope.scorecard_receipts_diagnosis_watch.v1';

    public const ONDA4_EMITTER_VERSION = 'atlas.acos.watchdog.onda4.v1';

    public const MEMORY_SCORE_REGRESSION_TOLERANCE = 5;

    public const MEMORY_SNAPSHOT_MAX_AGE_HOURS = 48;

    public const MEMORY_CONCENTRATION_FLOOR = 0.35;

    public const LEARNING_NEGATIVE_MAX_AGE_HOURS = 168;

    public const LEARNING_AEMOR_SOURCE_MAX_AGE_HOURS = 48;

    public const LEARNING_AI_RUN_OUTCOME_MAX_AGE_HOURS = 72;

    public const RAG_COVERAGE_FLOOR = 0.5;

    public const RAG_RETRIEVAL_EVAL_FLOOR = 95;

    public const RAG_RECALL_AT_5_FLOOR = 0.85;

    public const RAG_PRE_FILTER_CONCENTRATION_MASK_FLOOR = 0.5;

    public const FEEDBACK_WINDOW_HOURS = 168;

    public const FEEDBACK_TOTAL_EVENT_FLOOR = 10;

    public const FEEDBACK_MEASURED_COUNT_FLOOR = 3;

    public const FEEDBACK_SYNTHETIC_SHARE_MAX = 0.10;

    public const COMPACTION_WINDOW_DAYS = 14;

    public const COMPACTION_MIN_RECEIPTS = 50;

    public const COMPACTION_MIN_RETENTION_SCORE = 0.95;

    public const LIFT_STALLED_DAYS = 7;

    public const PIPELINE_PARTIAL_STALE_DAYS = 7;

    public const ENG_WINDOW_DAYS = 7;

    public const ENG_MIN_REAL_EXECUTIONS_PER_EXECUTOR = 1;

    public const ENG_MIN_FORGE_PROMOTED_CYCLES = 20;

    public const ENG_MIN_ADML_PROVEN_ROUTES = 3;

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function memoryQualityCheck(array $filters = []): array
    {
        $scorecard = app(AtlasMemoryQualityService::class)->scorecard($filters);
        $latestSnapshotAt = $this->parseDate(data_get($scorecard, 'latest_snapshot.snapshot_at'));
        $snapshotAgeHours = $latestSnapshotAt ? round($latestSnapshotAt->diffInMinutes(CarbonImmutable::now('UTC')) / 60, 2) : null;
        $freshness = (int) data_get($scorecard, 'components.freshness', 0);
        $concentration = AiValueNormalizer::finiteFloatOrNull(data_get($scorecard, 'ratios.recall_concentration_ratio', 0.0)) ?? 0.0;
        $recallUsageTotal = (int) data_get($scorecard, 'counts.retrieval_eval.recall_usage_total', 0);
        $demotionEnabled = (bool) config('atlas.semantic_memory.recall_concentration_demotion_enabled', true);
        $trendStatus = AiValueNormalizer::trimmedStringOrNull(data_get($scorecard, 'trend.status')) ?? 'unknown';
        $currentDelta = data_get($scorecard, 'trend.current_delta_from_latest');
        $latestDelta = data_get($scorecard, 'trend.latest_delta_from_previous');
        $scoreRegressed = in_array($trendStatus, ['regressed', 'watch_regressed'], true)
            || (is_numeric($currentDelta) && (int) $currentDelta < -self::MEMORY_SCORE_REGRESSION_TOLERANCE)
            || (is_numeric($latestDelta) && (int) $latestDelta < -self::MEMORY_SCORE_REGRESSION_TOLERANCE);

        $checks = [
            $this->checkRow('score_regression', ! $scoreRegressed, [
                'trend_status' => $trendStatus,
                'tolerance_points' => self::MEMORY_SCORE_REGRESSION_TOLERANCE,
                'current_delta_from_latest' => $currentDelta,
                'latest_delta_from_previous' => $latestDelta,
            ], 'memory_quality_score_regressed'),
            $this->checkRow('freshness_full', $freshness >= 100, [
                'freshness_component' => $freshness,
                'required' => 100,
            ], 'memory_freshness_below_full'),
            $this->checkRow('windowed_concentration_guarded', $concentration <= self::MEMORY_CONCENTRATION_FLOOR || $demotionEnabled || $recallUsageTotal === 0, [
                'windowed_concentration_ratio' => $concentration,
                'threshold' => self::MEMORY_CONCENTRATION_FLOOR,
                'demotion_enabled' => $demotionEnabled,
                'recall_usage_total' => $recallUsageTotal,
            ], 'recall_concentration_high_without_demotion'),
            $this->checkRow('snapshot_fresh', $snapshotAgeHours !== null && $snapshotAgeHours <= self::MEMORY_SNAPSHOT_MAX_AGE_HOURS, [
                'snapshot_age_hours' => $snapshotAgeHours,
                'max_age_hours' => self::MEMORY_SNAPSHOT_MAX_AGE_HOURS,
                'latest_snapshot_at' => $latestSnapshotAt?->toIso8601String(),
            ], 'memory_quality_snapshot_stale'),
        ];
        $failed = array_values(array_filter($checks, static fn (array $check): bool => ! (bool) $check['pass']));

        return [
            'schema_version' => self::MEMORY_QUALITY_SCHEMA,
            'status' => $failed === [] ? 'ok' : 'alert',
            'alert' => $failed !== [],
            'checks' => $checks,
            'raw' => [
                'score' => (int) (AiValueNormalizer::finiteFloatOrNull($scorecard['score'] ?? null) ?? 0),
                'status' => (AiValueNormalizer::trimmedStringOrNull($scorecard['status'] ?? null) ?? 'unknown'),
                'freshness' => $freshness,
                'windowed_concentration_ratio' => $concentration,
                'snapshot_age_hours' => $snapshotAgeHours,
                'recall_usage_total' => $recallUsageTotal,
            ],
            'alert_detail' => $failed === [] ? null : [
                'check_id' => AiValueNormalizer::trimmedScalarStringOrNull($failed[0]['id'] ?? null) ?? '',
                'code' => AiValueNormalizer::trimmedScalarStringOrNull($failed[0]['code'] ?? null) ?? '',
                'message' => 'Memory quality check failed.',
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public function learningCadenceReport(): array
    {
        $now = CarbonImmutable::now('UTC');
        $lastNegative = $this->latestMemoryFeedbackAt(AtlasMemoryEntryUsage::negativeFeedbackActions());
        $lastOutcome = $this->latestModelAt(AiRunOutcome::class, 'created_at', 'ai_run_outcomes');
        $lastDeliveredRefs = $this->latestRagDeliveredRefsAt();
        $aemor = [];
        foreach (['dev', 'forge', 'task'] as $source) {
            $aemor[$source] = $this->latestAemorSourceAt($source);
        }

        $lift = app(AtlasLearningRecallUseLiftService::class)->report();
        $checks = [
            $this->ageCheck('last_negative_feedback', $lastNegative, self::LEARNING_NEGATIVE_MAX_AGE_HOURS, $now),
            $this->ageCheck('last_ai_run_outcome', $lastOutcome, self::LEARNING_AI_RUN_OUTCOME_MAX_AGE_HOURS, $now),
            $this->checkRow('last_delivered_refs_event', $lastDeliveredRefs !== null, [
                'last_at' => $lastDeliveredRefs?->toIso8601String(),
            ], 'no_recent_delivered_refs_event'),
        ];
        foreach ($aemor as $source => $at) {
            $checks[] = $this->ageCheck('last_aemor_episode_'.$source, $at, self::LEARNING_AEMOR_SOURCE_MAX_AGE_HOURS, $now);
        }
        $checks[] = $this->checkRow('lift_case_count', (int) data_get($lift, 'measurement.with_recalled_memory.case_count', 0) > 0
            && (int) data_get($lift, 'measurement.without_recalled_memory.case_count', 0) > 0, [
                'with_recalled_memory' => data_get($lift, 'measurement.with_recalled_memory.case_count', 0),
                'without_recalled_memory' => data_get($lift, 'measurement.without_recalled_memory.case_count', 0),
                'measurement_ready' => data_get($lift, 'measurement.measurement_ready', false),
            ], 'learning_lift_cases_missing');

        return $this->reportFromChecks('atlas.learning.cadence_watchdog.v1', $checks, [
            'thresholds' => [
                'negative_feedback_max_age_hours' => self::LEARNING_NEGATIVE_MAX_AGE_HOURS,
                'aemor_source_max_age_hours' => self::LEARNING_AEMOR_SOURCE_MAX_AGE_HOURS,
                'ai_run_outcome_max_age_hours' => self::LEARNING_AI_RUN_OUTCOME_MAX_AGE_HOURS,
            ],
            'lift_status' => (AiValueNormalizer::trimmedStringOrNull($lift['status'] ?? null) ?? 'unknown'),
        ], 'learning_cadence_stalled');
    }

    /** @return array<string,mixed> */
    public function aurgCoverageReport(): array
    {
        $status = app(AtlasRealityGraphStatusService::class)->status();
        $coverage = AiValueNormalizer::arrayOrEmpty($status['coverage'] ?? null);
        $store = AiValueNormalizer::arrayOrEmpty($status['store'] ?? null);
        $edgesBySource = AiValueNormalizer::arrayOrEmpty($store['edges_by_source'] ?? null);
        $ratio = AiValueNormalizer::finiteFloatOrNull($coverage['memory_cross_layer_coverage_ratio'] ?? null) ?? 0.0;
        $blocking = [];
        if (! (bool) ($coverage['available'] ?? false)) {
            $blocking[] = (AiValueNormalizer::trimmedStringOrNull($coverage['reason'] ?? null) ?? 'aurg_store_unavailable');
        }
        if ($ratio < self::RAG_COVERAGE_FLOOR) {
            $blocking[] = 'memory_cross_layer_coverage_below_floor';
        }
        foreach (['linker_memory_code', 'linker_memory_domain', 'linker_evidence'] as $linker) {
            if ((int) (AiValueNormalizer::finiteFloatOrNull($edgesBySource[$linker] ?? null) ?? 0) === 0) {
                $blocking[] = 'cross_layer_linker_zero:'.$linker;
            }
        }

        return [
            'schema_version' => self::AURG_COVERAGE_SCHEMA,
            'status' => $blocking === [] ? 'ok' : 'alert',
            'alert' => $blocking !== [],
            'coverage' => $coverage,
            'store' => [
                'edges_by_source' => $edgesBySource,
                'last_ingest_at' => $store['last_ingest_at'] ?? null,
            ],
            'thresholds' => ['memory_cross_layer_coverage_ratio' => self::RAG_COVERAGE_FLOOR],
            'blocking' => array_values(array_unique($blocking)),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public function ragDimensionReport(): array
    {
        $quality = app(AtlasMemoryQualityService::class)->scorecard([]);
        $aurg = $this->aurgCoverageReport();
        $retrievalEval = (int) data_get($quality, 'components.retrieval_eval', 0);
        // Prefer the RAG-05 frozen golden surface; fall back to the older corpus metrics path.
        $recallAt5 = AiValueNormalizer::finiteFloatOrNull(
            data_get($quality, 'latest_snapshot.metadata.memory_recall_golden.recall_at_5')
        );
        if ($recallAt5 === null) {
            $recallAt5 = AiValueNormalizer::finiteFloatOrNull(
                data_get($quality, 'latest_snapshot.metadata.memory_recall_corpus.metrics.recall_at_5')
            );
        }
        $improperFloorDiscards = (int) (
            data_get($quality, 'latest_snapshot.metadata.memory_recall_golden.improper_floor_discards')
            ?? data_get($quality, 'latest_snapshot.metadata.memory_recall_corpus.metrics.improper_floor_discards', 0)
        );
        $coverageRatio = AiValueNormalizer::finiteFloatOrNull(data_get($aurg, 'coverage.memory_cross_layer_coverage_ratio', 0.0)) ?? 0.0;
        $preFilterConcentration = AiValueNormalizer::finiteFloatOrNull(data_get(
            $quality,
            'ratios.pre_filter_recall_concentration_ratio',
            data_get($quality, 'ratios.recall_concentration_ratio', 0.0),
        )) ?? 0.0;
        $issues = [];
        if ($retrievalEval < self::RAG_RETRIEVAL_EVAL_FLOOR) {
            $issues[] = 'retrieval_eval_below_floor';
        }
        if ($recallAt5 === null || $recallAt5 < self::RAG_RECALL_AT_5_FLOOR) {
            $issues[] = 'recall_at_5_below_floor_or_unmeasured';
        }
        if ($improperFloorDiscards > 0) {
            $issues[] = 'improper_floor_discards_present';
        }
        if ($coverageRatio < self::RAG_COVERAGE_FLOOR) {
            $issues[] = 'aurg_cross_layer_coverage_below_floor';
        }
        if ($preFilterConcentration >= self::RAG_PRE_FILTER_CONCENTRATION_MASK_FLOOR
            && $retrievalEval >= self::RAG_RETRIEVAL_EVAL_FLOOR) {
            $issues[] = 'concentration_masked_by_delivery_filter';
        }

        return [
            'schema_version' => self::RAG_DIMENSION_SCHEMA,
            'status' => $issues === [] ? 'ok' : 'alert',
            'alert' => $issues !== [],
            'issues' => array_values(array_unique($issues)),
            'raw' => [
                'retrieval_eval' => $retrievalEval,
                'recall_at_5' => $recallAt5,
                'improper_floor_discards' => $improperFloorDiscards,
                'aurg_cross_layer_coverage_ratio' => $coverageRatio,
                'pre_filter_concentration_ratio' => $preFilterConcentration,
            ],
            'thresholds' => [
                'retrieval_eval' => self::RAG_RETRIEVAL_EVAL_FLOOR,
                'recall_at_5' => self::RAG_RECALL_AT_5_FLOOR,
                'aurg_cross_layer_coverage_ratio' => self::RAG_COVERAGE_FLOOR,
                'pre_filter_concentration_mask_floor' => self::RAG_PRE_FILTER_CONCENTRATION_MASK_FLOOR,
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public function contextFeedbackHealthReport(): array
    {
        $since = CarbonImmutable::now('UTC')->subHours(self::FEEDBACK_WINDOW_HOURS);
        if (! DatabaseTableAvailability::has('ai_rag_feedback_events')) {
            return [
                'schema_version' => self::CONTEXT_FEEDBACK_SCHEMA,
                'status' => 'unavailable',
                'alert' => true,
                'blocking' => ['ai_rag_feedback_events_table_missing'],
                'total_event_count' => 0,
                'measured_share' => 0.0,
                'writer_shares' => [],
                'window' => ['hours' => self::FEEDBACK_WINDOW_HOURS, 'total_event_count' => 0],
                'generated_at' => now()->toIso8601String(),
            ];
        }

        $events = AiRagFeedbackEvent::query()->where('created_at', '>=', $since)->get();
        $total = $events->count();
        $measured = 0;
        $delivered = 0;
        $synthetic = 0;
        $transcript = 0;
        $byWriter = [];
        foreach ($events as $event) {
            $writer = AiValueNormalizer::trimmedScalarStringOrNull($event->flow_id ?: null) ?? 'unknown';
            $byWriter[$writer] ??= ['total' => 0, 'measured' => 0, 'delivered_refs' => 0];
            $byWriter[$writer]['total']++;
            $isMeasured = (int) $event->post_execution_utility > 0 || (int) $event->context_sufficiency > 0 || (int) $event->used_sources > 0;
            $hasDelivered = (int) $event->included_sources > 0 || (AiValueNormalizer::trimmedStringOrNull($event->retrieval_receipt_id) ?? '') !== '';
            $payload = AiValueNormalizer::arrayOrEmpty($event->payload);
            $isSynthetic = ($payload['synthetic'] ?? false) === true || str_contains(AiValueNormalizer::lowerTrimmedString($payload['source'] ?? ''), 'synthetic');
            $isTranscript = str_contains(AiValueNormalizer::lowerTrimmedString($payload['source'] ?? $payload['origin'] ?? ''), 'transcript_inferred');
            $measured += $isMeasured ? 1 : 0;
            $delivered += $hasDelivered ? 1 : 0;
            $synthetic += $isSynthetic ? 1 : 0;
            $transcript += $isTranscript ? 1 : 0;
            $byWriter[$writer]['measured'] += $isMeasured ? 1 : 0;
            $byWriter[$writer]['delivered_refs'] += $hasDelivered ? 1 : 0;
        }
        foreach ($byWriter as $writer => $row) {
            $byWriter[$writer]['measured_share'] = $row['total'] > 0 ? round($row['measured'] / $row['total'], 4) : 0.0;
            $byWriter[$writer]['delivered_refs_share'] = $row['total'] > 0 ? round($row['delivered_refs'] / $row['total'], 4) : 0.0;
        }
        $syntheticShare = $total > 0 ? round($synthetic / $total, 4) : 0.0;
        $blocking = [];
        if ($total < self::FEEDBACK_TOTAL_EVENT_FLOOR) {
            $blocking[] = 'total_event_count_below_floor';
        }
        if ($measured < self::FEEDBACK_MEASURED_COUNT_FLOOR) {
            $blocking[] = 'measured_count_below_floor';
        }
        if ($syntheticShare > self::FEEDBACK_SYNTHETIC_SHARE_MAX) {
            $blocking[] = 'synthetic_share_above_floor';
        }

        return [
            'schema_version' => self::CONTEXT_FEEDBACK_SCHEMA,
            'status' => $blocking === [] ? 'healthy' : 'alert',
            'alert' => $blocking !== [],
            'blocking' => $blocking,
            'window' => [
                'hours' => self::FEEDBACK_WINDOW_HOURS,
                'total_event_count' => $total,
                'measured_count' => $measured,
                'delivered_refs_count' => $delivered,
                'utility_real_share' => $total > 0 ? round($measured / $total, 4) : 0.0,
                'delivered_refs_share' => $total > 0 ? round($delivered / $total, 4) : 0.0,
                'synthetic_share' => $syntheticShare,
                'transcript_inferred_share' => $total > 0 ? round($transcript / $total, 4) : 0.0,
            ],
            'total_event_count' => $total,
            'measured_share' => $total > 0 ? round($measured / $total, 4) : 0.0,
            'writer_shares' => $byWriter,
            'by_writer' => $byWriter,
            'thresholds' => [
                'total_event_count_floor' => self::FEEDBACK_TOTAL_EVENT_FLOOR,
                'measured_count_floor' => self::FEEDBACK_MEASURED_COUNT_FLOOR,
                'synthetic_share_max' => self::FEEDBACK_SYNTHETIC_SHARE_MAX,
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public function compactionSoakWatchReport(): array
    {
        $since = CarbonImmutable::now('UTC')->subDays(self::COMPACTION_WINDOW_DAYS);
        if (! DatabaseTableAvailability::has('atlas_long_horizon_compaction_receipts')) {
            return [
                'schema_version' => self::COMPACTION_SOAK_SCHEMA,
                'status' => 'unavailable',
                'ready_to_enforce' => false,
                'blocking' => ['compaction_receipts_table_missing'],
                'window' => ['days' => self::COMPACTION_WINDOW_DAYS, 'compaction_count' => 0],
                'generated_at' => now()->toIso8601String(),
            ];
        }
        $receipts = AtlasLongHorizonCompactionReceipt::query()->where('created_at', '>=', $since)->get();
        $criticalCuts = 0;
        $retentionScores = [];
        foreach ($receipts as $receipt) {
            $retention = AiValueNormalizer::finiteFloatOrNull($receipt->context_retention_score);
            if ($retention !== null) {
                $retentionScores[] = $retention;
            }
            foreach (AiValueNormalizer::arrayOrEmpty($receipt->unresolved_loss ?? null) as $loss) {
                if (! is_array($loss)) {
                    continue;
                }
                $kind = AiValueNormalizer::lowerTrimmedString($loss['kind'] ?? $loss['severity'] ?? $loss['keep_kind'] ?? '');
                if (str_contains($kind, 'critical')) {
                    $criticalCuts++;
                }
            }
        }
        $crossWeek = app(LongHorizonCrossWeekRecallLiftGateService::class)->evaluate();
        $minRetention = $retentionScores === [] ? null : min($retentionScores);
        $blocking = [];
        if ($receipts->count() < self::COMPACTION_MIN_RECEIPTS) {
            $blocking[] = 'compaction_volume_below_floor';
        }
        if ($criticalCuts > 0) {
            $blocking[] = 'critical_must_keep_shadow_cut';
        }
        if ($minRetention === null || $minRetention < self::COMPACTION_MIN_RETENTION_SCORE) {
            $blocking[] = 'context_retention_score_below_floor';
        }
        if (($crossWeek['certified'] ?? false) !== true) {
            $blocking[] = 'cross_week_recall_lift_not_certified';
        }

        return [
            'schema_version' => self::COMPACTION_SOAK_SCHEMA,
            'status' => $blocking === [] ? 'ready' : 'not_ready',
            'ready_to_enforce' => $blocking === [],
            'blocking' => array_values(array_unique($blocking)),
            'window' => [
                'days' => self::COMPACTION_WINDOW_DAYS,
                'compaction_count' => $receipts->count(),
                'critical_must_keep_shadow_cuts' => $criticalCuts,
                'context_retention_score_min' => $minRetention,
                'context_retention_score_count' => count($retentionScores),
            ],
            'cross_week_recall_lift_gate' => [
                'status' => $crossWeek['status'] ?? 'unknown',
                'certified' => (bool) ($crossWeek['certified'] ?? false),
                'blockers' => AiValueNormalizer::arrayOrEmpty($crossWeek['blockers'] ?? null),
            ],
            'rollback_trigger' => [
                'id' => 'cpt_09_compaction_enforce',
                'condition' => '>=1 critical must_keep cut after enforcement flip',
                'rollback_env' => 'ATLAS_TOKEN_ECONOMY_ENFORCEMENT_MODE=observe',
            ],
            'thresholds' => [
                'window_days' => self::COMPACTION_WINDOW_DAYS,
                'min_compactions' => self::COMPACTION_MIN_RECEIPTS,
                'critical_must_keep_cuts' => 0,
                'min_context_retention_score' => self::COMPACTION_MIN_RETENTION_SCORE,
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public function pipelineStabilityReport(): array
    {
        $scorecard = app(AtlasCognitionScoreCardService::class)->build();
        $pipeline = AiValueNormalizer::finiteFloatOrNull(data_get($scorecard, 'score.dimensions.pipeline.score_out_of_10', 0.0)) ?? 0.0;
        $partials = array_values(array_filter(AiValueNormalizer::arrayOrEmpty($scorecard['subsystems'] ?? null), static fn (array $row): bool => ($row['pipeline_status'] ?? null) === AtlasCognitionScoreCardService::STATUS_PARTIAL));
        $blocking = [];
        if ($pipeline < 10.0) {
            $blocking[] = 'pipeline_score_below_perfect';
        }
        if ($partials !== []) {
            $blocking[] = 'pipeline_partials_present';
        }
        $payload = [
            'schema_version' => self::PIPELINE_SCORECARD_STABILITY_SCHEMA,
            'status' => $blocking === [] ? 'ok' : 'alert',
            'blocking' => $blocking,
            'scorecard_hash' => $scorecard['scorecard_hash'] ?? null,
            'pipeline_score_out_of_10' => $pipeline,
            'partial_count' => count($partials),
            'partial_acronyms' => array_values(array_map(static fn (array $row): string => (AiValueNormalizer::trimmedStringOrNull($row['acronym'] ?? null) ?? ''), array_slice($partials, 0, 10))),
            'generated_at' => now()->toIso8601String(),
        ];
        $this->recordLedger($blocking === [] ? LedgerEventType::OperationCompleted : LedgerEventType::OperationBlocked, $payload, 'pip-08.scorecard_stability');

        return $payload;
    }

    /** @return array<string,mixed> */
    public function liftCycleClosureReport(): array
    {
        $report = app(AtlasLearningRecallUseLiftService::class)->report();
        $blockers = array_values(AiValueNormalizer::arrayOrEmpty(data_get($report, 'measurement.blockers', [])));
        $status = (AiValueNormalizer::trimmedStringOrNull($report['status'] ?? null) ?? 'unknown');
        $series = $this->blockerSeries('ope-08.lift_cycle_closure', $blockers);
        $stalled = array_values(array_filter($series, static fn (array $row): bool => (int) (AiValueNormalizer::finiteFloatOrNull($row['days_in_block'] ?? null) ?? 0) > self::LIFT_STALLED_DAYS));
        $blocking = $blockers;
        if ($stalled !== []) {
            $blocking[] = 'lift_blocker_stalled';
        }
        $payload = [
            'schema_version' => self::OPE_LIFT_CYCLE_CLOSURE_SCHEMA,
            'status' => $blocking === [] && (bool) data_get($report, 'measurement.measurement_ready', false) ? 'ok' : 'alert',
            'lift_status' => $status,
            'blocking' => array_values(array_unique($blocking)),
            'case_counts' => [
                'with_recalled_memory' => (int) data_get($report, 'measurement.with_recalled_memory.case_count', 0),
                'without_recalled_memory' => (int) data_get($report, 'measurement.without_recalled_memory.case_count', 0),
            ],
            'blocker_series' => $series,
            'generated_at' => now()->toIso8601String(),
        ];
        $this->recordLedger($blocking === [] ? LedgerEventType::OperationCompleted : LedgerEventType::OperationBlocked, [
            'blockers' => $blockers,
            'status' => $status,
            'case_counts' => $payload['case_counts'],
            'blocker_series' => $series,
        ], 'ope-08.lift_cycle_closure');

        return $payload;
    }

    /** @return array<string,mixed> */
    public function scorecardReceiptsDiagnosisReport(): array
    {
        $scorecard = app(AtlasCognitionScoreCardService::class)->build();
        $resolver = app(AtlasCognitionEvidenceResolver::class);
        $partials = [];
        foreach (AiValueNormalizer::arrayOrEmpty($scorecard['subsystems'] ?? null) as $row) {
            if (($row['pipeline_status'] ?? null) !== AtlasCognitionScoreCardService::STATUS_PARTIAL) {
                continue;
            }
            $diagnosis = $resolver->resolvePipelineDiagnosis((AiValueNormalizer::trimmedStringOrNull($row['service_class'] ?? null) ?? ''));
            $partials[] = [
                'acronym' => (AiValueNormalizer::trimmedStringOrNull($row['acronym'] ?? null) ?? ''),
                'service_class' => (AiValueNormalizer::trimmedStringOrNull($row['service_class'] ?? null) ?? ''),
                'diagnosis' => $diagnosis,
            ];
        }
        $stale = array_values(array_filter($partials, static function (array $row): bool {
            $age = AiValueNormalizer::finiteFloatOrNull(data_get($row, 'diagnosis.latest_receipt_age_days'));

            return $age !== null && $age > self::PIPELINE_PARTIAL_STALE_DAYS;
        }));
        $blocking = [];
        if ($partials !== []) {
            $blocking[] = 'pipeline_partials_present';
        }
        if ($stale !== []) {
            $blocking[] = 'pipeline_partial_stale_after_mint_window';
        }

        return [
            'schema_version' => self::OPE_SCORECARD_RECEIPTS_DIAGNOSIS_SCHEMA,
            'status' => $blocking === [] ? 'ok' : 'alert',
            'blocking' => $blocking,
            'partial_count' => count($partials),
            'stale_partial_count' => count($stale),
            'diagnoses' => $partials,
            'thresholds' => ['partial_stale_days' => self::PIPELINE_PARTIAL_STALE_DAYS],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public function engineeringEnforceReadinessReport(): array
    {
        $coverage = app(ProviderGovernanceCoverageLedger::class);
        $live = app(AtlasDecideLiveOutcomeFeedbackService::class);
        $coverageRows = AppendOnlyJsonlStore::read($coverage->logPath());
        $windowCoverageRows = $this->rowsInWindow($coverageRows, self::ENG_WINDOW_DAYS, 'context.recorded_at');
        $summary = $coverage->summary();
        $liveRows = $this->rowsInWindow($live->listOutcomes(), self::ENG_WINDOW_DAYS);
        $governanceByExecutor = $this->countExecutors($windowCoverageRows);
        $forgePromoted = $this->forgePromotedCycles();
        $admlRoutes = $this->admlReadyRoutes($liveRows);
        $bypassRate = AiValueNormalizer::finiteFloatOrNull($summary['bypass_rate'] ?? null) ?? 1.0;
        $flips = [
            'governance_enforce' => [
                'ready' => count(array_filter($governanceByExecutor, static fn (int $count): bool => $count >= self::ENG_MIN_REAL_EXECUTIONS_PER_EXECUTOR)) >= 3
                    && $bypassRate === 0.0
                    && (int) (AiValueNormalizer::finiteFloatOrNull($summary['false_positive_total'] ?? null) ?? 0) === 0,
                'blocking' => array_values(array_filter([
                    count(array_filter($governanceByExecutor, static fn (int $count): bool => $count >= self::ENG_MIN_REAL_EXECUTIONS_PER_EXECUTOR)) >= 3 ? null : 'governance_soak_volume_below_floor',
                    $bypassRate === 0.0 ? null : 'governance_bypass_rate_nonzero',
                    (int) (AiValueNormalizer::finiteFloatOrNull($summary['false_positive_total'] ?? null) ?? 0) === 0 ? null : 'governance_false_positive_nonzero',
                ])),
                'raw' => [
                    'window_days' => self::ENG_WINDOW_DAYS,
                    'by_executor' => $governanceByExecutor,
                    'bypass_rate' => AiValueNormalizer::finiteFloatOrNull($summary['bypass_rate'] ?? null) ?? 0.0,
                    'false_positive_total' => (int) (AiValueNormalizer::finiteFloatOrNull($summary['false_positive_total'] ?? null) ?? 0),
                    'fp_definition' => (AiValueNormalizer::trimmedStringOrNull($summary['fp_definition'] ?? null) ?? ''),
                ],
            ],
            'forge_gate_enforce' => [
                'ready' => $forgePromoted >= self::ENG_MIN_FORGE_PROMOTED_CYCLES,
                'blocking' => $forgePromoted >= self::ENG_MIN_FORGE_PROMOTED_CYCLES ? [] : ['forge_promoted_cycle_volume_below_floor'],
                'raw' => ['promoted_harness_captured_cycles' => $forgePromoted],
            ],
            'adml_cost_outcome' => [
                'ready' => count($admlRoutes) >= self::ENG_MIN_ADML_PROVEN_ROUTES,
                'blocking' => count($admlRoutes) >= self::ENG_MIN_ADML_PROVEN_ROUTES ? [] : ['adml_proven_route_volume_below_floor'],
                'raw' => ['ready_routes' => count($admlRoutes), 'routes' => $admlRoutes],
            ],
        ];
        $blocking = [];
        foreach ($flips as $flip) {
            $blocking = array_merge($blocking, AiValueNormalizer::arrayOrEmpty($flip['blocking'] ?? null));
        }

        return [
            'schema_version' => self::ENGINEERING_READINESS_SCHEMA,
            'status' => $blocking === [] ? 'ready' : 'not_ready',
            'ready_to_enforce' => $blocking === [],
            'blocking' => array_values(array_unique($blocking)),
            'flips' => $flips,
            'sources' => [
                'provider_governance_coverage_ledger' => $this->relativePath($coverage->logPath()),
                'live_outcomes_jsonl' => $this->relativePath($live->logPath()),
                'forge_sovereign_verdict_jsonl' => $this->relativePath($this->forgeSovereignVerdictPath()),
            ],
            'thresholds' => [
                'window_days' => self::ENG_WINDOW_DAYS,
                'real_executions_per_executor' => self::ENG_MIN_REAL_EXECUTIONS_PER_EXECUTOR,
                'forge_promoted_cycles' => self::ENG_MIN_FORGE_PROMOTED_CYCLES,
                'adml_proven_routes' => self::ENG_MIN_ADML_PROVEN_ROUTES,
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @param array<string,mixed> $report */
    public function toCheckResult(array $report, string $alertCode, string $message): AtlasWatchdogCheckResult
    {
        $status = (AiValueNormalizer::trimmedStringOrNull($report['status'] ?? null) ?? '');
        $alert = (bool) ($report['alert'] ?? false)
            || in_array($status, ['alert', 'not_ready', 'unavailable'], true)
            || (isset($report['ready_to_enforce']) && $report['ready_to_enforce'] === false);

        if (! $alert) {
            return AtlasWatchdogCheckResult::ok($report);
        }

        return AtlasWatchdogCheckResult::alert(
            evidence: $report,
            alert: [
                'code' => $alertCode,
                'message' => $message,
            ],
        );
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function reportFromChecks(string $schema, array $checks, array $extra, string $alertCode): array
    {
        $failed = array_values(array_filter($checks, static fn (array $check): bool => ! (bool) $check['pass']));

        return [
            'schema_version' => $schema,
            'status' => $failed === [] ? 'ok' : 'alert',
            'alert' => $failed !== [],
            'checks' => $checks,
            'blocking' => array_values(array_map(static fn (array $check): string => AiValueNormalizer::trimmedScalarStringOrNull($check['code'] ?? null) ?? '', $failed)),
            'alert_detail' => $failed === [] ? null : [
                'code' => $alertCode,
                'check_id' => AiValueNormalizer::trimmedScalarStringOrNull($failed[0]['id'] ?? null) ?? '',
            ],
            ...$extra,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    private function checkRow(string $id, bool $pass, array $raw = [], string $code = ''): array
    {
        return [
            'id' => $id,
            'pass' => $pass,
            'code' => $code !== '' ? $code : ($pass ? 'ok' : $id.'_failed'),
            'raw' => $raw,
        ];
    }

    private function ageCheck(string $id, ?CarbonImmutable $at, int $maxAgeHours, CarbonImmutable $now): array
    {
        $age = $at ? round($at->diffInMinutes($now) / 60, 2) : null;

        return $this->checkRow($id, $age !== null && $age <= $maxAgeHours, [
            'last_at' => $at?->toIso8601String(),
            'age_hours' => $age,
            'max_age_hours' => $maxAgeHours,
        ], $id.'_stale_or_missing');
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }
        if (AiValueNormalizer::trimmedStringOrNull($value) === null) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<string> $actions */
    private function latestMemoryFeedbackAt(array $actions): ?CarbonImmutable
    {
        if (! DatabaseTableAvailability::has('atlas_memory_entry_usages')) {
            return null;
        }
        $raw = AtlasMemoryEntryUsage::query()
            ->whereIn('feedback_action', $actions)
            ->max('feedback_recorded_at');

        return $this->parseDate($raw);
    }

    /** @param class-string $model */
    private function latestModelAt(string $model, string $column, string $table): ?CarbonImmutable
    {
        if (! DatabaseTableAvailability::has($table)) {
            return null;
        }

        return $this->parseDate($model::query()->max($column));
    }

    private function latestRagDeliveredRefsAt(): ?CarbonImmutable
    {
        if (! DatabaseTableAvailability::has('ai_rag_feedback_events')) {
            return null;
        }
        $row = AiRagFeedbackEvent::query()
            ->where(function ($query): void {
                $query->where('included_sources', '>', 0)
                    ->orWhereNotNull('retrieval_receipt_id');
            })
            ->latest('created_at')
            ->first();

        return $this->parseDate($row?->created_at);
    }

    private function latestAemorSourceAt(string $source): ?CarbonImmutable
    {
        if (! DatabaseTableAvailability::has('atlas_aemor_execution_episodes')) {
            return null;
        }
        $needle = '%'.$source.'%';
        $raw = AtlasAemorExecutionEpisode::query()
            ->where(function ($query) use ($needle): void {
                $query->where('flow_id', 'like', $needle)
                    ->orWhere('surface_id', 'like', $needle)
                    ->orWhere('scope_type', 'like', $needle);
            })
            ->max('created_at');

        return $this->parseDate($raw);
    }

    /**
     * @param  list<string>  $blockers
     * @return list<array<string,mixed>>
     */
    private function blockerSeries(string $scopeId, array $blockers): array
    {
        if (! DatabaseTableAvailability::has('atlas_ledger_events')) {
            return array_map(static fn (string $blocker): array => [
                'blocker' => $blocker,
                'days_in_block' => 0,
                'first_seen_at' => null,
            ], $blockers);
        }
        $hasScopeColumns = DatabaseTableAvailability::hasColumn('atlas_ledger_events', 'scope_type')
            && DatabaseTableAvailability::hasColumn('atlas_ledger_events', 'scope_id');
        $series = [];
        foreach ($blockers as $blocker) {
            $query = AtlasLedgerEvent::query()
                ->where('event_type', LedgerEventType::OperationBlocked->value)
                ->whereJsonContains('payload->blockers', $blocker)
                ->orderBy('occurred_at');
            if ($hasScopeColumns) {
                $query->where('scope_type', 'acos_watchdog')->where('scope_id', $scopeId);
            } else {
                // Repair migrations may recreate the ledger without scope columns;
                // correlation_id still scopes watchdog blocker history honestly.
                $query->where('correlation_id', 'acos:watchdog:'.$scopeId);
            }
            $first = $query->first();
            $firstAt = $this->parseDate($first?->occurred_at) ?? CarbonImmutable::now('UTC');
            $series[] = [
                'blocker' => $blocker,
                'first_seen_at' => $first?->occurred_at?->toIso8601String(),
                'days_in_block' => (int) floor($firstAt->diffInHours(CarbonImmutable::now('UTC')) / 24),
            ];
        }

        return $series;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function recordLedger(LedgerEventType $type, array $payload, string $scopeId): void
    {
        try {
            app(AtlasEvidenceLedger::class)->record($type, $payload, [
                'tenant_id' => 'default',
                'operator_id' => 'system',
                'envelope_id' => 'acos:watchdog:'.$scopeId.':'.now()->format('YmdHis'),
                'correlation_id' => 'acos:watchdog:'.$scopeId,
                'scope_type' => 'acos_watchdog',
                'scope_id' => $scopeId,
                'emitter_stage' => 'atlas.acos.watchdog',
                'emitter_version' => self::ONDA4_EMITTER_VERSION,
            ]);
        } catch (Throwable) {
            // The health report remains honest even if the append-only evidence sink is absent.
        }
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function rowsInWindow(array $rows, int $days, string $recordedAtPath = 'recorded_at'): array
    {
        $since = CarbonImmutable::now('UTC')->subDays($days);

        return array_values(array_filter($rows, function (array $row) use ($since, $recordedAtPath): bool {
            $at = $this->parseDate(data_get($row, $recordedAtPath) ?? data_get($row, 'recorded_at') ?? data_get($row, 'created_at'));

            return $at !== null && $at->greaterThanOrEqualTo($since);
        }));
    }

    /** @param list<array<string,mixed>> $rows @return array<string,int> */
    private function countExecutors(array $rows): array
    {
        $counts = ['dev' => 0, 'forge' => 0, 'autonomos' => 0];
        foreach ($rows as $row) {
            $actor = AiValueNormalizer::lowerTrimmedString(data_get($row, 'context.executor', data_get($row, 'context.actor', data_get($row, 'surface', ''))));
            foreach (array_keys($counts) as $executor) {
                if (str_contains($actor, $executor)) {
                    $counts[$executor]++;
                }
            }
        }

        return $counts;
    }

    private function forgePromotedCycles(): int
    {
        $count = 0;
        foreach (AppendOnlyJsonlStore::read($this->forgeSovereignVerdictPath()) as $row) {
            if (($row['promoted'] ?? false) === true
                && ($row['evidence_provenance'] ?? null) === 'harness_captured'
                && (int) (AiValueNormalizer::finiteFloatOrNull($row['tests_run'] ?? null) ?? 0) > 0
                && count(AiValueNormalizer::arrayOrEmpty($row['commands'] ?? null)) > 0) {
                $count++;
            }
        }

        return $count;
    }

    /** @param list<array<string,mixed>> $rows @return array<string,int> */
    private function admlReadyRoutes(array $rows): array
    {
        $minEvidence = (int) app(GovernanceFloorRegistry::class)->atlasDecideCostOutcomeConfig(
            (bool) config('atlas.patamar4.adml_cost_outcome.enabled', false),
        )['min_evidence'];
        $routes = [];
        foreach ($rows as $row) {
            if (($row['proven_real'] ?? false) !== true) {
                continue;
            }
            $route = (AiValueNormalizer::trimmedStringOrNull($row['task_category'] ?? null) ?? '').'|'.(AiValueNormalizer::trimmedStringOrNull($row['role'] ?? null) ?? '');
            if ($route !== '|') {
                $routes[$route] = ($routes[$route] ?? 0) + 1;
            }
        }

        return array_filter($routes, static fn (int $count): bool => $count >= $minEvidence);
    }

    private function forgeSovereignVerdictPath(): string
    {
        return storage_path('app/atlas/engineering-kernel/forge-sovereign-verdicts.jsonl');
    }

    private function relativePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $base = str_replace('\\', '/', base_path());
        if (str_starts_with($path, $base.'/')) {
            return substr($path, strlen($base) + 1);
        }

        return $path;
    }
}
